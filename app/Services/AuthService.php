<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('AUTH_SERVICE_URL', 'http://fitnease-auth');
    }

    /**
     * Get user profile information
     */
    public function getUserProfile(int $userId, string $token): ?array
    {
        try {
            Log::info('Planning Service - Requesting user profile from Auth Service', [
                'user_id' => $userId,
                'service' => 'fitnease-auth'
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/auth/user-profile/' . $userId);

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
                'error' => 'Failed to get user profile',
                'status_code' => $response->status(),
                'response_time' => $response->handlerStats()['total_time'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get user profile from Auth Service', [
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
     * Validate user access for planning operations
     */
    public function validateUserAccess(int $userId, string $token): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/auth/user-access/' . $userId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            }

            return [
                'success' => false,
                'error' => 'User access validation failed',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - User access validation failed', [
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
     * Get user analytics for planning insights
     */
    public function getUserAnalytics(string $startDate = null): ?array
    {
        try {
            $endpoint = '/api/auth/user-analytics';
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
                'error' => 'Failed to get user analytics',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get user analytics', [
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