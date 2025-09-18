<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AuthService;
use App\Services\ContentService;
use App\Services\TrackingService;
use App\Services\MLService;
use App\Services\EngagementService;
use Illuminate\Support\Facades\Log;

class ServiceTestController extends Controller
{
    /**
     * Test all service communications from planning service
     */
    public function testAllServices(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $token = $request->bearerToken();

        Log::info('Planning Service - Testing all service communications', [
            'user_id' => $userId,
            'timestamp' => now()
        ]);

        $results = [
            'service' => 'fitnease-planning',
            'timestamp' => now(),
            'user_id' => $userId,
            'tests' => []
        ];

        // Test Auth Service
        try {
            $authService = new AuthService();
            $userProfile = $authService->getUserProfile($userId, $token);

            $results['tests']['auth_service'] = [
                'status' => $userProfile && $userProfile['success'] ? 'success' : 'failed',
                'test' => 'getUserProfile(' . $userId . ')',
                'response' => $userProfile ? 'User profile retrieved' : 'No data returned',
                'data' => $userProfile
            ];
        } catch (\Exception $e) {
            $results['tests']['auth_service'] = [
                'status' => 'error',
                'test' => 'getUserProfile(' . $userId . ')',
                'error' => $e->getMessage()
            ];
        }

        // Test Content Service
        try {
            $contentService = new ContentService();
            $exercise = $contentService->getExercise($token, 1);

            $results['tests']['content_service'] = [
                'status' => $exercise && $exercise['success'] ? 'success' : 'failed',
                'test' => 'getExercise(1)',
                'response' => $exercise ? 'Exercise data retrieved' : 'No data returned',
                'data' => $exercise
            ];
        } catch (\Exception $e) {
            $results['tests']['content_service'] = [
                'status' => 'error',
                'test' => 'getExercise(1)',
                'error' => $e->getMessage()
            ];
        }

        // Test Tracking Service
        try {
            $trackingService = new TrackingService();
            $progress = $trackingService->getUserProgress($token, $userId);

            $results['tests']['tracking_service'] = [
                'status' => $progress && $progress['success'] ? 'success' : 'failed',
                'test' => 'getUserProgress(' . $userId . ')',
                'response' => $progress ? 'User progress retrieved' : 'No data returned',
                'data' => $progress
            ];
        } catch (\Exception $e) {
            $results['tests']['tracking_service'] = [
                'status' => 'error',
                'test' => 'getUserProgress(' . $userId . ')',
                'error' => $e->getMessage()
            ];
        }

        // Test ML Service
        try {
            $mlService = new MLService();
            $recommendations = $mlService->getRecommendations($token, $userId);

            $results['tests']['ml_service'] = [
                'status' => $recommendations && $recommendations['success'] ? 'success' : 'failed',
                'test' => 'getRecommendations(' . $userId . ')',
                'response' => $recommendations ? 'ML recommendations retrieved' : 'No data returned',
                'data' => $recommendations
            ];
        } catch (\Exception $e) {
            $results['tests']['ml_service'] = [
                'status' => 'error',
                'test' => 'getRecommendations(' . $userId . ')',
                'error' => $e->getMessage()
            ];
        }

        // Test Engagement Service
        try {
            $engagementService = new EngagementService();
            $engagement = $engagementService->getUserEngagement($token, $userId);

            $results['tests']['engagement_service'] = [
                'status' => $engagement && $engagement['success'] ? 'success' : 'failed',
                'test' => 'getUserEngagement(' . $userId . ')',
                'response' => $engagement ? 'User engagement retrieved' : 'No data returned',
                'data' => $engagement
            ];
        } catch (\Exception $e) {
            $results['tests']['engagement_service'] = [
                'status' => 'error',
                'test' => 'getUserEngagement(' . $userId . ')',
                'error' => $e->getMessage()
            ];
        }

        // Summary
        $successCount = collect($results['tests'])->filter(function ($test) {
            return $test['status'] === 'success';
        })->count();

        $results['summary'] = [
            'total_tests' => count($results['tests']),
            'successful_tests' => $successCount,
            'failed_tests' => count($results['tests']) - $successCount,
            'success_rate' => count($results['tests']) > 0 ? round(($successCount / count($results['tests'])) * 100, 2) . '%' : '0%'
        ];

        Log::info('Planning Service - Service communication tests completed', [
            'user_id' => $userId,
            'successful_tests' => $successCount,
            'total_tests' => count($results['tests'])
        ]);

        return response()->json($results);
    }

    /**
     * Test specific service communication
     */
    public function testSpecificService(Request $request, $serviceName): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $token = $request->bearerToken();

        Log::info("Planning Service - Testing specific service: {$serviceName}", [
            'user_id' => $userId,
            'service' => $serviceName
        ]);

        $result = [
            'service' => 'fitnease-planning',
            'target_service' => $serviceName,
            'timestamp' => now(),
            'user_id' => $userId
        ];

        try {
            switch ($serviceName) {
                case 'auth':
                    $authService = new AuthService();
                    $data = $authService->getUserProfile($userId, $token);
                    $result['status'] = $data && $data['success'] ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'content':
                    $contentService = new ContentService();
                    $data = $contentService->getWorkoutTemplates($token, ['difficulty' => 'beginner']);
                    $result['status'] = $data && $data['success'] ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'tracking':
                    $trackingService = new TrackingService();
                    $data = $trackingService->getCompletionRates();
                    $result['status'] = $data && $data['success'] ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'ml':
                    $mlService = new MLService();
                    $data = $mlService->getModelHealth();
                    $result['status'] = $data && $data['success'] ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                case 'engagement':
                    $engagementService = new EngagementService();
                    $data = $engagementService->getEngagementAnalytics(now()->subDays(7)->toDateString());
                    $result['status'] = $data && $data['success'] ? 'success' : 'failed';
                    $result['data'] = $data;
                    break;

                default:
                    $result['status'] = 'error';
                    $result['error'] = 'Unknown service: ' . $serviceName;
            }
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();

            Log::error("Planning Service - Error testing service: {$serviceName}", [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
        }

        return response()->json($result);
    }
}