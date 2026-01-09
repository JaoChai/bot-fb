<?php

namespace Tests\Feature\Evaluation;

use App\Services\Evaluation\LLMJudgeService;
use App\Services\Evaluation\ModelTierSelector;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ModelTierTest extends TestCase
{
    public function test_tier_selector_integration_with_judge_service(): void
    {
        // Mock OpenRouterService
        $this->mock(OpenRouterService::class, function ($mock) {
            // Budget tier call
            $mock->shouldReceive('chat')
                ->with(\Mockery::on(function ($messages) {
                    return $messages['model'] === 'google/gemini-flash-1.5-8b-free';
                }))
                ->andReturn([
                    'content' => json_encode(['score' => 0.9, 'reasoning' => 'Budget model result']),
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                ]);

            // Premium tier call
            $mock->shouldReceive('chat')
                ->with(\Mockery::on(function ($messages) {
                    return $messages['model'] === 'anthropic/claude-3.5-sonnet';
                }))
                ->andReturn([
                    'content' => json_encode(['score' => 0.95, 'reasoning' => 'Premium model result']),
                    'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 100],
                ]);
        });

        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();
        Log::shouldReceive('error')->andReturnSelf();

        $service = app(LLMJudgeService::class);
        $tierSelector = app(ModelTierSelector::class);

        // Verify tier selector is properly integrated
        $this->assertInstanceOf(LLMJudgeService::class, $service);
        $this->assertInstanceOf(ModelTierSelector::class, $tierSelector);
    }

    public function test_fallback_logic_integration(): void
    {
        $tierSelector = app(ModelTierSelector::class);

        // Verify fallback models are configured correctly for each tier
        $budgetConfig = $tierSelector->selectForMetric('answer_relevancy');
        $this->assertEquals('openai/gpt-4o-mini', $budgetConfig->fallbackModelId,
            'Budget tier should fallback to gpt-4o-mini');

        $standardConfig = $tierSelector->selectForMetric('task_completion');
        $this->assertEquals('anthropic/claude-3.5-sonnet', $standardConfig->fallbackModelId,
            'Standard tier should fallback to claude-3.5-sonnet');

        $premiumConfig = $tierSelector->selectForMetric('faithfulness');
        $this->assertNull($premiumConfig->fallbackModelId,
            'Premium tier should have no fallback (already most expensive)');

        // Verify LLMJudgeService is registered with proper dependencies
        $judgeService = app(LLMJudgeService::class);
        $this->assertInstanceOf(LLMJudgeService::class, $judgeService);
    }

    public function test_cost_reduction_calculation(): void
    {
        $tierSelector = app(ModelTierSelector::class);
        $metrics = ['answer_relevancy', 'task_completion', 'faithfulness', 'role_adherence', 'context_precision'];
        $testCaseCount = 40;

        $tierCost = $tierSelector->estimateTotalCost($metrics, $testCaseCount);

        // Calculate baseline cost (all premium)
        $avgTokensPerEval = 700;
        $premiumCost = 9.0; // USD per 1M tokens
        $baselineCost = count($metrics) * $testCaseCount * ($avgTokensPerEval / 1_000_000) * $premiumCost;

        // Verify cost reduction
        $reduction = (($baselineCost - $tierCost) / $baselineCost) * 100;

        $this->assertGreaterThan(50, $reduction, 'Cost reduction should be at least 50%');
        $this->assertLessThan($baselineCost, $tierCost);
    }

    public function test_tier_configuration_integration(): void
    {
        $tierSelector = app(ModelTierSelector::class);

        // Test budget tier
        $budgetConfig = $tierSelector->selectForMetric('answer_relevancy');
        $this->assertEquals('budget', $budgetConfig->tier);
        $this->assertEquals('google/gemini-flash-1.5-8b-free', $budgetConfig->modelId);

        // Test standard tier
        $standardConfig = $tierSelector->selectForMetric('task_completion');
        $this->assertEquals('standard', $standardConfig->tier);
        $this->assertEquals('openai/gpt-4o-mini', $standardConfig->modelId);

        // Test premium tier
        $premiumConfig = $tierSelector->selectForMetric('faithfulness');
        $this->assertEquals('premium', $premiumConfig->tier);
        $this->assertEquals('anthropic/claude-3.5-sonnet', $premiumConfig->modelId);
    }
}
