<?php

namespace App\Http\Controllers;

use App\Models\AgentCostUsage;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Parse date range (default: last 30 days)
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Previous period for comparison
        $periodLength = $startDate->diffInDays($endDate);
        $prevStartDate = (clone $startDate)->subDays($periodLength);
        $prevEndDate = (clone $endDate)->subDays($periodLength);

        // Get user's bot IDs
        $botIds = Bot::where('user_id', $user->id)->pluck('id');

        // Handle case where user has no bots
        if ($botIds->isEmpty()) {
            return Inertia::render('Dashboard', [
                'stats' => $this->getEmptyStats(),
                'changes' => $this->getEmptyChanges(),
                'conversationTrend' => [],
                'costBreakdown' => [],
                'recentConversations' => [],
                'filters' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
            ]);
        }

        // Current period stats
        $stats = $this->calculateStats($botIds, $startDate, $endDate, $user->id);

        // Previous period stats for comparison
        $prevStats = $this->calculateStats($botIds, $prevStartDate, $prevEndDate, $user->id);

        // Calculate percentage changes
        $changes = $this->calculateChanges($stats, $prevStats);

        // Conversation trend (group by date)
        $conversationTrend = $this->getConversationTrend($botIds, $startDate, $endDate);

        // Cost breakdown by model
        $costBreakdown = $this->getCostBreakdown($botIds, $startDate, $endDate);

        // Recent conversations (limit to 5)
        $recentConversations = $this->getRecentConversations($botIds);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'changes' => $changes,
            'conversationTrend' => $conversationTrend,
            'costBreakdown' => $costBreakdown,
            'recentConversations' => $recentConversations,
            'filters' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Calculate stats for a given period.
     */
    private function calculateStats(
        \Illuminate\Support\Collection $botIds,
        Carbon $startDate,
        Carbon $endDate,
        int $userId
    ): array {
        $totalConversations = Conversation::whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $totalMessages = Message::whereHas('conversation', fn ($q) => $q->whereIn('bot_id', $botIds))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Use AgentCostUsage for AI cost tracking
        $totalAiCost = AgentCostUsage::whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('estimated_cost') ?? 0;

        return [
            'total_bots' => $botIds->count(),
            'total_conversations' => $totalConversations,
            'total_messages' => $totalMessages,
            'total_ai_cost' => (float) $totalAiCost,
            'total_ai_cost_formatted' => $this->formatThaiBaht($totalAiCost),
        ];
    }

    /**
     * Calculate percentage changes between current and previous periods.
     */
    private function calculateChanges(array $current, array $previous): array
    {
        return [
            'conversations' => $this->calculatePercentChange(
                $current['total_conversations'],
                $previous['total_conversations']
            ),
            'messages' => $this->calculatePercentChange(
                $current['total_messages'],
                $previous['total_messages']
            ),
            'ai_cost' => $this->calculatePercentChange(
                $current['total_ai_cost'],
                $previous['total_ai_cost']
            ),
        ];
    }

    /**
     * Calculate percentage change between two values.
     */
    private function calculatePercentChange(float $current, float $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Get conversation trend grouped by date.
     */
    private function getConversationTrend(
        \Illuminate\Support\Collection $botIds,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        return Conversation::whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'count' => (int) $item->count,
            ])
            ->toArray();
    }

    /**
     * Get cost breakdown by model.
     */
    private function getCostBreakdown(
        \Illuminate\Support\Collection $botIds,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        return AgentCostUsage::whereIn('bot_id', $botIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                'model_used',
                DB::raw('SUM(estimated_cost) as total_cost'),
                DB::raw('COUNT(*) as request_count'),
                DB::raw('SUM(prompt_tokens) as total_prompt_tokens'),
                DB::raw('SUM(completion_tokens) as total_completion_tokens')
            )
            ->groupBy('model_used')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn ($item) => [
                'model' => $item->model_used ?? 'unknown',
                'total_cost' => (float) $item->total_cost,
                'total_cost_formatted' => $this->formatThaiBaht($item->total_cost),
                'request_count' => (int) $item->request_count,
                'total_tokens' => (int) ($item->total_prompt_tokens + $item->total_completion_tokens),
            ])
            ->toArray();
    }

    /**
     * Get recent conversations (limit to 5).
     */
    private function getRecentConversations(\Illuminate\Support\Collection $botIds): array
    {
        return Conversation::whereIn('bot_id', $botIds)
            ->with([
                'bot:id,name,channel_type',
                'customerProfile:id,display_name,picture_url',
                'lastMessage:id,conversation_id,content,sender,created_at',
            ])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get()
            ->map(fn ($conversation) => [
                'id' => $conversation->id,
                'bot_name' => $conversation->bot->name ?? 'Unknown',
                'channel_type' => $conversation->bot->channel_type ?? 'unknown',
                'customer_name' => $conversation->customerProfile->display_name ?? 'Unknown',
                'customer_avatar' => $conversation->customerProfile->avatar_url ?? null,
                'last_message' => $conversation->lastMessage?->content ?? null,
                'last_message_sender' => $conversation->lastMessage?->sender ?? null,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'is_handover' => $conversation->is_handover,
                'unread_count' => $conversation->unread_count,
            ])
            ->toArray();
    }

    /**
     * Format amount as Thai Baht.
     */
    private function formatThaiBaht(float $amount): string
    {
        return number_format($amount, 2) . ' THB';
    }

    /**
     * Get empty stats for users with no bots.
     */
    private function getEmptyStats(): array
    {
        return [
            'total_bots' => 0,
            'total_conversations' => 0,
            'total_messages' => 0,
            'total_ai_cost' => 0,
            'total_ai_cost_formatted' => '0.00 THB',
        ];
    }

    /**
     * Get empty changes for users with no bots.
     */
    private function getEmptyChanges(): array
    {
        return [
            'conversations' => null,
            'messages' => null,
            'ai_cost' => null,
        ];
    }
}
