<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WorkoutPlanController;
use App\Http\Controllers\WorkoutScheduleController;
use App\Http\Controllers\AIRecommendationController;
use App\Http\Controllers\AnalyticsController;

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

    // Plan Management Routes
    Route::get('/workout-plan/{userId}', [WorkoutPlanController::class, 'getPersonalizedPlan']);
    Route::post('/create-plan', [WorkoutPlanController::class, 'createPlan']);
    Route::put('/plan/{id}', [WorkoutPlanController::class, 'updatePlan']);
    Route::delete('/plan/{id}', [WorkoutPlanController::class, 'deletePlan']);
    Route::get('/plans/{userId}', [WorkoutPlanController::class, 'getUserPlans']);
    Route::put('/plan/{id}/customize', [WorkoutPlanController::class, 'customizePlan']);

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

});
