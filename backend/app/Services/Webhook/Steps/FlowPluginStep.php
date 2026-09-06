<?php

namespace App\Services\Webhook\Steps;

use App\Models\Message;
use App\Services\FlowPluginService;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Legacy ProcessTelegramWebhook ran flow plugins after sending the bot reply.
 */
class FlowPluginStep
{
    public function __construct(private readonly FlowPluginService $plugins) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $botMessageId = $ctx->metadata['bot_message_id'] ?? null;
        if ($botMessageId !== null && $ctx->conversation !== null) {
            $botMessage = Message::find($botMessageId);
            if ($botMessage) {
                try {
                    $this->plugins->executePlugins($ctx->bot, $ctx->conversation, $botMessage);
                } catch (\Exception $e) {
                    Log::warning('Flow plugin execution failed in '.ucfirst($ctx->channelType).' webhook', [
                        'conversation_id' => $ctx->conversation->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $next($ctx);
    }
}
