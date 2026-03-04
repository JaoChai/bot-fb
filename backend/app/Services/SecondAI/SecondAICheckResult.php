<?php

namespace App\Services\SecondAI;

readonly class SecondAICheckResult
{
    public function __construct(
        /** @var bool Overall pass status (false if any check requires modification) */
        public bool $passed,

        /** @var array<string, array> Modifications from each check type */
        public array $modifications,

        /** @var string Final response after applying all modifications */
        public string $finalResponse,

        /** @var array Metadata about the check execution */
        public array $metadata = [],
    ) {}

    /**
     * Create from unified LLM JSON response
     */
    public static function fromJson(array $json): self
    {
        return new self(
            passed: $json['passed'] ?? true,
            modifications: $json['modifications'] ?? [],
            finalResponse: $json['final_response'] ?? '',
            metadata: [
                'timestamp' => now(),
                'model_used' => $json['model_used'] ?? 'unknown',
                'latency_ms' => $json['latency_ms'] ?? 0,
            ],
        );
    }

    /**
     * Check if specific check type was applied
     */
    public function wasApplied(string $checkType): bool
    {
        return isset($this->modifications[$checkType])
            && ($this->modifications[$checkType]['required'] ?? false);
    }

    /**
     * Get all applied check types (only checks with required: true)
     */
    public function getAppliedChecks(): array
    {
        return array_filter(
            array_keys($this->modifications),
            fn ($type) => $this->wasApplied($type)
        );
    }

    /**
     * Get all check type keys that were evaluated (regardless of required status)
     */
    public function getAllCheckTypes(): array
    {
        return array_keys($this->modifications);
    }

    /**
     * Convert to legacy format for backward compatibility
     */
    public function toLegacyFormat(): array
    {
        return [
            'content' => $this->finalResponse,
            'second_ai_applied' => ! $this->passed,
            'second_ai' => [
                'checks_applied' => $this->getAllCheckTypes(),
                'modifications' => $this->modifications,
                'elapsed_ms' => $this->metadata['latency_ms'] ?? 0,
                'model_used' => $this->metadata['model_used'] ?? null,
            ],
        ];
    }
}
