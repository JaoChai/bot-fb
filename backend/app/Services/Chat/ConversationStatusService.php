<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Services\ConversationCacheService;

/**
 * Handles conversation status changes and updates.
 *
 * Extracted from ConversationService for single responsibility.
 */
class ConversationStatusService
{
    public function __construct(
        private ConversationCacheService $cacheService,
    ) {}

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

        $this->cacheService->invalidateAll($conversation->bot_id);

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

        $this->cacheService->invalidateAll($conversation->bot_id);

        return $conversation;
    }
}
