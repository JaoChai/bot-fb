<?php

namespace App\Services\Webhook\Steps\Line;

use App\Services\LINEService;
use App\Services\Webhook\Channels\LINE\NonTextHandler;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Reproduces the event routing at the top of the legacy ProcessLINEWebhook::processEvent():
 *  - non-message events are logged and dropped;
 *  - non-text, non-image messages go to NonTextHandler (sticker / video / audio / file / location);
 *  - text and image messages continue to LinePipelineStep.
 */
class LineEventGateStep
{
    public function __construct(
        private readonly LINEService $lineService,
        private readonly NonTextHandler $nonTextHandler,
    ) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $event = $ctx->rawEvent;

        // Only process message events
        if (! $this->lineService->isMessageEvent($event)) {
            Log::debug('Ignoring non-message event', [
                'type' => $event['type'] ?? 'unknown',
            ]);

            return;
        }

        if (! $this->lineService->isTextMessage($event) && ! $this->lineService->isImageMessage($event)) {
            $this->nonTextHandler->handle($this->lineService, $event);

            return;
        }

        $next($ctx);
    }
}
