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
            'primary_chat_model' => ['nullable', 'string', 'max:100', 'not_regex:/-decisions$/'],
            'fallback_chat_model' => ['nullable', 'string', 'max:100', 'not_regex:/-decisions$/'],
            'utility_model' => ['nullable', 'string', 'max:100', 'not_regex:/-decisions$/'],
            'reasoning_effort' => ['nullable', 'in:low,medium,high'],

            // Support Router (Luna Decisions)
            'support_router_mode' => ['sometimes', 'in:off,shadow,on'],
            'support_router_model' => [
                'nullable', 'string', 'max:100', 'regex:/-decisions$/',
                'required_if:support_router_mode,shadow,on',
            ],
            'support_handover_message' => [
                'nullable', 'string', 'max:2000',
                'required_if:support_router_mode,on',
            ],

            // Auto handover
            'auto_handover' => ['sometimes', 'boolean'],

            // Auto account delivery
            'auto_delivery_enabled' => ['sometimes', 'boolean'],

            // LLM Settings
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

    public function messages(): array
    {
        return [
            'primary_chat_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'fallback_chat_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'utility_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'support_router_model.regex' => 'ช่องนี้ต้องเป็นโมเดลประเภท Decisions (ชื่อลงท้าย -decisions)',
        ];
    }
}
