<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutPlanScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'schedule_id' => $this->schedule_id,
            'workout_plan_id' => $this->workout_plan_id,
            'workout_id' => $this->workout_id,
            'scheduled_date' => $this->scheduled_date?->format('Y-m-d'),
            'scheduled_time' => $this->scheduled_time?->format('H:i:s'),
            'is_completed' => $this->is_completed,
            'completed_at' => $this->completed_at?->toISOString(),
            'session_id' => $this->session_id,
            'ml_recommendation_score' => $this->ml_recommendation_score ? (float) $this->ml_recommendation_score : null,
            'difficulty_adjustment' => (float) $this->difficulty_adjustment,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'actual_duration_minutes' => $this->actual_duration_minutes,
            'duration_difference' => $this->duration_difference,
            'skipped' => $this->skipped,
            'skip_reason' => $this->skip_reason,
            'rescheduled_from' => $this->rescheduled_from?->format('Y-m-d'),
            'is_overdue' => $this->is_overdue,
            'status' => $this->getStatus(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'workout_plan' => new WorkoutPlanResource($this->whenLoaded('workoutPlan'))
        ];
    }

    private function getStatus(): string
    {
        if ($this->is_completed) return 'completed';
        if ($this->skipped) return 'skipped';
        if ($this->is_overdue) return 'overdue';
        if ($this->scheduled_date?->isToday()) return 'today';
        if ($this->scheduled_date?->isFuture()) return 'upcoming';
        return 'pending';
    }
}
