<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutPlan extends Model
{
    protected $primaryKey = 'workout_plan_id';

    protected $fillable = [
        'user_id',
        'plan_name',
        'description',
        'start_date',
        'end_date',
        'workout_days',
        'rest_days',
        'is_active',
        'is_completed',
        'total_planned_workouts',
        'completed_workouts',
        'ml_generated',
        'personalization_score',
        'plan_difficulty',
        'target_duration_weeks',
        'actual_duration_weeks'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_completed' => 'boolean',
        'ml_generated' => 'boolean',
        'personalization_score' => 'decimal:4',
        'total_planned_workouts' => 'integer',
        'completed_workouts' => 'integer',
        'target_duration_weeks' => 'integer',
        'actual_duration_weeks' => 'integer'
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(WorkoutPlanSchedule::class, 'workout_plan_id', 'workout_plan_id');
    }

    public function getCompletionRateAttribute(): float
    {
        return $this->total_planned_workouts > 0
            ? $this->completed_workouts / $this->total_planned_workouts
            : 0;
    }

    public function getWorkoutDaysArrayAttribute(): array
    {
        return $this->workout_days ? explode(',', str_replace(['[', ']', '"'], '', $this->workout_days)) : [];
    }

    public function getRestDaysArrayAttribute(): array
    {
        return $this->rest_days ? explode(',', str_replace(['[', ']', '"'], '', $this->rest_days)) : [];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    public function scopeMlGenerated($query)
    {
        return $query->where('ml_generated', true);
    }
}
