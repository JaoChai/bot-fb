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
            'channel_type' => ['required', Rule::in(['line', 'facebook', 'telegram'])],
            'channel_access_token' => ['nullable', 'string'],
            'channel_secret' => ['nullable', 'string'],
            'page_id' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'channel_type.required' => 'Channel type is required',
            'channel_type.in' => 'Channel type must be line, facebook, or telegram',
        ];
    }
}
