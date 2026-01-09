<?php

namespace App\Services\Evaluation;

readonly class ModelTierConfig
{
    /** Model tier levels */
    public const TIER_BUDGET = 'budget';

    public const TIER_STANDARD = 'standard';

    public const TIER_PREMIUM = 'premium';

    /** Metric complexity mappings */
    private const METRIC_TIER_MAP = [
        'answer_relevancy' => self::TIER_BUDGET,
        'task_completion' => self::TIER_STANDARD,
        'faithfulness' => self::TIER_PREMIUM,
        'context_precision' => self::TIER_PREMIUM,
        'role_adherence' => self::TIER_STANDARD,
    ];

    /** Model selections per tier */
    private const TIER_MODEL_MAP = [
        self::TIER_BUDGET => [
            'primary' => 'google/gemini-flash-1.5-8b-free',
            'fallback' => 'openai/gpt-4o-mini',
        ],
        self::TIER_STANDARD => [
            'primary' => 'openai/gpt-4o-mini',
            'fallback' => 'anthropic/claude-3.5-sonnet',
        ],
        self::TIER_PREMIUM => [
            'primary' => 'anthropic/claude-3.5-sonnet',
            'fallback' => null,
        ],
    ];

    public function __construct(
        /** @var string Metric name (e.g., 'answer_relevancy') */
        public string $metricName,

        /** @var string Assigned tier (budget/standard/premium) */
        public string $tier,

        /** @var string Actual model ID to use */
        public string $modelId,

        /** @var string|null Fallback model if primary fails */
        public ?string $fallbackModelId = null,
    ) {}

    /**
     * Create config for a specific metric
     */
    public static function forMetric(string $metricName): self
    {
        $tier = self::METRIC_TIER_MAP[$metricName] ?? self::TIER_PREMIUM;
        $models = self::TIER_MODEL_MAP[$tier];

        return new self(
            metricName: $metricName,
            tier: $tier,
            modelId: $models['primary'],
            fallbackModelId: $models['fallback'] ?? null,
        );
    }

    /**
     * Check if this is a budget tier
     */
    public function isBudgetTier(): bool
    {
        return $this->tier === self::TIER_BUDGET;
    }

    /**
     * Check if this is a premium tier
     */
    public function isPremiumTier(): bool
    {
        return $this->tier === self::TIER_PREMIUM;
    }

    /**
     * Get estimated cost per 1M tokens (input + output)
     */
    public function getEstimatedCost(): float
    {
        return match ($this->tier) {
            self::TIER_BUDGET => 0.0,      // Gemini Flash free tier
            self::TIER_STANDARD => 0.75,   // GPT-4o-mini average
            self::TIER_PREMIUM => 9.0,     // Claude 3.5 Sonnet average
            default => 9.0,
        };
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'metric_name' => $this->metricName,
            'tier' => $this->tier,
            'model_id' => $this->modelId,
            'fallback_model_id' => $this->fallbackModelId,
            'estimated_cost' => $this->getEstimatedCost(),
        ];
    }
}
