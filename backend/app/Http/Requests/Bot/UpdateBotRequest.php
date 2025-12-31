<?php

namespace App\Http\Requests\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'paused'])],
            'channel_type' => ['sometimes', Rule::in(['line', 'facebook', 'telegram', 'testing', 'demo'])],
            'channel_access_token' => ['nullable', 'string'],
            'channel_secret' => ['nullable', 'string'],
            'page_id' => ['nullable', 'string'],
            'default_flow_id' => ['nullable', 'exists:flows,id'],

            // Multi-model LLM configuration (API key now in User Settings)
            'primary_chat_model' => ['nullable', 'string', 'max:100'],
            'fallback_chat_model' => ['nullable', 'string', 'max:100'],
            'decision_model' => ['nullable', 'string', 'max:100'],
            'fallback_decision_model' => ['nullable', 'string', 'max:100'],

            // Webhook forwarder
            'webhook_forwarder_enabled' => ['sometimes', 'boolean'],

            // Auto handover
            'auto_handover' => ['sometimes', 'boolean'],

            // LLM Settings (legacy)
            'llm_model' => ['sometimes', 'string', 'max:100'],
            'llm_fallback_model' => ['sometimes', 'string', 'max:100'],
            'system_prompt' => ['nullable', 'string', 'max:50000'],
            'llm_temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'llm_max_tokens' => ['sometimes', 'integer', 'min:100', 'max:8192'],
            'context_window' => ['sometimes', 'integer', 'min:1', 'max:50'],

            // Knowledge Base (RAG) Settings
            'kb_enabled' => ['sometimes', 'boolean'],
            'kb_relevance_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'kb_max_results' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
