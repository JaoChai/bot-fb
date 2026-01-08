<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\ModelTierConfig;
use App\Services\Evaluation\ModelTierSelector;
use Tests\TestCase;

class ModelTierSelectorTest extends TestCase
{
    protected ModelTierSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = new ModelTierSelector;
    }

    public function test_select_budget_tier_for_simple_metrics(): void
    {
        $config = $this->selector->selectForMetric('answer_relevancy');

        $this->assertEquals('answer_relevancy', $config->metricName);
        $this->assertEquals('budget', $config->tier);
        $this->assertEquals('google/gemini-flash-1.5-8b-free', $config->modelId);
        $this->assertEquals('openai/gpt-4o-mini', $config->fallbackModelId);
    }

    public function test_select_standard_tier_for_moderate_metrics(): void
    {
        $config = $this->selector->selectForMetric('task_completion');

        $this->assertEquals('task_completion', $config->metricName);
        $this->assertEquals('standard', $config->tier);
        $this->assertEquals('openai/gpt-4o-mini', $config->modelId);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->fallbackModelId);
    }

    public function test_select_premium_tier_for_complex_metrics(): void
    {
        $config = $this->selector->selectForMetric('faithfulness');

        $this->assertEquals('faithfulness', $config->metricName);
        $this->assertEquals('premium', $config->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->modelId);
        $this->assertNull($config->fallbackModelId); // No cheaper alternative
    }

    public function test_unknown_metric_defaults_to_standard(): void
    {
        $config = $this->selector->selectForMetric('unknown_metric');

        $this->assertEquals('unknown_metric', $config->metricName);
        $this->assertEquals('standard', $config->tier);
        $this->assertEquals('openai/gpt-4o-mini', $config->modelId);
    }

    public function test_get_fallback_model(): void
    {
        // Budget tier has fallback
        $fallback = $this->selector->getFallbackModel('answer_relevancy');
        $this->assertEquals('openai/gpt-4o-mini', $fallback);

        // Premium tier has no fallback
        $fallback = $this->selector->getFallbackModel('faithfulness');
        $this->assertNull($fallback);
    }

    public function test_get_supported_metrics(): void
    {
        $metrics = $this->selector->getSupportedMetrics();

        $this->assertCount(5, $metrics);
        $this->assertContains('answer_relevancy', $metrics);
        $this->assertContains('task_completion', $metrics);
        $this->assertContains('role_adherence', $metrics);
        $this->assertContains('faithfulness', $metrics);
        $this->assertContains('context_precision', $metrics);
    }

    public function test_get_tier_for_metric(): void
    {
        $this->assertEquals('budget', $this->selector->getTierForMetric('answer_relevancy'));
        $this->assertEquals('standard', $this->selector->getTierForMetric('task_completion'));
        $this->assertEquals('premium', $this->selector->getTierForMetric('faithfulness'));
        $this->assertEquals('standard', $this->selector->getTierForMetric('unknown')); // Default
    }

    public function test_estimate_total_cost(): void
    {
        $metrics = ['answer_relevancy', 'task_completion', 'faithfulness'];
        $testCaseCount = 10;

        $cost = $this->selector->estimateTotalCost($metrics, $testCaseCount);

        // Budget: $0 (free model)
        // Standard: 700 tokens * 10 cases * $0.50 per 1M = $0.0035
        // Premium: 700 tokens * 10 cases * $9.0 per 1M = $0.063
        // Total: ~$0.0665
        $this->assertGreaterThan(0, $cost);
        $this->assertLessThan(0.1, $cost); // Should be much cheaper than all-premium
    }

    public function test_estimate_cost_zero_for_all_budget(): void
    {
        $metrics = ['answer_relevancy']; // Only budget tier metric
        $testCaseCount = 10;

        $cost = $this->selector->estimateTotalCost($metrics, $testCaseCount);

        // Free model should cost $0
        $this->assertEquals(0, $cost);
    }

    public function test_forced_premium_mode(): void
    {
        // Set config to force premium
        config(['evaluation.force_premium' => true]);

        $config = $this->selector->selectForMetric('answer_relevancy');

        // Even simple metric should use premium tier
        $this->assertEquals('premium', $config->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->modelId);

        // Clean up
        config(['evaluation.force_premium' => false]);
    }

    public function test_tier_override_for_specific_metric(): void
    {
        // Override answer_relevancy to use premium
        config(['evaluation.tier_override.answer_relevancy' => 'premium']);

        $config = $this->selector->selectForMetric('answer_relevancy');

        $this->assertEquals('premium', $config->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->modelId);

        // Clean up
        config(['evaluation.tier_override.answer_relevancy' => null]);
    }

    public function test_invalid_tier_override_uses_default(): void
    {
        // Set invalid tier override
        config(['evaluation.tier_override.answer_relevancy' => 'invalid_tier']);

        $config = $this->selector->selectForMetric('answer_relevancy');

        // Should fallback to default tier mapping
        $this->assertEquals('budget', $config->tier);

        // Clean up
        config(['evaluation.tier_override.answer_relevancy' => null]);
    }

    public function test_model_tier_config_returns_correct_structure(): void
    {
        $config = $this->selector->selectForMetric('task_completion');

        $this->assertInstanceOf(ModelTierConfig::class, $config);
        $this->assertEquals('task_completion', $config->metricName);
        $this->assertEquals('standard', $config->tier);
        $this->assertEquals('openai/gpt-4o-mini', $config->modelId);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->fallbackModelId);
    }
}
