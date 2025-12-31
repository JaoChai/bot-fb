<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationReport;
use App\Models\EvaluationTestCase;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

class ReportGeneratorService
{
    protected OpenRouterService $openRouter;

    public function __construct(OpenRouterService $openRouter)
    {
        $this->openRouter = $openRouter;
    }

    /**
     * Generate evaluation report
     */
    public function generateReport(Evaluation $evaluation, ?string $apiKey = null): EvaluationReport
    {
        // Calculate aggregate scores
        $aggregateScores = $this->calculateAggregateScores($evaluation);

        // Get historical comparison
        $historicalComparison = $this->getHistoricalComparison($evaluation);

        // Analyze test case results
        $analysis = $this->analyzeTestCases($evaluation, $apiKey);

        // Generate recommendations using LLM
        $recommendations = $this->generateRecommendations($evaluation, $aggregateScores, $analysis, $apiKey);

        // Create report
        $report = EvaluationReport::create([
            'evaluation_id' => $evaluation->id,
            'executive_summary' => $this->generateExecutiveSummary($aggregateScores, $historicalComparison),
            'strengths' => $analysis['strengths'] ?? [],
            'weaknesses' => $analysis['weaknesses'] ?? [],
            'recommendations' => $recommendations['general'] ?? [],
            'prompt_suggestions' => $recommendations['prompt'] ?? [],
            'kb_gaps' => $analysis['kb_gaps'] ?? [],
            'historical_comparison' => $historicalComparison,
        ]);

        // Update evaluation with final scores
        $evaluation->markAsCompleted($aggregateScores, $aggregateScores['overall']);

        return $report;
    }

    /**
     * Calculate aggregate scores from all test cases
     */
    protected function calculateAggregateScores(Evaluation $evaluation): array
    {
        $testCases = $evaluation->testCases()
            ->where('status', EvaluationTestCase::STATUS_COMPLETED)
            ->get();

        if ($testCases->isEmpty()) {
            return [
                'answer_relevancy' => 0,
                'faithfulness' => 0,
                'role_adherence' => 0,
                'context_precision' => 0,
                'task_completion' => 0,
                'overall' => 0,
            ];
        }

        $metrics = ['answer_relevancy', 'faithfulness', 'role_adherence', 'context_precision', 'task_completion'];
        $scores = [];

        foreach ($metrics as $metric) {
            $values = $testCases->pluck($metric)->filter()->values();
            $scores[$metric] = $values->isEmpty() ? 0 : round($values->avg(), 4);
        }

        // Calculate weighted overall score
        $weights = EvaluationTestCase::METRIC_WEIGHTS;
        $overall = 0;
        foreach ($weights as $metric => $weight) {
            $overall += ($scores[$metric] ?? 0) * $weight;
        }
        $scores['overall'] = round($overall, 4);

        return $scores;
    }

    /**
     * Get historical comparison with previous evaluations
     */
    protected function getHistoricalComparison(Evaluation $evaluation): array
    {
        $previousEval = Evaluation::where('flow_id', $evaluation->flow_id)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->where('id', '<', $evaluation->id)
            ->orderByDesc('completed_at')
            ->first();

        if (!$previousEval) {
            return [
                'has_previous' => false,
                'previous_score' => null,
                'current_score' => null,
                'change' => null,
                'trend' => 'new',
            ];
        }

        $currentScore = $evaluation->overall_score;
        $previousScore = $previousEval->overall_score;
        $change = $currentScore - $previousScore;

        return [
            'has_previous' => true,
            'previous_eval_id' => $previousEval->id,
            'previous_score' => $previousScore,
            'current_score' => $currentScore,
            'change' => round($change, 4),
            'change_percent' => $previousScore > 0 ? round(($change / $previousScore) * 100, 2) : 0,
            'trend' => $change > 0.02 ? 'improved' : ($change < -0.02 ? 'declined' : 'stable'),
            'previous_metric_scores' => $previousEval->metric_scores,
        ];
    }

