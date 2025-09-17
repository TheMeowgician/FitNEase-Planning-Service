<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workout_plan_id' => 'required|integer|exists:workout_plans,workout_plan_id',
            'workout_id' => 'required|integer',
            'scheduled_date' => 'required|date|after_or_equal:today',
            'scheduled_time' => 'nullable|date_format:H:i:s',
            'estimated_duration_minutes' => 'nullable|integer|min:5|max:180',
            'ml_recommendation_score' => 'nullable|numeric|between:0,1',
            'difficulty_adjustment' => 'nullable|numeric|between:0.1,3.0'
        ];
    }

    public function messages(): array
    {
        return [
            'workout_plan_id.required' => 'Workout plan ID is required',
            'workout_plan_id.exists' => 'Selected workout plan does not exist',
            'workout_id.required' => 'Workout ID is required',
            'scheduled_date.required' => 'Scheduled date is required',
            'scheduled_date.after_or_equal' => 'Cannot schedule workouts in the past',
            'scheduled_time.date_format' => 'Time must be in HH:MM:SS format',
            'estimated_duration_minutes.min' => 'Workout duration must be at least 5 minutes',
            'estimated_duration_minutes.max' => 'Workout duration cannot exceed 180 minutes',
            'ml_recommendation_score.between' => 'ML score must be between 0 and 1',
            'difficulty_adjustment.between' => 'Difficulty adjustment must be between 0.1 and 3.0'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $workoutPlanId = $this->input('workout_plan_id');
            $scheduledDate = $this->input('scheduled_date');

            $existingSchedule = \App\Models\WorkoutPlanSchedule::where('workout_plan_id', $workoutPlanId)
                ->where('scheduled_date', $scheduledDate)
                ->first();

            if ($existingSchedule) {
                $validator->errors()->add('scheduled_date', 'A workout is already scheduled for this date in this plan');
            }
        });
    }
}
