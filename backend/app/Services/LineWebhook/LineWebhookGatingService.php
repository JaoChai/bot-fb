<?php

namespace App\Services\LineWebhook;

use App\Services\LINEService;
use App\Services\RateLimitService;
use Illuminate\Support\Facades\Log;

class LineWebhookGatingService
{
    public function __construct(
        private readonly RateLimitService $rateLimit,
        private readonly LINEService $line,
    ) {}

    public function check(WebhookContext $ctx): void
    {
        $userId = $ctx->userId();
        if ($userId === null) {
            return;
        }

        $result = $this->rateLimit->checkRateLimit($ctx->bot, $userId);
        if ($result['allowed']) {
            return;
        }

        $ctx->gateDecision = GateDecision::RATE_LIMITED;
        $this->dispatchRateLimitMessage($ctx, $userId, $result['status']);
    }

    private function dispatchRateLimitMessage(WebhookContext $ctx, string $userId, string $status): void
    {
        $message = $this->rateLimit->getRateLimitMessage($status, $ctx->bot->settings);
        if (! $message) {
            return;
        }

        try {
            $retryKey = $this->line->generateRetryKey();
            $this->line->replyWithFallback($ctx->bot, $ctx->replyToken(), $userId, [$message], $retryKey);
        } catch (\Exception $e) {
            Log::warning('Failed to send rate limit message', [
                'bot_id' => $ctx->bot->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
