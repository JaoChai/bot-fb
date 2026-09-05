<?php

namespace App\Services\Webhook;

use App\Services\AIService;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\FacebookService;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\TelegramService;
use App\Services\Webhook\Channels\LINE\NonTextHandler;
use App\Services\Webhook\Steps\BroadcastStep;
use App\Services\Webhook\Steps\DedupUserMessageStep;
use App\Services\Webhook\Steps\Facebook\FacebookTypingStep;
use App\Services\Webhook\Steps\FlowPluginStep;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\Steps\Line\LineEventGateStep;
use App\Services\Webhook\Steps\Line\LinePipelineStep;
use App\Services\Webhook\Steps\PersistUserMessageStep;
use App\Services\Webhook\Steps\ResolveConversationStep;
use App\Services\Webhook\Steps\SendResponseStep;
use App\Services\Webhook\Steps\Telegram\TelegramMediaStep;
use Closure;
use Illuminate\Support\Facades\DB;

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
     * Wrap inner steps in one DB transaction (legacy FB/TG wrapped resolve+persist+generate
     * in a transaction; here generation runs after commit, as the LINE path already does).
     * If an inner step short-circuits, the outer chain does not continue either.
     */
    public static function transactional(array $innerSteps): Closure
    {
        return function (WebhookContext $ctx, Closure $next) use ($innerSteps): void {
            $completed = false;
            DB::transaction(function () use ($ctx, $innerSteps, &$completed): void {
                (new self)->run($ctx, [...$innerSteps, function (WebhookContext $c, Closure $n) use (&$completed): void {
                    $completed = true;
                    $n($c);
                }]);
            });
            if ($completed) {
                $next($ctx);
            }
        };
    }

    /**
     * Compose the Facebook step list (resolve+dedup+persist in one transaction →
     * typing_on → generate → send → typing_off → broadcast) for the shared v2 pipeline.
     *
     * @return array<int, Closure|FacebookTypingStep|GenerateResponseStep|SendResponseStep|BroadcastStep>
     */
    public static function facebook(AIService $aiService, ?FacebookService $facebookService = null): array
    {
        $facebookService ??= app(FacebookService::class);

        return [
            self::transactional([
                new ResolveConversationStep,
                new DedupUserMessageStep,
                new PersistUserMessageStep,
            ]),
            new FacebookTypingStep($facebookService, 'typing_on'),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
            new FacebookTypingStep($facebookService, 'typing_off'),
            new BroadcastStep(app(LeadRecoveryService::class)),
        ];
    }

    /**
     * Compose the Telegram step list (resolve+dedup+media+persist in one transaction →
     * generate → send → flow plugins → broadcast) for the shared v2 pipeline.
     *
     * @return array<int, Closure|GenerateResponseStep|SendResponseStep|FlowPluginStep|BroadcastStep>
     */
    public static function telegram(TelegramService $telegramService, AIService $aiService): array
    {
        return [
            self::transactional([
                new ResolveConversationStep(null, $telegramService),
                new DedupUserMessageStep,
                new TelegramMediaStep($telegramService),
                new PersistUserMessageStep,
            ]),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
            new FlowPluginStep(app(FlowPluginService::class)),
            new BroadcastStep(app(LeadRecoveryService::class)),
        ];
    }
}
