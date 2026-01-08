<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\ModelTierConfig;
use PHPUnit\Framework\TestCase;

class ModelTierConfigTest extends TestCase
{
    public function test_creates_config_for_budget_metric(): void
    {
        $config = ModelTierConfig::forMetric('answer_relevancy');

        $this->assertEquals('answer_relevancy', $config->metricName);
        $this->assertEquals(ModelTierConfig::TIER_BUDGET, $config->tier);
        $this->assertEquals('google/gemini-flash-1.5-8b-free', $config->modelId);
        $this->assertEquals('openai/gpt-4o-mini', $config->fallbackModelId);
        $this->assertTrue($config->isBudgetTier());
        $this->assertFalse($config->isPremiumTier());
        $this->assertEquals(0.0, $config->getEstimatedCost());
    }

    public function test_creates_config_for_standard_metric(): void
    {
        $config = ModelTierConfig::forMetric('task_completion');

        $this->assertEquals(ModelTierConfig::TIER_STANDARD, $config->tier);
        $this->assertEquals('openai/gpt-4o-mini', $config->modelId);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->fallbackModelId);
        $this->assertFalse($config->isBudgetTier());
        $this->assertFalse($config->isPremiumTier());
        $this->assertEquals(0.75, $config->getEstimatedCost());
    }

    public function test_creates_config_for_premium_metric(): void
    {
        $config = ModelTierConfig::forMetric('faithfulness');

        $this->assertEquals(ModelTierConfig::TIER_PREMIUM, $config->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->modelId);
        $this->assertNull($config->fallbackModelId);
        $this->assertFalse($config->isBudgetTier());
        $this->assertTrue($config->isPremiumTier());
        $this->assertEquals(9.0, $config->getEstimatedCost());
    }

    public function test_defaults_to_premium_for_unknown_metric(): void
    {
        $config = ModelTierConfig::forMetric('unknown_metric');

        $this->assertEquals(ModelTierConfig::TIER_PREMIUM, $config->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $config->modelId);
    }

    public function test_converts_to_array(): void
    {
        $config = ModelTierConfig::forMetric('context_precision');

        $array = $config->toArray();

        $this->assertArrayHasKey('metric_name', $array);
        $this->assertArrayHasKey('tier', $array);
        $this->assertArrayHasKey('model_id', $array);
        $this->assertArrayHasKey('fallback_model_id', $array);
        $this->assertArrayHasKey('estimated_cost', $array);

        $this->assertEquals('context_precision', $array['metric_name']);
        $this->assertEquals(ModelTierConfig::TIER_PREMIUM, $array['tier']);
    }

    public function test_all_defined_metrics_have_tier_mapping(): void
    {
        $metrics = ['answer_relevancy', 'task_completion', 'faithfulness', 'context_precision', 'role_adherence'];

        foreach ($metrics as $metric) {
            $config = ModelTierConfig::forMetric($metric);

            $this->assertNotEmpty($config->tier);
            $this->assertNotEmpty($config->modelId);
            $this->assertIsFloat($config->getEstimatedCost());
        }
    }
}