    /**
     * Analyze test cases for patterns
     */
    protected function analyzeTestCases(Evaluation $evaluation, ?string $apiKey): array
    {
        $testCases = $evaluation->testCases()
            ->where('status', EvaluationTestCase::STATUS_COMPLETED)
            ->with('messages')
            ->get();

        $strengths = [];
        $weaknesses = [];
        $kbGaps = [];

        // Analyze by metric
        $metrics = ['answer_relevancy', 'faithfulness', 'role_adherence', 'context_precision', 'task_completion'];

        foreach ($metrics as $metric) {
            $avgScore = $testCases->avg($metric);
            $metricLabel = $this->getMetricLabel($metric);

            if ($avgScore >= 0.8) {
                $strengths[] = [
                    'metric' => $metric,
                    'label' => $metricLabel,
                    'score' => round($avgScore, 2),
                    'description' => $this->getStrengthDescription($metric, $avgScore),
                ];
            } elseif ($avgScore < 0.6) {
                $weaknesses[] = [
                    'metric' => $metric,
                    'label' => $metricLabel,
                    'score' => round($avgScore, 2),
                    'description' => $this->getWeaknessDescription($metric, $avgScore),
                ];
            }
        }

        // Analyze by persona
        $personaScores = $testCases->groupBy('persona_key')->map(function ($cases) {
            return $cases->avg('overall_score');
        });

        foreach ($personaScores as $persona => $score) {
            if ($score < 0.6) {
                $weaknesses[] = [
                    'type' => 'persona',
                    'persona' => $persona,
                    'score' => round($score, 2),
                    'description' => "Bot ตอบลูกค้าประเภท {$persona} ได้ไม่ดีเท่าที่ควร",
                ];
            }
        }

        // Analyze for KB gaps (low context_precision cases)
        $lowPrecisionCases = $testCases->where('context_precision', '<', 0.5);
        foreach ($lowPrecisionCases->take(5) as $case) {
            $topics = $case->expected_topics ?? [];
            if (!empty($topics)) {
                $kbGaps[] = [
                    'topics' => $topics,
                    'test_case_id' => $case->id,
                    'score' => $case->context_precision,
                ];
            }
        }

        return [
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'kb_gaps' => $kbGaps,
        ];
    }

