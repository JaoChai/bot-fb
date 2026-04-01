<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary data
     * GET /api/dashboard/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $botIds = Bot::where('user_id', $user->id)->pluck('id');

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        // Get aggregated summary using CTE for efficiency
        $summaryStats = $this->getSummaryStats($botIds, $isSqlite);

        // Get bots with their metrics
        $bots = $this->getBotsWithMetrics($user->id, $isSqlite);

        // Get alerts
        $alerts = $this->getAlerts($user->id, $botIds);

        // Get recent activity
        $recentActivity = ActivityLog::getRecentForUser($user->id, 10);

        return response()->json([
            'data' => [
                'summary' => $summaryStats,
                'bots' => $bots,
                'alerts' => $alerts,
                'recent_activity' => $recentActivity->map(fn ($activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'title' => $activity->title,
                    'description' => $activity->description,
                    'bot_id' => $activity->bot_id,
                    'bot_name' => $activity->bot?->name,
                    'metadata' => $activity->metadata,
                    'created_at' => $activity->created_at->toISOString(),
                ]),
            ],
        ]);
    }

    /**
     * Get aggregated summary statistics
     */
    protected function getSummaryStats($botIds, bool $isSqlite = false): array
    {
        if ($botIds->isEmpty()) {
            return [
                'total_bots' => 0,
                'active_bots' => 0,
                'total_conversations' => 0,
                'active_conversations' => 0,
                'handover_conversations' => 0,
                'messages_today' => 0,
                'messages_yesterday' => 0,
                'vip_customers' => 0,
                'vip_total_spent' => 0,
            ];
        }

        $botIdsStr = $botIds->implode(',');
        $todayEnd = $isSqlite ? "date(CURRENT_DATE, '+1 day')" : "CURRENT_DATE + INTERVAL '1 day'";
        $yesterdayStart = $isSqlite ? "date(CURRENT_DATE, '-1 day')" : "CURRENT_DATE - INTERVAL '1 day'";
        $notesLike = $isSqlite ? "CAST(c.memory_notes AS TEXT) LIKE '%VIP%'" : "c.memory_notes::text ILIKE '%VIP%'";

        $stats = DB::selectOne("
            WITH bot_stats AS (
                SELECT
                    COUNT(*) as total_bots,
                    COUNT(*) FILTER (WHERE status = 'active') as active_bots
                FROM bots
                WHERE id IN ({$botIdsStr}) AND deleted_at IS NULL
            ),
            conv_stats AS (
                SELECT
                    COUNT(*) as total_conversations,
                    COUNT(*) FILTER (WHERE status = 'active') as active_conversations,
                    COUNT(*) FILTER (WHERE status = 'handover') as handover_conversations
                FROM conversations
                WHERE bot_id IN ({$botIdsStr}) AND deleted_at IS NULL
            ),
            msg_today AS (
                SELECT COUNT(*) as messages_today
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE c.bot_id IN ({$botIdsStr})
                    AND c.deleted_at IS NULL
                    AND m.created_at >= CURRENT_DATE
                    AND m.created_at < {$todayEnd}
            ),
            yesterday_msgs AS (
                SELECT COUNT(*) as messages_yesterday
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.id
                WHERE c.bot_id IN ({$botIdsStr})
                    AND c.deleted_at IS NULL
                    AND m.created_at >= {$yesterdayStart}
                    AND m.created_at < CURRENT_DATE
            ),
            vip_customers AS (
                SELECT DISTINCT cp.id
                FROM customer_profiles cp
                INNER JOIN conversations c ON c.customer_profile_id = cp.id
                WHERE c.bot_id IN ({$botIdsStr})
                    AND c.deleted_at IS NULL
                    AND {$notesLike}
            ),
            vip_stats AS (
                SELECT
                    (SELECT COUNT(*) FROM vip_customers) as vip_customers,
                    COALESCE((SELECT SUM(total_amount) FROM orders WHERE customer_profile_id IN (SELECT id FROM vip_customers)), 0) as vip_total_spent
            )
            SELECT
                bs.total_bots,
                bs.active_bots,
                cs.total_conversations,
                cs.active_conversations,
                cs.handover_conversations,
                mt.messages_today,
                ym.messages_yesterday,
                vs.vip_customers,
                vs.vip_total_spent
            FROM bot_stats bs
            CROSS JOIN conv_stats cs
            CROSS JOIN msg_today mt
            CROSS JOIN yesterday_msgs ym
            CROSS JOIN vip_stats vs
        ");

        return [
            'total_bots' => (int) ($stats->total_bots ?? 0),
            'active_bots' => (int) ($stats->active_bots ?? 0),
            'total_conversations' => (int) ($stats->total_conversations ?? 0),
            'active_conversations' => (int) ($stats->active_conversations ?? 0),
            'handover_conversations' => (int) ($stats->handover_conversations ?? 0),
            'messages_today' => (int) ($stats->messages_today ?? 0),
            'messages_yesterday' => (int) ($stats->messages_yesterday ?? 0),
            'vip_customers' => (int) ($stats->vip_customers ?? 0),
            'vip_total_spent' => (float) ($stats->vip_total_spent ?? 0),
        ];
    }

    /**
     * Get bots with their metrics
     */
    protected function getBotsWithMetrics(int $userId, bool $isSqlite = false): array
    {
        $bots = Bot::where('user_id', $userId)
            ->withCount([
                'conversations',
                'conversations as active_conversations_count' => fn ($q) => $q->where('status', 'active'),
                'conversations as handover_count' => fn ($q) => $q->where('status', 'handover'),
            ])
            ->get();

        // Batch query: get messages_today for ALL bots in 1 query (instead of N+1)
        $messagesTodayByBot = [];
        if ($bots->isNotEmpty()) {
            $todayEnd = $isSqlite ? "messages.created_at < date(CURRENT_DATE, '+1 day')" : "messages.created_at < CURRENT_DATE + INTERVAL '1 day'";
            $messagesTodayByBot = DB::table('messages')
                ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
                ->whereIn('conversations.bot_id', $bots->pluck('id'))
                ->whereNull('conversations.deleted_at')
                ->whereRaw('messages.created_at >= CURRENT_DATE')
                ->whereRaw($todayEnd)
                ->groupBy('conversations.bot_id')
                ->selectRaw('conversations.bot_id, COUNT(*) as count')
                ->pluck('count', 'bot_id')
                ->toArray();
        }

        return $bots->map(function ($bot) use ($messagesTodayByBot) {
            return [
                'id' => $bot->id,
                'name' => $bot->name,
                'status' => $bot->status,
                'channel_type' => $bot->channel_type,
                'last_active_at' => $bot->last_active_at?->toISOString(),
                'conversation_count' => $bot->conversations_count,
                'active_conversations' => $bot->active_conversations_count,
                'handover_count' => $bot->handover_count,
                'messages_today' => (int) ($messagesTodayByBot[$bot->id] ?? 0),
            ];
        })->toArray();
    }

    /**
     * Get alerts (handover conversations)
     */
    protected function getAlerts(int $userId, $botIds): array
    {
        if ($botIds->isEmpty()) {
            return [
                'handover_conversations' => [],
            ];
        }

        // Handover conversations
        $handoverConversations = Conversation::whereIn('bot_id', $botIds)
            ->where('status', 'handover')
            ->with(['bot:id,name', 'customerProfile:id,display_name'])
            ->orderBy('updated_at', 'asc') // Oldest waiting first
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'bot_id' => $c->bot_id,
                'bot_name' => $c->bot->name,
                'customer_name' => $c->customerProfile?->display_name ?? $c->external_customer_id,
                'waiting_since' => $c->updated_at->toISOString(),
            ]);

        return [
            'handover_conversations' => $handoverConversations,
        ];
    }
}
