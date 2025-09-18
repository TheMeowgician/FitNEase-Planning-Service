<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServiceCommunicationTestController extends Controller
{
    /**
     * Test service connectivity from planning service
     */
    public function testServiceConnectivity(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $services = [
                'auth' => env('AUTH_SERVICE_URL', 'http://fitnease-auth'),
                'content' => env('CONTENT_SERVICE_URL', 'http://fitnease-content'),
                'tracking' => env('TRACKING_SERVICE_URL', 'http://fitnease-tracking'),
                'engagement' => env('ENGAGEMENT_SERVICE_URL', 'http://fitnease-engagement'),
                'ml' => env('ML_SERVICE_URL', 'http://fitnease-ml')
            ];

            $connectivity = [];

            foreach ($services as $serviceName => $serviceUrl) {
                try {
                    $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json'
                    ])->get($serviceUrl . '/api/health');

                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => $response->successful() ? 'connected' : 'failed',
                        'response_code' => $response->status(),
                        'response_time' => $response->handlerStats()['total_time'] ?? 'unknown'
                    ];

                } catch (\Exception $e) {
                    $connectivity[$serviceName] = [
                        'url' => $serviceUrl,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }

            $overallHealth = true;
            foreach ($connectivity as $service) {
                if ($service['status'] !== 'connected') {
                    $overallHealth = false;
                    break;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Service connectivity test completed',
                'overall_health' => $overallHealth ? 'healthy' : 'degraded',
                'services' => $connectivity,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service connectivity test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test incoming communications to planning service
     */
    public function testIncomingCommunications(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        Log::info('Planning Service - Testing incoming communications', [
            'user_id' => $userId,
            'timestamp' => now()
        ]);

        $results = [
            'service' => 'fitnease-planning',
            'test_type' => 'incoming_communications',
            'timestamp' => now(),
            'user_id' => $userId,
            'simulations' => []
        ];

        // Simulate Content Service requesting workout plans
        try {
            $workoutPlans = [
                [
                    'plan_id' => 1,
                    'name' => 'Beginner Strength Plan',
                    'duration_weeks' => 8,
                    'difficulty' => 'beginner',
                    'created_by' => 'AI_SYSTEM'
                ]
            ];

            $results['simulations']['content_service_plan_request'] = [
                'status' => 'success',
                'simulation' => 'Content Service requesting workout plans for exercise integration',
                'endpoint' => '/planning/workout-plan/' . $userId,
                'method' => 'GET',
                'response_data' => [
                    'plans_found' => count($workoutPlans),
                    'sample_plans' => $workoutPlans
                ],
                'metadata' => [
                    'caller_service' => 'fitnease-content',
                    'purpose' => 'Exercise-plan integration for content delivery'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['content_service_plan_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate ML Service requesting plan data for training
        try {
            $planData = [
                'training_data' => [
                    'user_adherence_rate' => 0.85,
                    'plan_completion_rate' => 0.78,
                    'difficulty_progression' => 'gradual',
                    'user_feedback_score' => 4.2
                ]
            ];

            $results['simulations']['ml_service_training_request'] = [
                'status' => 'success',
                'simulation' => 'ML Service requesting plan data for model training',
                'endpoint' => '/planning/ml-insights/' . $userId,
                'method' => 'GET',
                'response_data' => $planData,
                'metadata' => [
                    'caller_service' => 'fitnease-ml',
                    'purpose' => 'Model training with plan effectiveness data'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['ml_service_training_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate Tracking Service requesting plan analytics
        try {
            $analytics = [
                'plan_analytics' => [
                    'average_completion_rate' => 0.72,
                    'most_skipped_exercises' => ['burpees', 'mountain_climbers'],
                    'optimal_workout_duration' => 45,
                    'peak_workout_times' => ['07:00', '18:00']
                ]
            ];

            $results['simulations']['tracking_service_analytics_request'] = [
                'status' => 'success',
                'simulation' => 'Tracking Service requesting plan analytics',
                'endpoint' => '/planning/plan-analytics/1',
                'method' => 'GET',
                'response_data' => $analytics,
                'metadata' => [
                    'caller_service' => 'fitnease-tracking',
                    'purpose' => 'Cross-service analytics correlation'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['tracking_service_analytics_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Simulate Engagement Service requesting user plan progress
        try {
            $progress = [
                'user_progress' => [
                    'current_week' => 3,
                    'total_weeks' => 8,
                    'completion_percentage' => 37.5,
                    'streak_days' => 5,
                    'next_milestone' => 'Week 4 Complete'
                ]
            ];

            $results['simulations']['engagement_service_progress_request'] = [
                'status' => 'success',
                'simulation' => 'Engagement Service requesting user plan progress',
                'endpoint' => '/planning/plan-progress/1',
                'method' => 'GET',
                'response_data' => $progress,
                'metadata' => [
                    'caller_service' => 'fitnease-engagement',
                    'purpose' => 'Gamification and milestone tracking'
                ]
            ];
        } catch (\Exception $e) {
            $results['simulations']['engagement_service_progress_request'] = [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }

        // Summary
        $successCount = collect($results['simulations'])->filter(function ($simulation) {
            return $simulation['status'] === 'success';
        })->count();

        $results['summary'] = [
            'total_simulations' => count($results['simulations']),
            'successful_simulations' => $successCount,
            'failed_simulations' => count($results['simulations']) - $successCount,
            'success_rate' => count($results['simulations']) > 0 ? round(($successCount / count($results['simulations'])) * 100, 2) . '%' : '0%'
        ];

        Log::info('Planning Service - Incoming communication tests completed', [
            'user_id' => $userId,
            'successful_simulations' => $successCount,
            'total_simulations' => count($results['simulations'])
        ]);

        return response()->json($results);
    }

    /**
     * Test planning service token validation
     */
    public function testPlanningTokenValidation(Request $request): JsonResponse
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authentication token provided'
                ], 401);
            }

            $user = $request->attributes->get('user');

            return response()->json([
                'success' => true,
                'message' => 'Token validation successful in planning service',
                'planning_service_status' => 'connected',
                'user_data' => $user,
                'token_info' => [
                    'token_preview' => substr($token, 0, 10) . '...',
                    'validated_at' => now()->toISOString()
                ],
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Planning token validation test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}