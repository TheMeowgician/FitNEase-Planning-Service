<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWorkoutPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer',
            'plan_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'workout_days' => 'nullable|array|max:7',
            'workout_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'rest_days' => 'nullable|array|max:7',
            'rest_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'plan_difficulty' => 'required|in:beginner,medium,expert',
            'target_duration_weeks' => 'nullable|integer|min:1|max:24'
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required',
            'user_id.integer' => 'User ID must be a valid integer',
            'plan_name.required' => 'Plan name is required',
            'plan_name.max' => 'Plan name cannot exceed 100 characters',
            'start_date.required' => 'Start date is required',
            'start_date.after_or_equal' => 'Start date cannot be in the past',
            'end_date.after_or_equal' => 'End date must be on or after start date',
            'workout_days.max' => 'Cannot select more than 7 workout days',
            'workout_days.*.in' => 'Invalid workout day selected',
            'rest_days.max' => 'Cannot select more than 7 rest days',
            'rest_days.*.in' => 'Invalid rest day selected',
            'plan_difficulty.required' => 'Plan difficulty is required',
            'plan_difficulty.in' => 'Plan difficulty must be beginner, medium, or expert',
            'target_duration_weeks.min' => 'Plan duration must be at least 1 week',
            'target_duration_weeks.max' => 'Plan duration cannot exceed 24 weeks'
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('workout_days') && is_array($this->workout_days)) {
            $this->merge([
                'workout_days' => array_unique($this->workout_days)
            ]);
        }

        if ($this->has('rest_days') && is_array($this->rest_days)) {
            $this->merge([
                'rest_days' => array_unique($this->rest_days)
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $workoutDays = $this->input('workout_days', []);
            $restDays = $this->input('rest_days', []);

            if (empty($workoutDays)) {
                $validator->errors()->add('workout_days', 'At least one workout day must be selected');
            }

            if (count($workoutDays) === 7) {
                $validator->errors()->add('workout_days', 'At least one rest day per week is required for safety');
            }

            $overlap = array_intersect($workoutDays, $restDays);
            if (!empty($overlap)) {
                $validator->errors()->add('workout_days', 'Days cannot be both workout days and rest days');
            }
        });
    }
}
