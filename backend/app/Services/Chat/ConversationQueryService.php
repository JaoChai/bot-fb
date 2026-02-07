<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Handles conversation listing and search.
 *
 * Extracted from ConversationService for single responsibility.
 */
class ConversationQueryService
{
    public function __construct(
        private ConversationStatsService $statsService,
    ) {}

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

        // Optimized: Single CTE query with cache instead of 3-4 separate queries
        $allCounts = $this->statsService->getAllCounts($bot);

        $conversations = $query->paginate($request->input('per_page', 20));

        return [
            'conversations' => $conversations,
            'status_counts' => $allCounts,
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
                if ($messagesLimit) {
                    $query->orderBy('created_at', 'desc')->limit($messagesLimit);
                } else {
                    $query->orderBy('created_at', 'asc');
                }
            },
        ]);

        // When limited, reverse to get chronological order (asc)
        if ($messagesLimit && $conversation->relationLoaded('messages')) {
            $conversation->setRelation('messages', $conversation->messages->reverse()->values());
        }

        return $conversation;
    }

    /**
     * Apply filters to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
     */
    private function applyFilters($query, Request $request): void
    {
        // Filter by status (default to active/handover if not specified)
        if ($request->filled('status')) {
            $statuses = is_array($request->status)
                ? $request->status
                : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        } else {
            // Default: exclude closed conversations unless explicitly requested
            $query->whereIn('status', ['active', 'handover']);
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
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
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
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\HasMany $query
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
}
