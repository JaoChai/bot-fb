<?php

namespace App\Services\SecondAI;

class CheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $content,
        public readonly bool $wasModified = false,
        public readonly array $modifications = [],
        public readonly ?string $checkType = null,
    ) {}

    public static function passed(string $content): self
    {
        return new self(
            passed: true,
            content: $content,
            wasModified: false,
        );
    }

    public static function modified(string $content, array $modifications, string $checkType): self
    {
        return new self(
            passed: true,
            content: $content,
            wasModified: true,
            modifications: $modifications,
            checkType: $checkType,
        );
    }

    public static function failed(string $originalContent, string $reason): self
    {
        return new self(
            passed: false,
            content: $originalContent,
            wasModified: false,
            modifications: ['error' => $reason],
        );
    }
}
