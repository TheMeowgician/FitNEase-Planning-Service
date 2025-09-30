<?php

namespace App\Http\Controllers;

use App\Models\WorkoutPlan;
use App\Services\WorkoutPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WorkoutPlanController extends Controller
{
    protected $workoutPlanService;

    public function __construct(WorkoutPlanService $workoutPlanService)
    {
        $this->workoutPlanService = $workoutPlanService;
    }

    public function getPersonalizedPlan($userId): JsonResponse
    {
        try {
            $plan = WorkoutPlan::where('user_id', $userId)
                ->where('is_active', true)
                ->with('schedules')
                ->first();

            if (!$plan) {
                // For new users, no plan is expected - return 200 with null data instead of 404 error
                return response()->json([
                    'message' => 'No active plan found for user - this is expected for new users',
                    'data' => null,
                    'has_plan' => false
                ], 200);
            }

            return response()->json([
                'message' => 'Plan retrieved successfully',
                'data' => $plan
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createPlan(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer',
                'plan_name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'workout_days' => 'nullable|array',
                'rest_days' => 'nullable|array',
                'plan_difficulty' => 'required|in:beginner,medium,expert',
                'target_duration_weeks' => 'nullable|integer|min:1|max:24'
            ]);

            $validated['workout_days'] = isset($validated['workout_days'])
                ? implode(',', $validated['workout_days'])
                : null;
            $validated['rest_days'] = isset($validated['rest_days'])
                ? implode(',', $validated['rest_days'])
                : null;

            $plan = WorkoutPlan::create($validated);

            return response()->json([
                'message' => 'Plan created successfully',
                'data' => $plan
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updatePlan(Request $request, $id): JsonResponse
    {
        try {
            $plan = WorkoutPlan::findOrFail($id);

            $validated = $request->validate([
                'plan_name' => 'sometimes|string|max:100',
                'description' => 'nullable|string',
                'start_date' => 'sometimes|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'workout_days' => 'nullable|array',
                'rest_days' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
                'plan_difficulty' => 'sometimes|in:beginner,medium,expert',
                'target_duration_weeks' => 'nullable|integer|min:1|max:24'
            ]);

            if (isset($validated['workout_days'])) {
                $validated['workout_days'] = implode(',', $validated['workout_days']);
            }
            if (isset($validated['rest_days'])) {
                $validated['rest_days'] = implode(',', $validated['rest_days']);
            }

            $plan->update($validated);

            return response()->json([
                'message' => 'Plan updated successfully',
                'data' => $plan
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePlan($id): JsonResponse
    {
        try {
            $plan = WorkoutPlan::findOrFail($id);
            $plan->delete();

            return response()->json([
                'message' => 'Plan deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserPlans($userId): JsonResponse
    {
        try {
            $plans = WorkoutPlan::where('user_id', $userId)
                ->with('schedules')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'message' => 'Plans retrieved successfully',
                'data' => $plans
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function customizePlan(Request $request, $id): JsonResponse
    {
        try {
            $plan = WorkoutPlan::findOrFail($id);

            $validated = $request->validate([
                'target_duration_weeks' => 'required|integer|min:1|max:24',
                'workout_days' => 'nullable|array',
                'rest_days' => 'nullable|array',
                'plan_difficulty' => 'sometimes|in:beginner,medium,expert'
            ]);

            if (isset($validated['workout_days'])) {
                $validated['workout_days'] = implode(',', $validated['workout_days']);
            }
            if (isset($validated['rest_days'])) {
                $validated['rest_days'] = implode(',', $validated['rest_days']);
            }

            if ($validated['target_duration_weeks'] != $plan->target_duration_weeks) {
                $newEndDate = \Carbon\Carbon::parse($plan->start_date)
                    ->addWeeks($validated['target_duration_weeks']);
                $validated['end_date'] = $newEndDate->toDateString();
            }

            $plan->update($validated);

            return response()->json([
                'message' => 'Plan customized successfully',
                'data' => $plan
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to customize plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createWorkoutPlanWithSchedule(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer',
                'selected_days' => 'required|array|min:1',
                'selected_days.*' => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
                'sessions_per_week' => 'required|integer|min:1|max:7',
                'preferred_workout_types' => 'nullable|array',
                'session_duration' => 'required|integer|min:10|max:180',
                'rest_days' => 'nullable|array',
                'goals' => 'nullable|array',
                'difficulty' => 'required|in:beginner,intermediate,advanced'
            ]);

            // Create the workout plan
            $planData = [
                'user_id' => $validated['user_id'],
                'plan_name' => 'Personalized Workout Plan',
                'description' => 'Generated based on your preferences and goals',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addWeeks(12)->toDateString(), // Default 12 weeks
                'workout_days' => implode(',', $validated['selected_days']),
                'rest_days' => isset($validated['rest_days']) ? implode(',', $validated['rest_days']) : null,
                'plan_difficulty' => $this->mapDifficultyToDatabase($validated['difficulty']),
                'target_duration_weeks' => 12,
                'is_active' => true
            ];

            $plan = WorkoutPlan::create($planData);

            // Create schedule entries for the coming weeks (let's do 4 weeks ahead)
            $schedules = [];
            $startDate = now();

            for ($week = 0; $week < 4; $week++) {
                foreach ($validated['selected_days'] as $dayName) {
                    $dayNumber = $this->getDayNumber($dayName);
                    $scheduleDate = $startDate->copy()->addWeeks($week)->startOfWeek()->addDays($dayNumber);

                    // Only create future schedules
                    if ($scheduleDate->isToday() || $scheduleDate->isFuture()) {
                        $schedules[] = [
                            'workout_plan_id' => $plan->workout_plan_id,
                            'workout_id' => 1, // Default tabata workout ID (would need to be dynamic)
                            'scheduled_date' => $scheduleDate->toDateString(),
                            'scheduled_time' => '08:00:00', // Default morning time
                            'estimated_duration_minutes' => $validated['session_duration'],
                            'ml_recommendation_score' => 0.8, // Default score
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
            }

            // Bulk insert schedules
            if (!empty($schedules)) {
                \App\Models\WorkoutPlanSchedule::insert($schedules);
            }

            // Get the created schedules
            $createdSchedules = \App\Models\WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)->get();

            return response()->json([
                'message' => 'Workout plan and schedule created successfully',
                'data' => [
                    'plan' => $plan,
                    'schedule' => $createdSchedules
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create workout plan with schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getDayNumber($dayName): int
    {
        $days = [
            'monday' => 0,
            'tuesday' => 1,
            'wednesday' => 2,
            'thursday' => 3,
            'friday' => 4,
            'saturday' => 5,
            'sunday' => 6
        ];

        return $days[strtolower($dayName)] ?? 0;
    }

    private function mapDifficultyToDatabase($difficulty): string
    {
        $mapping = [
            'beginner' => 'beginner',
            'intermediate' => 'medium',
            'advanced' => 'expert'
        ];

        return $mapping[strtolower($difficulty)] ?? 'beginner';
    }
}
