<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MLService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('ML_SERVICE_URL', 'http://fitnease-ml');
    }

    /**
     * Get AI recommendations for workout plans
     */
    public function getRecommendations(string $token, int $userId): ?array
    {
        try {
            Log::info('Planning Service - Requesting AI recommendations from ML Service', [
                'user_id' => $userId,
                'service' => 'fitnease-ml'
            ]);

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/v1/recommendations/' . $userId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status(),
                    'response_time' => $response->handlerStats()['total_time'] ?? 0
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to get ML recommendations',
                'status_code' => $response->status(),
                'response_time' => $response->handlerStats()['total_time'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get ML recommendations', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500,
                'response_time' => 0
            ];
        }
    }

    /**
     * Get user behavior patterns for plan optimization
     */
    public function getUserBehaviorPatterns(string $token, int $userId): ?array
    {
        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/v1/user-patterns/' . $userId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to get user behavior patterns',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get user behavior patterns', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Get model health status
     */
    public function getModelHealth(): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/v1/model-health');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'ML service health check failed',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - ML service health check failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Send plan data for ML training
     */
    public function sendPlanData(string $token, array $planData): ?array
    {
        try {
            $response = Http::timeout(15)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/v1/training-data/plans', [
                'plan_data' => $planData,
                'source_service' => 'fitnease-planning'
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to send plan data to ML service',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to send plan data to ML service', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }
}