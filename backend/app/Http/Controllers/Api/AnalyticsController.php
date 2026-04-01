<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCostUsage;
use App\Models\Message;
use App\Services\SemanticCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    /**
     * Get cost analytics with flexible date ranges and grouping.
     * Results are cached for 5 minutes to improve performance.
     */
    public function costs(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date',
            'group_by' => 'sometimes|in:day,week,month',
            'bot_id' => [
                'sometimes',
                'integer',
                Rule::exists('bots', 'id')->where('user_id', $user->id),
            ],
        ]);

        $fromDate = isset($validated['from_date']) ? $validated['from_date'] : now()->subDays(30)->startOfDay();
        $toDate = isset($validated['to_date']) ? $validated['to_date'] : now()->endOfDay();
        $groupBy = $validated['group_by'] ?? 'day';
        $botId = $validated['bot_id'] ?? null;

        // Cache key based on user and parameters
        $cacheKey = sprintf(
            'analytics:costs:%d:%s:%s:%s:%s',
            $user->id,
            md5(json_encode([$fromDate, $toDate])),
            $groupBy,
            $botId ?? 'all',
            now()->format('Y-m-d-H') // Cache per hour for date-sensitive queries
        );

        $data = Cache::remember($cacheKey, 300, function () use ($user, $fromDate, $toDate, $groupBy, $botId) {
            return $this->buildAnalyticsData($user, $fromDate, $toDate, $groupBy, $botId);
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Build analytics data using optimized queries.
     */
    private function buildAnalyticsData($user, $fromDate, $toDate, $groupBy, $botId): array
    {
        $userId = $user->id;

        // Use CTE for better query performance
        $dateFormat = match ($groupBy) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-IW',
            'month' => 'YYYY-MM',
        };

        // Build bot filter SQL
        $botFilter = $botId ? "AND b.id = {$botId}" : '';

        // Single query using CTE to get all aggregations
        $results = DB::select("
            WITH user_messages AS (
                SELECT
                    m.id,
                    m.cost,
                    m.prompt_tokens,
                    m.completion_tokens,
                    m.model_used,
                    m.created_at,
                    b.id as bot_id,
                    b.name as bot_name
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                INNER JOIN bots b ON c.bot_id = b.id
                WHERE b.user_id = ?
                    AND m.sender = 'bot'
                    AND m.cost IS NOT NULL
                    {$botFilter}
            ),
            date_filtered AS (
                SELECT * FROM user_messages
                WHERE created_at BETWEEN ? AND ?
            ),
            quick_stats AS (
                SELECT
                    COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN cost ELSE 0 END), 0) as today_cost,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_TRUNC('week', CURRENT_DATE) THEN cost ELSE 0 END), 0) as week_cost,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_TRUNC('month', CURRENT_DATE) THEN cost ELSE 0 END), 0) as month_cost
                FROM user_messages
            )
            SELECT
                (SELECT COUNT(*) FROM date_filtered) as total_responses,
                (SELECT COALESCE(SUM(cost), 0) FROM date_filtered) as total_cost,
                (SELECT COALESCE(SUM(prompt_tokens), 0) FROM date_filtered) as total_prompt_tokens,
                (SELECT COALESCE(SUM(completion_tokens), 0) FROM date_filtered) as total_completion_tokens,
                (SELECT COALESCE(AVG(cost), 0) FROM date_filtered) as avg_cost_per_response,
                qs.today_cost,
                qs.week_cost,
                qs.month_cost
            FROM quick_stats qs
        ", [$userId, $fromDate, $toDate]);

        $stats = $results[0] ?? (object) [
            'total_responses' => 0,
            'total_cost' => 0,
            'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0,
            'avg_cost_per_response' => 0,
            'today_cost' => 0,
            'week_cost' => 0,
            'month_cost' => 0,
        ];

        // Base query for remaining aggregations
        $baseQuery = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('bots', 'conversations.bot_id', '=', 'bots.id')
            ->where('bots.user_id', $userId)
            ->where('messages.sender', 'bot')
            ->whereNotNull('messages.cost')
            ->whereBetween('messages.created_at', [$fromDate, $toDate]);

        if ($botId) {
            $baseQuery->where('bots.id', $botId);
        }

        // Cost by model
        $byModel = (clone $baseQuery)
            ->selectRaw('
                messages.model_used,
                COUNT(*) as response_count,
                COALESCE(SUM(messages.cost), 0) as total_cost,
                COALESCE(SUM(messages.prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(messages.completion_tokens), 0) as completion_tokens
            ')
            ->groupBy('messages.model_used')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($item) => [
                'model_used' => $item->model_used,
                'response_count' => (int) $item->response_count,
                'total_cost' => (float) $item->total_cost,
                'prompt_tokens' => (int) $item->prompt_tokens,
                'completion_tokens' => (int) $item->completion_tokens,
            ]);

        // Time series data
        $timeSeries = (clone $baseQuery)
            ->selectRaw("
                TO_CHAR(messages.created_at, '{$dateFormat}') as period,
                COUNT(*) as response_count,
                COALESCE(SUM(messages.cost), 0) as total_cost,
                COALESCE(SUM(messages.prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(messages.completion_tokens), 0) as completion_tokens
            ")
            ->groupByRaw("TO_CHAR(messages.created_at, '{$dateFormat}')")
            ->orderBy('period')
            ->get()
            ->map(fn ($item) => [
                'period' => $item->period,
                'response_count' => (int) $item->response_count,
                'total_cost' => (float) $item->total_cost,
                'prompt_tokens' => (int) $item->prompt_tokens,
                'completion_tokens' => (int) $item->completion_tokens,
            ]);

        // Cost by bot (if not filtered to single bot)
        $byBot = null;
        if (! $botId) {
            $byBot = (clone $baseQuery)
                ->selectRaw('
                    bots.id as bot_id,
                    bots.name as bot_name,
                    COUNT(*) as response_count,
                    COALESCE(SUM(messages.cost), 0) as total_cost
                ')
                ->groupBy('bots.id', 'bots.name')
                ->orderByDesc('total_cost')
                ->get()
                ->map(fn ($item) => [
                    'bot_id' => (int) $item->bot_id,
                    'bot_name' => $item->bot_name,
                    'response_count' => (int) $item->response_count,
                    'total_cost' => (float) $item->total_cost,
                ]);
        }

        // Get enhanced cost data from agent_cost_usage table
        $enhancedCostQuery = AgentCostUsage::where('user_id', $userId)
            ->whereBetween('created_at', [$fromDate, $toDate]);

        if ($botId) {
            $enhancedCostQuery->where('bot_id', $botId);
        }

        $enhancedStats = $enhancedCostQuery->selectRaw('
            COUNT(*) as record_count,
            COALESCE(SUM(actual_cost), 0) as total_actual_cost,
            COALESCE(SUM(cached_tokens), 0) as total_cached_tokens,
            COALESCE(SUM(reasoning_tokens), 0) as total_reasoning_tokens,
            COALESCE(SUM(estimated_cost), 0) as total_estimated_cost_from_usage
        ')->first();

        // Calculate cost savings (estimated - actual, when actual is available)
        $totalActualCost = (float) ($enhancedStats->total_actual_cost ?? 0);
        $totalEstimatedCost = (float) ($stats->total_cost ?? 0);
        $costSavings = $totalActualCost > 0 ? max(0, $totalEstimatedCost - $totalActualCost) : null;

        // Calculate enhanced data coverage from the same query (no extra DB round-trip)
        $totalResponses = (int) ($stats->total_responses ?? 0);
        $enhancedRecordCount = (int) ($enhancedStats->record_count ?? 0);
        $coveragePercent = $totalResponses > 0
            ? round(($enhancedRecordCount / $totalResponses) * 100, 1)
            : 0;

        return [
            'summary' => [
                'total_responses' => $totalResponses,
                'total_cost' => (float) ($stats->total_cost ?? 0),
                'total_prompt_tokens' => (int) ($stats->total_prompt_tokens ?? 0),
                'total_completion_tokens' => (int) ($stats->total_completion_tokens ?? 0),
                'avg_cost_per_response' => (float) ($stats->avg_cost_per_response ?? 0),
                'today_cost' => (float) ($stats->today_cost ?? 0),
                'week_cost' => (float) ($stats->week_cost ?? 0),
                'month_cost' => (float) ($stats->month_cost ?? 0),
                // Enhanced cost tracking (OpenRouter Best Practice)
                'total_actual_cost' => $totalActualCost,
                'total_cached_tokens' => (int) ($enhancedStats->total_cached_tokens ?? 0),
                'total_reasoning_tokens' => (int) ($enhancedStats->total_reasoning_tokens ?? 0),
                'cost_savings' => $costSavings,
                'enhanced_data_coverage' => $coveragePercent,
            ],
            'by_model' => $byModel,
            'time_series' => $timeSeries,
            'by_bot' => $byBot,
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
                'group_by' => $groupBy,
            ],
        ];
    }

    /**
     * Get Semantic Cache statistics for a specific bot or all bots.
     */
    public function cacheStats(Request $request, SemanticCacheService $cacheService): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'bot_id' => [
                'sometimes',
                'integer',
                Rule::exists('bots', 'id')->where('user_id', $user->id),
            ],
        ]);

        $botId = $validated['bot_id'] ?? null;

        // If specific bot requested, return stats for that bot
        if ($botId) {
            return response()->json([
                'data' => $cacheService->getStats($botId),
            ]);
        }

        // Otherwise, return stats for all user's bots
        $bots = $user->bots()->get(['id', 'name']);
        $allStats = [];
        $totalHits = 0;
        $totalEntries = 0;

        foreach ($bots as $bot) {
            $stats = $cacheService->getStats($bot->id);
            $allStats[] = [
                'bot_id' => $bot->id,
                'bot_name' => $bot->name,
                'stats' => $stats,
            ];
            $totalHits += $stats['total_hits'] ?? 0;
            $totalEntries += $stats['active_entries'] ?? 0;
        }

        return response()->json([
            'data' => [
                'summary' => [
                    'total_bots' => $bots->count(),
                    'total_active_entries' => $totalEntries,
                    'total_hits' => $totalHits,
                    'cache_enabled' => $cacheService->isEnabled(),
                ],
                'by_bot' => $allStats,
            ],
        ]);
    }

    /**
     * Clear Semantic Cache for a specific bot.
     */
    public function clearCache(Request $request, SemanticCacheService $cacheService): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'bot_id' => [
                'required',
                'integer',
                Rule::exists('bots', 'id')->where('user_id', $user->id),
            ],
        ]);

        $deletedCount = $cacheService->clearForBot($validated['bot_id']);

        return response()->json([
            'message' => "Cleared {$deletedCount} cached entries",
            'deleted_count' => $deletedCount,
        ]);
    }
}
