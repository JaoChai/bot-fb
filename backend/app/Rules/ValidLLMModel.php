<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLLMModel implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Allow null values (nullable fields)
        if ($value === null) {
            return;
        }

        // Get list of valid model IDs from config
        $validModels = array_keys(config('llm-models.models', []));

        if (! in_array($value, $validModels, true)) {
            $fail('The selected :attribute is not a supported LLM model.');
        }
    }

    /**
     * Get the list of valid model IDs.
     *
     * @return array<string>
     */
    public static function getValidModels(): array
    {
        return array_keys(config('llm-models.models', []));
    }

    /**
     * Get model information by ID.
     *
     * @param  string  $modelId
     * @return array|null
     */
    public static function getModelInfo(string $modelId): ?array
    {
        return config("llm-models.models.{$modelId}");
    }
}
