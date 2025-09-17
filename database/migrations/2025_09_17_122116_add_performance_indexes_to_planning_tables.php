<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Additional performance indexes for workout_plan_schedule table
        Schema::table('workout_plan_schedule', function (Blueprint $table) {
            // Index for today's workouts queries
            $table->index(['scheduled_date', 'is_completed', 'skipped'], 'idx_schedule_today_workouts');

            // Index for overdue workouts
            $table->index(['scheduled_date', 'is_completed', 'skipped'], 'idx_schedule_overdue');

            // Index for ML recommendations sorting
            $table->index(['ml_recommendation_score', 'workout_plan_id'], 'idx_schedule_ml_recommendations');

            // Index for workout analytics by date range
            $table->index(['scheduled_date', 'workout_plan_id'], 'idx_schedule_date_plan');

            // Index for session tracking
            $table->index(['session_id'], 'idx_schedule_session');
        });

        // Additional performance indexes for workout_plans table
        Schema::table('workout_plans', function (Blueprint $table) {
            // Index for active ML-generated plans
            $table->index(['ml_generated', 'is_active', 'user_id'], 'idx_plans_ml_active');

            // Index for difficulty-based queries
            $table->index(['plan_difficulty', 'user_id'], 'idx_plans_difficulty');

            // Index for plan duration queries
            $table->index(['target_duration_weeks', 'is_completed'], 'idx_plans_duration');

            // Index for personalization score analytics
            $table->index(['personalization_score', 'ml_generated'], 'idx_plans_personalization');

            // Index for plan date range queries
            $table->index(['start_date', 'end_date'], 'idx_plans_date_range');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_plans', function (Blueprint $table) {
            $table->dropIndex('idx_plans_ml_active');
            $table->dropIndex('idx_plans_difficulty');
            $table->dropIndex('idx_plans_duration');
            $table->dropIndex('idx_plans_personalization');
            $table->dropIndex('idx_plans_date_range');
        });

        Schema::table('workout_plan_schedule', function (Blueprint $table) {
            $table->dropIndex('idx_schedule_today_workouts');
            $table->dropIndex('idx_schedule_overdue');
            $table->dropIndex('idx_schedule_ml_recommendations');
            $table->dropIndex('idx_schedule_date_plan');
            $table->dropIndex('idx_schedule_session');
        });
    }
};