    /**
     * Generate recommendations using LLM
     */
    protected function generateRecommendations(
        Evaluation $evaluation,
        array $scores,
        array $analysis,
        ?string $apiKey
    ): array {
        $flow = $evaluation->flow;
        $systemPrompt = $flow->system_prompt ?? 'ไม่มี system prompt';

        $weaknessesText = collect($analysis['weaknesses'])
            ->map(function($w) {
                $label = $w['label'] ?? $w['type'];
                return "- {$label}: {$w['description']}";
            })
            ->implode("\n");

        $kbGapsText = collect($analysis['kb_gaps'])
            ->map(fn($g) => "- หัวข้อ: " . implode(', ', $g['topics']))
            ->implode("\n");

        $prompt = <<<PROMPT
คุณเป็นที่ปรึกษาด้าน AI Chatbot ให้คำแนะนำการปรับปรุงจากผลการประเมิน

## คะแนนปัจจุบัน
- Answer Relevancy: {$scores['answer_relevancy']}
- Faithfulness: {$scores['faithfulness']}
- Role Adherence: {$scores['role_adherence']}
- Context Precision: {$scores['context_precision']}
- Task Completion: {$scores['task_completion']}
- Overall: {$scores['overall']}

## จุดอ่อนที่พบ
{$weaknessesText}

## ช่องว่างใน Knowledge Base
{$kbGapsText}

## System Prompt ปัจจุบัน
{$systemPrompt}

## ขอให้ให้คำแนะนำ
1. คำแนะนำทั่วไปในการปรับปรุง (2-3 ข้อ)
2. คำแนะนำเฉพาะสำหรับปรับปรุง System Prompt (ถ้ามี)
3. ข้อมูลที่ควรเพิ่มใน Knowledge Base (ถ้ามี)

## รูปแบบ Output (JSON)
{
  "general": [
    {"title": "หัวข้อ", "description": "รายละเอียด", "priority": "high/medium/low"}
  ],
  "prompt": [
    {"suggestion": "คำแนะนำ", "example": "ตัวอย่าง prompt ที่ปรับปรุง"}
  ],
  "kb": [
    {"topic": "หัวข้อที่ควรเพิ่ม", "description": "รายละเอียด"}
  ]
}
PROMPT;

        try {
            $response = $this->openRouter->chat(
                messages: [['role' => 'user', 'content' => $prompt]],
                model: 'anthropic/claude-3.5-sonnet',
                temperature: 0.3,
                maxTokens: 1500,
                apiKeyOverride: $apiKey
            );

            $result = $this->extractJson($response['content']);
            $recommendations = json_decode($result, true);

            return [
                'general' => $recommendations['general'] ?? [],
                'prompt' => $recommendations['prompt'] ?? [],
                'kb' => $recommendations['kb'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error("Failed to generate recommendations: {$e->getMessage()}");
            return [
                'general' => [
                    ['title' => 'ปรับปรุงจุดอ่อน', 'description' => 'ดูจากจุดอ่อนที่พบและปรับปรุง', 'priority' => 'high']
                ],
                'prompt' => [],
                'kb' => [],
            ];
        }
    }

    /**
     * Generate executive summary
     */
    protected function generateExecutiveSummary(array $scores, array $history): string
    {
        $overall = $scores['overall'];
        $grade = $this->getGrade($overall);

        $summary = "## สรุปผลการประเมิน\n\n";
        $summary .= "**คะแนนรวม: {$overall} ({$grade})**\n\n";

        // Score breakdown
        $summary .= "### คะแนนแต่ละด้าน\n";
        $summary .= "- ความเกี่ยวข้องของคำตอบ: {$scores['answer_relevancy']}\n";
        $summary .= "- ความถูกต้อง (ไม่ hallucinate): {$scores['faithfulness']}\n";
        $summary .= "- การทำตาม persona: {$scores['role_adherence']}\n";
        $summary .= "- การดึงข้อมูลที่ถูกต้อง: {$scores['context_precision']}\n";
        $summary .= "- การช่วยเหลือลูกค้า: {$scores['task_completion']}\n\n";

        // Historical comparison
        if ($history['has_previous']) {
            $changeIcon = $history['trend'] === 'improved' ? '📈' : ($history['trend'] === 'declined' ? '📉' : '➡️');
            $summary .= "### เปรียบเทียบกับครั้งก่อน\n";
            $summary .= "{$changeIcon} {$history['trend']} ({$history['change_percent']}%)\n";
        }

        return $summary;
    }

    /**
     * Helper methods
     */
    protected function getMetricLabel(string $metric): string
    {
        return match ($metric) {
            'answer_relevancy' => 'ความเกี่ยวข้องของคำตอบ',
            'faithfulness' => 'ความถูกต้อง',
            'role_adherence' => 'การทำตาม Persona',
            'context_precision' => 'การดึงข้อมูล KB',
            'task_completion' => 'การช่วยเหลือลูกค้า',
            default => $metric,
        };
    }

    protected function getStrengthDescription(string $metric, float $score): string
    {
        $label = $this->getMetricLabel($metric);
        return "{$label}อยู่ในระดับดีเยี่ยม (คะแนน: {$score})";
    }

    protected function getWeaknessDescription(string $metric, float $score): string
    {
        $label = $this->getMetricLabel($metric);
        return "{$label}ต้องปรับปรุง (คะแนน: {$score})";
    }

    protected function getGrade(float $score): string
    {
        return match (true) {
            $score >= 0.9 => 'A+',
            $score >= 0.8 => 'A',
            $score >= 0.7 => 'B',
            $score >= 0.6 => 'C',
            $score >= 0.5 => 'D',
            default => 'F',
        };
    }

    protected function extractJson(string $content): string
    {
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            return $matches[0];
        }
        return '{}';
    }
}
