<?php

namespace App\Http\Requests\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBotRequest extends FormRequest
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
            'channel_type' => ['required', Rule::in(['line', 'facebook', 'telegram', 'testing', 'demo'])],
            'channel_access_token' => ['nullable', 'string'],
            'channel_secret' => ['nullable', 'string'],
            'page_id' => ['nullable', 'string'],

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
            'auto_handover' => ['nullable', 'boolean'],

            // Auto account delivery
            'auto_delivery_enabled' => ['nullable', 'boolean'],

            // Support nested api_keys format
            'api_keys' => ['nullable', 'array'],
            'api_keys.channel_access_token' => ['nullable', 'string'],
            'api_keys.channel_secret' => ['nullable', 'string'],
        ];
    }

    /**
     * Prepare the data for validation.
     * Extract api_keys into top-level fields if provided.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('api_keys') && is_array($this->api_keys)) {
            $apiKeys = $this->api_keys;

            // Merge api_keys values into top-level if not already set
            $this->merge([
                'channel_access_token' => $this->channel_access_token ?? ($apiKeys['channel_access_token'] ?? null),
                'channel_secret' => $this->channel_secret ?? ($apiKeys['channel_secret'] ?? null),
            ]);
        }
    }

    /**
     * Get the validated data, excluding api_keys wrapper.
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            unset($validated['api_keys']);
        }

        return $validated;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'channel_type.required' => 'Channel type is required',
            'channel_type.in' => 'Channel type must be line, facebook, or telegram',

            'primary_chat_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'fallback_chat_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'utility_model.not_regex' => 'โมเดลประเภท Decisions ใช้ตอบแชทไม่ได้ ใช้ได้เฉพาะช่องคัดแยกส่ง Support',
            'support_router_model.regex' => 'ช่องนี้ต้องเป็นโมเดลประเภท Decisions (ชื่อลงท้าย -decisions)',
        ];
    }
}
