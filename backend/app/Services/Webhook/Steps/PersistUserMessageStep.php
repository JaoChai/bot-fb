<?php

namespace App\Services\Webhook\Steps;

use App\Models\Message;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dedup on external message id, save the user message, bump conversation and bot
 * stats — the shared part of the legacy Facebook/Telegram transaction bodies.
 * Short-circuits (does not call $next) on a duplicate.
 */
class PersistUserMessageStep
{
    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = $ctx->conversation;
        $externalId = $this->externalMessageId($ctx);

        if ($externalId !== null && Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $externalId)
            ->exists()) {
            Log::info('Duplicate '.ucfirst($ctx->channelType).' message ignored', [
                'conversation_id' => $conversation->id,
                'message_id' => $externalId,
            ]);

            return;
        }

        $userMessage = $conversation->messages()->create($this->messageAttributes($ctx, $externalId));

        // Update conversation stats
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $userMessage->id,
        ]);

        // Update bot stats
        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ];
        if (! empty($ctx->metadata['is_new_conversation'])) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }
        $ctx->bot->update($botUpdate);

        $ctx->userMessage = $userMessage;

        $next($ctx);
    }

    private function externalMessageId(WebhookContext $ctx): ?string
    {
        $id = match ($ctx->channelType) {
            'facebook' => $ctx->metadata['mid'] ?? null,
            'telegram' => $ctx->metadata['message_id'] ?? null,
            default => null,
        };

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function messageAttributes(WebhookContext $ctx, ?string $externalId): array
    {
        if ($ctx->channelType === 'facebook' && $ctx->eventType() === 'postback') {
            $payload = $ctx->metadata['postback_payload'] ?? null;
            $title = $ctx->metadata['postback_title'] ?? null;

            return [
                'sender' => 'user',
                'content' => $title ?: $payload,
                'type' => 'postback',
                'media_metadata' => ['postback_payload' => $payload],
            ];
        }

        if ($ctx->channelType === 'telegram') {
            $media = $ctx->metadata['media'] ?? [];
            $content = $ctx->text();
            if (! $content && $ctx->messageType() !== 'text') {
                $content = $ctx->metadata['placeholder'] ?? '[Media]';
            }

            return [
                'sender' => 'user',
                'content' => $content,
                'type' => $ctx->messageType(),
                'media_url' => $media['url'] ?? null,
                'media_type' => $media['mime_type'] ?? null,
                'media_metadata' => $media['metadata'] ?? null,
                'external_message_id' => $externalId,
                'reply_to_message_id' => $ctx->metadata['reply_to_message_id'] ?? null,
            ];
        }

        // Facebook message event
        return [
            'sender' => 'user',
            'content' => $ctx->text(),
            'type' => $ctx->messageType(),
            'media_url' => $ctx->metadata['media_url'] ?? null,
            'media_type' => null,
            'media_metadata' => $ctx->metadata['media_metadata'] ?? null,
            'external_message_id' => $externalId,
        ];
    }
}
