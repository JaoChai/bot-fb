<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * List conversations for a bot with filters, pagination, and search.
     *
     * @return array{conversations: LengthAwarePaginator, status_counts: array}
     */
    public function listConversations(Bot $bot, Request $request): array
    {
        $query = $bot->conversations()
            ->with(['customerProfile', 'assignedUser', 'lastMessage']);

        $this->applyFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySorting($query, $request);

        $statusCounts = $this->getStatusCounts($bot);
        $responseCounts = $this->getResponseCounts($bot);

        $conversations = $query->paginate($request->input('per_page', 20));

        return [
            'conversations' => $conversations,
            'status_counts' => array_merge($statusCounts, $responseCounts),
        ];
    }

    /**
     * Get a single conversation with optional message limit.
     */
    public function getConversation(Conversation $conversation, ?int $messagesLimit = null): Conversation
    {
        $conversation->load([
            'customerProfile',
            'assignedUser',
            'currentFlow',
            'messages' => function ($query) use ($messagesLimit) {
                $query->orderBy('created_at', 'asc');
                if ($messagesLimit) {
                    $query->limit($messagesLimit);
                }
            },
        ]);

        return $conversation;
    }

    /**
     * Update a conversation with validated data.
     */
    public function updateConversation(Conversation $conversation, array $data): Conversation
    {
        // Cast boolean field for PostgreSQL
        if (isset($data['is_handover'])) {
            $data['is_handover'] = (bool) $data['is_handover'];
        }

        $conversation->update($data);
        $conversation->load(['customerProfile', 'assignedUser']);

        return $conversation;
    }

    /**
     * Close a conversation.
     */
    public function closeConversation(Conversation $conversation): Conversation
    {
        $conversation->update([
            'status' => 'closed',
            'is_handover' => false,
            'assigned_user_id' => null,
        ]);
        $conversation->load(['customerProfile']);

        $this->invalidateStatsCache($conversation->bot_id);

        return $conversation;
    }

    /**
     * Reopen a closed conversation.
     */
    public function reopenConversation(Conversation $conversation): Conversation
    {
        $conversation->update([
            'status' => 'active',
        ]);
        $conversation->load(['customerProfile']);

        $this->invalidateStatsCache($conversation->bot_id);

        return $conversation;
    }

    /**
     * Clear bot context for a conversation.
     */
    public function clearContext(Conversation $conversation): Conversation
    {
        $conversation->update(['context_cleared_at' => now()]);
        $conversation->load(['customerProfile']);

        return $conversation;
    }

    /**
     * Clear bot context for all active/handover conversations.
     */
    public function clearContextAll(Bot $bot): int
    {
        $updatedCount = $bot->conversations()
            ->whereIn('status', ['active', 'handover'])
            ->update(['context_cleared_at' => now()]);

        $this->invalidateStatsCache($bot->id);

        return $updatedCount;
    }

    /**
     * Get conversation statistics for a bot (cached).
     */
    public function getStats(Bot $bot): array
    {
        $cacheKey = "bot:{$bot->id}:conversation:stats";

        $stats = Cache::remember($cacheKey, 30, fn () => DB::selectOne("
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
        ", [$bot->id, $bot->id]));

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'closed' => (int) ($stats->closed ?? 0),
            'handover' => (int) ($stats->handover ?? 0),
            'messages_today' => (int) ($stats->messages_today ?? 0),
            'avg_messages_per_conversation' => round((float) ($stats->avg_messages ?? 0), 1),
            'by_channel' => json_decode($stats->by_channel ?? '{}', true),
        ];
    }

    /**
     * Apply filters to the query.
     *
     * @param Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
     */
    private function applyFilters($query, Request $request): void
    {
        // Filter by status
        if ($request->filled('status')) {
            $statuses = is_array($request->status)
                ? $request->status
                : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        // Filter by channel type
        if ($request->filled('channel_type')) {
            $query->where('channel_type', $request->channel_type);
        }

        // Filter by Telegram chat type
        if ($request->filled('telegram_chat_type')) {
            $chatTypes = is_array($request->telegram_chat_type)
                ? $request->telegram_chat_type
                : explode(',', $request->telegram_chat_type);
            $query->whereIn('telegram_chat_type', $chatTypes);
        }

        // Filter by handover status
        if ($request->has('is_handover')) {
            $query->where('is_handover', filter_var($request->is_handover, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by assigned user
        if ($request->filled('assigned_user_id')) {
            $query->where('assigned_user_id', $request->assigned_user_id);
        }

        // Filter by tags
        if ($request->filled('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            foreach ($tags as $tag) {
                $query->whereJsonContains('tags', trim($tag));
            }
        }

        // Date range filters
        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->to_date);
        }
    }

    /**
     * Apply search to the query.
     *
     * @param Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
     */
    private function applySearch($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->leftJoin('customer_profiles as cp_search', 'conversations.customer_profile_id', '=', 'cp_search.id')
                ->where(function ($q) use ($search) {
                    $q->where('conversations.external_customer_id', 'ilike', "%{$search}%")
                        ->orWhereRaw(
                            "to_tsvector('simple', coalesce(cp_search.display_name, '') || ' ' || coalesce(cp_search.email, '') || ' ' || coalesce(cp_search.phone, '')) @@ plainto_tsquery('simple', ?)",
                            [$search]
                        );
                })
                ->select('conversations.*');
        }
    }

    /**
     * Apply sorting to the query.
     *
     * @param Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
     */
    private function applySorting($query, Request $request): void
    {
        $sortField = $request->input('sort_by', 'last_message_at');
        $sortDirection = $request->input('sort_direction', 'desc');

        $allowedSortFields = ['last_message_at', 'created_at', 'message_count', 'status'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('last_message_at');
        }
    }

    /**
     * Get status counts for a bot.
     */
    private function getStatusCounts(Bot $bot): array
    {
        $statusCounts = $bot->conversations()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'active' => $statusCounts['active'] ?? 0,
            'closed' => $statusCounts['closed'] ?? 0,
            'handover' => $statusCounts['handover'] ?? 0,
            'total' => array_sum($statusCounts),
        ];
    }

    /**
     * Get response counts (needs_response, waiting_customer) for a bot.
     */
    private function getResponseCounts(Bot $bot): array
    {
        $needsResponseCount = 0;
        $waitingCustomerCount = 0;

        if ($bot->auto_handover) {
            $needsResponseCount = $bot->conversations()
                ->where('status', '!=', 'closed')
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('messages as m')
                        ->whereColumn('m.conversation_id', 'conversations.id')
                        ->where('m.sender', 'user')
                        ->whereRaw('m.id = (SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = conversations.id)');
                })
                ->count();

            $waitingCustomerCount = $bot->conversations()
                ->where('status', '!=', 'closed')
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('messages as m')
                        ->whereColumn('m.conversation_id', 'conversations.id')
                        ->whereIn('m.sender', ['bot', 'agent'])
                        ->whereRaw('m.id = (SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = conversations.id)');
                })
                ->count();
        }

        return [
            'needs_response' => $needsResponseCount,
            'waiting_customer' => $waitingCustomerCount,
        ];
    }

    /**
     * Invalidate stats cache for a bot.
     */
    private function invalidateStatsCache(int $botId): void
    {
        Cache::forget("bot:{$botId}:conversation:stats");
    }
}
