<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFlowPluginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:telegram'],
            'name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'trigger_condition' => ['required', 'string'],
            'config' => ['required', 'array'],
            'config.access_token' => ['required', 'string'],
            'config.chat_id' => ['required', 'string'],
            'config.message_template' => ['required', 'string'],
            'config.trigger_keywords' => ['nullable', 'array'],
            'config.trigger_keywords.*' => ['string', 'max:100'],
        ];
    }
}
