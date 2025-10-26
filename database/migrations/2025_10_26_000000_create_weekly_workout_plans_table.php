<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Feature #4: Weekly Workout Plans with Preferred Days
     * This table stores auto-generated weekly workout plans based on user preferences.
     * Each plan covers one week (Monday-Sunday) and contains daily workout details.
     */
    public function up(): void
    {
        Schema::create('weekly_workout_plans', function (Blueprint $table) {
            // Primary key
            $table->id('plan_id');

            // Foreign key to users table (from auth service)
            $table->unsignedBigInteger('user_id');

            // Week date range
            $table->date('week_start_date'); // Monday of the week
            $table->date('week_end_date');   // Sunday of the week

            // Plan status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_current_week')->default(false);

            // Plan data - JSON structure containing daily workouts
            // Format: { "monday": {...}, "tuesday": {...}, ... }
            $table->json('plan_data');

            // Metadata
            $table->integer('total_workout_days')->default(0);
            $table->integer('total_rest_days')->default(0);
            $table->integer('total_exercises')->default(0);
            $table->integer('estimated_weekly_duration')->default(0); // in minutes
            $table->integer('estimated_weekly_calories')->default(0);

            // ML generation metadata
            $table->boolean('ml_generated')->default(true);
            $table->decimal('ml_confidence_score', 5, 4)->nullable();
            $table->string('generation_method', 50)->default('ml_auto'); // ml_auto, manual, template

            // User preferences snapshot (for regeneration reference)
            $table->json('user_preferences_snapshot')->nullable();

            // Tracking
            $table->integer('workouts_completed')->default(0);
            $table->integer('workouts_skipped')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0.00); // percentage

            // Timestamps
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // Indexes for performance
            $table->index(['user_id', 'week_start_date'], 'idx_weekly_plans_user_week');
            $table->index(['user_id', 'is_current_week'], 'idx_weekly_plans_current');
            $table->index(['user_id', 'is_active'], 'idx_weekly_plans_active');
            $table->index(['week_start_date', 'week_end_date'], 'idx_weekly_plans_date_range');

            // Unique constraint: one plan per user per week
            $table->unique(['user_id', 'week_start_date'], 'unique_user_week_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weekly_workout_plans');
    }
};
