<?php

namespace App\Services\Webhook;

use App\Services\AIService;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\TelegramService;
use App\Services\Webhook\Channels\LINE\NonTextHandler;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\Steps\Line\LineEventGateStep;
use App\Services\Webhook\Steps\Line\LinePipelineStep;
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
     * @param  array<int, callable|object>  $steps  Each step: closure fn(WebhookContext, Closure $next): void, or an object with handle(WebhookContext, Closure): void
     */
    public function run(WebhookContext $ctx, array $steps): void
    {
        $core = function (WebhookContext $c): void {};
        $chain = array_reduce(
            array_reverse($steps),
            fn (Closure $next, callable|object $step) => fn (WebhookContext $c) => is_callable($step)
                ? $step($c, $next)
                : $step->handle($c, $next),
            $core
        );
        $chain($ctx);
    }

    /**
     * LINE step list: legacy event gate → the LINE-specific pipeline that
     * production runs today (see LinePipelineStep).
     *
     * @return array<int, LineEventGateStep|LinePipelineStep>
     */
    public static function line(
        LINEService $lineService,
        NonTextHandler $nonTextHandler,
        LineWebhookGatingService $gating,
        LineWebhookContextService $contextSvc,
        LineWebhookResponseService $responseSvc,
        LineWebhookOutputService $outputSvc,
    ): array {
        return [
            new LineEventGateStep($lineService, $nonTextHandler),
            new LinePipelineStep($gating, $contextSvc, $responseSvc, $outputSvc),
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
            new ResolveConversationStep,
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
