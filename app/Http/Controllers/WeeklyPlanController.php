<?php

namespace App\Http\Controllers;

use App\Models\WeeklyWorkoutPlan;
use App\Services\ProgressiveOverload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * WeeklyPlanController
 *
 * Feature #4: Weekly Workout Plans with Preferred Days
 * Handles generation, retrieval, and management of weekly workout plans
 */
class WeeklyPlanController extends Controller
{
    /**
     * Generate a new weekly workout plan for the user
     *
     * POST /api/plans/generate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateWeeklyPlan(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer',
                'regenerate' => 'boolean',
                'week_start_date' => 'date|date_format:Y-m-d',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user_id;
            $regenerate = $request->input('regenerate', false);
            $weekStartDate = $request->input('week_start_date')
                ? Carbon::parse($request->week_start_date)
                : Carbon::now()->startOfWeek();

            Log::info('[WEEKLY_PLAN] Generating plan', [
                'user_id' => $userId,
                'week_start' => $weekStartDate->toDateString(),
                'regenerate' => $regenerate
            ]);

            // Check if plan already exists for this week
            $existingPlan = WeeklyWorkoutPlan::forWeek($userId, $weekStartDate)->first();

            if ($existingPlan && !$regenerate) {
                Log::info('[WEEKLY_PLAN] Plan already exists, returning existing plan', [
                    'plan_id' => $existingPlan->plan_id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Weekly plan already exists',
                    'data' => $existingPlan,
                    'regenerated' => false
                ]);
            }

            // Get user data from auth service
            $userData = $this->getUserData($userId);

            if (!$userData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch user data'
                ], 500);
            }

            // force_fresh=true means the user manually pressed "Regenerate Plan".
            // We pass a random week_seed to ML so a genuinely different exercise set is produced.
            // Auto-regen (system-triggered) never sets force_fresh, so it stays on the
            // deterministic ISO-week seed — invisible and consistent for the user.
            $forceFresh = (bool) $request->input('force_fresh', false);
            if ($forceFresh) {
                // Use range 1000–99999 to avoid colliding with ISO week numbers (1–53)
                $userData['week_seed'] = rand(1000, 99999);
                Log::info('[WEEKLY_PLAN] force_fresh=true, using random week_seed', [
                    'week_seed' => $userData['week_seed'],
                ]);
            }

            // Pillar 1: PHP is the single source of truth for exercise count.
            // When client_session_count is provided (auto-regen path), PHP computes
            // the authoritative exercises_per_day and passes it to ML, so ML never
            // uses its own tracking query or time-cap formula for this value.
            $clientSessionCountForRegen = (int) $request->input('client_session_count', -1);
            if ($clientSessionCountForRegen >= 0) {
                $baseForML = ProgressiveOverload::getBaseCount($userData['fitness_level'] ?? 'beginner');
                $userData['session_count'] = $clientSessionCountForRegen;
                $userData['exercises_per_day'] = $baseForML + (ProgressiveOverload::getSessionTier($clientSessionCountForRegen) - 1);
            }

            // When client_session_count is not provided (e.g. direct "Regenerate" button call),
            // derive it from the tracking service BEFORE calling ML so that:
            // 1. ML receives the correct exercises_per_day for the user's actual tier.
            // 2. The stored snapshot records the authoritative session_tier (stops infinite
            //    regeneration loops caused by ML metadata always returning session_tier=1).
            $cachedAnalysis = null;
            if ($clientSessionCountForRegen < 0) {
                $cachedAnalysis = $this->getSessionAnalysis(
                    (int) $userId,
                    $weekStartDate,
                    $weekStartDate->copy()->endOfWeek(),
                    $userData['fitness_level'] ?? 'beginner'
                );
                $clientSessionCountForRegen = $cachedAnalysis['pre_week_count'] + count($cachedAnalysis['completed_day_counts']);
                $baseForML = ProgressiveOverload::getBaseCount($userData['fitness_level'] ?? 'beginner');
                $userData['session_count'] = $clientSessionCountForRegen;
                $userData['exercises_per_day'] = $baseForML + (ProgressiveOverload::getSessionTier($clientSessionCountForRegen) - 1);
            }

            // Reconcile exercises_per_day with user's time_constraints AND fitness level bounds.
            //
            // Time preference (inverse Tabata formula): how many exercises fit in the chosen duration.
            // This acts as a FLOOR (onboarding promised 20min=4, 25min=5, 30min=6).
            //
            // Fitness level CEILING: beginners cap at 6, intermediate at 8, advanced at 12.
            // Without this cap, a beginner who chose 45min would get 9 exercises — far beyond
            // what a beginner's body can handle, defeating the purpose of fitness-level scaling.
            $timeConstraints = $userData['time_constraints'] ?? 30;
            $maxByTime = (int) floor(($timeConstraints * 60 + 60) / 300);
            [, $levelMax] = ProgressiveOverload::getExerciseBounds($userData['fitness_level'] ?? 'beginner');
            $userData['exercises_per_day'] = min(max($userData['exercises_per_day'], $maxByTime), $levelMax);

            // Call ML service to generate weekly plan
            $weeklyPlanData = $this->callMLPlanGeneration($userData);

            if (!$weeklyPlanData) {
                if ($regenerate && $existingPlan) {
                    // ML unavailable during regeneration — preserve the existing plan rather
                    // than storing a wrong fallback. The client will retry on next load,
                    // at which point ML will likely be available again.
                    Log::warning('[WEEKLY_PLAN] ML unavailable during regen, preserving existing plan', [
                        'user_id' => $userId,
                        'plan_id' => $existingPlan->plan_id,
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => 'Weekly plan unchanged (ML service temporarily unavailable)',
                        'data' => $existingPlan,
                        'regenerated' => false,
                        'ml_unavailable' => true,
                    ]);
                }
                // First-time plan creation — no existing plan to fall back to, use simple distribution
                Log::warning('[WEEKLY_PLAN] ML service failed, using fallback for new plan');
                $weeklyPlanData = $this->generateFallbackPlan($userData, $request->bearerToken());
            }

            // Trim exercises for completed days to the count the user actually did.
            // Uses tier-chronology: we sort ALL sessions oldest-first and track the
            // running cumulative count to determine what tier was active at each
            // completion — no dependency on user_exercise_history records.
            //
            // Counts are passed from getCurrentWeekPlan to avoid a second tracking call.
            // If called directly (e.g. manual "Regenerate"), we query the service here.
            $completedDayCounts = [];
            $preWeekCount = -1;

            $countsJson = $request->input('completed_session_counts');
            if ($countsJson) {
                $completedDayCounts = json_decode($countsJson, true) ?? [];
            }

            $preWeekCountInput = $request->input('pre_week_session_count');
            if ($preWeekCountInput !== null) {
                $preWeekCount = (int) $preWeekCountInput;
            }

            if ((empty($completedDayCounts) && $existingPlan) || $preWeekCount < 0) {
                $analysis = $cachedAnalysis ?? $this->getSessionAnalysis(
                    (int) $userId,
                    $weekStartDate,
                    $weekStartDate->copy()->endOfWeek(),
                    $userData['fitness_level'] ?? 'beginner'
                );
                if (empty($completedDayCounts)) {
                    $completedDayCounts = $analysis['completed_day_counts'];
                }
                if ($preWeekCount < 0) {
                    $preWeekCount = $analysis['pre_week_count'];
                }
            }

            $base = ProgressiveOverload::getBaseCount($userData['fitness_level'] ?? 'beginner');
            $timeFloorForRegen = $maxByTime; // already computed above from time_constraints
            $pastMissedTierCount = min(max($base + (ProgressiveOverload::getSessionTier(max(0, $preWeekCount)) - 1), $timeFloorForRegen), $levelMax);

            $daysOfWeek = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $today = strtolower(Carbon::now()->format('l'));
            $todayIndex = array_search($today, $daysOfWeek);
            $completedDayNames = array_keys($completedDayCounts);

            // When force_fresh is set, the ML returned a brand-new exercise pool.
            // Completed days must show the EXACT exercises the user already did —
            // not the first N exercises from an unrelated random pool.
            // Capture them from the existing plan before we apply any trimming.
            $preservedCompletedExercises = [];
            if ($forceFresh && $existingPlan && !empty($completedDayCounts)) {
                foreach (array_keys($completedDayCounts) as $dayName) {
                    $dayData = $existingPlan->plan_data[$dayName] ?? [];
                    if (!empty($dayData['exercises'])) {
                        $preservedCompletedExercises[$dayName] = $dayData['exercises'];
                    }
                }
            }

            // Trim/restore completed days:
            // - force_fresh:  restore exact exercises from the old plan (user already did these)
            // - normal regen: trim to the correct count from the new ML pool (same seed = same exercises)
            foreach ($completedDayCounts as $dayName => $expectedCount) {
                if (!isset($weeklyPlanData['plan_data'][$dayName])) continue;
                if (isset($preservedCompletedExercises[$dayName])) {
                    // Restore exactly what the user did — don't touch the new ML pool
                    $weeklyPlanData['plan_data'][$dayName]['exercises'] = $preservedCompletedExercises[$dayName];
                    Log::info('[WEEKLY_PLAN] Restored exercises for completed day (force_fresh)', [
                        'day' => $dayName,
                        'count' => count($preservedCompletedExercises[$dayName]),
                    ]);
                } elseif ($expectedCount > 0) {
                    $weeklyPlanData['plan_data'][$dayName]['exercises'] = array_slice(
                        $weeklyPlanData['plan_data'][$dayName]['exercises'],
                        0,
                        $expectedCount
                    );
                    Log::info('[WEEKLY_PLAN] Trimmed exercises for completed day', [
                        'day' => $dayName,
                        'trimmed_to' => $expectedCount,
                    ]);
                }
            }

            // Trim past missed days to pre-week tier count (not current tier)
            foreach ($weeklyPlanData['plan_data'] as $dayName => &$dayData) {
                if (!($dayData['planned'] ?? false)) continue;
                if (in_array($dayName, $completedDayNames)) continue;
                $dayIndex = array_search(strtolower($dayName), $daysOfWeek);
                if ($dayIndex === false || $dayIndex >= $todayIndex) continue;
                if (!isset($dayData['exercises'])) continue;
                $dayData['exercises'] = array_slice($dayData['exercises'], 0, $pastMissedTierCount);
                Log::info('[WEEKLY_PLAN] Trimmed past missed day to pre-week tier', [
                    'day' => $dayName,
                    'trimmed_to' => $pastMissedTierCount,
                    'pre_week_count' => $preWeekCount,
                ]);
            }
            unset($dayData);

            // Safety net: enforce correct tier count for future/today uncompleted days.
            // Catches cases where ML returned wrong count (stale cache, time-cap, or
            // different session query). $base, $timeFloorForRegen and $clientSessionCountForRegen
            // are already in scope from above.
            $currentTierCountForRegen = $clientSessionCountForRegen >= 0
                ? min(max($base + (ProgressiveOverload::getSessionTier($clientSessionCountForRegen) - 1), $timeFloorForRegen), $levelMax)
                : min(max($base + (($weeklyPlanData['session_tier'] ?? 1) - 1), $timeFloorForRegen), $levelMax);

            foreach ($weeklyPlanData['plan_data'] as $dayName => &$dayData) {
                if (!($dayData['planned'] ?? false)) continue;
                if (in_array($dayName, $completedDayNames)) continue;
                $dayIndex = array_search(strtolower($dayName), $daysOfWeek);
                if ($dayIndex === false || $dayIndex < $todayIndex) continue; // Past day — already handled
                if (!isset($dayData['exercises'])) continue;
                $dayData['exercises'] = array_slice($dayData['exercises'], 0, $currentTierCountForRegen);
            }
            unset($dayData);

            // Calculate week end date (Sunday)
            $weekEndDate = $weekStartDate->copy()->endOfWeek();

            // Delete old plan if regenerating
            if ($existingPlan) {
                Log::info('[WEEKLY_PLAN] Deleting existing plan for regeneration', [
                    'old_plan_id' => $existingPlan->plan_id
                ]);
                $existingPlan->delete();
            }

            // Clear is_current_week flag on any older plans for this user that are
            // still marked as current. This prevents orphaned plans from previous
            // weeks being returned by the currentWeek() scope, which has no ORDER BY.
            WeeklyWorkoutPlan::where('user_id', $userId)
                ->where('is_current_week', true)
                ->where('week_start_date', '<', $weekStartDate)
                ->update(['is_current_week' => false, 'is_active' => false]);

            // Create new weekly plan
            $plan = WeeklyWorkoutPlan::create([
                'user_id' => $userId,
                'week_start_date' => $weekStartDate,
                'week_end_date' => $weekEndDate,
                'is_active' => true,
                'is_current_week' => $this->isCurrentWeek($weekStartDate),
                'plan_data' => $weeklyPlanData['plan_data'],
                'total_workout_days' => $weeklyPlanData['total_workout_days'],
                'total_rest_days' => $weeklyPlanData['total_rest_days'],
                'total_exercises' => $weeklyPlanData['total_exercises'],
                'estimated_weekly_duration' => $weeklyPlanData['estimated_weekly_duration'],
                'estimated_weekly_calories' => $weeklyPlanData['estimated_weekly_calories'],
                'ml_generated' => $weeklyPlanData['ml_generated'] ?? true,
                'ml_confidence_score' => $weeklyPlanData['ml_confidence_score'] ?? null,
                'generation_method' => $weeklyPlanData['generation_method'] ?? 'ml_auto',
                'user_preferences_snapshot' => [
                    'fitness_level' => $userData['fitness_level'],
                    'preferred_workout_days' => $userData['preferred_workout_days'],
                    'target_muscle_groups' => $userData['target_muscle_groups'],
                    'time_constraints' => $userData['time_constraints'],
                    'session_count' => $clientSessionCountForRegen >= 0 ? $clientSessionCountForRegen : ($weeklyPlanData['session_count'] ?? 0),
                    'session_tier' => $clientSessionCountForRegen >= 0
                        ? ProgressiveOverload::getSessionTier($clientSessionCountForRegen)
                        : ($weeklyPlanData['session_tier'] ?? 1),
                ],
            ]);

            Log::info('[WEEKLY_PLAN] Plan created successfully', [
                'plan_id' => $plan->plan_id,
                'workout_days' => $plan->total_workout_days,
                'stored_session_tier' => $plan->user_preferences_snapshot['session_tier'] ?? 'NULL',
                'stored_session_count' => $plan->user_preferences_snapshot['session_count'] ?? 'NULL',
                'client_session_count_used' => $clientSessionCountForRegen,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Weekly plan generated successfully',
                'data' => $plan,
                'regenerated' => $regenerate
            ], 201);

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] Generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate weekly plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current week's plan for a user
     *
     * GET /api/plans/current?user_id={id}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCurrentWeekPlan(Request $request): JsonResponse
    {
        try {
            $userId = $request->query('user_id');

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }

            Log::info('[WEEKLY_PLAN] Fetching current week plan', ['user_id' => $userId]);

            // Try to find current week's plan
            $plan = WeeklyWorkoutPlan::currentWeek($userId)->first();

            // Check if we should regenerate:
            // 1. No plan exists
            // 2. Plan has inconsistent exercise counts (old buggy generation)
            // 3. Force regenerate query parameter
            $shouldRegenerate = false;

            if (!$plan) {
                Log::info('[WEEKLY_PLAN] No current plan found, generating new one');
                $shouldRegenerate = true;
            } else {
                // Check for out-of-range exercise counts (indicates old buggy generation)
                // Using RANGE check to support progressive overload (not a fixed expected count)
                $planData = $plan->plan_data ?? [];
                [$minCount, $maxCount] = ProgressiveOverload::getExerciseBounds($plan->user_preferences_snapshot['fitness_level'] ?? 'beginner');
                $hasInconsistentCounts = false;

                foreach ($planData as $dayName => $dayData) {
                    if (isset($dayData['planned']) && $dayData['planned'] && isset($dayData['exercises'])) {
                        $exerciseCount = count($dayData['exercises']);
                        if ($exerciseCount > 0 && ($exerciseCount < $minCount || $exerciseCount > $maxCount)) {
                            $hasInconsistentCounts = true;
                            Log::info('[WEEKLY_PLAN] Detected out-of-range exercise count', [
                                'day' => $dayName,
                                'count' => $exerciseCount,
                                'valid_range' => "{$minCount}-{$maxCount}",
                            ]);
                            break;
                        }
                    }
                }

                if ($hasInconsistentCounts) {
                    Log::info('[WEEKLY_PLAN] Plan has inconsistent counts, regenerating');
                    $shouldRegenerate = true;
                }

                // Check if plan used fallback exercises (indicates Content Service fetch failed)
                if (!$shouldRegenerate && isset($plan->generation_method) && $plan->generation_method === 'fallback') {
                    Log::info('[WEEKLY_PLAN] Plan used fallback exercises, regenerating with real database exercises');
                    $shouldRegenerate = true;
                }

                // Check if exercises match the user's fitness level (fixes old bug where all exercises were difficulty 2)
                // - Beginner users should have difficulty 1 exercises
                // - Advanced users should have difficulty 3 exercises
                // - Intermediate users are OK with difficulty 2
                if (!$shouldRegenerate) {
                    $planFitnessLevel = $plan->user_preferences_snapshot['fitness_level'] ?? 'beginner';
                    $expectedDifficulty = match($planFitnessLevel) {
                        'beginner' => 1,
                        'advanced' => 3,
                        default => 2
                    };

                    // Only check beginner and advanced - intermediate is fine with difficulty 2
                    if ($planFitnessLevel === 'beginner' || $planFitnessLevel === 'advanced') {
                        $hasCorrectDifficulty = false;
                        foreach ($planData as $dayName => $dayData) {
                            if (isset($dayData['exercises']) && is_array($dayData['exercises'])) {
                                foreach ($dayData['exercises'] as $exercise) {
                                    if (isset($exercise['difficulty_level']) && $exercise['difficulty_level'] == $expectedDifficulty) {
                                        $hasCorrectDifficulty = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                        if (!$hasCorrectDifficulty) {
                            Log::info('[WEEKLY_PLAN] User fitness level mismatch detected, regenerating', [
                                'fitness_level' => $planFitnessLevel,
                                'expected_difficulty' => $expectedDifficulty,
                                'reason' => 'difficulty mapping fix'
                            ]);
                            $shouldRegenerate = true;
                        }
                    }
                }

                // Check if user's fitness level has changed since plan was generated
                // This ensures exercises match the user's CURRENT fitness level, not the old one
                if (!$shouldRegenerate) {
                    $currentUserData = $this->getUserData((int) $userId);
                    $planFitnessLevel = $plan->user_preferences_snapshot['fitness_level'] ?? 'beginner';
                    $currentFitnessLevel = $currentUserData['fitness_level'] ?? 'beginner';

                    if ($currentFitnessLevel !== $planFitnessLevel) {
                        Log::info('[WEEKLY_PLAN] Fitness level changed, regenerating plan', [
                            'old_level' => $planFitnessLevel,
                            'new_level' => $currentFitnessLevel,
                            'user_id' => $userId
                        ]);
                        $shouldRegenerate = true;
                    }
                }

                // Allow force regenerate via query parameter
                if ($request->query('force_regenerate') === 'true') {
                    Log::info('[WEEKLY_PLAN] Force regenerate requested');
                    $shouldRegenerate = true;
                }

                // Check if user's progressive overload tier has advanced since plan was generated
                $clientSessionCount = (int) $request->query('session_count', -1);
                if (!$shouldRegenerate && $clientSessionCount >= 0) {
                    $storedTier = $plan->user_preferences_snapshot['session_tier'] ?? 1;
                    $currentTier = ProgressiveOverload::getSessionTier($clientSessionCount);
                    if ($currentTier > $storedTier) {
                        Log::info('[WEEKLY_PLAN] Session tier advanced, regenerating', [
                            'stored_tier' => $storedTier,
                            'current_tier' => $currentTier,
                            'session_count' => $clientSessionCount,
                        ]);
                        $shouldRegenerate = true;
                    }
                }
            }

            // Check if any completed day's exercise count in the plan doesn't match
            // what the user actually did (determined by tier at completion time).
            // Querying here lets us pass the result to generateWeeklyPlan so it
            // doesn't need a second tracking service call.
            $completedDayCounts = [];
            $preWeekCount = 0;
            if ($plan && !$shouldRegenerate) {
                $fitnessLevel = $plan->user_preferences_snapshot['fitness_level'] ?? 'beginner';
                $weekStart = Carbon::now()->startOfWeek();
                $weekEnd = Carbon::now()->endOfWeek();
                $analysis = $this->getSessionAnalysis(
                    (int) $userId,
                    $weekStart,
                    $weekEnd,
                    $fitnessLevel
                );
                $completedDayCounts = $analysis['completed_day_counts'];
                $preWeekCount = $analysis['pre_week_count'];

                // Check 2: Completed days with wrong exercise count
                foreach ($completedDayCounts as $dayName => $expectedCount) {
                    $planDayData = ($plan->plan_data ?? [])[$dayName] ?? [];
                    if (!($planDayData['planned'] ?? false)) {
                        continue;
                    }
                    $planCount = count($planDayData['exercises'] ?? []);
                    if ($expectedCount > 0 && $planCount !== $expectedCount) {
                        Log::info('[WEEKLY_PLAN] Completed day exercise count mismatch, fixing', [
                            'day' => $dayName,
                            'plan_count' => $planCount,
                            'expected_count' => $expectedCount,
                        ]);
                        $shouldRegenerate = true;
                        break;
                    }
                }

                // Check 3: Uncompleted days with wrong exercise count for their expected tier.
                // Past missed days use pre-week tier; future/today days use current tier.
                // Apply time preference floor so plans generated with the time-aware formula
                // are not flagged as mismatched against the raw progressive overload count.
                if (!$shouldRegenerate && $clientSessionCount >= 0) {
                    $base = ProgressiveOverload::getBaseCount($fitnessLevel);
                    $timeConstraintsCheck = $plan->user_preferences_snapshot['time_constraints'] ?? 30;
                    $timeFloor = (int) floor(($timeConstraintsCheck * 60 + 60) / 300);
                    [, $levelMaxCheck] = ProgressiveOverload::getExerciseBounds($fitnessLevel);
                    $currentTierCount = min(max($base + (ProgressiveOverload::getSessionTier($clientSessionCount) - 1), $timeFloor), $levelMaxCheck);
                    $preWeekTierCount = min(max($base + (ProgressiveOverload::getSessionTier($preWeekCount) - 1), $timeFloor), $levelMaxCheck);
                    $completedDayNames = array_keys($completedDayCounts);
                    $daysOfWeek = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    $todayName = strtolower(Carbon::now()->format('l'));
                    $todayIdx = array_search($todayName, $daysOfWeek);
                    foreach ($plan->plan_data ?? [] as $dayName => $dayData) {
                        if (!($dayData['planned'] ?? false)) continue;
                        if (in_array($dayName, $completedDayNames)) continue;
                        $dayIdx = array_search(strtolower($dayName), $daysOfWeek);
                        $isPast = $dayIdx !== false && $dayIdx < $todayIdx;
                        $expectedCount = $isPast ? $preWeekTierCount : $currentTierCount;
                        $planCount = count($dayData['exercises'] ?? []);
                        if ($planCount > 0 && $planCount !== $expectedCount) {
                            Log::info('[WEEKLY_PLAN] Uncompleted day has wrong exercise count for tier', [
                                'day' => $dayName,
                                'plan_count' => $planCount,
                                'expected' => $expectedCount,
                                'is_past' => $isPast,
                                'session_count' => $clientSessionCount,
                            ]);
                            $shouldRegenerate = true;
                            break;
                        }
                    }
                }
            }

            if ($shouldRegenerate) {
                $regenerateResponse = $this->generateWeeklyPlan(new Request([
                    'user_id' => $userId,
                    'regenerate' => !is_null($plan),
                    'completed_session_counts' => !empty($completedDayCounts)
                        ? json_encode($completedDayCounts)
                        : null,
                    'pre_week_session_count' => $preWeekCount,
                    'client_session_count' => $clientSessionCount >= 0 ? $clientSessionCount : null,
                ]));

                // If regeneration failed, return the error response
                $regenerateData = json_decode($regenerateResponse->getContent(), true);
                if (!($regenerateData['success'] ?? false)) {
                    return $regenerateResponse;
                }

                // Fetch the newly generated plan to return in correct format
                $plan = WeeklyWorkoutPlan::currentWeek($userId)->first();
                if (!$plan) {
                    return $regenerateResponse; // Fallback to original response if fetch fails
                }

                // Return in the same format as normal getCurrentWeekPlan response
                $todayPlan = $plan->getTodayPlan();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'plan' => $plan,
                        'today' => $todayPlan,
                        'today_day_name' => strtolower(Carbon::now()->format('l'))
                    ],
                    'regenerated' => true
                ]);
            }

            // Add today's plan to response
            $todayPlan = $plan->getTodayPlan();

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => $plan,
                    'today' => $todayPlan,
                    'today_day_name' => strtolower(Carbon::now()->format('l'))
                ]
            ]);

        } catch (\Exception $e) {
            Log::warning('[WEEKLY_PLAN] Could not fetch or generate plan, returning empty state', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => true,
                'data' => null,
                'no_plan' => true,
                'message' => 'No plan available'
            ], 200);
        }
    }

    /**
     * Get plan for a specific week
     *
     * GET /api/plans/week/{date}?user_id={id}
     *
     * @param string $date
     * @param Request $request
     * @return JsonResponse
     */
    public function getWeekPlan(string $date, Request $request): JsonResponse
    {
        try {
            $userId = $request->query('user_id');

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }

            $weekStartDate = Carbon::parse($date)->startOfWeek();

            $plan = WeeklyWorkoutPlan::forWeek($userId, $weekStartDate)->first();

            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'No plan found for this week'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $plan
            ]);

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] Failed to fetch week plan', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark a day's workout as completed
     *
     * POST /api/plans/{id}/complete-day
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function completeDayWorkout(int $id, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'day' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $plan = WeeklyWorkoutPlan::findOrFail($id);
            $day = $request->input('day');

            $success = $plan->markDayCompleted($day);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'No workout planned for this day'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Workout marked as completed',
                'data' => $plan->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] Failed to mark day completed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update workout status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper: Fetch user data from auth service
     *
     * Uses fitness_level from the latest initial_onboarding assessment (same as Dashboard).
     * This ensures consistent fitness level across the system.
     *
     * @param int $userId
     * @return array|null
     */
    protected function getUserData(int $userId): ?array
    {
        try {
            $authServiceUrl = env('AUTH_SERVICE_URL', 'http://fitnease-auth');
            $internalToken = env('INTERNAL_SERVICE_TOKEN');

            // Fetch user profile with fitness assessments
            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $internalToken])
                ->get("{$authServiceUrl}/api/internal/users/{$userId}");

            if ($response->successful()) {
                $user = $response->json();

                // Get fitness level from the latest initial_onboarding assessment
                // This matches how the Dashboard determines fitness level
                $fitnessLevel = $user['fitness_level'] ?? 'beginner';
                // Handle both snake_case and camelCase (Laravel serialization varies)
                $fitnessAssessments = $user['fitness_assessments'] ?? $user['fitnessAssessments'] ?? [];

                // Look for initial_onboarding assessment with fitness_level
                foreach ($fitnessAssessments as $assessment) {
                    if (($assessment['assessment_type'] ?? '') === 'initial_onboarding') {
                        $assessmentData = $assessment['assessment_data'] ?? [];
                        if (isset($assessmentData['fitness_level'])) {
                            $fitnessLevel = $assessmentData['fitness_level'];
                            Log::info('[WEEKLY_PLAN] Using fitness_level from initial_onboarding assessment', [
                                'user_id' => $userId,
                                'fitness_level' => $fitnessLevel
                            ]);
                            break;
                        }
                    }
                }

                return [
                    'user_id' => $userId,
                    'fitness_level' => $fitnessLevel,
                    'preferred_workout_days' => $user['preferred_workout_days'] ?? [],
                    'target_muscle_groups' => $user['target_muscle_groups'] ?? [],
                    'fitness_goals' => $user['fitness_goals'] ?? [],
                    'time_constraints' => $user['time_constraints_minutes'] ?? 30,
                    'activity_level' => $user['activity_level'] ?? 'moderate',
                    'workout_experience' => $user['workout_experience_years'] ?? 1,
                ];
            }

            Log::error('[WEEKLY_PLAN] Failed to fetch user from auth service', [
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] Exception fetching user data', [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Helper: Call ML service to generate weekly plan
     *
     * @param array $userData
     * @return array|null
     */
    protected function callMLPlanGeneration(array $userData): ?array
    {
        $mlServiceUrl = env('ML_SERVICE_URL', 'http://fitnease-ml:5000');
        $maxRetries = 2;
        $retryDelay = 1; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info('[WEEKLY_PLAN] Calling ML service for weekly plan generation', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'user_id' => $userData['user_id'],
                    'workout_days' => $userData['preferred_workout_days'],
                    'fitness_level' => $userData['fitness_level']
                ]);

                // Start timer to measure ML service response time
                $startTime = microtime(true);

                // Increased timeout to 10 seconds to allow ML processing
                $response = Http::timeout(10)->post("{$mlServiceUrl}/api/v1/generate-weekly-plan", [
                    'user_id' => $userData['user_id'],
                    'workout_days' => $userData['preferred_workout_days'],
                    'fitness_level' => $userData['fitness_level'],
                    'target_muscle_groups' => $userData['target_muscle_groups'],
                    'goals' => $userData['fitness_goals'],
                    'time_constraints' => $userData['time_constraints'],
                    // Pillar 1: pass PHP-authoritative counts so ML doesn't recompute
                    'session_count' => $userData['session_count'] ?? null,
                    'exercises_per_day' => $userData['exercises_per_day'] ?? null,
                    // Optional random seed for force_fresh manual regenerations
                    'week_seed' => $userData['week_seed'] ?? null,
                ]);

                $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                Log::info('[WEEKLY_PLAN] ML service responded', [
                    'attempt' => $attempt,
                    'duration_ms' => round($duration, 2),
                    'status' => $response->status()
                ]);

                if ($response->successful()) {
                    $mlResponse = $response->json();
                    $mlData = $mlResponse['data'] ?? $mlResponse; // Handle both formats

                    Log::info('[WEEKLY_PLAN] ✅ ML service generated plan successfully', [
                        'duration_ms' => round($duration, 2),
                        'total_exercises' => $mlData['metadata']['total_exercises'] ?? 0,
                        'week_seed' => $mlData['metadata']['week_seed'] ?? null
                    ]);

                    return [
                        'plan_data' => $mlData['weekly_plan'],
                        'total_workout_days' => count($userData['preferred_workout_days']),
                        'total_rest_days' => 7 - count($userData['preferred_workout_days']),
                        'total_exercises' => $mlData['metadata']['total_exercises'] ?? 0,
                        'estimated_weekly_duration' => $mlData['metadata']['estimated_weekly_duration'] ?? 0,
                        'estimated_weekly_calories' => $mlData['metadata']['estimated_weekly_calories'] ?? 0,
                        'ml_generated' => true,
                        'ml_confidence_score' => $mlData['metadata']['confidence_score'] ?? null,
                        'generation_method' => 'ml_auto',
                        'session_count' => $mlData['metadata']['session_count'] ?? 0,
                        'session_tier' => $mlData['metadata']['session_tier'] ?? 1,
                    ];
                }

                Log::warning('[WEEKLY_PLAN] ML service returned non-successful status', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'duration_ms' => round($duration, 2),
                    'response_body' => $response->body()
                ]);

                // Don't retry on non-successful HTTP status
                return null;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('[WEEKLY_PLAN] ML service connection timeout', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage()
                ]);

                // Retry on timeout/connection errors
                if ($attempt < $maxRetries) {
                    Log::info('[WEEKLY_PLAN] Retrying ML service call after delay', [
                        'retry_delay_seconds' => $retryDelay
                    ]);
                    sleep($retryDelay);
                    continue;
                }

                // Max retries reached
                Log::error('[WEEKLY_PLAN] ❌ ML service failed after all retries - using fallback', [
                    'total_attempts' => $maxRetries,
                    'final_error' => $e->getMessage()
                ]);

                return null;

            } catch (\Exception $e) {
                Log::error('[WEEKLY_PLAN] ML service call exception', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e)
                ]);

                // Don't retry on other exceptions
                return null;
            }
        }

        // Should never reach here, but added for safety
        return null;
    }

    /**
     * Helper: Generate fallback plan when ML service is unavailable
     *
     * @param array $userData
     * @param string|null $token Bearer token for authentication
     * @return array
     */
    protected function generateFallbackPlan(array $userData, ?string $token = null): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $preferredDays = $userData['preferred_workout_days'] ?? [];
        $timeConstraints = $userData['time_constraints'] ?? 30;

        $planData = [];
        $totalExercises = 0;
        $totalDuration = 0;
        $totalCalories = 0;

        // Fetch exercises ONCE for all days to get variety
        // Use the already-computed exercises_per_day (respects time preference + progressive overload)
        $exercisesPerDay = $userData['exercises_per_day'] ?? ProgressiveOverload::getBaseCount($userData['fitness_level']);
        $totalNeededExercises = count($preferredDays) * $exercisesPerDay;

        // Fetch MANY more exercises to ensure we have enough unique ones
        $allExercises = $this->fetchExercisesFromContent(
            $userData['fitness_level'],
            $userData['target_muscle_groups'] ?? ['core'],
            $totalNeededExercises * 3, // Fetch 3x what we need for maximum variety
            $token
        );

        // Remove duplicates based on exercise_id
        $uniqueExercises = [];
        $seenIds = [];
        foreach ($allExercises as $exercise) {
            $id = $exercise['exercise_id'];
            if (!in_array($id, $seenIds)) {
                $uniqueExercises[] = $exercise;
                $seenIds[] = $id;
            }
        }

        // Shuffle for randomness
        shuffle($uniqueExercises);

        // Create a master pool with all unique exercises + defaults for guaranteed supply
        $masterPool = array_merge($uniqueExercises, $this->getDefaultExercises(10));
        $poolIndex = 0; // Track position in master pool

        foreach ($days as $day) {
            if (in_array($day, $preferredDays)) {
                // Workout day - assign exactly $exercisesPerDay exercises
                $durationPerDay = min($timeConstraints, $exercisesPerDay * 4); // 4 min per exercise (Tabata)

                $dayExercises = [];

                // Take exercises from master pool
                for ($i = 0; $i < $exercisesPerDay; $i++) {
                    if ($poolIndex < count($masterPool)) {
                        // Use next exercise from pool
                        $dayExercises[] = $masterPool[$poolIndex];
                        $poolIndex++;
                    } else {
                        // Pool exhausted - cycle back to beginning (allow duplication)
                        $poolIndex = 0;
                        $dayExercises[] = $masterPool[$poolIndex];
                        $poolIndex++;
                    }
                }

                // Double-check: guarantee exact count
                while (count($dayExercises) < $exercisesPerDay) {
                    $cycleIndex = count($dayExercises) % count($masterPool);
                    $dayExercises[] = $masterPool[$cycleIndex];
                }

                // Triple safety: trim if somehow we have too many
                $dayExercises = array_slice($dayExercises, 0, $exercisesPerDay);

                $planData[$day] = [
                    'planned' => true,
                    'rest_day' => false,
                    'workout_type' => 'tabata',
                    'exercises' => $dayExercises,
                    'estimated_duration' => $durationPerDay,
                    'estimated_calories' => $durationPerDay * 7, // Approx 7 cal/min
                    'focus_areas' => $userData['target_muscle_groups'] ?? ['full_body'],
                    'completed' => false,
                    'skipped' => false,
                ];

                $totalExercises += count($dayExercises);
                $totalDuration += $durationPerDay;
                $totalCalories += $planData[$day]['estimated_calories'];
            } else {
                // Rest day
                $planData[$day] = [
                    'planned' => false,
                    'rest_day' => true,
                ];
            }
        }

        return [
            'plan_data' => $planData,
            'total_workout_days' => count($preferredDays),
            'total_rest_days' => 7 - count($preferredDays),
            'total_exercises' => $totalExercises,
            'estimated_weekly_duration' => $totalDuration,
            'estimated_weekly_calories' => $totalCalories,
            'ml_generated' => false,
            'generation_method' => 'fallback',
        ];
    }

    /**
     * Helper: Fetch exercises from Content Service
     *
     * @param string $fitnessLevel
     * @param array $targetMuscleGroups
     * @param int $count
     * @param string|null $token Bearer token for authentication
     * @return array
     */
    protected function fetchExercisesFromContent(string $fitnessLevel, array $targetMuscleGroups, int $count, ?string $token = null): array
    {
        try {
            $client = new \GuzzleHttp\Client();

            // Use internal service token if user token not provided
            $authToken = $token ?? env('INTERNAL_SERVICE_TOKEN');

            $response = $client->get(env('CONTENT_SERVICE_URL') . '/api/content/exercises', [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Internal-Secret' => env('INTERNAL_SERVICE_TOKEN'), // Internal service auth
                ],
                'query' => [
                    'difficulty' => $fitnessLevel,
                    'muscle_groups' => implode(',', $targetMuscleGroups),
                    'limit' => $count,
                ],
                'timeout' => 10, // Allow time for database query with large dataset
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['data']) && is_array($data['data'])) {
                return array_map(function($exercise) {
                    $durationSeconds = 240; // 4 minutes (Tabata protocol)
                    $caloriesPerMinute = 7; // Average calories per minute for Tabata
                    $estimatedCalories = ($durationSeconds / 60) * $caloriesPerMinute;

                    return [
                        'exercise_id' => $exercise['exercise_id'],
                        'exercise_name' => $exercise['exercise_name'], // Fixed: was 'name', now 'exercise_name'
                        'target_muscle_group' => $exercise['target_muscle_group'] ?? 'core',
                        'difficulty_level' => $exercise['difficulty_level'] ?? 'beginner',
                        'default_duration_seconds' => $durationSeconds,
                        'estimated_calories_burned' => (int) $estimatedCalories,
                        'equipment_needed' => $exercise['equipment_needed'] ?? 'none',
                        'exercise_category' => $exercise['exercise_category'] ?? 'strength',
                    ];
                }, $data['data']);
            }

            Log::warning('[WEEKLY_PLAN] Content service returned no exercises, using defaults');
            return $this->getDefaultExercises($count);

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] Failed to fetch exercises from content service', [
                'error' => $e->getMessage()
            ]);
            return $this->getDefaultExercises($count);
        }
    }

    /**
     * Helper: Get default exercises as fallback
     *
     * @param int $count
     * @return array
     */
    protected function getDefaultExercises(int $count): array
    {
        $caloriesPerMinute = 7; // Average calories per minute for Tabata
        $durationSeconds = 240;
        $estimatedCalories = (int) (($durationSeconds / 60) * $caloriesPerMinute);

        $defaultExercises = [
            [
                'exercise_id' => 1,
                'exercise_name' => 'Jumping Jacks',
                'target_muscle_group' => 'full_body',
                'difficulty_level' => 'beginner',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'cardio',
            ],
            [
                'exercise_id' => 2,
                'exercise_name' => 'High Knees',
                'target_muscle_group' => 'legs',
                'difficulty_level' => 'beginner',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'cardio',
            ],
            [
                'exercise_id' => 3,
                'exercise_name' => 'Mountain Climbers',
                'target_muscle_group' => 'core',
                'difficulty_level' => 'intermediate',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'strength',
            ],
            [
                'exercise_id' => 4,
                'exercise_name' => 'Burpees',
                'target_muscle_group' => 'full_body',
                'difficulty_level' => 'intermediate',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'cardio',
            ],
            [
                'exercise_id' => 5,
                'exercise_name' => 'Squats',
                'target_muscle_group' => 'legs',
                'difficulty_level' => 'beginner',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'strength',
            ],
            [
                'exercise_id' => 6,
                'exercise_name' => 'Push-ups',
                'target_muscle_group' => 'chest',
                'difficulty_level' => 'beginner',
                'default_duration_seconds' => $durationSeconds,
                'estimated_calories_burned' => $estimatedCalories,
                'equipment_needed' => 'none',
                'exercise_category' => 'strength',
            ],
        ];

        return array_slice($defaultExercises, 0, $count);
    }

    /**
     * Helper: Get the valid exercise count range for a fitness level.
     *
     * Progressive overload ranges (per professor requirements):
     *   Beginner:     4–6  exercises
     *   Intermediate: 6–8  exercises
     *   Advanced:     8–12 exercises
     *
     * Used for the inconsistency check so plans with progressive overload
     * counts are NOT incorrectly flagged and force-regenerated.
     *
     * @param string $fitnessLevel
     * @return array [min, max]
     */
    /**
     * Helper: Determine the exercise count each completed day this week should show.
     *
     * Fetches ALL individual completed sessions for the user (sorted oldest-first),
     * tracks a running cumulative count, and uses getSessionTier(cumulativeCount)
     * to determine what progressive-overload tier was active at each session.
     *
     * This is 100% reliable — it does NOT depend on user_exercise_history records
     * or actual_duration_minutes; it uses pure tier math.
     *
     * Returns ['day_name' => expected_exercise_count] only for days in the current
     * week that had a completed session.  Days NOT in the result are not yet done
     * and should use the current tier's count unchanged.
     *
     * Example:
     *   - Sessions 1-5 (tier 1): wednesday → 4 exercises
     *   - Session 6+  (tier 2): thursday  → 5 exercises  (if done after crossing)
     *
     * @param int    $userId
     * @param Carbon $weekStart
     * @param Carbon $weekEnd
     * @param string $fitnessLevel  e.g. 'beginner'
     * @return array ['day_name' => expected_count]
     */
    protected function getCompletedDayTierCounts(
        int $userId,
        Carbon $weekStart,
        Carbon $weekEnd,
        string $fitnessLevel
    ): array {
        try {
            $trackingUrl  = env('TRACKING_SERVICE_URL', 'http://fitnease-tracking');
            $internalToken = env('INTERNAL_SERVICE_TOKEN');

            $response = Http::timeout(5)
                ->withHeaders(['X-Internal-Secret' => $internalToken])
                ->get("{$trackingUrl}/api/ml-internal/user-sessions/{$userId}", [
                    'per_page' => 500,
                ]);

            if (!$response->successful()) {
                Log::warning('[WEEKLY_PLAN] Tracking service non-success in getCompletedDayTierCounts', [
                    'user_id' => $userId,
                    'status'  => $response->status(),
                ]);
                return [];
            }

            $data     = $response->json();
            $sessions = $data['data']['data'] ?? [];

            // Keep only individual completed sessions, then sort oldest → newest
            $individual = array_filter($sessions, static function ($s) {
                return ($s['session_type'] ?? '') === 'individual'
                    && ($s['is_completed'] ?? false);
            });

            usort($individual, static function ($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });

            $weekEnd->setTime(23, 59, 59);

            // Base exercise count for tier 1 of this fitness level.
            // Each tier adds one more exercise: tier N → base + (N-1).
            $base   = ProgressiveOverload::getBaseCount($fitnessLevel);
            $result = [];        // ['day_name' => expected_count]
            $cumulative = 0;    // running total of individual completed sessions

            foreach ($individual as $session) {
                $cumulative++;

                try {
                    $sessionDate = Carbon::parse($session['created_at']);

                    if (!$sessionDate->between($weekStart, $weekEnd)) {
                        continue; // not this week — still counts toward cumulative total
                    }

                    $dayName = strtolower($sessionDate->format('l'));

                    // Use the FIRST session on each day to determine that day's tier
                    if (!isset($result[$dayName])) {
                        $tierAtTime   = ProgressiveOverload::getSessionTier($cumulative);
                        $result[$dayName] = $base + ($tierAtTime - 1);
                    }
                } catch (\Exception $e) {
                    // Skip sessions with unparseable dates
                }
            }

            Log::info('[WEEKLY_PLAN] Completed day tier counts', [
                'user_id'  => $userId,
                'counts'   => $result,
                'base'     => $base,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::warning('[WEEKLY_PLAN] Exception in getCompletedDayTierCounts', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Helper: Fetch all individual completed sessions and return:
     *   - completed_day_counts: per-day exercise count based on tier at completion time
     *   - pre_week_count: number of individual sessions completed before $weekStart
     *
     * This replaces getCompletedDayTierCounts while also providing pre-week count
     * (needed to correctly trim past missed days during plan regeneration).
     */
    protected function getSessionAnalysis(
        int $userId,
        Carbon $weekStart,
        Carbon $weekEnd,
        string $fitnessLevel
    ): array {
        try {
            $trackingUrl   = env('TRACKING_SERVICE_URL', 'http://fitnease-tracking');
            $internalToken = env('INTERNAL_SERVICE_TOKEN');

            $response = Http::timeout(5)
                ->withHeaders(['X-Internal-Secret' => $internalToken])
                ->get("{$trackingUrl}/api/ml-internal/user-sessions/{$userId}", [
                    'per_page' => 500,
                ]);

            if (!$response->successful()) {
                return ['completed_day_counts' => [], 'pre_week_count' => 0];
            }

            $data     = $response->json();
            $sessions = $data['data']['data'] ?? [];

            $individual = array_filter($sessions, static function ($s) {
                return ($s['session_type'] ?? '') === 'individual'
                    && ($s['is_completed'] ?? false);
            });
            usort($individual, static function ($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });

            $weekEnd->setTime(23, 59, 59);
            $base               = ProgressiveOverload::getBaseCount($fitnessLevel);
            $completedDayCounts = [];
            $preWeekCount       = 0;
            $cumulative         = 0;

            foreach ($individual as $session) {
                $cumulative++;
                try {
                    $sessionDate = Carbon::parse($session['created_at']);
                    if ($sessionDate->lt($weekStart)) {
                        $preWeekCount = $cumulative;
                        continue;
                    }
                    if (!$sessionDate->between($weekStart, $weekEnd)) {
                        continue;
                    }
                    $dayName = strtolower($sessionDate->format('l'));
                    if (!isset($completedDayCounts[$dayName])) {
                        $tierAtTime = ProgressiveOverload::getSessionTier($cumulative);
                        $completedDayCounts[$dayName] = $base + ($tierAtTime - 1);
                    }
                } catch (\Exception $e) {}
            }

            return ['completed_day_counts' => $completedDayCounts, 'pre_week_count' => $preWeekCount];

        } catch (\Exception $e) {
            Log::warning('[WEEKLY_PLAN] Exception in getSessionAnalysis', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return ['completed_day_counts' => [], 'pre_week_count' => 0];
        }
    }

    /**
     * Helper: Check if a week is the current week
     *
     * @param Carbon $weekStartDate
     * @return bool
     */
    protected function isCurrentWeek(Carbon $weekStartDate): bool
    {
        $currentWeekStart = Carbon::now()->startOfWeek();
        return $weekStartDate->equalTo($currentWeekStart);
    }

    /**
     * Adapt existing weekly plan when workout days change
     *
     * POST /api/plans/{id}/adapt
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function adaptWeeklyPlan(int $id, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'old_days' => 'required|array',
                'new_days' => 'required|array',
                'preserve_completed' => 'boolean',
                'adaptation_strategy' => 'string|in:reallocate,regenerate,hybrid'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $plan = WeeklyWorkoutPlan::findOrFail($id);
            $oldDays = $request->input('old_days', []);
            $newDays = $request->input('new_days', []);
            $preserveCompleted = $request->input('preserve_completed', true);
            $strategy = $request->input('adaptation_strategy', 'reallocate');

            Log::info('[WEEKLY_PLAN_ADAPT] Starting adaptation', [
                'plan_id' => $id,
                'old_days' => $oldDays,
                'new_days' => $newDays,
                'strategy' => $strategy
            ]);

            // Determine removed and added days
            $removedDays = array_diff($oldDays, $newDays);
            $addedDays = array_diff($newDays, $oldDays);
            $unchangedDays = array_intersect($oldDays, $newDays);

            Log::info('[WEEKLY_PLAN_ADAPT] Day changes', [
                'removed' => $removedDays,
                'added' => $addedDays,
                'unchanged' => $unchangedDays
            ]);

            // Execute adaptation strategy
            $updatedPlanData = $this->executeAdaptationStrategy(
                $plan,
                $removedDays,
                $addedDays,
                $unchangedDays,
                $strategy,
                $preserveCompleted
            );

            // Update the plan
            $plan->plan_data = $updatedPlanData;
            $plan->user_preferences_snapshot = array_merge(
                $plan->user_preferences_snapshot ?? [],
                ['preferred_workout_days' => $newDays]
            );

            // Recalculate totals
            $totalWorkoutDays = count($newDays);
            $totalRestDays = 7 - $totalWorkoutDays;
            $totalExercises = 0;
            $totalDuration = 0;
            $totalCalories = 0;

            foreach ($updatedPlanData as $dayData) {
                if ($dayData['planned'] ?? false) {
                    $totalExercises += count($dayData['exercises'] ?? []);
                    $totalDuration += $dayData['estimated_duration'] ?? 0;
                    $totalCalories += $dayData['estimated_calories'] ?? 0;
                }
            }

            $plan->total_workout_days = $totalWorkoutDays;
            $plan->total_rest_days = $totalRestDays;
            $plan->total_exercises = $totalExercises;
            $plan->estimated_weekly_duration = $totalDuration;
            $plan->estimated_weekly_calories = $totalCalories;
            $plan->save();

            Log::info('[WEEKLY_PLAN_ADAPT] Adaptation completed successfully', [
                'plan_id' => $id,
                'new_total_exercises' => $totalExercises
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Weekly plan adapted successfully',
                'data' => $plan->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN_ADAPT] Adaptation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to adapt weekly plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute adaptation strategy
     *
     * @param WeeklyWorkoutPlan $plan
     * @param array $removedDays
     * @param array $addedDays
     * @param array $unchangedDays
     * @param string $strategy
     * @param bool $preserveCompleted
     * @return array
     */
    protected function executeAdaptationStrategy(
        WeeklyWorkoutPlan $plan,
        array $removedDays,
        array $addedDays,
        array $unchangedDays,
        string $strategy,
        bool $preserveCompleted
    ): array {
        $planData = $plan->plan_data;
        $currentDayOfWeek = strtolower(Carbon::now()->format('l'));

        Log::info('[WEEKLY_PLAN_ADAPT] Executing strategy', [
            'strategy' => $strategy,
            'current_day' => $currentDayOfWeek
        ]);

        switch ($strategy) {
            case 'reallocate':
                return $this->reallocateExercises(
                    $plan,
                    $planData,
                    $removedDays,
                    $addedDays,
                    $unchangedDays,
                    $currentDayOfWeek,
                    $preserveCompleted
                );

            case 'regenerate':
                // For regenerate, call ML service to generate completely new plan
                // (This is handled by the existing generateWeeklyPlan method)
                return $planData; // Placeholder - actual regeneration done separately

            case 'hybrid':
            default:
                return $this->reallocateExercises(
                    $plan,
                    $planData,
                    $removedDays,
                    $addedDays,
                    $unchangedDays,
                    $currentDayOfWeek,
                    $preserveCompleted
                );
        }
    }

    /**
     * Reallocate exercises from removed days to new days
     *
     * @param WeeklyWorkoutPlan $plan
     * @param array $planData
     * @param array $removedDays
     * @param array $addedDays
     * @param array $unchangedDays
     * @param string $currentDayOfWeek
     * @param bool $preserveCompleted
     * @return array
     */
    protected function reallocateExercises(
        WeeklyWorkoutPlan $plan,
        array $planData,
        array $removedDays,
        array $addedDays,
        array $unchangedDays,
        string $currentDayOfWeek,
        bool $preserveCompleted
    ): array {
        $orphanedExercises = [];

        // Step 1: Collect exercises from removed days
        foreach ($removedDays as $day) {
            $dayData = $planData[$day] ?? null;

            if (!$dayData) continue;

            $isCompleted = $dayData['completed'] ?? false;
            $isFutureDay = $this->isFutureDay($day, $currentDayOfWeek);

            Log::info('[WEEKLY_PLAN_ADAPT] Processing removed day', [
                'day' => $day,
                'completed' => $isCompleted,
                'is_future' => $isFutureDay
            ]);

            // Only reallocate if day is in future and not completed
            if ($isFutureDay && !$isCompleted && !empty($dayData['exercises'])) {
                $orphanedExercises = array_merge($orphanedExercises, $dayData['exercises']);
                Log::info('[WEEKLY_PLAN_ADAPT] Collected exercises from removed day', [
                    'day' => $day,
                    'count' => count($dayData['exercises'])
                ]);
            }

            // Mark removed day as rest day (unless completed and preserving)
            if (!($preserveCompleted && $isCompleted)) {
                $planData[$day] = [
                    'planned' => false,
                    'rest_day' => true
                ];
            }
        }

        Log::info('[WEEKLY_PLAN_ADAPT] Total orphaned exercises', [
            'count' => count($orphanedExercises)
        ]);

        // Step 2: Distribute orphaned exercises to new days
        // Use time preference as floor so day-change respects the onboarding promise
        $fitnessLevelForAdapt = $plan->user_preferences_snapshot['fitness_level'] ?? 'beginner';
        $timeConstraintsForAdapt = $plan->user_preferences_snapshot['time_constraints'] ?? 30;
        $baseCountForAdapt = ProgressiveOverload::getBaseCount($fitnessLevelForAdapt);
        $maxByTimeForAdapt = (int) floor(($timeConstraintsForAdapt * 60 + 60) / 300);
        [, $levelMaxForAdapt] = ProgressiveOverload::getExerciseBounds($fitnessLevelForAdapt);
        $exercisesPerDay = min(max($baseCountForAdapt, $maxByTimeForAdapt), $levelMaxForAdapt);

        $orphanIndex = 0;
        foreach ($addedDays as $day) {
            $dayExercises = [];

            // Take exercises from orphaned pool first
            while ($orphanIndex < count($orphanedExercises) && count($dayExercises) < $exercisesPerDay) {
                $dayExercises[] = $orphanedExercises[$orphanIndex];
                $orphanIndex++;
            }

            // If not enough orphaned exercises, generate new ones
            if (count($dayExercises) < $exercisesPerDay) {
                $needed = $exercisesPerDay - count($dayExercises);
                Log::info('[WEEKLY_PLAN_ADAPT] Generating additional exercises', [
                    'day' => $day,
                    'needed' => $needed
                ]);

                $newExercises = $this->generateExercisesForDay($plan, $needed);
                $dayExercises = array_merge($dayExercises, $newExercises);
            }

            // Calculate metrics
            $duration = count($dayExercises) * 4; // Tabata: 4 min per exercise
            $calories = array_sum(array_column($dayExercises, 'estimated_calories_burned'));

            // Create new day plan
            $planData[$day] = [
                'planned' => true,
                'rest_day' => false,
                'exercises' => $dayExercises,
                'estimated_duration' => $duration,
                'estimated_calories' => $calories,
                'focus_areas' => array_unique(array_column($dayExercises, 'target_muscle_group')),
                'completed' => false,
                'skipped' => false,
                'adapted_from_reallocation' => true
            ];

            Log::info('[WEEKLY_PLAN_ADAPT] Created plan for added day', [
                'day' => $day,
                'exercises' => count($dayExercises),
                'from_orphaned' => $orphanIndex,
                'newly_generated' => $needed ?? 0
            ]);
        }

        return $planData;
    }

    /**
     * Check if a day is in the future relative to current day
     *
     * @param string $day
     * @param string $currentDayOfWeek
     * @return bool
     */
    protected function isFutureDay(string $day, string $currentDayOfWeek): bool
    {
        $daysOfWeek = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $currentIndex = array_search(strtolower($currentDayOfWeek), $daysOfWeek);
        $targetIndex = array_search(strtolower($day), $daysOfWeek);

        return $targetIndex > $currentIndex;
    }

    /**
     * Generate exercises for a specific day
     *
     * @param WeeklyWorkoutPlan $plan
     * @param int $count
     * @return array
     */
    protected function generateExercisesForDay(WeeklyWorkoutPlan $plan, int $count): array
    {
        $userData = $plan->user_preferences_snapshot ?? [];
        $fitnessLevel = $userData['fitness_level'] ?? 'beginner';
        $targetMuscleGroups = $userData['target_muscle_groups'] ?? ['core'];

        // Fetch exercises from Content Service
        $exercises = $this->fetchExercisesFromContent(
            $fitnessLevel,
            $targetMuscleGroups,
            $count,
            null
        );

        return array_slice($exercises, 0, $count);
    }
}
