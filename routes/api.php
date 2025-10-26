<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkoutPlanController;
use App\Http\Controllers\WorkoutScheduleController;
use App\Http\Controllers\AIRecommendationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ServiceTestController;
use App\Http\Controllers\ServiceCommunicationTestController;
use App\Http\Controllers\ServiceIntegrationDemoController;
use App\Http\Controllers\WeeklyPlanController;

Route::get('/user', function (Request $request) {
    return $request->attributes->get('user');
})->middleware('auth.api');

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'fitnease-planning',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0'
    ]);
})->name('health.check');

Route::prefix('planning')->middleware('auth.api')->group(function () {

    // DEBUG ROUTE - Test if routes work at all
    Route::get('/test-simple', function() {
        return response()->json(['message' => 'Simple route works']);
    });

    // DEBUG ROUTE - Test WeeklyPlanController
    Route::get('/test-controller', function() {
        try {
            $controller = new \App\Http\Controllers\WeeklyPlanController();
            return response()->json(['message' => 'Controller instantiated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    // DEBUG ROUTE - Test getCurrentWeekPlan method directly
    Route::get('/test-get-current', function(Illuminate\Http\Request $request) {
        try {
            \Illuminate\Support\Facades\Log::info('[DEBUG] Testing getCurrentWeekPlan');
            $controller = new \App\Http\Controllers\WeeklyPlanController();
            $request->merge(['user_id' => 2040]);
            $result = $controller->getCurrentWeekPlan($request);
            \Illuminate\Support\Facades\Log::info('[DEBUG] Method executed successfully');
            return $result;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[DEBUG] Method failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    });

    // Plan Management Routes
    Route::get('/workout-plan/{userId}', [WorkoutPlanController::class, 'getPersonalizedPlan']);
    Route::post('/create-plan', [WorkoutPlanController::class, 'createPlan']);
    Route::put('/plan/{id}', [WorkoutPlanController::class, 'updatePlan']);
    Route::delete('/plan/{id}', [WorkoutPlanController::class, 'deletePlan']);
    Route::get('/plans/{userId}', [WorkoutPlanController::class, 'getUserPlans']);
    Route::put('/plan/{id}/customize', [WorkoutPlanController::class, 'customizePlan']);
    Route::post('/workout-plan-with-schedule', [WorkoutPlanController::class, 'createWorkoutPlanWithSchedule']);

    // Schedule Management Routes
    Route::post('/schedule', [WorkoutScheduleController::class, 'createSchedule']);
    Route::get('/schedule/{planId}', [WorkoutScheduleController::class, 'getPlanSchedule']);
    Route::put('/schedule/{scheduleId}', [WorkoutScheduleController::class, 'updateSchedule']);
    Route::post('/schedule/{scheduleId}/complete', [WorkoutScheduleController::class, 'completeWorkout']);
    Route::post('/schedule/{scheduleId}/skip', [WorkoutScheduleController::class, 'skipWorkout']);
    Route::post('/schedule/{scheduleId}/reschedule', [WorkoutScheduleController::class, 'rescheduleWorkout']);

    // User Schedule Views
    Route::get('/today-schedule/{userId}', [WorkoutScheduleController::class, 'getTodaySchedule']);
    Route::get('/upcoming-schedule/{userId}', [WorkoutScheduleController::class, 'getUpcomingSchedule']);
    Route::get('/overdue-workouts/{userId}', [WorkoutScheduleController::class, 'getOverdueWorkouts']);

    // AI-Generated Plans Routes
    Route::get('/ai-generated-plan/{userId}', [AIRecommendationController::class, 'generateAIPlan']);
    Route::post('/generate-smart-plan', [AIRecommendationController::class, 'generateSmartPlan']);
    Route::put('/plan/{id}/optimize', [AIRecommendationController::class, 'optimizePlan']);

    // ML Insights Routes
    Route::get('/ml-insights/{userId}', [AIRecommendationController::class, 'getMLInsights']);
    Route::get('/validate-ml-recommendations/{userId}', [AIRecommendationController::class, 'validateMLRecommendations']);

    // Plan Analytics Routes
    Route::get('/plan-progress/{planId}', [AnalyticsController::class, 'getPlanProgress']);
    Route::get('/plan-analytics/{planId}', [AnalyticsController::class, 'getPlanAnalytics']);
    Route::get('/adherence-report/{userId}', [AnalyticsController::class, 'getUserAdherenceReport']);
    Route::get('/completion-trends/{userId}', [AnalyticsController::class, 'getCompletionTrends']);
    Route::get('/workout-insights/{userId}', [AnalyticsController::class, 'getWorkoutInsights']);

    // Weekly Workout Plans Routes (Feature #4)
    // Using closure approach due to Laravel 12 routing issue - works identically
    Route::post('/weekly-plans/generate', function(Request $request) {
        $controller = new WeeklyPlanController();
        return $controller->generateWeeklyPlan($request);
    });

    Route::get('/weekly-plans/current', function(Request $request) {
        $controller = new WeeklyPlanController();
        return $controller->getCurrentWeekPlan($request);
    });

    Route::get('/weekly-plans/week/{date}', function($date, Request $request) {
        $controller = new WeeklyPlanController();
        return $controller->getWeekPlan($date, $request);
    });

    Route::post('/weekly-plans/{id}/complete-day', function($id, Request $request) {
        $controller = new WeeklyPlanController();
        return $controller->completeDayWorkout($id, $request);
    });

});

// Service Communication Testing Routes (Protected)
Route::middleware('auth.api')->group(function () {
    Route::get('/test-services', [ServiceTestController::class, 'testAllServices']);
    Route::get('/test-service/{serviceName}', [ServiceTestController::class, 'testSpecificService']);
    Route::get('/service-test/connectivity', [ServiceCommunicationTestController::class, 'testServiceConnectivity']);
    Route::get('/service-test/communications', [ServiceCommunicationTestController::class, 'testIncomingCommunications']);
    Route::get('/service-test/token-validation', [ServiceCommunicationTestController::class, 'testPlanningTokenValidation']);
});

// Service Integration Demo Routes (Public - No Auth Required)
Route::prefix('demo')->group(function () {
    Route::get('/integrations', [ServiceIntegrationDemoController::class, 'getServiceIntegrationOverview']);
    Route::get('/content-service', [ServiceIntegrationDemoController::class, 'demoContentServiceCall']);
    Route::get('/ml-service', [ServiceIntegrationDemoController::class, 'demoMLServiceCall']);
    Route::get('/tracking-service', [ServiceIntegrationDemoController::class, 'demoTrackingServiceCall']);
    Route::get('/engagement-service', [ServiceIntegrationDemoController::class, 'demoEngagementServiceCall']);
});
