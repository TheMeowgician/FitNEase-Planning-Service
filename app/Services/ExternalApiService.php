<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ExternalApiService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false
        ]);
    }

    public function getUserProfile($userId)
    {
        try {
            $response = $this->client->get(env('AUTH_SERVICE_URL') . '/auth/user-profile/' . $userId);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to get user profile', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function validateUser($token)
    {
        try {
            $response = $this->client->get(env('AUTH_SERVICE_URL') . '/auth/validate', [
                'headers' => ['Authorization' => 'Bearer ' . $token]
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to validate user', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getMlRecommendations($userId)
    {
        try {
            $response = $this->client->get(env('ML_SERVICE_URL') . '/api/v1/recommendations/' . $userId);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to get ML recommendations', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getUserBehaviorPatterns($userId)
    {
        try {
            $response = $this->client->get(env('ML_SERVICE_URL') . '/api/v1/user-patterns/' . $userId);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to get user behavior patterns', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getWorkoutsByCriteria($criteria)
    {
        try {
            $response = $this->client->get(env('CONTENT_SERVICE_URL') . '/content/workouts/criteria', [
                'query' => $criteria
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to get workouts by criteria', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getUserProgress($userId)
    {
        try {
            $response = $this->client->get(env('TRACKING_SERVICE_URL') . '/tracking/progress/' . $userId);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to get user progress', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function sendWorkoutReminder($data)
    {
        try {
            $response = $this->client->post(env('COMMS_SERVICE_URL') . '/comms/workout-reminder', [
                'json' => $data
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to send workout reminder', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function notifyEngagementService($data)
    {
        try {
            $response = $this->client->post(env('ENGAGEMENT_SERVICE_URL') . '/engagement/milestone-achieved', [
                'json' => $data
            ]);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            Log::error('Failed to notify engagement service', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}