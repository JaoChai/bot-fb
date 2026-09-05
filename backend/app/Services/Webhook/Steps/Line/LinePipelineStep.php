<?php

namespace App\Services\Webhook\Steps\Line;

use App\Jobs\ProcessAggregatedMessages;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext as LineCtx;
use App\Services\MessageAggregationService;
use App\Services\Webhook\WebhookContext;
use App\Support\QueueRouter;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the LINE-specific pipeline (Gating → Context → Response → Output) that
 * production LINE bots have used since 2026-05-16, as one v2 step.
 * Body moved verbatim from ProcessLINEWebhook::runPipeline().
 */
class LinePipelineStep
{
    public function __construct(
        private readonly LineWebhookGatingService $gating,
        private readonly LineWebhookContextService $contextSvc,
        private readonly LineWebhookResponseService $responseSvc,
        private readonly LineWebhookOutputService $outputSvc,
    ) {}

    public function handle(WebhookContext $shared, Closure $next): void
    {
        $ctx = new LineCtx($shared->bot, $shared->rawEvent);
        $shared->metadata['line_ctx'] = $ctx;

        Log::debug('LINE webhook pipeline.start', [
            'bot_id' => $shared->bot->id,
            'event_type' => $ctx->messageType(),
        ]);

        // Stage 1: Gating (rate limit only)
        $this->gating->check($ctx);
        if ($ctx->gateDecision !== null && $ctx->gateDecision->isBlocked()) {
            return;
        }

        // Stage 2: Context (profile, conversation, msg save, aggregation, outside-hours)
        $this->contextSvc->resolve($ctx);
        if ($ctx->gateDecision !== null && $ctx->gateDecision->isBlocked()) {
            return;
        }
        if ($ctx->aggregationBuffered) {
            return;
        }

        // For text messages: acquire response lock around Stage 3 + Stage 4 (mirror legacy order)
        if ($ctx->messageType() === 'text' && $ctx->conversation !== null && $ctx->userMessage !== null) {
            $lock = Cache::lock("ai_response:{$ctx->conversation->id}", 30);
            if (! $lock->get()) {
                Log::info('Response lock held, falling back to aggregation', [
                    'conversation_id' => $ctx->conversation->id,
                ]);

                $aggregation = app(MessageAggregationService::class);
                $fallback = $aggregation->startOrContinueAggregation(
                    $ctx->conversation, $ctx->userMessage, 15000
                );
                if ($fallback) {
                    ProcessAggregatedMessages::dispatch(
                        $ctx->bot, $ctx->conversation, $fallback['group_id'], $ctx->userId()
                    )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addSeconds(15));
                }

                return;
            }

            try {
                $this->responseSvc->generate($ctx);
                $this->outputSvc->dispatch($ctx);
            } finally {
                $lock->release();
            }

            $next($shared);

            return;
        }

        // Non-text path: no lock (sticker/image have different concurrency semantics)
        $this->responseSvc->generate($ctx);
        $this->outputSvc->dispatch($ctx);

        $next($shared);
    }
}
