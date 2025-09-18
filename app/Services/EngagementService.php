<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EngagementService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('ENGAGEMENT_SERVICE_URL', 'http://fitnease-engagement');
    }

    /**
     * Get user engagement metrics for plan personalization
     */
    public function getUserEngagement(string $token, int $userId): ?array
    {
        try {
            Log::info('Planning Service - Requesting user engagement from Engagement Service', [
                'user_id' => $userId,
                'service' => 'fitnease-engagement'
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/engagement/users/' . $userId . '/metrics');

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
                'error' => 'Failed to get user engagement',
                'status_code' => $response->status(),
                'response_time' => $response->handlerStats()['total_time'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get user engagement', [
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
     * Get engagement analytics for planning insights
     */
    public function getEngagementAnalytics(string $startDate = null): ?array
    {
        try {
            $endpoint = '/api/engagement/analytics';
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
                'error' => 'Failed to get engagement analytics',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get engagement analytics', [
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
     * Notify engagement service about plan milestones
     */
    public function notifyPlanMilestone(string $token, int $userId, array $milestoneData): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/engagement/milestones', [
                'user_id' => $userId,
                'milestone_data' => $milestoneData,
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
                'error' => 'Failed to notify plan milestone',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to notify plan milestone', [
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
}