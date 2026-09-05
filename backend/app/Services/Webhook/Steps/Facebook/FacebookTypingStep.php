<?php

namespace App\Services\Webhook\Steps\Facebook;

use App\Services\FacebookService;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\WebhookContext;
use Closure;

/**
 * Legacy ProcessFacebookWebhook::generateAIResponse() sent `typing_on` right
 * before generating a reply and `typing_off` right after sending it — and only
 * on the path where a reply is actually generated. Two instances of this step
 * reproduce that: one with 'typing_on' placed before GenerateResponseStep
 * (gated on GenerateResponseStep::shouldGenerate()), one with 'typing_off'
 * placed after SendResponseStep (gated on a bot message having been produced,
 * so a swallowed generation failure leaves the indicator on, as legacy did).
 */
class FacebookTypingStep
{
    public function __construct(
        private readonly FacebookService $facebookService,
        private readonly string $action,
    ) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $recipientId = (string) ($ctx->metadata['sender_id'] ?? '');

        if ($recipientId !== '' && $this->shouldSend($ctx)) {
            $this->facebookService->sendTypingIndicator($ctx->bot, $recipientId, $this->action);
        }

        $next($ctx);
    }

    private function shouldSend(WebhookContext $ctx): bool
    {
        return match ($this->action) {
            'typing_on' => GenerateResponseStep::shouldGenerate($ctx),
            'typing_off' => isset($ctx->metadata['bot_message_id']),
            default => false,
        };
    }
}
