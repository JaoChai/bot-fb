<?php

namespace App\Services\Webhook\Steps;

use App\Models\Message;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Drop the event when a message with the same external id already exists in
 * the conversation. Runs before any media download or persistence — the same
 * position the legacy Facebook/Telegram jobs checked it (dedup first, then
 * processMedia / save). Short-circuits (does not call $next) on a duplicate.
 */
class DedupUserMessageStep
{
    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = $ctx->conversation;
        $externalId = self::externalMessageId($ctx);

        if ($externalId !== null && Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $externalId)
            ->exists()) {
            Log::info('Duplicate '.ucfirst($ctx->channelType).' message ignored', [
                'conversation_id' => $conversation->id,
                'message_id' => $externalId,
            ]);

            return;
        }

        $next($ctx);
    }

    /**
     * Provider message id used for dedup and stored as messages.external_message_id.
     */
    public static function externalMessageId(WebhookContext $ctx): ?string
    {
        $id = match ($ctx->channelType) {
            'facebook' => $ctx->metadata['mid'] ?? null,
            'telegram' => $ctx->metadata['message_id'] ?? null,
            default => null,
        };

        return $id === null || $id === '' ? null : (string) $id;
    }
}
