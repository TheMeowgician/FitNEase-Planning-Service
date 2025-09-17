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
                return response()->json([
                    'message' => 'No active plan found for user',
                    'data' => null
                ], 404);
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
}
