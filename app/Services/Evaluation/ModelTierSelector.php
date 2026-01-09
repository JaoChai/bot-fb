<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Facades\Log;

/**
 * Model Tier Selector Service
 *
 * Selects appropriate LLM model tier based on evaluation metric complexity
 * to optimize cost while maintaining accuracy.
 *
 * Tier Strategy:
 * - Budget: Simple metrics (free models) - answer_relevancy
 * - Standard: Moderate metrics (cheap models) - task_completion, role_adherence
 * - Premium: Complex metrics (expensive models) - faithfulness, context_precision
 */
class ModelTierSelector
{
    /**
     * Metric to Tier mapping
     *
     * Maps evaluation metrics to their complexity tier.
     * Determines which model tier is appropriate for each metric.
     */
    protected const METRIC_TIER_MAP = [
        // Simple metrics - basic semantic comparison
        'answer_relevancy' => 'budget',

        // Moderate metrics - requires reasoning
        'task_completion' => 'standard',
        'role_adherence' => 'standard',

        // Complex metrics - requires deep analysis
        'faithfulness' => 'premium',
        'context_precision' => 'premium',
    ];

    /**
     * Tier to Model mapping
     *
     * Maps each tier to primary and fallback models.
     * Fallback is used if primary model fails (rate limit, unavailable, etc.)
     */
    protected const TIER_MODEL_MAP = [
        'budget' => [
            'primary' => 'google/gemini-flash-1.5-8b-free',
            'fallback' => 'openai/gpt-4o-mini',
        ],
        'standard' => [
            'primary' => 'openai/gpt-4o-mini',
            'fallback' => 'anthropic/claude-3.5-sonnet',
        ],
        'premium' => [
            'primary' => 'anthropic/claude-3.5-sonnet',
            'fallback' => null,  // no cheaper alternative
        ],
    ];

    /**
     * Model cost estimates (USD per 1M tokens)
     *
     * Used for cost estimation and reporting.
     * Based on OpenRouter pricing as of Jan 2026.
     */
    protected const MODEL_COSTS = [
        'google/gemini-flash-1.5-8b-free' => 0.0,
        'openai/gpt-4o-mini' => 0.50,
        'anthropic/claude-3.5-sonnet' => 9.0,
    ];

    /**
     * Select model configuration for a specific metric
     *
     * Returns ModelTierConfig with appropriate tier and models.
     * Checks for environment overrides before using default mapping.
     *
     * @param  string  $metricName  Name of the evaluation metric
     */
    public function selectForMetric(string $metricName): ModelTierConfig
    {
        // Check for forced premium mode (env override)
        if (config('evaluation.force_premium', false)) {
            return $this->buildConfig($metricName, 'premium');
        }

        // Check for metric-specific tier override
        $tierOverride = config("evaluation.tier_override.{$metricName}");
        if ($tierOverride && in_array($tierOverride, ['budget', 'standard', 'premium'])) {
            return $this->buildConfig($metricName, $tierOverride);
        }

        // Use default tier mapping
        $tier = self::METRIC_TIER_MAP[$metricName] ?? 'standard';

        return $this->buildConfig($metricName, $tier);
    }

    /**
     * Get fallback model ID for a metric
     *
     * Returns null if no fallback available (e.g., premium tier).
     *
     * @param  string  $metricName  Name of the evaluation metric
     * @return string|null Fallback model ID or null
     */
    public function getFallbackModel(string $metricName): ?string
    {
        $config = $this->selectForMetric($metricName);

        return $config->fallbackModelId;
    }

    /**
     * Estimate total cost for an evaluation
     *
     * Calculates estimated cost based on:
     * - Number of test cases
     * - Metrics to evaluate
     * - Tier/model for each metric
     * - Average token usage per evaluation (~700 tokens)
     *
     * @param  array  $metrics  List of metric names
     * @param  int  $testCaseCount  Number of test cases
     * @return float Estimated cost in USD
     */
    public function estimateTotalCost(array $metrics, int $testCaseCount): float
    {
        $totalCost = 0;

        // Average token usage per evaluation
        // Input: ~500 tokens (prompt + test case)
        // Output: ~200 tokens (score + reasoning)
        $avgTokensPerEval = 700;

        foreach ($metrics as $metricName) {
            $config = $this->selectForMetric($metricName);
            $modelCost = self::MODEL_COSTS[$config->modelId] ?? 0;

            // Cost per evaluation = (tokens / 1M) * cost_per_1M_tokens
            $costPerEval = ($avgTokensPerEval / 1_000_000) * $modelCost;

            // Total for this metric
            $totalCost += $costPerEval * $testCaseCount;

            Log::debug('Cost estimate for metric', [
                'metric' => $metricName,
                'tier' => $config->tier,
                'model' => $config->modelId,
                'cost_per_eval' => $costPerEval,
                'test_cases' => $testCaseCount,
                'subtotal' => $costPerEval * $testCaseCount,
            ]);
        }

        Log::info('Total evaluation cost estimated', [
            'metrics' => $metrics,
            'test_case_count' => $testCaseCount,
            'total_cost_usd' => $totalCost,
        ]);

        return $totalCost;
    }

    /**
     * Build ModelTierConfig for a given metric and tier
     *
     * Internal helper to construct config objects.
     *
     * @param  string  $metricName  Name of the metric
     * @param  string  $tier  Tier level (budget|standard|premium)
     */
    protected function buildConfig(string $metricName, string $tier): ModelTierConfig
    {
        $models = self::TIER_MODEL_MAP[$tier] ?? self::TIER_MODEL_MAP['standard'];

        return new ModelTierConfig(
            metricName: $metricName,
            tier: $tier,
            modelId: $models['primary'],
            fallbackModelId: $models['fallback'],
        );
    }

    /**
     * Get all supported metrics
     *
     * Returns list of metrics that have tier mappings defined.
     *
     * @return array List of metric names
     */
    public function getSupportedMetrics(): array
    {
        return array_keys(self::METRIC_TIER_MAP);
    }

    /**
     * Get tier for a specific metric
     *
     * @param  string  $metricName  Name of the metric
     * @return string Tier level
     */
    public function getTierForMetric(string $metricName): string
    {
        return self::METRIC_TIER_MAP[$metricName] ?? 'standard';
    }
}
