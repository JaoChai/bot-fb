<?php

namespace App\Http\Requests\Flow;

use Illuminate\Foundation\Http\FormRequest;

class StoreFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string', 'max:10000'],
            'model' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:128000'],
            'agentic_mode' => ['nullable', 'boolean'],
            'max_tool_calls' => ['nullable', 'integer', 'min:1', 'max:20'],
            'enabled_tools' => ['nullable', 'array'],
            'enabled_tools.*' => ['string', 'max:100'],
            'knowledge_base_id' => ['nullable', 'exists:knowledge_bases,id'],
            'kb_top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'kb_similarity_threshold' => ['nullable', 'numeric', 'between:0,1'],
            'language' => ['nullable', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Flow name is required',
            'system_prompt.required' => 'System prompt is required',
            'temperature.between' => 'Temperature must be between 0 and 2',
            'kb_similarity_threshold.between' => 'Similarity threshold must be between 0 and 1',
        ];
    }
}
