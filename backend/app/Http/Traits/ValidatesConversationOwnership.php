<?php

namespace App\Http\Traits;

use App\Models\Bot;
use App\Models\Conversation;

/**
 * Trait for validating conversation ownership.
 *
 * Extracted to eliminate code duplication across 5 conversation controllers.
 * Previously duplicated 24+ times across:
 * - ConversationController
 * - ConversationMessageController
 * - ConversationAssignmentController
 * - ConversationTagController
 * - ConversationNoteController
 */
trait ValidatesConversationOwnership
{
    /**
     * Validate that the conversation belongs to the given bot.
     *
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    protected function validateConversationBelongsToBot(Conversation $conversation, Bot $bot): void
    {
        if ($conversation->bot_id !== $bot->id) {
            abort(404, 'Conversation not found');
        }
    }
}
