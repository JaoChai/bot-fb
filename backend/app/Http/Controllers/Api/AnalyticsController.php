<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    /**
     * Get cost analytics with flexible date ranges and grouping.
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

        // Base query - messages from user's bots
        $baseQuery = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('bots', 'conversations.bot_id', '=', 'bots.id')
            ->where('bots.user_id', $user->id)
            ->where('messages.sender', 'bot')
            ->whereNotNull('messages.cost')
            ->whereBetween('messages.created_at', [$fromDate, $toDate]);

        // Filter by bot if specified
        if (isset($validated['bot_id'])) {
            $baseQuery->where('bots.id', $validated['bot_id']);
        }

        // Summary stats
        $summary = (clone $baseQuery)
            ->selectRaw('
                COUNT(*) as total_responses,
                COALESCE(SUM(messages.cost), 0) as total_cost,
                COALESCE(SUM(messages.prompt_tokens), 0) as total_prompt_tokens,
                COALESCE(SUM(messages.completion_tokens), 0) as total_completion_tokens,
                COALESCE(AVG(messages.cost), 0) as avg_cost_per_response
            ')
            ->first();

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
        $dateFormat = match ($groupBy) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-IW',
            'month' => 'YYYY-MM',
        };

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
        if (! isset($validated['bot_id'])) {
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

        // Today's cost for quick stat
        $todayCost = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('bots', 'conversations.bot_id', '=', 'bots.id')
            ->where('bots.user_id', $user->id)
            ->where('messages.sender', 'bot')
            ->whereNotNull('messages.cost')
            ->whereDate('messages.created_at', today())
            ->sum('messages.cost');

        // This week's cost
        $weekCost = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('bots', 'conversations.bot_id', '=', 'bots.id')
            ->where('bots.user_id', $user->id)
            ->where('messages.sender', 'bot')
            ->whereNotNull('messages.cost')
            ->whereBetween('messages.created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('messages.cost');

        // This month's cost
        $monthCost = Message::query()
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('bots', 'conversations.bot_id', '=', 'bots.id')
            ->where('bots.user_id', $user->id)
            ->where('messages.sender', 'bot')
            ->whereNotNull('messages.cost')
            ->whereBetween('messages.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('messages.cost');

        return response()->json([
            'data' => [
                'summary' => [
                    'total_responses' => (int) ($summary->total_responses ?? 0),
                    'total_cost' => (float) ($summary->total_cost ?? 0),
                    'total_prompt_tokens' => (int) ($summary->total_prompt_tokens ?? 0),
                    'total_completion_tokens' => (int) ($summary->total_completion_tokens ?? 0),
                    'avg_cost_per_response' => (float) ($summary->avg_cost_per_response ?? 0),
                    'today_cost' => (float) ($todayCost ?? 0),
                    'week_cost' => (float) ($weekCost ?? 0),
                    'month_cost' => (float) ($monthCost ?? 0),
                ],
                'by_model' => $byModel,
                'time_series' => $timeSeries,
                'by_bot' => $byBot,
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                    'group_by' => $groupBy,
                ],
            ],
        ]);
    }
}
