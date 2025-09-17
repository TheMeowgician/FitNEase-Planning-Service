<?php

namespace App\Services;

use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WorkoutPlanService
{
    protected $externalApiService;

    public function __construct(ExternalApiService $externalApiService)
    {
        $this->externalApiService = $externalApiService;
    }

    public function generatePersonalizedPlan($userId)
    {
        $userData = $this->externalApiService->getUserProfile($userId);
        if (!$userData) {
            throw new \Exception('Unable to retrieve user profile');
        }

        $behaviorPatterns = $this->externalApiService->getUserBehaviorPatterns($userId);
        $recommendations = $this->externalApiService->getMlRecommendations($userId);

        if (!$recommendations) {
            return $this->createRuleBasedPlan($userData);
        }

        $criteria = [
            'difficulty' => $userData['fitness_level'] ?? 'beginner',
            'muscle_groups' => implode(',', $userData['target_muscle_groups'] ?? []),
            'equipment' => implode(',', $userData['available_equipment'] ?? []),
            'duration' => $userData['time_constraints_minutes'] ?? 30
        ];

        $workouts = $this->externalApiService->getWorkoutsByCriteria($criteria);

        return $this->createOptimizedPlan($userData, $recommendations, $workouts, $behaviorPatterns);
    }

    private function createOptimizedPlan($userData, $recommendations, $workouts, $behaviorPatterns)
    {
        $plan = WorkoutPlan::create([
            'user_id' => $userData['user_id'],
            'plan_name' => 'AI-Generated Plan - ' . now()->format('M Y'),
            'description' => 'Personalized plan based on your preferences and behavioral patterns',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks(4)->toDateString(),
            'workout_days' => $this->optimizeWorkoutDays($behaviorPatterns),
            'rest_days' => $this->optimizeRestDays($behaviorPatterns),
            'ml_generated' => true,
            'plan_difficulty' => $userData['fitness_level'] ?? 'beginner',
            'target_duration_weeks' => 4,
            'personalization_score' => $this->calculatePersonalizationScore($recommendations)
        ]);

        $this->scheduleWorkoutsWithML($plan, $recommendations, $behaviorPatterns, $workouts);

        return $plan;
    }

    private function createRuleBasedPlan($userData)
    {
        $plan = WorkoutPlan::create([
            'user_id' => $userData['user_id'],
            'plan_name' => 'Standard Plan - ' . now()->format('M Y'),
            'description' => 'Rule-based plan based on your preferences',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeeks(4)->toDateString(),
            'workout_days' => implode(',', ['monday', 'wednesday', 'friday']),
            'rest_days' => implode(',', ['tuesday', 'thursday', 'saturday', 'sunday']),
            'ml_generated' => false,
            'plan_difficulty' => $userData['fitness_level'] ?? 'beginner',
            'target_duration_weeks' => 4,
            'personalization_score' => 0.5000
        ]);

        $this->scheduleBasicWorkouts($plan);

        return $plan;
    }

    private function scheduleWorkoutsWithML($plan, $recommendations, $behaviorPatterns, $workouts)
    {
        $workoutDays = explode(',', str_replace(['[', ']', '"'], '', $plan->workout_days));
        $startDate = Carbon::parse($plan->start_date);
        $endDate = Carbon::parse($plan->end_date);

        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dayName = strtolower($currentDate->format('l'));

            if (in_array($dayName, $workoutDays)) {
                $bestWorkout = $this->selectBestWorkoutForDay($recommendations, $workouts, $currentDate);

                if ($bestWorkout) {
                    WorkoutPlanSchedule::create([
                        'workout_plan_id' => $plan->workout_plan_id,
                        'workout_id' => $bestWorkout['workout_id'],
                        'scheduled_date' => $currentDate->toDateString(),
                        'scheduled_time' => $this->getOptimalTime($behaviorPatterns),
                        'ml_recommendation_score' => $bestWorkout['recommendation_score'],
                        'estimated_duration_minutes' => $bestWorkout['estimated_duration']
                    ]);

                    $plan->increment('total_planned_workouts');
                }
            }

            $currentDate->addDay();
        }
    }

    private function scheduleBasicWorkouts($plan)
    {
        $workoutDays = explode(',', $plan->workout_days);
        $startDate = Carbon::parse($plan->start_date);
        $endDate = Carbon::parse($plan->end_date);

        $currentDate = $startDate->copy();
        $defaultWorkoutId = 1;

        while ($currentDate->lte($endDate)) {
            $dayName = strtolower($currentDate->format('l'));

            if (in_array($dayName, $workoutDays)) {
                WorkoutPlanSchedule::create([
                    'workout_plan_id' => $plan->workout_plan_id,
                    'workout_id' => $defaultWorkoutId,
                    'scheduled_date' => $currentDate->toDateString(),
                    'scheduled_time' => '18:00:00',
                    'estimated_duration_minutes' => 30
                ]);

                $plan->increment('total_planned_workouts');
            }

            $currentDate->addDay();
        }
    }

    private function optimizeWorkoutDays($behaviorPatterns)
    {
        if (!$behaviorPatterns || !isset($behaviorPatterns['preferred_workout_days'])) {
            return implode(',', ['monday', 'wednesday', 'friday']);
        }

        return implode(',', $behaviorPatterns['preferred_workout_days']);
    }

    private function optimizeRestDays($behaviorPatterns)
    {
        $allDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $workoutDays = explode(',', $this->optimizeWorkoutDays($behaviorPatterns));
        $restDays = array_diff($allDays, $workoutDays);

        return implode(',', $restDays);
    }

    private function getOptimalTime($behaviorPatterns)
    {
        return $behaviorPatterns['most_active_time_of_day'] ?? '18:00:00';
    }

    private function selectBestWorkoutForDay($recommendations, $workouts, $date)
    {
        if (empty($recommendations) || empty($workouts)) return null;

        usort($recommendations, function($a, $b) {
            return $b['recommendation_score'] <=> $a['recommendation_score'];
        });

        return $recommendations[0] ?? null;
    }

    private function calculatePersonalizationScore($recommendations)
    {
        if (empty($recommendations)) return 0.0000;

        $totalScore = array_sum(array_column($recommendations, 'recommendation_score'));
        $avgScore = $totalScore / count($recommendations);

        return min(1.0000, max(0.0000, $avgScore));
    }

    public function adaptPlanBasedOnProgress($planId)
    {
        $plan = WorkoutPlan::find($planId);
        if (!$plan) {
            throw new \Exception('Plan not found');
        }

        $progressData = $this->externalApiService->getUserProgress($plan->user_id);
        $newRecommendations = $this->externalApiService->getMlRecommendations($plan->user_id);

        $adherenceRate = $this->calculateAdherenceRate($plan);

        if ($adherenceRate < 0.7) {
            $this->reducePlanDifficulty($plan);
        } elseif ($adherenceRate > 0.9) {
            $this->increasePlanDifficulty($plan);
        }

        if ($newRecommendations) {
            $this->updateUpcomingWorkouts($plan, $newRecommendations);
        }

        return [
            'plan_updated' => true,
            'adherence_rate' => $adherenceRate,
            'adjustments_made' => $this->getAdjustmentsSummary($plan)
        ];
    }

    private function calculateAdherenceRate($plan)
    {
        $scheduledWorkouts = WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)
            ->where('scheduled_date', '<=', now()->toDateString())
            ->count();

        $completedWorkouts = WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)
            ->where('is_completed', true)
            ->count();

        return $scheduledWorkouts > 0 ? $completedWorkouts / $scheduledWorkouts : 0;
    }

    private function reducePlanDifficulty($plan)
    {
        WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)
            ->where('scheduled_date', '>', now()->toDateString())
            ->update(['difficulty_adjustment' => 0.8]);
    }

    private function increasePlanDifficulty($plan)
    {
        WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)
            ->where('scheduled_date', '>', now()->toDateString())
            ->update(['difficulty_adjustment' => 1.2]);
    }

    private function updateUpcomingWorkouts($plan, $recommendations)
    {
        $upcomingWorkouts = WorkoutPlanSchedule::where('workout_plan_id', $plan->workout_plan_id)
            ->where('scheduled_date', '>', now()->toDateString())
            ->where('is_completed', false)
            ->get();

        foreach ($upcomingWorkouts as $workout) {
            $bestWorkout = $this->selectBestWorkoutForDay($recommendations, [], Carbon::parse($workout->scheduled_date));
            if ($bestWorkout) {
                $workout->update([
                    'workout_id' => $bestWorkout['workout_id'],
                    'ml_recommendation_score' => $bestWorkout['recommendation_score']
                ]);
            }
        }
    }

    private function getAdjustmentsSummary($plan)
    {
        return [
            'difficulty_adjusted' => true,
            'upcoming_workouts_updated' => true,
            'personalization_score_updated' => true
        ];
    }

    public function checkPlanMilestones($planId)
    {
        $plan = WorkoutPlan::find($planId);
        if (!$plan) return;

        $completionRate = $plan->completed_workouts / max($plan->total_planned_workouts, 1);

        $milestones = [];

        if ($completionRate >= 0.25) $milestones[] = 'quarter_complete';
        if ($completionRate >= 0.5) $milestones[] = 'half_complete';
        if ($completionRate >= 0.75) $milestones[] = 'three_quarters_complete';
        if ($completionRate >= 1.0) $milestones[] = 'plan_complete';

        foreach ($milestones as $milestone) {
            $this->triggerAchievement($plan->user_id, $milestone, $plan);
        }
    }

    private function triggerAchievement($userId, $milestone, $plan)
    {
        $data = [
            'user_id' => $userId,
            'milestone_type' => $milestone,
            'plan_id' => $plan->workout_plan_id,
            'plan_name' => $plan->plan_name,
            'completion_rate' => $plan->completed_workouts / max($plan->total_planned_workouts, 1)
        ];

        $this->externalApiService->notifyEngagementService($data);
    }

    public function sendWorkoutReminders($userId)
    {
        $upcomingWorkouts = WorkoutPlanSchedule::join('workout_plans', 'workout_plan_schedule.workout_plan_id', '=', 'workout_plans.workout_plan_id')
            ->where('workout_plans.user_id', $userId)
            ->where('workout_plans.is_active', true)
            ->where('workout_plan_schedule.scheduled_date', now()->toDateString())
            ->where('workout_plan_schedule.is_completed', false)
            ->select('workout_plan_schedule.*')
            ->get();

        foreach ($upcomingWorkouts as $workout) {
            $this->sendWorkoutNotification($userId, $workout);
        }
    }

    private function sendWorkoutNotification($userId, $workout)
    {
        $data = [
            'user_id' => $userId,
            'workout_name' => 'Scheduled Workout',
            'scheduled_time' => $workout->scheduled_time,
            'message_type' => 'workout_reminder'
        ];

        $this->externalApiService->sendWorkoutReminder($data);
    }
}