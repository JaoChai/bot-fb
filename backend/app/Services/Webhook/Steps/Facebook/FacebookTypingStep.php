<?php

namespace App\Services\Webhook\Steps\Facebook;

use App\Services\FacebookService;
use App\Services\Webhook\WebhookContext;
use Closure;

/**
 * Legacy ProcessFacebookWebhook::generateAIResponse() wrapped generation in
 * typing_on / typing_off sender actions. Runs them around the rest of the chain.
 */
class FacebookTypingStep
{
    public function __construct(private readonly FacebookService $facebookService) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $recipientId = (string) ($ctx->metadata['sender_id'] ?? '');
        $shouldType = $ctx->userMessage !== null && $recipientId !== '';

        if ($shouldType) {
            $this->facebookService->sendTypingIndicator($ctx->bot, $recipientId, 'typing_on');
        }

        try {
            $next($ctx);
        } finally {
            if ($shouldType) {
                $this->facebookService->sendTypingIndicator($ctx->bot, $recipientId, 'typing_off');
            }
        }
    }
}
