<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('CONTENT_SERVICE_URL', 'http://fitnease-content');
    }

    /**
     * Get exercise details for workout plans
     */
    public function getExercise(string $token, int $exerciseId): ?array
    {
        try {
            Log::info('Planning Service - Requesting exercise from Content Service', [
                'exercise_id' => $exerciseId,
                'service' => 'fitnease-content'
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/content/exercises/' . $exerciseId);

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
                'error' => 'Failed to get exercise details',
                'status_code' => $response->status(),
                'response_time' => $response->handlerStats()['total_time'] ?? 0
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get exercise from Content Service', [
                'error' => $e->getMessage(),
                'exercise_id' => $exerciseId
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
     * Get workout templates for plan creation
     */
    public function getWorkoutTemplates(string $token, array $criteria = []): ?array
    {
        try {
            $queryParams = http_build_query($criteria);
            $endpoint = '/api/content/workout-templates';
            if ($queryParams) {
                $endpoint .= '?' . $queryParams;
            }

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
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
                'error' => 'Failed to get workout templates',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to get workout templates', [
                'error' => $e->getMessage(),
                'criteria' => $criteria
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 500
            ];
        }
    }

    /**
     * Notify content service about new workout plan creation
     */
    public function notifyPlanCreation(string $token, int $planId, array $planData): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/content/plans/' . $planId . '/notify', [
                'plan_data' => $planData,
                'notification_type' => 'plan_created',
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
                'error' => 'Failed to notify plan creation',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Planning Service - Failed to notify plan creation', [
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