<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * WeeklyWorkoutPlan Model
 *
 * Feature #4: Weekly Workout Plans with Preferred Days
 * Represents an auto-generated weekly workout plan for a user.
 * Contains daily workout assignments based on user preferences and ML recommendations.
 */
class WeeklyWorkoutPlan extends Model
{
    use HasFactory;

    protected $primaryKey = 'plan_id';

    protected $fillable = [
        'user_id',
        'week_start_date',
        'week_end_date',
        'is_active',
        'is_current_week',
        'plan_data',
        'total_workout_days',
        'total_rest_days',
        'total_exercises',
        'estimated_weekly_duration',
        'estimated_weekly_calories',
        'ml_generated',
        'ml_confidence_score',
        'generation_method',
        'user_preferences_snapshot',
        'workouts_completed',
        'workouts_skipped',
        'completion_rate',
        'completed_at',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'week_end_date' => 'date',
        'is_active' => 'boolean',
        'is_current_week' => 'boolean',
        'plan_data' => 'array',
        'total_workout_days' => 'integer',
        'total_rest_days' => 'integer',
        'total_exercises' => 'integer',
        'estimated_weekly_duration' => 'integer',
        'estimated_weekly_calories' => 'integer',
        'ml_generated' => 'boolean',
        'ml_confidence_score' => 'decimal:4',
        'user_preferences_snapshot' => 'array',
        'workouts_completed' => 'integer',
        'workouts_skipped' => 'integer',
        'completion_rate' => 'decimal:2',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * NOTE: In a microservices architecture, we do NOT use Eloquent relationships
     * across service boundaries. User data should be fetched from the auth service
     * via HTTP when needed (which the controller already does).
     *
     * Removed user() relationship to prevent serialization errors.
     */

    /**
     * Check if this plan is for the current week
     *
     * @return bool
     */
    public function isCurrentWeek(): bool
    {
        $today = Carbon::now();
        return $today->greaterThanOrEqualTo($this->week_start_date) &&
               $today->lessThanOrEqualTo($this->week_end_date);
    }

    /**
     * Get workout plan for a specific day
     *
     * @param string $day Day of week (lowercase): monday, tuesday, etc.
     * @return array|null
     */
    public function getDayPlan(string $day): ?array
    {
        $day = strtolower($day);
        return $this->plan_data[$day] ?? null;
    }

    /**
     * Get workout plan for today
     *
     * @return array|null
     */
    public function getTodayPlan(): ?array
    {
        $today = strtolower(Carbon::now()->format('l')); // 'Monday' -> 'monday'
        return $this->getDayPlan($today);
    }

    /**
     * Mark a day's workout as completed
     *
     * @param string $day
     * @return bool
     */
    public function markDayCompleted(string $day): bool
    {
        $day = strtolower($day);
        $planData = $this->plan_data;

        if (!isset($planData[$day]) || !($planData[$day]['planned'] ?? false)) {
            return false;
        }

        $planData[$day]['completed'] = true;
        $planData[$day]['completed_at'] = Carbon::now()->toDateTimeString();

        $this->plan_data = $planData;
        $this->workouts_completed += 1;
        $this->updateCompletionRate();
        $this->save();

        return true;
    }

    /**
     * Mark a day's workout as skipped
     *
     * @param string $day
     * @param string|null $reason
     * @return bool
     */
    public function markDaySkipped(string $day, ?string $reason = null): bool
    {
        $day = strtolower($day);
        $planData = $this->plan_data;

        if (!isset($planData[$day]) || !($planData[$day]['planned'] ?? false)) {
            return false;
        }

        $planData[$day]['skipped'] = true;
        $planData[$day]['skip_reason'] = $reason;
        $planData[$day]['skipped_at'] = Carbon::now()->toDateTimeString();

        $this->plan_data = $planData;
        $this->workouts_skipped += 1;
        $this->updateCompletionRate();
        $this->save();

        return true;
    }

    /**
     * Update completion rate based on completed/skipped workouts
     */
    protected function updateCompletionRate(): void
    {
        if ($this->total_workout_days > 0) {
            $this->completion_rate = ($this->workouts_completed / $this->total_workout_days) * 100;
        }
    }

    /**
     * Get all workout days in this plan
     *
     * @return array Array of day names that have workouts planned
     */
    public function getWorkoutDays(): array
    {
        $workoutDays = [];
        foreach ($this->plan_data as $day => $dayData) {
            if ($dayData['planned'] ?? false) {
                $workoutDays[] = $day;
            }
        }
        return $workoutDays;
    }

    /**
     * Get all rest days in this plan
     *
     * @return array Array of day names that are rest days
     */
    public function getRestDays(): array
    {
        $restDays = [];
        foreach ($this->plan_data as $day => $dayData) {
            if ($dayData['rest_day'] ?? false) {
                $restDays[] = $day;
            }
        }
        return $restDays;
    }

    /**
     * Scope: Get current week's plan for a user
     */
    public function scopeCurrentWeek($query, int $userId)
    {
        return $query->where('user_id', $userId)
                    ->where('is_current_week', true)
                    ->where('is_active', true);
    }

    /**
     * Scope: Get plan for a specific week
     */
    public function scopeForWeek($query, int $userId, Carbon $weekStartDate)
    {
        return $query->where('user_id', $userId)
                    ->where('week_start_date', $weekStartDate)
                    ->where('is_active', true);
    }

    /**
     * Scope: Get active plans for a user
     */
    public function scopeActive($query, int $userId)
    {
        return $query->where('user_id', $userId)
                    ->where('is_active', true)
                    ->orderBy('week_start_date', 'desc');
    }
}
