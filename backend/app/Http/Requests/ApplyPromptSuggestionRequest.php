<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyPromptSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization handled in controller via policy
        return true;
    }

    public function rules(): array
    {
        $bot = $this->route('bot');

        return [
            'flow_id' => [
                'required',
                'integer',
                Rule::exists('flows', 'id')->where(function ($query) use ($bot) {
                    // Flow must belong to the same bot
                    $query->where('bot_id', $bot->id)
                        ->whereNull('deleted_at');
                }),
            ],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'flow_id.required' => 'A flow ID is required to apply the suggestion.',
            'flow_id.integer' => 'Flow ID must be a valid integer.',
            'flow_id.exists' => 'The specified flow does not exist or does not belong to this bot.',
            'force.boolean' => 'The force parameter must be true or false.',
        ];
    }

    /**
     * Get the validated force parameter with default
     */
    public function shouldForce(): bool
    {
        return $this->boolean('force', false);
    }
}
