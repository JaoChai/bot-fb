<?php

namespace App\Services\SecondAI;

/**
 * DetectionResult - Value object for prompt injection detection results
 *
 * Immutable object containing:
 * - Detection status (detected or not)
 * - Risk score (0.0-1.0)
 * - Matched patterns with details
 * - Action to take (blocked/flagged/allowed)
 */
readonly class DetectionResult
{
    public function __construct(
        /** @var bool Whether any patterns were detected */
        public bool $detected,

        /** @var float Risk score from 0.0 to 1.0 */
        public float $riskScore,

        /** @var array<array{pattern: string, risk: float, category: string}> Matched patterns */
        public array $patterns,

        /** @var string Action: blocked, flagged, or allowed */
        public string $action,

        /** @var string Human-readable message for blocked/flagged actions */
        public string $message = '',
    ) {}

    /**
     * Check if the input should be blocked
     */
    public function isBlocked(): bool
    {
        return $this->action === 'blocked';
    }

    /**
     * Check if the input was flagged for review
     */
    public function isFlagged(): bool
    {
        return $this->action === 'flagged';
    }

    /**
     * Check if the input is allowed
     */
    public function isAllowed(): bool
    {
        return $this->action === 'allowed';
    }

    /**
     * Get pattern names only
     *
     * @return array<string>
     */
    public function getPatternNames(): array
    {
        return array_column($this->patterns, 'pattern');
    }

    /**
     * Get patterns by category
     *
     * @param string $category Category name (english, thai, encoding, custom)
     * @return array<array{pattern: string, risk: float, category: string}>
     */
    public function getPatternsByCategory(string $category): array
    {
        return array_filter(
            $this->patterns,
            fn (array $p) => $p['category'] === $category
        );
    }

    /**
     * Get the highest risk pattern
     *
     * @return array{pattern: string, risk: float, category: string}|null
     */
    public function getHighestRiskPattern(): ?array
    {
        if (empty($this->patterns)) {
            return null;
        }

        return array_reduce(
            $this->patterns,
            fn (?array $carry, array $item) =>
                $carry === null || $item['risk'] > $carry['risk'] ? $item : $carry
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'detected' => $this->detected,
            'risk_score' => round($this->riskScore, 2),
            'patterns' => $this->patterns,
            'action' => $this->action,
            'message' => $this->message,
        ];
    }

    /**
     * Create a "safe" result (nothing detected)
     */
    public static function safe(): self
    {
        return new self(
            detected: false,
            riskScore: 0.0,
            patterns: [],
            action: 'allowed',
            message: '',
        );
    }
}
