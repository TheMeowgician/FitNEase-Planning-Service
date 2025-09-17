<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutPlanSchedule extends Model
{
    protected $table = 'workout_plan_schedule';
    protected $primaryKey = 'schedule_id';

    protected $fillable = [
        'workout_plan_id',
        'workout_id',
        'scheduled_date',
        'scheduled_time',
        'is_completed',
        'completed_at',
        'session_id',
        'ml_recommendation_score',
        'difficulty_adjustment',
        'estimated_duration_minutes',
        'actual_duration_minutes',
        'skipped',
        'skip_reason',
        'rescheduled_from'
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'scheduled_time' => 'datetime:H:i:s',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'ml_recommendation_score' => 'decimal:4',
        'difficulty_adjustment' => 'decimal:2',
        'estimated_duration_minutes' => 'integer',
        'actual_duration_minutes' => 'integer',
        'skipped' => 'boolean',
        'rescheduled_from' => 'date',
        'workout_plan_id' => 'integer',
        'workout_id' => 'integer',
        'session_id' => 'integer'
    ];

    public function workoutPlan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id', 'workout_plan_id');
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    public function scopeSkipped($query)
    {
        return $query->where('skipped', true);
    }

    public function scopeScheduledFor($query, $date)
    {
        return $query->where('scheduled_date', $date);
    }

    public function scopeForPlan($query, $planId)
    {
        return $query->where('workout_plan_id', $planId);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('scheduled_date', '>=', now()->toDateString())
                    ->where('is_completed', false)
                    ->where('skipped', false);
    }

    public function scopePast($query)
    {
        return $query->where('scheduled_date', '<', now()->toDateString());
    }

    public function scopeToday($query)
    {
        return $query->where('scheduled_date', now()->toDateString());
    }

    public function getDurationDifferenceAttribute(): ?int
    {
        if ($this->estimated_duration_minutes && $this->actual_duration_minutes) {
            return $this->actual_duration_minutes - $this->estimated_duration_minutes;
        }
        return null;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->scheduled_date < now()->toDateString()
               && !$this->is_completed
               && !$this->skipped;
    }
}
