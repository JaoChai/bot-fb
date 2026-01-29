<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Services\ConversationCacheService;
use Illuminate\Support\Facades\DB;

/**
 * Handles conversation statistics and counts.
 *
 * Extracted from ConversationService for single responsibility.
 */
class ConversationStatsService
{
    public function __construct(
        private ConversationCacheService $cacheService,
    ) {}

    /**
     * Get conversation statistics for a bot (cached).
     */
    public function getStats(Bot $bot): array
    {
        return $this->cacheService->rememberStats($bot->id, function () use ($bot) {
            $stats = DB::selectOne("
                WITH conv_base AS (
                    SELECT
                        id,
                        status,
                        channel_type,
                        message_count
                    FROM conversations
                    WHERE bot_id = ? AND deleted_at IS NULL
                ),
                status_counts AS (
                    SELECT
                        COUNT(*) as total,
                        COUNT(*) FILTER (WHERE status = 'active') as active,
                        COUNT(*) FILTER (WHERE status = 'closed') as closed,
                        COUNT(*) FILTER (WHERE status = 'handover') as handover,
                        COALESCE(AVG(message_count), 0) as avg_messages
                    FROM conv_base
                ),
                channel_counts AS (
                    SELECT jsonb_object_agg(channel_type, cnt) as by_channel
                    FROM (
                        SELECT channel_type, COUNT(*) as cnt
                        FROM conv_base
                        GROUP BY channel_type
                    ) sub
                ),
                messages_today AS (
                    SELECT COUNT(*) as count
                    FROM messages m
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    WHERE c.bot_id = ? AND c.deleted_at IS NULL
                        AND m.created_at >= CURRENT_DATE
                        AND m.created_at < CURRENT_DATE + INTERVAL '1 day'
                )
                SELECT
                    sc.total,
                    sc.active,
                    sc.closed,
                    sc.handover,
                    sc.avg_messages,
                    mt.count as messages_today,
                    COALESCE(cc.by_channel, '{}'::jsonb) as by_channel
                FROM status_counts sc
                CROSS JOIN messages_today mt
                CROSS JOIN channel_counts cc
            ", [$bot->id, $bot->id]);

            return [
                'total' => (int) ($stats->total ?? 0),
                'active' => (int) ($stats->active ?? 0),
                'closed' => (int) ($stats->closed ?? 0),
                'handover' => (int) ($stats->handover ?? 0),
                'messages_today' => (int) ($stats->messages_today ?? 0),
                'avg_messages_per_conversation' => round((float) ($stats->avg_messages ?? 0), 1),
                'by_channel' => json_decode($stats->by_channel ?? '{}', true),
            ];
        });
    }

    /**
     * Get all counts (status + response) in a single optimized CTE query with caching.
     *
     * This replaces getStatusCounts() + getResponseCounts() to reduce DB queries from 3-4 to 1.
     * Uses PostgreSQL window function ROW_NUMBER() instead of correlated subqueries.
     *
     * @return array{active: int, closed: int, handover: int, total: int, needs_response: int, waiting_customer: int}
     */
    public function getAllCounts(Bot $bot): array
    {
        return $this->cacheService->rememberCounts($bot->id, function () use ($bot) {
            // For bots without auto_handover, use simpler query without response counts
            if (! $bot->auto_handover) {
                $result = DB::selectOne("
                    SELECT
                        COUNT(*) FILTER (WHERE status = 'active') as active,
                        COUNT(*) FILTER (WHERE status = 'closed') as closed,
                        COUNT(*) FILTER (WHERE status = 'handover') as handover,
                        COUNT(*) as total
                    FROM conversations
                    WHERE bot_id = ? AND deleted_at IS NULL
                ", [$bot->id]);

                return [
                    'active' => (int) ($result->active ?? 0),
                    'closed' => (int) ($result->closed ?? 0),
                    'handover' => (int) ($result->handover ?? 0),
                    'total' => (int) ($result->total ?? 0),
                    'needs_response' => 0,
                    'waiting_customer' => 0,
                ];
            }

            // Full query with response counts using window function
            $result = DB::selectOne("
                WITH last_messages AS (
                    SELECT
                        m.conversation_id,
                        m.sender,
                        ROW_NUMBER() OVER (PARTITION BY m.conversation_id ORDER BY m.id DESC) as rn
                    FROM messages m
                    INNER JOIN conversations c ON m.conversation_id = c.id
                    WHERE c.bot_id = ? AND c.deleted_at IS NULL
                ),
                conv_with_last_sender AS (
                    SELECT c.id, c.status, lm.sender as last_sender
                    FROM conversations c
                    LEFT JOIN last_messages lm ON lm.conversation_id = c.id AND lm.rn = 1
                    WHERE c.bot_id = ? AND c.deleted_at IS NULL
                )
                SELECT
                    COUNT(*) FILTER (WHERE status = 'active') as active,
                    COUNT(*) FILTER (WHERE status = 'closed') as closed,
                    COUNT(*) FILTER (WHERE status = 'handover') as handover,
                    COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status != 'closed' AND last_sender = 'user') as needs_response,
                    COUNT(*) FILTER (WHERE status != 'closed' AND last_sender IN ('bot', 'agent')) as waiting_customer
                FROM conv_with_last_sender
            ", [$bot->id, $bot->id]);

            return [
                'active' => (int) ($result->active ?? 0),
                'closed' => (int) ($result->closed ?? 0),
                'handover' => (int) ($result->handover ?? 0),
                'total' => (int) ($result->total ?? 0),
                'needs_response' => (int) ($result->needs_response ?? 0),
                'waiting_customer' => (int) ($result->waiting_customer ?? 0),
            ];
        });
    }
}
