<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQAInspectorSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    public function rules(): array
    {
        return [
            'qa_inspector_enabled' => ['sometimes', 'boolean'],
            'qa_realtime_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_realtime_fallback_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_analysis_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_analysis_fallback_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_report_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_report_fallback_model' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+\/[a-z0-9.-]+$/i'],
            'qa_score_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'qa_sampling_rate' => ['sometimes', 'integer', 'between:1,100'],
            'qa_report_schedule' => ['sometimes', 'string', 'in:monday_00:00,monday_09:00,friday_18:00,sunday_00:00'],
            'qa_notifications' => ['sometimes', 'array'],
            'qa_notifications.email' => ['sometimes', 'boolean'],
            'qa_notifications.alert' => ['sometimes', 'boolean'],
            'qa_notifications.slack' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'qa_realtime_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_realtime_fallback_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_analysis_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_analysis_fallback_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_report_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_report_fallback_model.regex' => 'Model name must be in format: provider/model-name',
            'qa_score_threshold.between' => 'Score threshold must be between 0 and 1',
            'qa_sampling_rate.between' => 'Sampling rate must be between 1 and 100',
        ];
    }
}
