<?php

namespace App\Services\Webhook\Steps;

use App\Services\Channel\ChannelAdapterFactory;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Send response step (Task 9).
 *
 * Sends the generated bot message (populated by GenerateResponseStep into
 * $ctx->metadata['bot_message']) through the ChannelAdapterInterface for
 * the context's channel, resolved via ChannelAdapterFactory. No-op when
 * there is no bot message (e.g. handover / inactive bot paths).
 */
class SendResponseStep
{
    public function __construct(private ?ChannelAdapterFactory $factory = null)
    {
        $this->factory ??= app(ChannelAdapterFactory::class);
    }

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $botMessage = $ctx->metadata['bot_message'] ?? null;

        if ($botMessage !== null && $ctx->conversation !== null) {
            $adapter = $this->factory->make($ctx->channelType);

            try {
                $adapter->sendMessage(
                    $ctx->bot,
                    $ctx->conversation,
                    (string) ($botMessage['type'] ?? 'text'),
                    (string) ($botMessage['content'] ?? ''),
                    $botMessage['media_url'] ?? null
                );
            } catch (\Exception $e) {
                Log::error('Failed to send webhook response via channel adapter', [
                    'bot_id' => $ctx->bot->id,
                    'channel_type' => $ctx->channelType,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $next($ctx);
    }
}
