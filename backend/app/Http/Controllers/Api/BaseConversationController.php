<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ValidatesConversationOwnership;
use App\Models\Conversation;
use App\Services\ConversationCacheService;

/**
 * Base controller for conversation-related endpoints.
 *
 * Provides common functionality:
 * - Conversation ownership validation via trait
 * - Cache service injection
 * - Shared helper methods
 *
 * Extend this controller for:
 * - ConversationController
 * - ConversationMessageController
 * - ConversationAssignmentController
 * - ConversationTagController
 * - ConversationNoteController
 */
abstract class BaseConversationController extends Controller
{
    use ValidatesConversationOwnership;

    protected ConversationCacheService $cacheService;

    public function __construct(ConversationCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Invalidate conversation caches for a bot.
     *
     * Helper method to centralize cache invalidation.
     */
    protected function invalidateConversationCaches(int $botId): void
    {
        $this->cacheService->invalidateAll($botId);
    }

    /**
     * Load customer profile and assigned user relationships.
     *
     * Helper method to reduce duplication across assignment endpoints.
     */
    protected function loadConversationRelationships(Conversation $conversation): Conversation
    {
        return $conversation->load(['customerProfile', 'assignedUser']);
    }
}
