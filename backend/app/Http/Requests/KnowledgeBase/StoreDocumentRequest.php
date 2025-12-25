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
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB
                'mimes:pdf,txt,md,docx,doc',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The uploaded file is invalid.',
            'file.max' => 'File size must not exceed 10MB.',
            'file.mimes' => 'File must be PDF, TXT, MD, or Word document.',
        ];
    }
}
