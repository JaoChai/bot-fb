<?php

namespace App\Services\Webhook\Steps;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Message;
use App\Services\LeadRecoveryService;
use App\Services\Webhook\WebhookContext;
use Closure;

/**
 * Post-commit side effects shared by the legacy Facebook/Telegram jobs:
 * refresh conversation, mark lead recovery responded, broadcast MessageSent
 * (user + bot) and ConversationUpdated (created | message_received).
 * Must run OUTSIDE the DB transaction (see WebhookPipeline::transactional()).
 */
class BroadcastStep
{
    public function __construct(private readonly LeadRecoveryService $leadRecovery) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = $ctx->conversation;
        $userMessage = $ctx->userMessage;
        $botMessage = isset($ctx->metadata['bot_message_id']) ? Message::find($ctx->metadata['bot_message_id']) : null;
        $conversationData = null;

        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            $this->leadRecovery->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
        }
        if ($conversation) {
            $updateType = ! empty($ctx->metadata['is_new_conversation']) ? 'created' : 'message_received';
            broadcast(new ConversationUpdated($conversation, $updateType))->toOthers();
        }

        $next($ctx);
    }
}
