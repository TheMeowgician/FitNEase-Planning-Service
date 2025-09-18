<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('TRACKING_SERVICE_URL', 'http://fitnease-tracking');
    }

    /**
     * Get user progress data for planning insights
     */
    public function getUserProgress(string $token, int $userId): ?array
    {
        try {
            Log::info('Planning Service - Requesting user progress from Tracking Service', [
                'user_id' => $userId,
                'service' => 'fitnease-tracking'
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/tracking/user/' . $userId . '/progress');

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
                'error' => 'Failed to get user progress',
                'status_code' => $response->status(),
                'response_time' => $response->handlerStats()['total_time'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get user progress from Tracking Service', [
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
     * Get workout analytics for plan optimization
     */
    public function getWorkoutAnalytics(string $startDate = null): ?array
    {
        try {
            $endpoint = '/api/tracking/analytics';
            if ($startDate) {
                $endpoint .= '?start_date=' . $startDate;
            }

            $response = Http::timeout(10)->withHeaders([
                'Accept' => 'application/json',
            ])->get($this->baseUrl . $endpoint);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to get workout analytics',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get workout analytics', [
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
     * Get completion rates for plan effectiveness
     */
    public function getCompletionRates(): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/tracking/completion-rates');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to get completion rates',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get completion rates', [
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
     * Notify tracking service about new workout plan
     */
    public function notifyWorkoutPlan(string $token, int $userId, int $planId, array $planData): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/tracking/plans/' . $planId, [
                'user_id' => $userId,
                'plan_data' => $planData,
                'notification_type' => 'new_plan',
                'service' => 'fitnease-planning'
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
                'error' => 'Failed to notify workout plan',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to notify workout plan', [
                'error' => $e->getMessage(),
                'plan_id' => $planId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }
}