<?php

namespace App\Http\Controllers;

use App\Models\WorkoutPlan;
use App\Services\WorkoutPlanService;
use App\Services\ExternalApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AIRecommendationController extends Controller
{
    protected $workoutPlanService;
    protected $externalApiService;

    public function __construct(WorkoutPlanService $workoutPlanService, ExternalApiService $externalApiService)
    {
        $this->workoutPlanService = $workoutPlanService;
        $this->externalApiService = $externalApiService;
    }

    public function generateAIPlan($userId): JsonResponse
    {
        try {
            $userProfile = $this->externalApiService->getUserProfile($userId);
            if (!$userProfile) {
                return response()->json([
                    'message' => 'Unable to retrieve user profile for AI plan generation',
                    'data' => null
                ], 400);
            }

            $recommendations = $this->externalApiService->getMlRecommendations($userId);
            if (!$recommendations) {
                return response()->json([
                    'message' => 'ML service unavailable. Please try again later.',
                    'data' => null
                ], 503);
            }

            $plan = $this->workoutPlanService->generatePersonalizedPlan($userId);

            return response()->json([
                'message' => 'AI-generated plan created successfully',
                'data' => $plan,
                'ml_confidence' => $plan->personalization_score,
                'recommendations_count' => count($recommendations)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate AI plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateSmartPlan(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer',
                'preferences' => 'nullable|array',
                'goals' => 'nullable|array',
                'constraints' => 'nullable|array',
                'duration_weeks' => 'nullable|integer|min:1|max:24'
            ]);

            $userId = $validated['user_id'];

            $recommendations = $this->externalApiService->getMlRecommendations($userId);
            if (!$recommendations) {
                return response()->json([
                    'message' => 'ML recommendations not available',
                    'fallback' => 'rule_based_plan'
                ], 503);
            }

            $confidenceScore = $this->calculateConfidenceScore($recommendations);

            if ($confidenceScore < 0.6) {
                return response()->json([
                    'message' => 'Insufficient data for ML recommendations',
                    'confidence_score' => $confidenceScore,
                    'recommendation' => 'Complete more workouts for better AI recommendations'
                ], 400);
            }

            $plan = $this->workoutPlanService->generatePersonalizedPlan($userId);

            $this->applyUserPreferences($plan, $validated);

            return response()->json([
                'message' => 'Smart plan generated successfully',
                'data' => $plan,
                'confidence_score' => $confidenceScore,
                'personalization_level' => $this->getPersonalizationLevel($confidenceScore)
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate smart plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function optimizePlan($planId): JsonResponse
    {
        try {
            $plan = WorkoutPlan::findOrFail($planId);

            $optimizationResult = $this->workoutPlanService->adaptPlanBasedOnProgress($planId);

            return response()->json([
                'message' => 'Plan optimized successfully',
                'data' => $plan->fresh(),
                'optimization_result' => $optimizationResult,
                'adherence_rate' => $optimizationResult['adherence_rate'],
                'adjustments' => $optimizationResult['adjustments_made']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to optimize plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMLInsights($userId): JsonResponse
    {
        try {
            $recommendations = $this->externalApiService->getMlRecommendations($userId);
            $behaviorPatterns = $this->externalApiService->getUserBehaviorPatterns($userId);

            if (!$recommendations || !$behaviorPatterns) {
                return response()->json([
                    'message' => 'ML insights not available',
                    'data' => null
                ], 503);
            }

            $insights = [
                'workout_preferences' => $this->extractWorkoutPreferences($recommendations),
                'optimal_timing' => $behaviorPatterns['most_active_time_of_day'] ?? 'Not available',
                'preferred_days' => $behaviorPatterns['preferred_workout_days'] ?? [],
                'adherence_prediction' => $this->predictAdherence($behaviorPatterns),
                'recommended_difficulty' => $this->recommendDifficulty($recommendations),
                'personalization_score' => $this->calculateConfidenceScore($recommendations)
            ];

            return response()->json([
                'message' => 'ML insights retrieved successfully',
                'data' => $insights
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve ML insights',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function validateMLRecommendations($userId): JsonResponse
    {
        try {
            $recommendations = $this->externalApiService->getMlRecommendations($userId);

            if (!$recommendations) {
                return response()->json([
                    'message' => 'No ML recommendations available',
                    'status' => 'unavailable'
                ]);
            }

            $confidenceScore = $this->calculateConfidenceScore($recommendations);
            $isValid = $confidenceScore > 0.6;

            return response()->json([
                'message' => 'ML recommendations validation completed',
                'status' => $isValid ? 'valid' : 'insufficient_data',
                'confidence_score' => $confidenceScore,
                'recommendations_count' => count($recommendations),
                'can_generate_ai_plan' => $isValid
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to validate ML recommendations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function calculateConfidenceScore($recommendations): float
    {
        if (empty($recommendations)) return 0.0;

        $totalScore = array_sum(array_column($recommendations, 'recommendation_score'));
        $avgScore = $totalScore / count($recommendations);

        return min(1.0, max(0.0, $avgScore));
    }

    private function applyUserPreferences($plan, $validated): void
    {
        if (isset($validated['duration_weeks'])) {
            $plan->update([
                'target_duration_weeks' => $validated['duration_weeks'],
                'end_date' => \Carbon\Carbon::parse($plan->start_date)
                    ->addWeeks($validated['duration_weeks'])->toDateString()
            ]);
        }
    }

    private function getPersonalizationLevel($confidenceScore): string
    {
        if ($confidenceScore >= 0.9) return 'High';
        if ($confidenceScore >= 0.7) return 'Medium';
        if ($confidenceScore >= 0.5) return 'Low';
        return 'Minimal';
    }

    private function extractWorkoutPreferences($recommendations): array
    {
        return [
            'top_workout_types' => array_slice(array_column($recommendations, 'workout_type'), 0, 3),
            'preferred_intensity' => $this->getPreferredIntensity($recommendations),
            'recommended_frequency' => $this->getRecommendedFrequency($recommendations)
        ];
    }

    private function predictAdherence($behaviorPatterns): array
    {
        $adherenceScore = $behaviorPatterns['historical_adherence'] ?? 0.7;

        return [
            'predicted_rate' => $adherenceScore,
            'risk_level' => $adherenceScore < 0.6 ? 'High' : ($adherenceScore < 0.8 ? 'Medium' : 'Low'),
            'suggestions' => $this->getAdherenceSuggestions($adherenceScore)
        ];
    }

    private function recommendDifficulty($recommendations): string
    {
        $avgDifficulty = array_sum(array_column($recommendations, 'difficulty_score')) / count($recommendations);

        if ($avgDifficulty >= 0.7) return 'expert';
        if ($avgDifficulty >= 0.4) return 'medium';
        return 'beginner';
    }

    private function getPreferredIntensity($recommendations): string
    {
        $intensityScores = array_column($recommendations, 'intensity_preference');
        $avgIntensity = array_sum($intensityScores) / count($intensityScores);

        if ($avgIntensity >= 0.7) return 'High';
        if ($avgIntensity >= 0.4) return 'Medium';
        return 'Low';
    }

    private function getRecommendedFrequency($recommendations): int
    {
        return (int) (array_sum(array_column($recommendations, 'frequency_score')) / count($recommendations) * 7);
    }

    private function getAdherenceSuggestions($adherenceScore): array
    {
        if ($adherenceScore < 0.6) {
            return [
                'Start with shorter, easier workouts',
                'Set realistic goals',
                'Consider working out with a friend'
            ];
        }

        if ($adherenceScore < 0.8) {
            return [
                'Try varying your workout routine',
                'Set weekly challenges',
                'Track your progress more closely'
            ];
        }

        return [
            'Consider increasing workout intensity',
            'Try advanced workout variations',
            'Set longer-term fitness goals'
        ];
    }
}
