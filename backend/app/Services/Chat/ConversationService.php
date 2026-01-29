<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Facade for conversation-related operations.
 *
 * Delegates to specialized services:
 * - ConversationQueryService: Listing, filtering, searching
 * - ConversationStatsService: Statistics and counts
 * - ConversationContextService: Context clearing
 * - ConversationStatusService: Status changes and updates
 *
 * This class is kept for backward compatibility with existing controllers.
 */
class ConversationService
{
    public function __construct(
        private ConversationQueryService $queryService,
        private ConversationStatsService $statsService,
        private ConversationContextService $contextService,
        private ConversationStatusService $statusService,
    ) {}

    /**
     * List conversations for a bot with filters, pagination, and search.
     *
     * @return array{conversations: LengthAwarePaginator, status_counts: array}
     */
    public function listConversations(Bot $bot, Request $request): array
    {
        return $this->queryService->listConversations($bot, $request);
    }

    /**
     * Get a single conversation with optional message limit.
     */
    public function getConversation(Conversation $conversation, ?int $messagesLimit = null): Conversation
    {
        return $this->queryService->getConversation($conversation, $messagesLimit);
    }

    /**
     * Update a conversation with validated data.
     */
    public function updateConversation(Conversation $conversation, array $data): Conversation
    {
        return $this->statusService->updateConversation($conversation, $data);
    }

    /**
     * Close a conversation.
     */
    public function closeConversation(Conversation $conversation): Conversation
    {
        return $this->statusService->closeConversation($conversation);
    }

    /**
     * Reopen a closed conversation.
     */
    public function reopenConversation(Conversation $conversation): Conversation
    {
        return $this->statusService->reopenConversation($conversation);
    }

    /**
     * Clear bot context for a conversation.
     */
    public function clearContext(Conversation $conversation): Conversation
    {
        return $this->contextService->clearContext($conversation);
    }

    /**
     * Clear bot context for all active/handover conversations.
     */
    public function clearContextAll(Bot $bot): int
    {
        return $this->contextService->clearContextAll($bot);
    }

    /**
     * Get conversation statistics for a bot (cached).
     */
    public function getStats(Bot $bot): array
    {
        return $this->statsService->getStats($bot);
    }
}
