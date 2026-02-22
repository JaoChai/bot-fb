<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLLMModel implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates that the model ID follows the provider/model-name format.
     * No longer restricted to config-only models since the system now
     * supports any OpenRouter model dynamically.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Allow null values (nullable fields)
        if ($value === null) {
            return;
        }

        // Validate format: provider/model-name
        if (! is_string($value) || ! preg_match('#^[a-z0-9_-]+/[a-z0-9._-]+$#i', $value)) {
            $fail('The :attribute must be a valid model ID in provider/model-name format (e.g., openai/gpt-4o-mini).');
        }
    }
}
