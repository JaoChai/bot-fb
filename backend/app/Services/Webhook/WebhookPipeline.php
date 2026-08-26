<?php

namespace App\Services\Webhook;

use App\Services\AIService;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\LINEService;
use App\Services\TelegramService;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\Steps\ResolveConversationStep;
use App\Services\Webhook\Steps\SendResponseStep;
use Closure;

/**
 * Channel-agnostic webhook pipeline orchestrator (Task 9).
 *
 * Runs an array of steps as an onion: each step receives
 * (WebhookContext $ctx, Closure $next). A step calls $next($ctx) to
 * continue the chain; if it does not, the remaining steps are skipped
 * (short-circuit). The pipeline itself performs no work — the core at
 * the center of the onion is a no-op.
 */
class WebhookPipeline
{
    /**
     * @param array<int, callable> $steps Each step: fn(WebhookContext, Closure $next): void
     */
    public function run(WebhookContext $ctx, array $steps): void
    {
        $core = function (WebhookContext $c): void {};
        $chain = array_reduce(
            array_reverse($steps),
            fn (Closure $next, callable $step) => fn (WebhookContext $c) => $step($c, $next),
            $core
        );
        $chain($ctx);
    }

    /**
     * Compose the LINE step list (resolve → response → send) for the
     * shared v2 pipeline (Task 9).
     *
     * @return array<int, ResolveConversationStep|GenerateResponseStep|SendResponseStep>
     */
    public static function line(LINEService $lineService, AIService $aiService): array
    {
        return [
            new ResolveConversationStep($lineService),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
        ];
    }

    /**
     * Compose the Facebook step list (resolve → response → send) for the
     * shared v2 pipeline (Task 9).
     *
     * @return array<int, ResolveConversationStep|GenerateResponseStep|SendResponseStep>
     */
    public static function facebook(AIService $aiService): array
    {
        return [
            new ResolveConversationStep(),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
        ];
    }

    /**
     * Compose the Telegram step list (resolve → response → send) for the
     * shared v2 pipeline (Task 9).
     *
     * @return array<int, ResolveConversationStep|GenerateResponseStep|SendResponseStep>
     */
    public static function telegram(TelegramService $telegramService, AIService $aiService): array
    {
        return [
            new ResolveConversationStep(null, $telegramService),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
        ];
    }
}
