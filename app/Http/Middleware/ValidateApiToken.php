<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            // Call auth service to validate token
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ])->get(env('AUTH_SERVICE_URL') . '/api/auth/user');

            if ($response->failed()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            // Store user data in request for later use
            $userData = $response->json();
            $request->attributes->set('user', $userData);
            $request->attributes->set('user_id', $userData['user_id']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Authentication service unavailable.'], 503);
        }

        return $next($request);
    }
}
