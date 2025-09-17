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
        Schema::create('workout_plans', function (Blueprint $table) {
            $table->id('workout_plan_id');
            $table->unsignedBigInteger('user_id');
            $table->string('plan_name', 100);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->set('workout_days', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->nullable();
            $table->set('rest_days', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_completed')->default(false);
            $table->integer('total_planned_workouts')->default(0);
            $table->integer('completed_workouts')->default(0);
            $table->boolean('ml_generated')->default(false);
            $table->decimal('personalization_score', 5, 4)->default(0.0000);
            $table->enum('plan_difficulty', ['beginner', 'medium', 'expert']);
            $table->integer('target_duration_weeks')->default(4);
            $table->integer('actual_duration_weeks')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active'], 'idx_workout_plans_user_active');
            $table->index(['user_id', 'is_completed', 'created_at'], 'idx_plans_completion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_plans');
    }
};
