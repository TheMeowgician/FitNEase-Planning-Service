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
                $weeklyPlanData = $this->generateFallbackPlan($userData);
            }

            // Calculate week end date (Sunday)
            $weekEndDate = $weekStartDate->copy()->endOfWeek();

            // Deactivate old plan if regenerating
            if ($existingPlan) {
                $existingPlan->is_active = false;
                $existingPlan->is_current_week = false;
                $existingPlan->save();
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

            // If no plan exists, generate one
            if (!$plan) {
                Log::info('[WEEKLY_PLAN] No current plan found, generating new one');

                return $this->generateWeeklyPlan(new Request([
                    'user_id' => $userId,
                    'regenerate' => false
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

            $response = Http::timeout(10)->get("{$authServiceUrl}/api/users/{$userId}");

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
        try {
            $mlServiceUrl = env('ML_SERVICE_URL', 'http://fitnease-ml:5000');

            $response = Http::timeout(30)->post("{$mlServiceUrl}/generate-weekly-plan", [
                'user_id' => $userData['user_id'],
                'workout_days' => $userData['preferred_workout_days'],
                'fitness_level' => $userData['fitness_level'],
                'target_muscle_groups' => $userData['target_muscle_groups'],
                'goals' => $userData['fitness_goals'],
                'time_constraints' => $userData['time_constraints'],
            ]);

            if ($response->successful()) {
                $mlResponse = $response->json();

                return [
                    'plan_data' => $mlResponse['weekly_plan'],
                    'total_workout_days' => count($userData['preferred_workout_days']),
                    'total_rest_days' => 7 - count($userData['preferred_workout_days']),
                    'total_exercises' => $mlResponse['metadata']['total_exercises'] ?? 0,
                    'estimated_weekly_duration' => $mlResponse['metadata']['estimated_weekly_duration'] ?? 0,
                    'estimated_weekly_calories' => $mlResponse['metadata']['estimated_weekly_calories'] ?? 0,
                    'ml_generated' => true,
                    'ml_confidence_score' => $mlResponse['metadata']['confidence_score'] ?? null,
                    'generation_method' => 'ml_auto',
                ];
            }

            Log::warning('[WEEKLY_PLAN] ML service returned non-successful status', [
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('[WEEKLY_PLAN] ML service call failed', [
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Helper: Generate fallback plan when ML service is unavailable
     *
     * @param array $userData
     * @return array
     */
    protected function generateFallbackPlan(array $userData): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $preferredDays = $userData['preferred_workout_days'] ?? [];
        $timeConstraints = $userData['time_constraints'] ?? 30;

        $planData = [];
        $totalExercises = 0;
        $totalDuration = 0;
        $totalCalories = 0;

        foreach ($days as $day) {
            if (in_array($day, $preferredDays)) {
                // Workout day
                $exercisesPerDay = $this->getExercisesCountByFitnessLevel($userData['fitness_level']);
                $durationPerDay = min($timeConstraints, $exercisesPerDay * 4); // 4 min per exercise (Tabata)

                $planData[$day] = [
                    'planned' => true,
                    'rest_day' => false,
                    'workout_type' => 'tabata',
                    'exercises' => [], // Will be populated later
                    'estimated_duration' => $durationPerDay,
                    'estimated_calories' => $durationPerDay * 7, // Approx 7 cal/min
                    'focus_areas' => $userData['target_muscle_groups'] ?? ['full_body'],
                    'completed' => false,
                    'skipped' => false,
                ];

                $totalExercises += $exercisesPerDay;
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
}
