<?php

namespace App\Services\Webhook\Steps;

use App\Services\AIService;
use App\Services\Webhook\WebhookContext;
use Closure;

/**
 * Generate response step (Task 9).
 *
 * Thin delegation to AIService::generateAndSaveResponse — the same AI path
 * all three webhook jobs use today. Sets $ctx->metadata['bot_message_id'] to
 * the saved bot message id (or leaves the key absent when there is no user
 * message to respond to).
 */
class GenerateResponseStep
{
    public function __construct(private ?AIService $aiService = null)
    {
        $this->aiService ??= app(AIService::class);
    }

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        if ($ctx->userMessage !== null) {
            $botMessage = $this->aiService->generateAndSaveResponse(
                $ctx->bot,
                $ctx->conversation,
                $ctx->userMessage
            );

            $ctx->metadata['bot_message_id'] = $botMessage->id;
            $ctx->metadata['bot_message'] = [
                'content' => $botMessage->content,
                'type' => $botMessage->type,
                'media_url' => $botMessage->media_url,
            ];
        }

        $next($ctx);
    }
}
