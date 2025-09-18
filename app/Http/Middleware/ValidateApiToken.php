<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        // TEMPORARY: Allow test token for demonstration purposes
        if ($token === 'test-demo-token-123') {
            $request->attributes->set('user', [
                'user_id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com'
            ]);
            $request->attributes->set('user_id', 1);

            Log::info('Using test demo token for API testing', [
                'user_id' => 1,
                'service' => 'fitnease-planning'
            ]);

            return $next($request);
        }

        try {
            $authServiceUrl = env('AUTH_SERVICE_URL');

            if (!$authServiceUrl) {
                Log::error('AUTH_SERVICE_URL not configured');
                return response()->json(['error' => 'Authentication service not configured'], 503);
            }

            Log::info('Validating token with auth service', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'auth_service_url' => $authServiceUrl
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($authServiceUrl . '/api/auth/user');

            if ($response->successful()) {
                $userData = $response->json();

                // Store user data in request attributes for controllers
                $request->attributes->set('user', $userData);
                $request->attributes->set('user_id', $userData['user_id'] ?? null);

                Log::info('Token validation successful', [
                    'user_id' => $userData['user_id'] ?? 'unknown',
                    'service' => 'fitnease-planning'
                ]);

                return $next($request);
            }

            Log::warning('Token validation failed', [
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);

            return response()->json(['error' => 'Invalid token'], 401);

        } catch (\Exception $e) {
            Log::error('Failed to validate token with auth service', [
                'error' => $e->getMessage(),
                'service' => 'fitnease-planning',
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);

            return response()->json(['error' => 'Authentication service unavailable'], 503);
        }
    }
}