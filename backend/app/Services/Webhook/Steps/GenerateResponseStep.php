<?php

namespace App\Services\Webhook\Steps;

use App\Services\AIService;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generate response step (Task 9).
 *
 * Generates the bot reply via AIService::generateAndSaveResponse when the
 * legacy guards allow (user message present, bot active, not in handover,
 * FB postback or non-empty text). Bumps conversation/bot stats for the bot
 * message like legacy FB/TG and sets $ctx->metadata['bot_message_id'] /
 * ['bot_message'] for SendResponseStep. Generation failures are logged and
 * swallowed so the pipeline continues.
 */
class GenerateResponseStep
{
    public function __construct(private ?AIService $aiService = null)
    {
        $this->aiService ??= app(AIService::class);
    }

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        if (self::shouldGenerate($ctx)) {
            try {
                $botMessage = $this->aiService->generateAndSaveResponse(
                    $ctx->bot,
                    $ctx->conversation,
                    $ctx->userMessage
                );

                // Legacy FB/TG: bump conversation + bot stats for the bot message
                // (AIService already increments bot total_messages once; legacy adds one more — preserved).
                $ctx->conversation->update([
                    'message_count' => DB::raw('message_count + 1'),
                    'last_message_at' => now(),
                    'last_message_id' => $botMessage->id,
                ]);
                $ctx->bot->update([
                    'total_messages' => DB::raw('total_messages + 1'),
                ]);

                $ctx->metadata['bot_message_id'] = $botMessage->id;
                $ctx->metadata['bot_message'] = [
                    'content' => $botMessage->content,
                    'type' => $botMessage->type,
                    'media_url' => $botMessage->media_url,
                ];
            } catch (\Exception $e) {
                // Legacy generateAIResponse() swallowed and logged; the user message is already saved.
                Log::error('Failed to generate AI response for '.ucfirst($ctx->channelType), [
                    'conversation_id' => $ctx->conversation?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $next($ctx);
    }

    public static function shouldGenerate(WebhookContext $ctx): bool
    {
        if ($ctx->userMessage === null || $ctx->conversation === null) {
            return false;
        }
        if (! empty($ctx->metadata['is_handover']) || $ctx->bot->status !== 'active') {
            return false;
        }
        if ($ctx->channelType === 'facebook' && $ctx->eventType() === 'postback') {
            return true;
        }

        return $ctx->messageType() === 'text' && (string) $ctx->userMessage->content !== '';
    }
}
