<?php

namespace App\Services\Evaluation;

use App\Models\Bot;
use App\Models\Evaluation;
use App\Models\EvaluationTestCase;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class EvaluationService
{
    protected TestCaseGeneratorService $testCaseGenerator;
    protected ConversationSimulatorService $conversationSimulator;
    protected LLMJudgeService $llmJudge;
    protected ReportGeneratorService $reportGenerator;
    protected PersonaService $personaService;

    public function __construct(
        TestCaseGeneratorService $testCaseGenerator,
        ConversationSimulatorService $conversationSimulator,
        LLMJudgeService $llmJudge,
        ReportGeneratorService $reportGenerator,
        PersonaService $personaService
    ) {
        $this->testCaseGenerator = $testCaseGenerator;
        $this->conversationSimulator = $conversationSimulator;
        $this->llmJudge = $llmJudge;
        $this->reportGenerator = $reportGenerator;
        $this->personaService = $personaService;
    }

    /**
     * Create a new evaluation
     */
    public function createEvaluation(
        Bot $bot,
        Flow $flow,
        User $user,
        array $config = []
    ): Evaluation {
        // Validate personas
        $personas = $config['personas'] ?? $this->personaService->getPersonaKeys();
        if (!$this->personaService->validatePersonaKeys($personas)) {
            throw new \InvalidArgumentException('Invalid persona keys provided');
        }

        $evaluation = Evaluation::create([
            'bot_id' => $bot->id,
            'flow_id' => $flow->id,
            'user_id' => $user->id,
            'name' => $config['name'] ?? "Evaluation " . now()->format('Y-m-d H:i'),
            'description' => $config['description'] ?? null,
            'status' => Evaluation::STATUS_PENDING,
            'judge_model' => $config['judge_model'] ?? 'anthropic/claude-3.5-sonnet',
            'generator_model' => $config['generator_model'] ?? 'anthropic/claude-3-haiku-20240307',
            'simulator_model' => $config['simulator_model'] ?? 'anthropic/claude-3-haiku-20240307',
            'personas' => $personas,
            'config' => [
                'test_count' => $config['test_count'] ?? 40,
                'include_multi_turn' => $config['include_multi_turn'] ?? true,
                'include_edge_cases' => $config['include_edge_cases'] ?? true,
                'max_turns_per_conversation' => $config['max_turns_per_conversation'] ?? 3,
            ],
        ]);

        return $evaluation;
    }

    /**
     * Run the complete evaluation pipeline
     */
    public function runEvaluation(Evaluation $evaluation, ?string $apiKey = null): void
    {
        $bot = $evaluation->bot;
        $flow = $evaluation->flow;
        $config = $evaluation->config ?? [];

        try {
            // Phase 1: Generate test cases
            $this->runTestCaseGeneration($evaluation, $flow, $apiKey);

            // Phase 2: Simulate conversations
            $this->runConversationSimulation($evaluation, $bot, $flow, $apiKey);

            // Phase 3: Evaluate with LLM Judge
            $this->runLLMJudging($evaluation, $flow, $apiKey);

            // Phase 4: Generate report
            $this->runReportGeneration($evaluation, $apiKey);

        } catch (\Exception $e) {
            Log::error("Evaluation {$evaluation->id} failed: {$e->getMessage()}");
            $evaluation->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Phase 1: Generate test cases
     */
    public function runTestCaseGeneration(Evaluation $evaluation, Flow $flow, ?string $apiKey = null): void
    {
        $evaluation->markAsGeneratingTests();
        $config = $evaluation->config ?? [];
        $targetCount = $config['test_count'] ?? 40;

        Log::info("Generating {$targetCount} test cases for evaluation {$evaluation->id}");

        $testCases = $this->testCaseGenerator->generateTestCases(
            evaluation: $evaluation,
            flow: $flow,
            targetCount: $targetCount,
            apiKey: $apiKey,
            model: $evaluation->generator_model
        );

        $evaluation->update([
            'total_test_cases' => $testCases->count(),
        ]);

        Log::info("Generated {$testCases->count()} test cases for evaluation {$evaluation->id}");
    }

    /**
     * Phase 2: Simulate conversations
     */
    public function runConversationSimulation(Evaluation $evaluation, Bot $bot, Flow $flow, ?string $apiKey = null): void
    {
        $evaluation->markAsRunning();

        $testCases = $evaluation->testCases()
            ->where('status', EvaluationTestCase::STATUS_PENDING)
            ->get();

        Log::info("Simulating conversations for {$testCases->count()} test cases");

        foreach ($testCases as $testCase) {
            $result = $this->conversationSimulator->simulateConversation(
                testCase: $testCase,
                bot: $bot,
                flow: $flow,
                apiKey: $apiKey,
                model: $evaluation->simulator_model
            );

            if ($result['success']) {
                $evaluation->addTokensUsed($result['tokens_used'] ?? 0);
            }
        }

        Log::info("Completed conversation simulation for evaluation {$evaluation->id}");
    }

    /**
     * Phase 3: LLM Judging
     */
    public function runLLMJudging(Evaluation $evaluation, Flow $flow, ?string $apiKey = null): void
    {
        $evaluation->markAsEvaluating();

        $testCases = $evaluation->testCases()
            ->where('status', EvaluationTestCase::STATUS_RUNNING)
            ->orWhere(function ($query) use ($evaluation) {
                $query->where('evaluation_id', $evaluation->id)
                      ->whereNotNull('id')
                      ->whereNull('overall_score');
            })
            ->get();

        Log::info("Evaluating {$testCases->count()} test cases with LLM Judge");

        foreach ($testCases as $testCase) {
            // Skip if no messages (conversation simulation failed)
            if ($testCase->messages()->count() === 0) {
                $testCase->markAsFailed();
                $evaluation->incrementCompletedTestCases();
                continue;
            }

            $result = $this->llmJudge->evaluateTestCase(
                testCase: $testCase,
                flow: $flow,
                judgeModel: $evaluation->judge_model,
                apiKey: $apiKey
            );

            $evaluation->addTokensUsed($result['tokens_used'] ?? 0);
            $evaluation->incrementCompletedTestCases();
        }

        Log::info("Completed LLM judging for evaluation {$evaluation->id}");
    }

    /**
     * Phase 4: Generate report
     */
    public function runReportGeneration(Evaluation $evaluation, ?string $apiKey = null): void
    {
        Log::info("Generating report for evaluation {$evaluation->id}");

        $report = $this->reportGenerator->generateReport($evaluation, $apiKey);

        Log::info("Report generated for evaluation {$evaluation->id}");
    }

    /**
     * Get evaluation progress
     */
    public function getProgress(Evaluation $evaluation): array
    {
        $total = $evaluation->total_test_cases;
        $completed = $evaluation->completed_test_cases;

        return [
            'status' => $evaluation->status,
            'phase' => $this->getPhaseLabel($evaluation->status),
            'total_test_cases' => $total,
            'completed_test_cases' => $completed,
            'percent_complete' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'tokens_used' => $evaluation->total_tokens_used,
            'estimated_cost' => $this->estimateCost($evaluation->total_tokens_used),
        ];
    }

    /**
     * Cancel a running evaluation
     */
    public function cancelEvaluation(Evaluation $evaluation): void
    {
        if (!$evaluation->isRunning()) {
            throw new \RuntimeException('Evaluation is not running');
        }

        $evaluation->markAsFailed('Cancelled by user');
    }

    /**
     * Retry a failed evaluation
     */
    public function retryEvaluation(Evaluation $evaluation, ?string $apiKey = null): void
    {
        if (!$evaluation->isFailed()) {
            throw new \RuntimeException('Only failed evaluations can be retried');
        }

        // Reset failed test cases to pending
        $evaluation->testCases()
            ->where('status', EvaluationTestCase::STATUS_FAILED)
            ->update(['status' => EvaluationTestCase::STATUS_PENDING]);

        // Clear error and reset
        $evaluation->update([
            'status' => Evaluation::STATUS_PENDING,
            'error_message' => null,
            'completed_at' => null,
        ]);

        // Re-run evaluation
        $this->runEvaluation($evaluation, $apiKey);
    }

    /**
     * Get available personas
     */
    public function getPersonas(): array
    {
        return $this->personaService->getPersonasForDisplay();
    }

    /**
     * Helper: Get phase label
     */
    protected function getPhaseLabel(string $status): string
    {
        return match ($status) {
            Evaluation::STATUS_PENDING => 'รอดำเนินการ',
            Evaluation::STATUS_GENERATING_TESTS => 'กำลังสร้าง test cases',
            Evaluation::STATUS_RUNNING => 'กำลังจำลองบทสนทนา',
            Evaluation::STATUS_EVALUATING => 'กำลังประเมินผล',
            Evaluation::STATUS_COMPLETED => 'เสร็จสิ้น',
            Evaluation::STATUS_FAILED => 'ล้มเหลว',
            default => $status,
        };
    }

    /**
     * Helper: Estimate cost based on tokens used
     */
    protected function estimateCost(int $tokens): float
    {
        // Approximate cost for Claude 3.5 Sonnet
        // Input: $3/1M, Output: $15/1M
        // Assuming 60% input, 40% output
        $inputCost = ($tokens * 0.6) * (3 / 1_000_000);
        $outputCost = ($tokens * 0.4) * (15 / 1_000_000);

        return round($inputCost + $outputCost, 4);
    }
}
