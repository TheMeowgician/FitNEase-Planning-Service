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
        Schema::create('workout_plan_schedule', function (Blueprint $table) {
            $table->id('schedule_id');
            $table->unsignedBigInteger('workout_plan_id');
            $table->unsignedBigInteger('workout_id');
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->decimal('ml_recommendation_score', 5, 4)->nullable();
            $table->decimal('difficulty_adjustment', 3, 2)->default(1.00);
            $table->integer('estimated_duration_minutes')->nullable();
            $table->integer('actual_duration_minutes')->nullable();
            $table->boolean('skipped')->default(false);
            $table->text('skip_reason')->nullable();
            $table->date('rescheduled_from')->nullable();
            $table->timestamps();

            $table->unique(['workout_plan_id', 'scheduled_date']);
            $table->index(['workout_plan_id', 'scheduled_date'], 'idx_schedule_plan_date');
            $table->index(['workout_plan_id', 'scheduled_date', 'is_completed'], 'idx_schedule_user_date');
            $table->index(['ml_recommendation_score', 'scheduled_date'], 'idx_schedule_ml_score');
            $table->index(['workout_plan_id', 'is_completed', 'scheduled_date'], 'idx_schedule_completion_stats');

            $table->foreign('workout_plan_id')->references('workout_plan_id')->on('workout_plans')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_plan_schedule');
    }
};
