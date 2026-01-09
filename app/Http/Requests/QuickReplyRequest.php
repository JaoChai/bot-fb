<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by policy
    }

    public function rules(): array
    {
        $quickReplyId = $this->route('quick_reply')?->id;
        $userId = $this->user()->id;

        return [
            'shortcut' => [
                'required',
                'string',
                'min:1',
                'max:50',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('quick_replies')
                    ->where('user_id', $userId)
                    ->ignore($quickReplyId),
            ],
            'title' => ['required', 'string', 'min:1', 'max:100'],
            'content' => ['required', 'string', 'min:1', 'max:5000'],
            'category' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'shortcut.regex' => 'Shortcut must contain only lowercase letters, numbers, hyphens, and underscores.',
            'shortcut.unique' => 'This shortcut is already in use.',
            'content.max' => 'Content must not exceed 5000 characters (LINE message limit).',
        ];
    }
}
