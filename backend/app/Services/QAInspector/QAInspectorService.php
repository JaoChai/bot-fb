<?php

namespace App\Services\QAInspector;

use App\Models\Bot;
use App\Models\InjectionAttemptLog;
use App\Models\QAEvaluationLog;
use App\Models\SecondAILog;
use App\Services\Evaluation\LLMJudgeService;
use App\Services\OpenRouterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QAInspectorService
{
    public function __construct(
        private LLMJudgeService $llmJudgeService,
        private OpenRouterService $openRouterService,
    ) {}

    /**
     * Check if QA Inspector is enabled for a bot
     */
    public function isEnabled(Bot $bot): bool
    {
        return (bool) $bot->qa_inspector_enabled;
    }

    /**
     * Determine if a conversation should be evaluated based on sampling rate
     *
     * Uses cryptographically secure random number generation for proper sampling.
     */
    public function shouldEvaluate(Bot $bot): bool
    {
        if (!$this->isEnabled($bot)) {
            return false;
        }

        $samplingRate = $bot->qa_sampling_rate ?? 100;

        // Ensure sampling rate is within valid bounds
        $samplingRate = max(0, min(100, $samplingRate));

        // 0% = never evaluate
        if ($samplingRate <= 0) {
            return false;
        }

        // 100% = always evaluate
        if ($samplingRate >= 100) {
            return true;
        }

        // Random sampling based on percentage using cryptographically secure random
        return random_int(1, 100) <= $samplingRate;
    }

    /**
     * Get model configuration for a specific layer
     *
     * @param string $layer One of: 'realtime', 'analysis', 'report'
     */
    public function getModelsForLayer(Bot $bot, string $layer): array
    {
        $defaults = $this->getDefaultModels();

        $modelField = "qa_{$layer}_model";
        $fallbackField = "qa_{$layer}_fallback_model";

        return [
            'primary' => $bot->{$modelField} ?? $defaults[$layer]['primary'],
            'fallback' => $bot->{$fallbackField} ?? $defaults[$layer]['fallback'],
        ];
    }

    /**
     * Get score threshold for flagging issues
     */
    public function getThreshold(Bot $bot): float
    {
        return (float) ($bot->qa_score_threshold ?? 0.70);
    }

    /**
     * Get notification settings
     */
    public function getNotificationSettings(Bot $bot): array
    {
        return $bot->qa_notifications ?? [
            'email' => true,
            'alert' => true,
            'slack' => false,
        ];
    }

    /**
     * Get report schedule for a bot
     */
    public function getReportSchedule(Bot $bot): string
    {
        return $bot->qa_report_schedule ?? 'monday_00:00';
    }

    /**
     * Get default models for all layers
     */
    public function getDefaultModels(): array
    {
        return [
            'realtime' => [
                'primary' => 'google/gemini-2.5-flash-preview',
                'fallback' => 'openai/gpt-4o-mini',
            ],
            'analysis' => [
                'primary' => 'anthropic/claude-sonnet-4',
                'fallback' => 'openai/gpt-4o',
            ],
            'report' => [
                'primary' => 'anthropic/claude-opus-4-5',
                'fallback' => 'anthropic/claude-sonnet-4',
            ],
        ];
    }

    /**
     * Calculate weighted overall score from individual metrics
     *
     * Weights: relevancy 25%, faithfulness 25%, role 20%, context 15%, task 15%
     */
    public function calculateOverallScore(array $scores): float
    {
        $weights = [
            'answer_relevancy' => 0.25,
            'faithfulness' => 0.25,
            'role_adherence' => 0.20,
            'context_precision' => 0.15,
            'task_completion' => 0.15,
        ];

        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($weights as $metric => $weight) {
            if (isset($scores[$metric]) && $scores[$metric] !== null) {
                $weightedSum += $scores[$metric] * $weight;
                $totalWeight += $weight;
            }
        }

        // Return weighted average (handle case where some metrics are missing)
        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
    }

    /**
     * Determine if a score should be flagged as an issue
     */
    public function shouldFlag(float $score, Bot $bot): bool
    {
        return $score < $this->getThreshold($bot);
    }

    /**
     * Categorize an issue based on the scores
     */
    public function categorizeIssue(array $scores, float $threshold): ?string
    {
        // Find the worst performing metric
        $issues = [];

        if (isset($scores['faithfulness']) && $scores['faithfulness'] < $threshold) {
            $issues['hallucination'] = $scores['faithfulness'];
        }

        if (isset($scores['answer_relevancy']) && $scores['answer_relevancy'] < $threshold) {
            $issues['off_topic'] = $scores['answer_relevancy'];
        }

        if (isset($scores['role_adherence']) && $scores['role_adherence'] < $threshold) {
            $issues['wrong_tone'] = $scores['role_adherence'];
        }

        if (isset($scores['context_precision']) && $scores['context_precision'] < $threshold) {
            $issues['missing_info'] = $scores['context_precision'];
        }

        if (isset($scores['task_completion']) && $scores['task_completion'] < $threshold) {
            $issues['unanswered'] = $scores['task_completion'];
        }

        if (empty($issues)) {
            return null;
        }

        // Return the issue type with the worst score
        return array_keys($issues, min($issues))[0];
    }

    /**
     * Get available report schedules
     */
    public function getAvailableSchedules(): array
    {
        return [
            'monday_00:00' => 'Monday 00:00',
            'monday_06:00' => 'Monday 06:00',
            'friday_18:00' => 'Friday 18:00',
            'sunday_00:00' => 'Sunday 00:00',
        ];
    }

    /**
     * Check if a bot's report schedule matches the current time
     *
     * Schedule format: "dayname_HH:MM" (e.g., "monday_00:00", "friday_18:00")
     *
     * @param  Bot  $bot  The bot to check
     * @param  Carbon|null  $now  Current time (defaults to now)
     * @return bool True if the schedule matches current day and hour
     */
    public function isScheduleMatching(Bot $bot, ?Carbon $now = null): bool
    {
        $schedule = $this->getReportSchedule($bot);
        $now = $now ?? Carbon::now();

        // Parse schedule format: "dayname_HH:MM"
        if (!preg_match('/^([a-z]+)_(\d{2}):(\d{2})$/i', $schedule, $matches)) {
            // Invalid format, default to Monday 00:00
            return $now->isMonday() && $now->hour === 0;
        }

        $scheduledDay = strtolower($matches[1]);
        $scheduledHour = (int) $matches[2];

        // Map day names to Carbon day methods
        $dayMethods = [
            'monday' => 'isMonday',
            'tuesday' => 'isTuesday',
            'wednesday' => 'isWednesday',
            'thursday' => 'isThursday',
            'friday' => 'isFriday',
            'saturday' => 'isSaturday',
            'sunday' => 'isSunday',
        ];

        $dayMethod = $dayMethods[$scheduledDay] ?? null;
        if (!$dayMethod) {
            // Invalid day name, default to Monday
            return $now->isMonday() && $now->hour === 0;
        }

        // Check if current day and hour match the schedule
        return $now->{$dayMethod}() && $now->hour === $scheduledHour;
    }

    /**
     * Get LLMJudgeService instance
     */
    public function getLLMJudgeService(): LLMJudgeService
    {
        return $this->llmJudgeService;
    }

    /**
     * Get OpenRouterService instance
     */
    public function getOpenRouterService(): OpenRouterService
    {
        return $this->openRouterService;
    }

    /**
     * Validate model name format (provider/model-name)
     */
    public function isValidModelFormat(string $modelName): bool
    {
        return (bool) preg_match('/^[a-z0-9-]+\/[a-z0-9._-]+$/i', $modelName);
    }

    /**
     * Get list of known providers
     */
    public function getKnownProviders(): array
    {
        return [
            'openai',
            'anthropic',
            'google',
            'meta-llama',
            'mistralai',
            'cohere',
            'deepseek',
        ];
    }

    /**
     * Extract provider from model name
     */
    public function extractProvider(string $modelName): ?string
    {
        $parts = explode('/', $modelName, 2);
        return $parts[0] ?? null;
    }

    /**
     * Get estimated cost per 1M tokens for a model
     */
    public function getModelCostEstimate(string $modelName): float
    {
        // Approximate costs per 1M tokens (input + output averaged)
        return match (true) {
            str_contains($modelName, 'gemini-2.5-flash') => 0.15,
            str_contains($modelName, 'gemini-2.5-pro') => 2.50,
            str_contains($modelName, 'gpt-4o-mini') => 0.30,
            str_contains($modelName, 'gpt-4o') => 5.00,
            str_contains($modelName, 'claude-haiku') => 1.00,
            str_contains($modelName, 'claude-sonnet') => 3.00,
            str_contains($modelName, 'claude-opus') => 15.00,
            str_contains($modelName, 'llama') => 0.20,
            str_contains($modelName, 'mistral') => 0.25,
            default => 1.00,
        };
    }

    /**
     * Calculate estimated monthly cost for QA Inspector
     *
     * @param  int  $conversationsPerDay  Average daily conversations
     * @param  int  $samplingRate  Sampling percentage (1-100)
     * @param  float  $flagRate  Expected percentage of flagged issues
     */
    public function calculateMonthlyCostEstimate(
        Bot $bot,
        int $conversationsPerDay = 200,
        int $samplingRate = 100,
        float $flagRate = 0.12,
    ): array {
        $models = [
            'realtime' => $this->getModelsForLayer($bot, 'realtime'),
            'analysis' => $this->getModelsForLayer($bot, 'analysis'),
            'report' => $this->getModelsForLayer($bot, 'report'),
        ];

        // Tokens used per evaluation (approximate)
        $tokensPerLayer = [
            'realtime' => 1500,  // ~1.5K tokens per evaluation
            'analysis' => 3000,  // ~3K tokens for deep analysis
            'report' => 10000,   // ~10K tokens for weekly report
        ];

        // Calculate daily evaluations
        $dailyEvaluations = (int) ($conversationsPerDay * ($samplingRate / 100));
        $dailyFlagged = (int) ($dailyEvaluations * $flagRate);
        $monthlyEvaluations = $dailyEvaluations * 30;
        $monthlyFlagged = $dailyFlagged * 30;
        $monthlyReports = 4; // 4 weeks

        // Calculate costs
        $realtimeCost = ($monthlyEvaluations * $tokensPerLayer['realtime'] / 1_000_000)
            * $this->getModelCostEstimate($models['realtime']['primary']);

        $analysisCost = ($monthlyFlagged * $tokensPerLayer['analysis'] / 1_000_000)
            * $this->getModelCostEstimate($models['analysis']['primary']);

        $reportCost = ($monthlyReports * $tokensPerLayer['report'] / 1_000_000)
            * $this->getModelCostEstimate($models['report']['primary']);

        return [
            'total_monthly' => round($realtimeCost + $analysisCost + $reportCost, 2),
            'breakdown' => [
                'realtime' => round($realtimeCost, 2),
                'analysis' => round($analysisCost, 2),
                'report' => round($reportCost, 2),
            ],
            'assumptions' => [
                'conversations_per_day' => $conversationsPerDay,
                'sampling_rate' => $samplingRate,
                'flag_rate' => $flagRate,
                'monthly_evaluations' => $monthlyEvaluations,
                'monthly_flagged' => $monthlyFlagged,
            ],
        ];
    }

    /**
     * Calculate dashboard statistics for a bot
     *
     * @param  string  $period  One of: 1d, 7d, 30d
     */
    public function calculateDashboardStats(Bot $bot, string $period = '7d'): array
    {
        $cacheKey = "qa:stats:{$bot->id}:{$period}";
        $ttl = $period === '30d' ? 900 : 300; // 15 min for 30d, 5 min for others

        return Cache::remember($cacheKey, $ttl, function () use ($bot, $period) {
            $days = match ($period) {
                '1d' => 1,
                '7d' => 7,
                '30d' => 30,
                default => 7,
            };

            $startDate = Carbon::now()->subDays($days)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            // Base query
            $baseQuery = QAEvaluationLog::byBot($bot->id)
                ->byDateRange($startDate, $endDate);

            // Combined summary stats and metric averages (single query instead of two)
            $summary = (clone $baseQuery)
                ->selectRaw('
                    COUNT(*) as total_evaluated,
                    SUM(CASE WHEN is_flagged = true THEN 1 ELSE 0 END) as total_flagged,
                    AVG(overall_score) as average_score,
                    AVG(answer_relevancy) as answer_relevancy,
                    AVG(faithfulness) as faithfulness,
                    AVG(role_adherence) as role_adherence,
                    AVG(context_precision) as context_precision,
                    AVG(task_completion) as task_completion
                ')
                ->first();

            $totalEvaluated = (int) ($summary->total_evaluated ?? 0);
            $totalFlagged = (int) ($summary->total_flagged ?? 0);
            $averageScore = (float) ($summary->average_score ?? 0);
            $errorRate = $totalEvaluated > 0
                ? round(($totalFlagged / $totalEvaluated) * 100, 2)
                : 0;

            // Score trend (daily averages)
            $scoreTrend = (clone $baseQuery)
                ->selectRaw('DATE(created_at) as date, AVG(overall_score) as average_score')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'average_score' => round((float) $row->average_score * 100, 1),
                ])
                ->toArray();

            // Issue breakdown
            $issueBreakdown = (clone $baseQuery)
                ->where('is_flagged', true)
                ->whereNotNull('issue_type')
                ->selectRaw('issue_type as type, COUNT(*) as count')
                ->groupBy('issue_type')
                ->orderBy('count', 'desc')
                ->get()
                ->map(function ($row) use ($totalFlagged) {
                    return [
                        'type' => $row->type,
                        'count' => (int) $row->count,
                        'percentage' => $totalFlagged > 0
                            ? round(($row->count / $totalFlagged) * 100, 1)
                            : 0,
                    ];
                })
                ->toArray();

            // Cost tracking using PostgreSQL JSON aggregation (memory efficient)
            $costThisPeriod = (float) ((clone $baseQuery)
                ->whereNotNull('model_metadata')
                ->selectRaw("COALESCE(SUM((model_metadata->>'cost_estimate')::numeric), 0) as total_cost")
                ->value('total_cost') ?? 0);

            return [
                'summary' => [
                    'total_evaluated' => $totalEvaluated,
                    'total_flagged' => $totalFlagged,
                    'error_rate' => $errorRate,
                    'average_score' => round($averageScore * 100, 1),
                ],
                'score_trend' => $scoreTrend,
                'issue_breakdown' => $issueBreakdown,
                'metric_averages' => [
                    'answer_relevancy' => round((float) ($summary->answer_relevancy ?? 0), 2),
                    'faithfulness' => round((float) ($summary->faithfulness ?? 0), 2),
                    'role_adherence' => round((float) ($summary->role_adherence ?? 0), 2),
                    'context_precision' => round((float) ($summary->context_precision ?? 0), 2),
                    'task_completion' => round((float) ($summary->task_completion ?? 0), 2),
                ],
                'cost_this_period' => round($costThisPeriod, 2),
            ];
        });
    }

    /**
     * Invalidate dashboard stats cache for a bot
     *
     * Call this when a new evaluation is created to ensure fresh data.
     */
    public function invalidateStatsCache(Bot $bot): void
    {
        Cache::forget("qa:stats:{$bot->id}:1d");
        Cache::forget("qa:stats:{$bot->id}:7d");
        Cache::forget("qa:stats:{$bot->id}:30d");
        Cache::forget("secondai:stats:{$bot->id}:1d");
        Cache::forget("secondai:stats:{$bot->id}:7d");
        Cache::forget("secondai:stats:{$bot->id}:30d");
    }

    /**
     * Calculate Second AI dashboard statistics for a bot
     *
     * @param string $period One of: 1d, 7d, 30d
     */
    public function calculateSecondAIDashboardStats(Bot $bot, string $period = '7d'): array
    {
        $cacheKey = "secondai:stats:{$bot->id}:{$period}";
        $ttl = $period === '30d' ? 900 : 300; // 15 min for 30d, 5 min for others

        return Cache::remember($cacheKey, $ttl, function () use ($bot, $period) {
            $days = match ($period) {
                '1d' => 1,
                '7d' => 7,
                '30d' => 30,
                default => 7,
            };

            $startDate = Carbon::now()->subDays($days)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            // Second AI Logs stats
            $secondAiQuery = SecondAILog::byBot($bot->id)
                ->byDateRange($startDate, $endDate);

            $secondAiSummary = (clone $secondAiQuery)
                ->selectRaw('
                    COUNT(*) as total_checked,
                    SUM(CASE WHEN was_modified = true THEN 1 ELSE 0 END) as total_modified,
                    AVG(overall_score) as avg_overall_score,
                    AVG(groundedness_score) as avg_groundedness,
                    AVG(policy_compliance_score) as avg_policy,
                    AVG(personality_match_score) as avg_personality,
                    AVG(latency_ms) as avg_latency_ms
                ')
                ->first();

            $totalChecked = (int) ($secondAiSummary->total_checked ?? 0);
            $totalModified = (int) ($secondAiSummary->total_modified ?? 0);
            $modificationRate = $totalChecked > 0
                ? round(($totalModified / $totalChecked) * 100, 2)
                : 0;

            // Check type breakdown
            $checkTypeBreakdown = (clone $secondAiQuery)
                ->whereNotNull('checks_applied')
                ->select('checks_applied')
                ->get()
                ->flatMap(fn ($row) => $row->checks_applied ?? [])
                ->countBy()
                ->toArray();

            // Execution mode breakdown
            $executionModeBreakdown = (clone $secondAiQuery)
                ->selectRaw('execution_mode, COUNT(*) as count')
                ->groupBy('execution_mode')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->execution_mode => (int) $row->count])
                ->toArray();

            // Score trend (daily averages)
            $scoreTrend = (clone $secondAiQuery)
                ->selectRaw('DATE(created_at) as date, AVG(overall_score) as avg_score, COUNT(*) as count')
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'avg_score' => round((float) ($row->avg_score ?? 0) * 100, 1),
                    'count' => (int) $row->count,
                ])
                ->toArray();

            // Injection Attempts stats
            $injectionQuery = InjectionAttemptLog::byBot($bot->id)
                ->byDateRange($startDate, $endDate);

            $injectionSummary = (clone $injectionQuery)
                ->selectRaw('
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN action_taken = \'blocked\' THEN 1 ELSE 0 END) as total_blocked,
                    SUM(CASE WHEN action_taken = \'flagged\' THEN 1 ELSE 0 END) as total_flagged,
                    AVG(risk_score) as avg_risk_score
                ')
                ->first();

            return [
                'second_ai' => [
                    'summary' => [
                        'total_checked' => $totalChecked,
                        'total_modified' => $totalModified,
                        'modification_rate' => $modificationRate,
                        'avg_overall_score' => round((float) ($secondAiSummary->avg_overall_score ?? 0) * 100, 1),
                        'avg_latency_ms' => (int) ($secondAiSummary->avg_latency_ms ?? 0),
                    ],
                    'score_averages' => [
                        'groundedness' => round((float) ($secondAiSummary->avg_groundedness ?? 0), 2),
                        'policy_compliance' => round((float) ($secondAiSummary->avg_policy ?? 0), 2),
                        'personality_match' => round((float) ($secondAiSummary->avg_personality ?? 0), 2),
                    ],
                    'check_type_breakdown' => $checkTypeBreakdown,
                    'execution_mode_breakdown' => $executionModeBreakdown,
                    'score_trend' => $scoreTrend,
                ],
                'injection_detection' => [
                    'total_attempts' => (int) ($injectionSummary->total_attempts ?? 0),
                    'total_blocked' => (int) ($injectionSummary->total_blocked ?? 0),
                    'total_flagged' => (int) ($injectionSummary->total_flagged ?? 0),
                    'avg_risk_score' => round((float) ($injectionSummary->avg_risk_score ?? 0), 2),
                ],
            ];
        });
    }
}
