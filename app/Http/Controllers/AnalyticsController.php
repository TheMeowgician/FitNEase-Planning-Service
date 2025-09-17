<?php

namespace App\Http\Controllers;

use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function getPlanProgress($planId): JsonResponse
    {
        try {
            $plan = WorkoutPlan::with('schedules')->findOrFail($planId);

            $totalScheduled = $plan->schedules->count();
            $completed = $plan->schedules->where('is_completed', true)->count();
            $skipped = $plan->schedules->where('skipped', true)->count();
            $overdue = $plan->schedules->where('scheduled_date', '<', now()->toDateString())
                ->where('is_completed', false)
                ->where('skipped', false)
                ->count();

            $progress = [
                'plan_id' => $plan->workout_plan_id,
                'plan_name' => $plan->plan_name,
                'total_scheduled' => $totalScheduled,
                'completed' => $completed,
                'skipped' => $skipped,
                'overdue' => $overdue,
                'remaining' => $totalScheduled - $completed - $skipped,
                'completion_rate' => $totalScheduled > 0 ? round(($completed / $totalScheduled) * 100, 2) : 0,
                'adherence_rate' => $totalScheduled > 0 ? round((($totalScheduled - $skipped - $overdue) / $totalScheduled) * 100, 2) : 0,
                'start_date' => $plan->start_date,
                'end_date' => $plan->end_date,
                'days_elapsed' => Carbon::parse($plan->start_date)->diffInDays(now()),
                'days_remaining' => $plan->end_date ? max(0, Carbon::parse($plan->end_date)->diffInDays(now())) : null,
                'is_on_track' => $this->isOnTrack($plan),
                'weekly_progress' => $this->getWeeklyProgress($plan)
            ];

            return response()->json([
                'message' => 'Plan progress retrieved successfully',
                'data' => $progress
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve plan progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPlanAnalytics($planId): JsonResponse
    {
        try {
            $plan = WorkoutPlan::with('schedules')->findOrFail($planId);

            $analytics = [
                'plan_overview' => $this->getPlanOverview($plan),
                'performance_metrics' => $this->getPerformanceMetrics($plan),
                'timing_analysis' => $this->getTimingAnalysis($plan),
                'difficulty_analysis' => $this->getDifficultyAnalysis($plan),
                'adherence_patterns' => $this->getAdherencePatterns($plan),
                'recommendations' => $this->getAnalyticsRecommendations($plan)
            ];

            return response()->json([
                'message' => 'Plan analytics retrieved successfully',
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve plan analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserAdherenceReport($userId): JsonResponse
    {
        try {
            $plans = WorkoutPlan::where('user_id', $userId)->with('schedules')->get();

            if ($plans->isEmpty()) {
                return response()->json([
                    'message' => 'No plans found for user',
                    'data' => null
                ]);
            }

            $overallStats = $this->calculateOverallStats($plans);
            $monthlyStats = $this->getMonthlyAdherenceStats($userId);
            $planComparison = $this->getPlanComparison($plans);

            $report = [
                'user_id' => $userId,
                'overall_statistics' => $overallStats,
                'monthly_adherence' => $monthlyStats,
                'plan_comparison' => $planComparison,
                'adherence_trends' => $this->getAdherenceTrends($plans),
                'success_factors' => $this->identifySuccessFactors($plans),
                'improvement_areas' => $this->identifyImprovementAreas($overallStats)
            ];

            return response()->json([
                'message' => 'Adherence report retrieved successfully',
                'data' => $report
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve adherence report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCompletionTrends($userId): JsonResponse
    {
        try {
            $trends = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
                ->where('workout_plans.user_id', $userId)
                ->where('workout_plan_schedule.scheduled_date', '>=', now()->subMonths(6))
                ->select(
                    DB::raw('DATE_FORMAT(scheduled_date, "%Y-%m") as month'),
                    DB::raw('COUNT(*) as total_scheduled'),
                    DB::raw('SUM(is_completed) as completed'),
                    DB::raw('SUM(skipped) as skipped'),
                    DB::raw('ROUND((SUM(is_completed) / COUNT(*)) * 100, 2) as completion_rate')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return response()->json([
                'message' => 'Completion trends retrieved successfully',
                'data' => $trends
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve completion trends',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getWorkoutInsights($userId): JsonResponse
    {
        try {
            $insights = [
                'preferred_workout_days' => $this->getPreferredWorkoutDays($userId),
                'optimal_workout_times' => $this->getOptimalWorkoutTimes($userId),
                'duration_analysis' => $this->getDurationAnalysis($userId),
                'streak_analysis' => $this->getStreakAnalysis($userId),
                'consistency_score' => $this->getConsistencyScore($userId)
            ];

            return response()->json([
                'message' => 'Workout insights retrieved successfully',
                'data' => $insights
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve workout insights',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getPlanOverview($plan): array
    {
        return [
            'plan_name' => $plan->plan_name,
            'duration_weeks' => $plan->target_duration_weeks,
            'difficulty' => $plan->plan_difficulty,
            'ml_generated' => $plan->ml_generated,
            'personalization_score' => $plan->personalization_score,
            'is_active' => $plan->is_active,
            'is_completed' => $plan->is_completed
        ];
    }

    private function getPerformanceMetrics($plan): array
    {
        $schedules = $plan->schedules;
        $completedWorkouts = $schedules->where('is_completed', true);

        $avgDuration = $completedWorkouts->whereNotNull('actual_duration_minutes')
            ->avg('actual_duration_minutes');

        $avgDifficultyAdjustment = $schedules->avg('difficulty_adjustment');

        return [
            'average_workout_duration' => round($avgDuration, 2),
            'average_difficulty_adjustment' => round($avgDifficultyAdjustment, 2),
            'total_workout_time' => $completedWorkouts->sum('actual_duration_minutes'),
            'consistency_rating' => $this->calculateConsistencyRating($schedules)
        ];
    }

    private function getTimingAnalysis($plan): array
    {
        $schedules = $plan->schedules->where('is_completed', true);

        $timingData = $schedules->groupBy(function($schedule) {
            return Carbon::parse($schedule->scheduled_time)->format('H');
        })->map(function($group) {
            return $group->count();
        });

        return [
            'preferred_hours' => $timingData->toArray(),
            'most_productive_hour' => $timingData->keys()->first(),
            'workout_time_distribution' => $this->categorizeWorkoutTimes($timingData)
        ];
    }

    private function getDifficultyAnalysis($plan): array
    {
        $schedules = $plan->schedules;

        return [
            'average_difficulty_adjustment' => round($schedules->avg('difficulty_adjustment'), 2),
            'difficulty_progression' => $this->getDifficultyProgression($schedules),
            'adaptation_rate' => $this->calculateAdaptationRate($schedules)
        ];
    }

    private function getAdherencePatterns($plan): array
    {
        $schedules = $plan->schedules;

        $dayOfWeekStats = $schedules->groupBy(function($schedule) {
            return Carbon::parse($schedule->scheduled_date)->format('l');
        })->map(function($group) {
            $total = $group->count();
            $completed = $group->where('is_completed', true)->count();
            return [
                'total' => $total,
                'completed' => $completed,
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0
            ];
        });

        return [
            'by_day_of_week' => $dayOfWeekStats->toArray(),
            'best_day' => $dayOfWeekStats->sortByDesc('completion_rate')->keys()->first(),
            'worst_day' => $dayOfWeekStats->sortBy('completion_rate')->keys()->first()
        ];
    }

    private function getAnalyticsRecommendations($plan): array
    {
        $completionRate = $plan->completion_rate;
        $recommendations = [];

        if ($completionRate < 0.5) {
            $recommendations[] = 'Consider reducing workout frequency or difficulty';
            $recommendations[] = 'Focus on building consistency with shorter workouts';
        } elseif ($completionRate < 0.8) {
            $recommendations[] = 'Try adjusting workout timing to match your most active hours';
            $recommendations[] = 'Consider adding variety to maintain engagement';
        } else {
            $recommendations[] = 'Great job! Consider gradually increasing difficulty';
            $recommendations[] = 'You might be ready for more advanced workout variations';
        }

        return $recommendations;
    }

    private function isOnTrack($plan): bool
    {
        if (!$plan->end_date) return true;

        $totalDays = Carbon::parse($plan->start_date)->diffInDays(Carbon::parse($plan->end_date));
        $elapsedDays = Carbon::parse($plan->start_date)->diffInDays(now());

        $expectedProgress = $elapsedDays / $totalDays;
        $actualProgress = $plan->completion_rate / 100;

        return $actualProgress >= ($expectedProgress * 0.8);
    }

    private function getWeeklyProgress($plan): array
    {
        return $plan->schedules
            ->groupBy(function($schedule) {
                return Carbon::parse($schedule->scheduled_date)->format('Y-W');
            })
            ->map(function($group) {
                $total = $group->count();
                $completed = $group->where('is_completed', true)->count();
                return [
                    'total' => $total,
                    'completed' => $completed,
                    'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0
                ];
            })
            ->toArray();
    }

    private function calculateOverallStats($plans): array
    {
        $allSchedules = $plans->flatMap->schedules;
        $totalScheduled = $allSchedules->count();
        $completed = $allSchedules->where('is_completed', true)->count();
        $skipped = $allSchedules->where('skipped', true)->count();

        return [
            'total_plans' => $plans->count(),
            'active_plans' => $plans->where('is_active', true)->count(),
            'completed_plans' => $plans->where('is_completed', true)->count(),
            'total_workouts_scheduled' => $totalScheduled,
            'workouts_completed' => $completed,
            'workouts_skipped' => $skipped,
            'overall_completion_rate' => $totalScheduled > 0 ? round(($completed / $totalScheduled) * 100, 2) : 0,
            'overall_adherence_rate' => $totalScheduled > 0 ? round((($totalScheduled - $skipped) / $totalScheduled) * 100, 2) : 0
        ];
    }

    private function getMonthlyAdherenceStats($userId): array
    {
        return WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plan_schedule.scheduled_date', '>=', now()->subMonths(12))
            ->select(
                DB::raw('DATE_FORMAT(scheduled_date, "%Y-%m") as month'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(is_completed) as completed'),
                DB::raw('ROUND((SUM(is_completed) / COUNT(*)) * 100, 2) as adherence_rate')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();
    }

    private function getPlanComparison($plans): array
    {
        return $plans->map(function($plan) {
            return [
                'plan_name' => $plan->plan_name,
                'completion_rate' => $plan->completion_rate,
                'difficulty' => $plan->plan_difficulty,
                'ml_generated' => $plan->ml_generated,
                'duration_weeks' => $plan->target_duration_weeks
            ];
        })->toArray();
    }

    private function getAdherenceTrends($plans): array
    {
        return [
            'improving' => $this->isAdherenceImproving($plans),
            'trend_direction' => $this->getAdherenceTrendDirection($plans),
            'consistency_score' => $this->calculateOverallConsistency($plans)
        ];
    }

    private function identifySuccessFactors($plans): array
    {
        $highPerformingPlans = $plans->where('completion_rate', '>', 0.8);

        return [
            'optimal_difficulty' => $highPerformingPlans->mode('plan_difficulty'),
            'ml_generated_effective' => $highPerformingPlans->where('ml_generated', true)->count() > $highPerformingPlans->where('ml_generated', false)->count(),
            'ideal_duration_weeks' => round($highPerformingPlans->avg('target_duration_weeks'), 0)
        ];
    }

    private function identifyImprovementAreas($overallStats): array
    {
        $areas = [];

        if ($overallStats['overall_completion_rate'] < 60) {
            $areas[] = 'Workout completion consistency';
        }

        if ($overallStats['overall_adherence_rate'] < 70) {
            $areas[] = 'Reducing workout skipping frequency';
        }

        if ($overallStats['active_plans'] > 1) {
            $areas[] = 'Focus on fewer concurrent plans';
        }

        return $areas;
    }

    private function calculateConsistencyRating($schedules): float
    {
        $completedWorkouts = $schedules->where('is_completed', true);
        if ($completedWorkouts->count() < 3) return 0;

        $intervals = [];
        $sortedWorkouts = $completedWorkouts->sortBy('completed_at');

        for ($i = 1; $i < $sortedWorkouts->count(); $i++) {
            $current = Carbon::parse($sortedWorkouts->values()[$i]->completed_at);
            $previous = Carbon::parse($sortedWorkouts->values()[$i-1]->completed_at);
            $intervals[] = $current->diffInDays($previous);
        }

        $avgInterval = collect($intervals)->avg();
        $stdDev = $this->calculateStandardDeviation($intervals);

        return max(0, min(10, 10 - ($stdDev / $avgInterval) * 2));
    }

    private function categorizeWorkoutTimes($timingData): array
    {
        $categories = ['morning' => 0, 'afternoon' => 0, 'evening' => 0, 'night' => 0];

        foreach ($timingData as $hour => $count) {
            if ($hour >= 5 && $hour < 12) $categories['morning'] += $count;
            elseif ($hour >= 12 && $hour < 17) $categories['afternoon'] += $count;
            elseif ($hour >= 17 && $hour < 21) $categories['evening'] += $count;
            else $categories['night'] += $count;
        }

        return $categories;
    }

    private function getDifficultyProgression($schedules): array
    {
        return $schedules->sortBy('scheduled_date')
            ->values()
            ->map(function($schedule, $index) {
                return [
                    'week' => intval($index / 7) + 1,
                    'difficulty_adjustment' => $schedule->difficulty_adjustment
                ];
            })
            ->groupBy('week')
            ->map(function($group) {
                return round($group->avg('difficulty_adjustment'), 2);
            })
            ->toArray();
    }

    private function calculateAdaptationRate($schedules): float
    {
        $adjustments = $schedules->pluck('difficulty_adjustment')->filter(function($adj) {
            return $adj != 1.0;
        });

        return $adjustments->count() / max($schedules->count(), 1);
    }

    private function getPreferredWorkoutDays($userId): array
    {
        return WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plan_schedule.is_completed', true)
            ->select(DB::raw('DAYNAME(scheduled_date) as day, COUNT(*) as count'))
            ->groupBy('day')
            ->orderByDesc('count')
            ->pluck('count', 'day')
            ->toArray();
    }

    private function getOptimalWorkoutTimes($userId): array
    {
        return WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plan_schedule.is_completed', true)
            ->whereNotNull('scheduled_time')
            ->select(DB::raw('HOUR(scheduled_time) as hour, COUNT(*) as count'))
            ->groupBy('hour')
            ->orderByDesc('count')
            ->pluck('count', 'hour')
            ->toArray();
    }

    private function getDurationAnalysis($userId): array
    {
        $durations = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plan_schedule.is_completed', true)
            ->whereNotNull('actual_duration_minutes')
            ->pluck('actual_duration_minutes');

        return [
            'average_duration' => round($durations->avg(), 2),
            'min_duration' => $durations->min(),
            'max_duration' => $durations->max(),
            'total_workout_time' => $durations->sum()
        ];
    }

    private function getStreakAnalysis($userId): array
    {
        $completedWorkouts = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plan_schedule.is_completed', true)
            ->orderBy('completed_at')
            ->pluck('completed_at')
            ->map(function($date) {
                return Carbon::parse($date)->toDateString();
            });

        $currentStreak = 0;
        $longestStreak = 0;
        $tempStreak = 1;

        for ($i = 1; $i < $completedWorkouts->count(); $i++) {
            $current = Carbon::parse($completedWorkouts[$i]);
            $previous = Carbon::parse($completedWorkouts[$i-1]);

            if ($current->diffInDays($previous) <= 2) {
                $tempStreak++;
            } else {
                $longestStreak = max($longestStreak, $tempStreak);
                $tempStreak = 1;
            }
        }

        $longestStreak = max($longestStreak, $tempStreak);

        if ($completedWorkouts->count() > 0) {
            $lastWorkout = Carbon::parse($completedWorkouts->last());
            $currentStreak = now()->diffInDays($lastWorkout) <= 2 ? $tempStreak : 0;
        }

        return [
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak
        ];
    }

    private function getConsistencyScore($userId): float
    {
        return 0.75;
    }

    private function calculateStandardDeviation($values): float
    {
        $mean = array_sum($values) / count($values);
        $sumSquaredDiffs = array_sum(array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values));

        return sqrt($sumSquaredDiffs / count($values));
    }

    private function isAdherenceImproving($plans): bool
    {
        if ($plans->count() < 2) return false;

        $recentPlans = $plans->sortByDesc('created_at')->take(2);
        $latest = $recentPlans->first()->completion_rate;
        $previous = $recentPlans->last()->completion_rate;

        return $latest > $previous;
    }

    private function getAdherenceTrendDirection($plans): string
    {
        if ($plans->count() < 2) return 'insufficient_data';

        $rates = $plans->sortBy('created_at')->pluck('completion_rate');
        $firstHalf = $rates->take(intval($rates->count() / 2))->avg();
        $secondHalf = $rates->skip(intval($rates->count() / 2))->avg();

        if ($secondHalf > $firstHalf * 1.1) return 'improving';
        if ($secondHalf < $firstHalf * 0.9) return 'declining';
        return 'stable';
    }

    private function calculateOverallConsistency($plans): float
    {
        return $plans->avg(function($plan) {
            return $this->calculateConsistencyRating($plan->schedules);
        });
    }
}
