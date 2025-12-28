<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
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
            ->get();

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
            ->get();

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
                ->get();
        }

        return [
            'summary' => [
                'total_responses' => (int) ($stats->total_responses ?? 0),
                'total_cost' => (float) ($stats->total_cost ?? 0),
                'total_prompt_tokens' => (int) ($stats->total_prompt_tokens ?? 0),
                'total_completion_tokens' => (int) ($stats->total_completion_tokens ?? 0),
                'avg_cost_per_response' => (float) ($stats->avg_cost_per_response ?? 0),
                'today_cost' => (float) ($stats->today_cost ?? 0),
                'week_cost' => (float) ($stats->week_cost ?? 0),
                'month_cost' => (float) ($stats->month_cost ?? 0),
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
}
