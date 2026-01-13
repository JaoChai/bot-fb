<?php

namespace App\Services\QAInspector;

use App\Models\Bot;
use App\Models\QAEvaluationLog;
use App\Models\QAWeeklyReport;
use App\Services\OpenRouterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WeeklyReportGenerator
{
    public function __construct(
        private QAInspectorService $qaInspectorService,
        private OpenRouterService $openRouterService,
    ) {}

    /**
     * Generate a weekly report for a bot
     */
    public function generate(Bot $bot, Carbon $weekStart): QAWeeklyReport
    {
        $startTime = microtime(true);
        $weekEnd = $weekStart->copy()->endOfWeek();

        Log::channel('qa_inspector')->info('Weekly report generation started', [
            'bot_id' => $bot->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
        ]);

        // Create or update report record
        $report = QAWeeklyReport::updateOrCreate(
            ['bot_id' => $bot->id, 'week_start' => $weekStart->toDateString()],
            [
                'week_end' => $weekEnd->toDateString(),
                'status' => QAWeeklyReport::STATUS_GENERATING,
            ]
        );

        try {
            // Get all evaluation logs for the week
            $logs = QAEvaluationLog::byBot($bot->id)
                ->byDateRange($weekStart, $weekEnd)
                ->get();

            if ($logs->isEmpty()) {
                $report->update([
                    'status' => QAWeeklyReport::STATUS_COMPLETED,
                    'performance_summary' => ['message' => 'No data available for this week'],
                    'top_issues' => [],
                    'prompt_suggestions' => [],
                    'total_conversations' => 0,
                    'total_flagged' => 0,
                    'average_score' => 0,
                    'generated_at' => now(),
                ]);

                Log::channel('qa_inspector')->info('Weekly report completed (no data)', [
                    'bot_id' => $bot->id,
                    'report_id' => $report->id,
                    'week_start' => $weekStart->toDateString(),
                ]);

                return $report;
            }

            // Calculate performance summary
            $performanceSummary = $this->calculatePerformanceSummary($logs);

            // Get previous week's average for trend
            $previousReport = QAWeeklyReport::byBot($bot->id)
                ->byWeek($weekStart->copy()->subWeek()->toDateString())
                ->completed()
                ->first();

            // Identify top issues
            $topIssues = $this->identifyTopIssues($logs);

            // Generate prompt suggestions using AI
            $promptSuggestions = $this->generatePromptSuggestions($bot, $topIssues);

            // Calculate generation cost
            $generationCost = $this->estimateGenerationCost($logs->count(), count($topIssues));

            $totalFlagged = $logs->where('is_flagged', true)->count();
            $averageScore = round($logs->avg('overall_score') * 100, 2);

            // Update report
            $report->update([
                'status' => QAWeeklyReport::STATUS_COMPLETED,
                'performance_summary' => $performanceSummary,
                'top_issues' => $topIssues,
                'prompt_suggestions' => $promptSuggestions,
                'total_conversations' => $logs->count(),
                'total_flagged' => $totalFlagged,
                'average_score' => $averageScore,
                'previous_average_score' => $previousReport?->average_score,
                'generation_cost' => $generationCost,
                'generated_at' => now(),
            ]);

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('qa_inspector')->info('Weekly report generation completed', [
                'bot_id' => $bot->id,
                'report_id' => $report->id,
                'week_start' => $weekStart->toDateString(),
                'total_evaluated' => $logs->count(),
                'total_flagged' => $totalFlagged,
                'average_score' => $averageScore,
                'top_issues_count' => count($topIssues),
                'suggestions_count' => count($promptSuggestions),
                'generation_cost' => $generationCost,
                'duration_ms' => $durationMs,
            ]);

            return $report;

        } catch (\Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('qa_inspector')->error('Weekly report generation failed', [
                'bot_id' => $bot->id,
                'report_id' => $report->id ?? null,
                'week_start' => $weekStart->toDateString(),
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'duration_ms' => $durationMs,
            ]);

            $report->update([
                'status' => QAWeeklyReport::STATUS_FAILED,
            ]);

            throw $e;
        }
    }

    private function calculatePerformanceSummary($logs): array
    {
        $total = $logs->count();
        $flagged = $logs->where('is_flagged', true)->count();
        $avgScore = $logs->avg('overall_score');

        // Score distribution
        $distribution = [
            'excellent' => $logs->filter(fn ($l) => $l->overall_score >= 0.9)->count(),
            'good' => $logs->filter(fn ($l) => $l->overall_score >= 0.7 && $l->overall_score < 0.9)->count(),
            'needs_improvement' => $logs->filter(fn ($l) => $l->overall_score >= 0.5 && $l->overall_score < 0.7)->count(),
            'poor' => $logs->filter(fn ($l) => $l->overall_score < 0.5)->count(),
        ];

        return [
            'total_conversations' => $total,
            'total_evaluated' => $total,
            'total_flagged' => $flagged,
            'error_rate' => $total > 0 ? round(($flagged / $total) * 100, 2) : 0,
            'average_score' => round($avgScore * 100, 1),
            'score_trend' => null, // Will be calculated from previous
            'score_distribution' => $distribution,
            'metric_averages' => [
                'answer_relevancy' => round($logs->avg('answer_relevancy') ?? 0, 2),
                'faithfulness' => round($logs->avg('faithfulness') ?? 0, 2),
                'role_adherence' => round($logs->avg('role_adherence') ?? 0, 2),
                'context_precision' => round($logs->avg('context_precision') ?? 0, 2),
                'task_completion' => round($logs->avg('task_completion') ?? 0, 2),
            ],
        ];
    }

    private function identifyTopIssues($logs): array
    {
        $flaggedLogs = $logs->where('is_flagged', true);

        if ($flaggedLogs->isEmpty()) {
            return [];
        }

        $issueGroups = $flaggedLogs->groupBy('issue_type');
        $topIssues = [];
        $rank = 1;

        foreach ($issueGroups->sortByDesc(fn ($group) => $group->count())->take(5) as $type => $group) {
            $examples = $group->take(3)->map(fn ($log) => [
                'evaluation_log_id' => $log->id,
                'user_question' => Str::limit($log->user_question, 100),
                'bot_response' => Str::limit($log->bot_response, 150),
            ])->toArray();

            // Get common prompt section from issue_details
            $promptSections = $group->pluck('issue_details.prompt_section_identified')->filter()->countBy();
            $commonSection = $promptSections->sortDesc()->keys()->first() ?? 'Unknown';

            // Get common root cause
            $rootCauses = $group->pluck('issue_details.root_cause')->filter();
            $commonRootCause = $rootCauses->first() ?? 'Analysis pending';

            $topIssues[] = [
                'rank' => $rank++,
                'issue_type' => $type ?? 'other',
                'count' => $group->count(),
                'percentage' => round(($group->count() / $flaggedLogs->count()) * 100, 1),
                'pattern' => $this->identifyPattern($group),
                'prompt_section' => $commonSection,
                'example_conversations' => $examples,
                'root_cause' => $commonRootCause,
            ];
        }

        return $topIssues;
    }

    private function identifyPattern($logs): string
    {
        // Simple pattern detection based on common words in questions
        $questions = $logs->pluck('user_question')->join(' ');

        // Common Thai patterns
        $patterns = [
            'confirm' => 'User types "confirm" without answering previous question',
            'price' => 'Questions about product pricing',
            'upsell' => 'Related to upsell offers',
            'product' => 'Questions about product information',
        ];

        foreach ($patterns as $keyword => $pattern) {
            if (str_contains(strtolower($questions), strtolower($keyword))) {
                return $pattern;
            }
        }

        return 'Pattern requires further analysis';
    }

    private function generatePromptSuggestions(Bot $bot, array $topIssues): array
    {
        if (empty($topIssues)) {
            return [];
        }

        $models = $this->qaInspectorService->getModelsForLayer($bot, 'report');
        $suggestions = [];

        // Get the flow's system prompt
        $flow = $bot->flows()->where('is_active', true)->first();
        $systemPrompt = $flow?->system_prompt ?? $bot->system_prompt ?? '';

        foreach (array_slice($topIssues, 0, 3) as $issue) {
            $prompt = $this->buildSuggestionPrompt($issue, $systemPrompt);

            try {
                $result = $this->callModel($models['primary'], $prompt);

                if (!$result && $models['fallback']) {
                    $result = $this->callModel($models['fallback'], $prompt);
                }

                if ($result) {
                    $suggestions[] = [
                        'priority' => $issue['rank'],
                        'section' => $issue['prompt_section'],
                        'line_range' => $result['line_range'] ?? 'N/A',
                        'issue_addressed' => $issue['issue_type'],
                        'expected_impact' => "Fix {$issue['count']} cases ({$issue['percentage']}% of flagged)",
                        'before' => $result['before'] ?? '',
                        'after' => $result['after'] ?? '',
                        'applied' => false,
                        'applied_at' => null,
                    ];

                    Log::channel('qa_inspector')->debug('Prompt suggestion generated', [
                        'bot_id' => $bot->id,
                        'issue_type' => $issue['issue_type'],
                        'section' => $issue['prompt_section'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('qa_inspector')->warning('Suggestion generation failed', [
                    'bot_id' => $bot->id,
                    'issue_type' => $issue['issue_type'],
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);
            }
        }

        return $suggestions;
    }

    private function buildSuggestionPrompt(array $issue, string $systemPrompt): string
    {
        $examples = json_encode($issue['example_conversations'] ?? [], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a prompt engineer improving a chatbot's system prompt.
Analyze this issue and suggest a specific fix.

## Current System Prompt:
{$systemPrompt}

## Issue Type: {$issue['issue_type']}
## Affected Section: {$issue['prompt_section']}
## Root Cause: {$issue['root_cause']}
## Example Conversations:
{$examples}

Suggest a fix. Return JSON:
{
  "line_range": "45-67",
  "before": "The original problematic text from the prompt",
  "after": "The improved text that fixes the issue"
}

Keep suggestions in Thai if the original prompt is in Thai.
Focus on the specific section mentioned. Be concise.
PROMPT;
    }

    private function callModel(string $modelId, string $prompt): ?array
    {
        try {
            $response = $this->openRouterService->chat(
                messages: [
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $modelId,
                temperature: 0.3,
                maxTokens: 1500,
                useFallback: false,
            );

            $content = $response['content'] ?? '';

            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $result = json_decode($matches[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $result;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function estimateGenerationCost(int $totalLogs, int $topIssuesCount): float
    {
        // Approximate cost calculation
        $analysisCost = $topIssuesCount * 0.003; // ~3K tokens per analysis at $1/1M
        $reportCost = 0.015; // ~10K tokens for report generation at $1.5/1M
        $suggestionCost = min($topIssuesCount, 3) * 0.015; // ~10K tokens per suggestion

        return round($analysisCost + $reportCost + $suggestionCost, 4);
    }
}
