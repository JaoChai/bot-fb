<?php

namespace App\Console\Commands;

use App\Services\Evaluation\ModelTierSelector;
use Illuminate\Console\Command;

class TestModelTiers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:model-tiers {--test-cases=40 : Number of test cases to simulate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test model tier system for evaluation cost reduction';

    protected ModelTierSelector $tierSelector;

    public function __construct(ModelTierSelector $tierSelector)
    {
        parent::__construct();
        $this->tierSelector = $tierSelector;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧪 Testing Model Tier System');
        $this->newLine();

        $metrics = ['answer_relevancy', 'task_completion', 'faithfulness', 'role_adherence', 'context_precision'];
        $testCaseCount = (int) $this->option('test-cases');

        // Display tier assignments
        $this->info('📊 Tier Assignments:');
        $this->newLine();

        $tierTable = [];
        foreach ($metrics as $metric) {
            $config = $this->tierSelector->selectForMetric($metric);
            $tierTable[] = [
                $metric,
                $config->tier,
                $config->modelId,
                $config->fallbackModelId ?? 'N/A',
            ];
        }

        $this->table(
            ['Metric', 'Tier', 'Primary Model', 'Fallback Model'],
            $tierTable
        );

        $this->newLine();

        // Calculate costs
        $this->info('💰 Cost Analysis:');
        $this->newLine();

        $tierCost = $this->tierSelector->estimateTotalCost($metrics, $testCaseCount);

        // Calculate baseline cost (all premium)
        $avgTokensPerEval = 700;
        $premiumCost = 9.0; // USD per 1M tokens
        $baselineCost = count($metrics) * $testCaseCount * ($avgTokensPerEval / 1_000_000) * $premiumCost;

        $reduction = (($baselineCost - $tierCost) / $baselineCost) * 100;

        $this->info("Test Cases: {$testCaseCount}");
        $this->info('Metrics: '.count($metrics));
        $this->newLine();

        $this->line('Baseline Cost (all premium): $'.number_format($baselineCost, 4));
        $this->line('Tier System Cost: $'.number_format($tierCost, 4));
        $this->newLine();

        if ($reduction >= 50) {
            $this->info('✅ Cost Reduction: '.number_format($reduction, 2).'% (Target: ≥50%)');
        } else {
            $this->warn('⚠️  Cost Reduction: '.number_format($reduction, 2).'% (Target: ≥50%)');
        }

        $this->newLine();

        // Display breakdown
        $this->info('📋 Cost Breakdown by Tier:');
        $this->newLine();

        $tierBreakdown = [
            'budget' => 0,
            'standard' => 0,
            'premium' => 0,
        ];

        $modelCosts = [
            'google/gemini-flash-1.5-8b-free' => 0.0,
            'openai/gpt-4o-mini' => 0.50,
            'anthropic/claude-3.5-sonnet' => 9.0,
        ];

        foreach ($metrics as $metric) {
            $config = $this->tierSelector->selectForMetric($metric);
            $modelCost = $modelCosts[$config->modelId] ?? 0;
            $costPerMetric = $testCaseCount * ($avgTokensPerEval / 1_000_000) * $modelCost;
            $tierBreakdown[$config->tier] += $costPerMetric;
        }

        $breakdownTable = [];
        foreach ($tierBreakdown as $tier => $cost) {
            $breakdownTable[] = [
                ucfirst($tier),
                '$'.number_format($cost, 4),
                number_format(($cost / $tierCost) * 100, 1).'%',
            ];
        }

        $this->table(
            ['Tier', 'Cost', '% of Total'],
            $breakdownTable
        );

        $this->newLine();
        $this->info('✨ Test completed successfully!');

        return Command::SUCCESS;
    }
}
