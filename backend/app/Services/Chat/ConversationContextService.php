<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\ConversationCacheService;

/**
 * Handles conversation context clearing.
 *
 * Extracted from ConversationService for single responsibility.
 */
class ConversationContextService
{
    public function __construct(
        private ConversationCacheService $cacheService,
    ) {}

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

        $this->cacheService->invalidateAll($bot->id);

        return $updatedCount;
    }
}
