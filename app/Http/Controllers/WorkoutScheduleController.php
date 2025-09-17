<?php

namespace App\Http\Controllers;

use App\Models\WorkoutPlanSchedule;
use App\Models\WorkoutPlan;
use App\Services\WorkoutPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class WorkoutScheduleController extends Controller
{
    protected $workoutPlanService;

    public function __construct(WorkoutPlanService $workoutPlanService)
    {
        $this->workoutPlanService = $workoutPlanService;
    }

    public function createSchedule(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'workout_plan_id' => 'required|integer|exists:workout_plans,workout_plan_id',
                'workout_id' => 'required|integer',
                'scheduled_date' => 'required|date',
                'scheduled_time' => 'nullable|date_format:H:i:s',
                'estimated_duration_minutes' => 'nullable|integer|min:1',
                'ml_recommendation_score' => 'nullable|numeric|between:0,1'
            ]);

            $schedule = WorkoutPlanSchedule::create($validated);

            $plan = WorkoutPlan::find($validated['workout_plan_id']);
            $plan->increment('total_planned_workouts');

            return response()->json([
                'message' => 'Schedule created successfully',
                'data' => $schedule
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPlanSchedule($planId): JsonResponse
    {
        try {
            $schedules = WorkoutPlanSchedule::where('workout_plan_id', $planId)
                ->orderBy('scheduled_date', 'asc')
                ->get();

            return response()->json([
                'message' => 'Schedule retrieved successfully',
                'data' => $schedules
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateSchedule(Request $request, $scheduleId): JsonResponse
    {
        try {
            $schedule = WorkoutPlanSchedule::findOrFail($scheduleId);

            $validated = $request->validate([
                'workout_id' => 'sometimes|integer',
                'scheduled_date' => 'sometimes|date',
                'scheduled_time' => 'nullable|date_format:H:i:s',
                'estimated_duration_minutes' => 'nullable|integer|min:1',
                'difficulty_adjustment' => 'nullable|numeric|between:0.1,3.0',
                'ml_recommendation_score' => 'nullable|numeric|between:0,1'
            ]);

            $schedule->update($validated);

            return response()->json([
                'message' => 'Schedule updated successfully',
                'data' => $schedule
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function completeWorkout(Request $request, $scheduleId): JsonResponse
    {
        try {
            $schedule = WorkoutPlanSchedule::findOrFail($scheduleId);

            if ($schedule->is_completed) {
                return response()->json([
                    'message' => 'Workout already completed',
                    'data' => $schedule
                ]);
            }

            $validated = $request->validate([
                'session_id' => 'nullable|integer',
                'actual_duration_minutes' => 'nullable|integer|min:1'
            ]);

            $schedule->update([
                'is_completed' => true,
                'completed_at' => now(),
                'session_id' => $validated['session_id'] ?? null,
                'actual_duration_minutes' => $validated['actual_duration_minutes'] ?? null
            ]);

            $plan = WorkoutPlan::find($schedule->workout_plan_id);
            $plan->increment('completed_workouts');

            $this->workoutPlanService->checkPlanMilestones($plan->workout_plan_id);

            return response()->json([
                'message' => 'Workout completed successfully',
                'data' => $schedule
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to complete workout',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function skipWorkout(Request $request, $scheduleId): JsonResponse
    {
        try {
            $schedule = WorkoutPlanSchedule::findOrFail($scheduleId);

            if ($schedule->is_completed) {
                return response()->json([
                    'message' => 'Cannot skip completed workout',
                    'data' => null
                ], 400);
            }

            $validated = $request->validate([
                'skip_reason' => 'nullable|string|max:500'
            ]);

            $schedule->update([
                'skipped' => true,
                'skip_reason' => $validated['skip_reason'] ?? null
            ]);

            return response()->json([
                'message' => 'Workout skipped successfully',
                'data' => $schedule
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to skip workout',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function rescheduleWorkout(Request $request, $scheduleId): JsonResponse
    {
        try {
            $schedule = WorkoutPlanSchedule::findOrFail($scheduleId);

            if ($schedule->is_completed) {
                return response()->json([
                    'message' => 'Cannot reschedule completed workout',
                    'data' => null
                ], 400);
            }

            $validated = $request->validate([
                'new_date' => 'required|date|after_or_equal:today',
                'new_time' => 'nullable|date_format:H:i:s'
            ]);

            $originalDate = $schedule->scheduled_date;

            $schedule->update([
                'scheduled_date' => $validated['new_date'],
                'scheduled_time' => $validated['new_time'] ?? $schedule->scheduled_time,
                'rescheduled_from' => $originalDate,
                'skipped' => false
            ]);

            return response()->json([
                'message' => 'Workout rescheduled successfully',
                'data' => $schedule
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reschedule workout',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTodaySchedule($userId): JsonResponse
    {
        try {
            $today = now()->toDateString();

            $schedules = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
                ->where('workout_plans.user_id', $userId)
                ->where('workout_plans.is_active', true)
                ->where('workout_plan_schedule.scheduled_date', $today)
                ->select('workout_plan_schedule.*')
                ->with('workoutPlan')
                ->get();

            return response()->json([
                'message' => 'Today\'s schedule retrieved successfully',
                'data' => $schedules,
                'date' => $today
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve today\'s schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUpcomingSchedule($userId): JsonResponse
    {
        try {
            $schedules = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
                ->where('workout_plans.user_id', $userId)
                ->where('workout_plans.is_active', true)
                ->where('workout_plan_schedule.scheduled_date', '>', now()->toDateString())
                ->where('workout_plan_schedule.is_completed', false)
                ->where('workout_plan_schedule.skipped', false)
                ->select('workout_plan_schedule.*')
                ->with('workoutPlan')
                ->orderBy('workout_plan_schedule.scheduled_date', 'asc')
                ->limit(10)
                ->get();

            return response()->json([
                'message' => 'Upcoming schedule retrieved successfully',
                'data' => $schedules
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve upcoming schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getOverdueWorkouts($userId): JsonResponse
    {
        try {
            $schedules = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
                ->where('workout_plans.user_id', $userId)
                ->where('workout_plans.is_active', true)
                ->where('workout_plan_schedule.scheduled_date', '<', now()->toDateString())
                ->where('workout_plan_schedule.is_completed', false)
                ->where('workout_plan_schedule.skipped', false)
                ->select('workout_plan_schedule.*')
                ->with('workoutPlan')
                ->orderBy('workout_plan_schedule.scheduled_date', 'desc')
                ->get();

            return response()->json([
                'message' => 'Overdue workouts retrieved successfully',
                'data' => $schedules,
                'count' => $schedules->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve overdue workouts',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
