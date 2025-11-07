<?php

namespace App\Http\Controllers;

use App\Models\WeeklyWorkoutPlan;
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

            // Call ML service to generate weekly plan
            $weeklyPlanData = $this->callMLPlanGeneration($userData);

            if (!$weeklyPlanData) {
                // Fallback to simple distribution if ML service fails
                Log::warning('[WEEKLY_PLAN] ML service failed, using fallback');
                $weeklyPlanData = $this->generateFallbackPlan($userData, $request->bearerToken());
            }

            // Calculate week end date (Sunday)
            $weekEndDate = $weekStartDate->copy()->endOfWeek();

            // Delete old plan if regenerating
            if ($existingPlan) {
                Log::info('[WEEKLY_PLAN] Deleting existing plan for regeneration', [
                    'old_plan_id' => $existingPlan->plan_id
                ]);
                $existingPlan->delete();
            }

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
                ],
            ]);

            Log::info('[WEEKLY_PLAN] Plan created successfully', [
                'plan_id' => $plan->plan_id,
                'workout_days' => $plan->total_workout_days
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
                // Check for inconsistent exercise counts (indicates old buggy generation)
                $planData = $plan->plan_data ?? [];
                $expectedExercisesPerDay = $this->getExercisesCountByFitnessLevel($plan->user_preferences_snapshot['fitness_level'] ?? 'beginner');
                $hasInconsistentCounts = false;

                foreach ($planData as $dayName => $dayData) {
                    if (isset($dayData['planned']) && $dayData['planned'] && isset($dayData['exercises'])) {
                        $exerciseCount = count($dayData['exercises']);
                        if ($exerciseCount !== $expectedExercisesPerDay && $exerciseCount > 0) {
                            $hasInconsistentCounts = true;
                            Log::info('[WEEKLY_PLAN] Detected inconsistent exercise count', [
                                'day' => $dayName,
                                'count' => $exerciseCount,
                                'expected' => $expectedExercisesPerDay
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

                // Allow force regenerate via query parameter
                if ($request->query('force_regenerate') === 'true') {
                    Log::info('[WEEKLY_PLAN] Force regenerate requested');
                    $shouldRegenerate = true;
                }
            }

            if ($shouldRegenerate) {
                return $this->generateWeeklyPlan(new Request([
                    'user_id' => $userId,
                    'regenerate' => !is_null($plan)
                ]));
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
            Log::error('[WEEKLY_PLAN] Failed to fetch current plan', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch current plan',
                'error' => $e->getMessage()
            ], 500);
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
     * @param int $userId
     * @return array|null
     */
    protected function getUserData(int $userId): ?array
    {
        try {
            $authServiceUrl = env('AUTH_SERVICE_URL', 'http://fitnease-auth');
            $internalToken = env('INTERNAL_SERVICE_TOKEN');

            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $internalToken])
                ->get("{$authServiceUrl}/api/internal/users/{$userId}");

            if ($response->successful()) {
                $user = $response->json();

                return [
                    'user_id' => $userId,
                    'fitness_level' => $user['fitness_level'] ?? 'beginner',
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
        $exercisesPerDay = $this->getExercisesCountByFitnessLevel($userData['fitness_level']);
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
     * Helper: Get number of exercises per day based on fitness level
     *
     * @param string $fitnessLevel
     * @return int
     */
    protected function getExercisesCountByFitnessLevel(string $fitnessLevel): int
    {
        return match($fitnessLevel) {
            'beginner' => 4,
            'intermediate' => 5,
            'advanced' => 6,
            default => 4,
        };
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
        $exercisesPerDay = $this->getExercisesCountByFitnessLevel(
            $plan->user_preferences_snapshot['fitness_level'] ?? 'beginner'
        );

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
