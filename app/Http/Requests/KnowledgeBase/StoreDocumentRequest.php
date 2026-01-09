<?php

namespace App\Http\Requests\KnowledgeBase;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title for the document.',
            'title.max' => 'Title must not exceed 255 characters.',
            'content.required' => 'Please provide content for the document.',
            'content.max' => 'Content must not exceed 100,000 characters.',
        ];
    }
}
