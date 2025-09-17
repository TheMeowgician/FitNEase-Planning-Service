<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'workout_plan_id' => $this->workout_plan_id,
            'user_id' => $this->user_id,
            'plan_name' => $this->plan_name,
            'description' => $this->description,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'workout_days' => $this->workout_days_array,
            'rest_days' => $this->rest_days_array,
            'is_active' => $this->is_active,
            'is_completed' => $this->is_completed,
            'total_planned_workouts' => $this->total_planned_workouts,
            'completed_workouts' => $this->completed_workouts,
            'completion_rate' => $this->completion_rate,
            'ml_generated' => $this->ml_generated,
            'personalization_score' => (float) $this->personalization_score,
            'plan_difficulty' => $this->plan_difficulty,
            'target_duration_weeks' => $this->target_duration_weeks,
            'actual_duration_weeks' => $this->actual_duration_weeks,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'schedules' => WorkoutPlanScheduleResource::collection($this->whenLoaded('schedules')),
            'progress_summary' => [
                'days_elapsed' => $this->start_date ? $this->start_date->diffInDays(now()) : 0,
                'days_remaining' => $this->end_date ? max(0, $this->end_date->diffInDays(now())) : null,
                'is_overdue' => $this->end_date ? now()->gt($this->end_date) && !$this->is_completed : false,
                'next_workout_date' => $this->getNextWorkoutDate(),
            ]
        ];
    }

    private function getNextWorkoutDate(): ?string
    {
        $nextSchedule = $this->schedules()
            ->where('scheduled_date', '>=', now()->toDateString())
            ->where('is_completed', false)
            ->where('skipped', false)
            ->orderBy('scheduled_date')
            ->first();

        return $nextSchedule?->scheduled_date?->format('Y-m-d');
    }
}
