<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\ConversationCacheService;
use Illuminate\Support\Facades\Log;

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
     * Auto-clear context if conversation has been idle for too long.
     * Returns true if context was cleared.
     */
    public function autoClearIfIdle(Conversation $conversation, int $idleHours = 6): bool
    {
        // Skip if no previous messages (new conversation)
        if (! $conversation->last_message_at) {
            return false;
        }

        // Skip if already cleared recently (within threshold)
        if ($conversation->context_cleared_at
            && $conversation->context_cleared_at->gte($conversation->last_message_at)) {
            return false;
        }

        // Check if idle for too long
        if ($conversation->last_message_at->diffInHours(now()) < $idleHours) {
            return false;
        }

        // Auto-clear
        $conversation->update(['context_cleared_at' => now()]);

        Log::info('Auto-cleared context for idle conversation', [
            'conversation_id' => $conversation->id,
            'last_message_at' => $conversation->last_message_at->toISOString(),
            'idle_hours' => $conversation->last_message_at->diffInHours(now()),
        ]);

        return true;
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
