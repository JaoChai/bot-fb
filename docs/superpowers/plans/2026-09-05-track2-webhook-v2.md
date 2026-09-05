# Track 2 — Webhook Pipeline v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every channel webhook (LINE, Facebook, Telegram) runs through the shared v2 pipeline with full behavior parity, LINE bot 26 soaks on v2 in production for 7 days, then the legacy paths and both feature flags are deleted.

**Architecture:** Three PRs. PR-1 makes v2 for LINE a thin wrapper around the proven `LineWebhook/*` pipeline (verbatim move of `ProcessLINEWebhook::runPipeline()` into a step, plus an event-gate step that reproduces the legacy non-message / non-text routing). PR-2 gives Facebook/Telegram the parity the generic steps lack (persist user message + dedup + stats, guarded generation, typing indicator, plugins, post-commit broadcasts + lead recovery) and adds `FacebookChannelAdapter`; it also ships the rollout runbook. PR-3 (after the soak) deletes `processEvent()`, `processMessagingEvent()`, the Telegram inline transaction, both flags and their config.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4 / PHPUnit 12 (`php artisan test`), Mockery, `Http::fake`, `Event::fake`, sqlite test DB (pgsql in prod).

**Spec:** `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §6 (Track 2)

## Global Constraints

- Verbatim-move rule (spec §8): bodies copied, not rewritten. Where PR-2 must merge three channel-specific bodies into one step, the plan says "adapted" and the tests pin behavior.
- No behavior change on the LINE path that bot 26 runs today (`LineWebhookPipelineFlag` path). PR-1 only re-routes that exact code through v2.
- Only LINE serves customers (spec D4). Facebook/Telegram parity is proven by tests only.
- Flags are flipped by the user (spec D3). Claude never edits Railway env.
- Every commit: `php artisan test --parallel` green (baseline 1123 passed / 15 skipped on main), `vendor/bin/pint --test` clean.
- Branches: `refactor/webhook-v2-line` (PR-1), `refactor/webhook-v2-parity` (PR-2, from PR-1 head), `refactor/webhook-v2-remove-legacy` (PR-3, from main after soak). Create via `superpowers:using-git-worktrees`.
- Commit message footer (required):
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
  ```

## Verified facts this plan relies on (2026-09-05)

- `ProcessLINEWebhook::handle()` order: `WebhookPipelineV2Flag` → `runSharedPipeline()`; else `LineWebhookPipelineFlag` **and** message event **and** (text or image) → `runPipeline()` (the 4 `LineWebhook/*` services, live on bot 26); else `processEvent()` (legacy). Legacy `processEvent()` for a **non-message** event only logs and returns; for a **non-text** message it builds `NonTextHandler` (sticker/image/video/audio/file/location) with two closures from the job (`createNewConversation`, `updateStatsForUserMessageOnly`) and returns.
- Existing v2 steps: `ResolveConversationStep` (sets `$ctx->conversation`, `metadata['is_handover']`, `metadata['is_new_conversation']`, `$ctx->profile`), `GenerateResponseStep` (calls `AIService::generateAndSaveResponse` only when `$ctx->userMessage !== null`), `SendResponseStep` (adapter send when `metadata['bot_message']`). **Nothing persists the user message**, so today v2 for FB/TG saves no user message and never generates. `ChannelAdapterFactory` registers `line` and `telegram` only.
- Legacy FB/TG behavior (inside one `DB::transaction`): find-or-create conversation → dedup on `external_message_id` → (TG) download media / placeholder text; (FB postback) content = title ?: payload, type `postback` → `messages()->create` → conversation stats (`unread_count+1`, `message_count+1`, `last_message_at`, `last_message_id`) → bot stats (`total_messages+1`, `last_active_at`, `+total_conversations` if new) → if `! is_handover && bot active && (text || FB postback)` generate (FB: typing_on/off around it; TG: `FlowPluginService::executePlugins` after send) and bump conversation `message_count`/`last_message_id` + bot `total_messages` for the bot message. After commit: `refresh()`, `LeadRecoveryService::markCustomerResponded`, `broadcast(MessageSent(user))`, `broadcast(MessageSent(bot))`, `broadcast(ConversationUpdated(created|message_received))`, all `->toOthers()`.
- `AIService::generateAndSaveResponse(Bot, Conversation, Message): Message` already creates the bot message and increments bot `total_messages` + `last_active_at`. Legacy FB/TG then increment bot `total_messages` **again** for the bot message (double count) — pinned by the existing FB postback test (`total_messages === 2` after one postback with AI reply). PR-2 preserves this so the test keeps passing; PR-3 may not change it either (out of scope).
- Mapper metadata keys — Facebook: `sender_id`, `recipient_id`, `mid`, `media_url`, `media_metadata`, `postback_payload`, `postback_title`. Telegram: `chat_id`, `message_id`, `reply_to_message_id`, `file_id`, `media_metadata`, `user_id`, `username`, `first_name`, `last_name`.
- Tests: `tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` (mocks the 4 LINE services, asserts call order), `ProcessFacebookWebhookPostbackTest.php` (DB assertions; skips on sqlite where noted), `ProcessTelegramWebhookMapperTest.php` (sqlite cannot insert `channel_type='telegram'`; tests the mapper boundary), `tests/Feature/LINEWebhookTest.php`, `PipelineImageRoutingTest.php`, fixtures under `tests/fixtures/`.

---

## PR-1 — LINE on v2 (`refactor/webhook-v2-line`)

### Task 1: `LineEventGateStep` + `LinePipelineStep`; route v2 LINE through them

**Files:**
- Create: `backend/app/Services/Webhook/Steps/Line/LineEventGateStep.php`
- Create: `backend/app/Services/Webhook/Steps/Line/LinePipelineStep.php`
- Modify: `backend/app/Services/Webhook/WebhookPipeline.php` (`line()` signature + body; imports)
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php:218-231` (`runSharedPipeline`) and the `handle()` call at line 112–116
- Test: `backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` (add 4 tests)

**Interfaces:**
- Consumes: `LineWebhookGatingService::check(LineCtx)`, `LineWebhookContextService::resolve(LineCtx)`, `LineWebhookResponseService::generate(LineCtx)`, `LineWebhookOutputService::dispatch(LineCtx)` where `LineCtx = App\Services\LineWebhook\WebhookContext`; `NonTextHandler::handle(LINEService, array $event)`; `LINEService::isMessageEvent/isTextMessage/isImageMessage(array)`.
- Produces:
  - `WebhookPipeline::line(LINEService $lineService, NonTextHandler $nonTextHandler, LineWebhookGatingService $gating, LineWebhookContextService $contextSvc, LineWebhookResponseService $responseSvc, LineWebhookOutputService $outputSvc): array` — step list `[LineEventGateStep, LinePipelineStep]`.
  - `ProcessLINEWebhook::runSharedPipeline(LINEService, LineWebhookGatingService, LineWebhookContextService, LineWebhookResponseService, LineWebhookOutputService): void` (protected).

- [ ] **Step 1: Add the failing tests**

Append to `backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` (inside the class; reuse the file's existing helpers `$this->bot`, `$this->textEvent`, `$circuitBreaker` mock, and `makeJob()`/`textEvent` naming — read the file's `setUp` first and match names exactly):

```php
    public function test_v2_flag_routes_text_event_through_line_pipeline_services(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id],
            'line_webhook.pipeline_enabled' => false,
        ]);

        $callOrder = [];
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')->once()->andReturnUsing(function ($ctx) use (&$callOrder) {
            $callOrder[] = 'gating';
        });
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldReceive('resolve')->once()->andReturnUsing(function ($ctx) use (&$callOrder) {
            $callOrder[] = 'context';
            // Non-text semantics: no lock branch (messageType 'text' needs conversation+userMessage to lock)
        });
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldReceive('generate')->once()->andReturnUsing(function ($ctx) use (&$callOrder) {
            $callOrder[] = 'response';
        });
        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldReceive('dispatch')->once()->andReturnUsing(function ($ctx) use (&$callOrder) {
            $callOrder[] = 'output';
        });

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(true);
        $lineService->shouldReceive('isImageMessage')->andReturn(false);
        $lineService->shouldReceive('extractUserId')->andReturn('U123');

        $job = new ProcessLINEWebhook($this->bot, $this->textEvent);
        $job->handle(
            $lineService,
            Mockery::mock(AIService::class),
            Mockery::mock(RateLimitService::class),
            Mockery::mock(MessageAggregationService::class),
            Mockery::mock(ResponseHoursService::class),
            Mockery::mock(CircuitBreakerService::class),
            $gating, $contextSvc, $responseSvc, $outputSvc,
        );

        $this->assertSame(['gating', 'context', 'response', 'output'], $callOrder);
    }

    public function test_v2_flag_short_circuits_when_gating_blocks(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id],
        ]);

        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')->once()->andReturnUsing(function ($ctx) {
            $ctx->gateDecision = GateDecision::RATE_LIMITED;
        });
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldNotReceive('resolve');
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldNotReceive('generate');
        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldNotReceive('dispatch');

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(true);
        $lineService->shouldReceive('isImageMessage')->andReturn(false);
        $lineService->shouldReceive('extractUserId')->andReturn('U123');

        $job = new ProcessLINEWebhook($this->bot, $this->textEvent);
        $job->handle(
            $lineService,
            Mockery::mock(AIService::class),
            Mockery::mock(RateLimitService::class),
            Mockery::mock(MessageAggregationService::class),
            Mockery::mock(ResponseHoursService::class),
            Mockery::mock(CircuitBreakerService::class),
            $gating, $contextSvc, $responseSvc, $outputSvc,
        );

        $this->addToAssertionCount(1); // Mockery shouldNotReceive constraints are the assertions
    }

    public function test_v2_flag_ignores_non_message_event_without_touching_pipeline(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id],
        ]);

        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldNotReceive('check');
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldNotReceive('resolve');
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $outputSvc = Mockery::mock(LineWebhookOutputService::class);

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(false);
        $lineService->shouldReceive('extractUserId')->andReturn('U123');

        $followEvent = ['type' => 'follow', 'source' => ['userId' => 'U123'], 'replyToken' => 'rt'];
        $job = new ProcessLINEWebhook($this->bot, $followEvent);
        $job->handle(
            $lineService,
            Mockery::mock(AIService::class),
            Mockery::mock(RateLimitService::class),
            Mockery::mock(MessageAggregationService::class),
            Mockery::mock(ResponseHoursService::class),
            Mockery::mock(CircuitBreakerService::class),
            $gating, $contextSvc, $responseSvc, $outputSvc,
        );

        $this->addToAssertionCount(1);
    }

    public function test_v2_flag_routes_sticker_event_to_non_text_handler(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id],
        ]);

        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldNotReceive('check');
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldNotReceive('resolve');
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $outputSvc = Mockery::mock(LineWebhookOutputService::class);

        $stickerEvent = include base_path('tests/fixtures/line-sticker-event.php');

        // NonTextHandler::handle() starts by calling these three on LINEService —
        // proving the sticker reached the non-text branch, not the text pipeline.
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(false);
        $lineService->shouldReceive('isImageMessage')->andReturn(false);
        $lineService->shouldReceive('extractUserId')->once()->andReturn(null); // null userId => handler returns early
        $lineService->shouldReceive('extractReplyToken')->andReturn('rt');
        $lineService->shouldReceive('extractMessage')->andReturn(['type' => 'sticker', 'id' => 'm1', 'text' => null]);

        $job = new ProcessLINEWebhook($this->bot, $stickerEvent);
        $job->handle(
            $lineService,
            Mockery::mock(AIService::class),
            Mockery::mock(RateLimitService::class),
            Mockery::mock(MessageAggregationService::class),
            Mockery::mock(ResponseHoursService::class),
            Mockery::mock(CircuitBreakerService::class),
            $gating, $contextSvc, $responseSvc, $outputSvc,
        );

        $this->addToAssertionCount(1);
    }
```

Add the missing `use` lines at the top of the test file if absent: `use App\Services\LineWebhook\GateDecision;`.

Note for the sticker test: read `NonTextHandler::handle()` lines 66–90 first. If it does not return early on a null `userId`, change the `extractUserId` expectation to return `'U123'` and add `shouldReceive` stubs for whatever `LINEService` methods it calls next until the handler returns without DB writes (the existing `line-sticker-event.php` fixture + `tests/Feature/LINEWebhookTest.php` show the sticker path's expectations). The assertion that matters is `shouldNotReceive('check')` / `shouldNotReceive('resolve')`.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookPipelineTest`
Expected: the 4 new tests FAIL (v2 currently runs `ResolveConversationStep`/`GenerateResponseStep`, so `check`/`resolve` mocks are never hit and `AIService` mock receives unexpected calls); the existing tests still pass.

- [ ] **Step 3: Create `LineEventGateStep` (legacy non-message / non-text routing)**

Create `backend/app/Services/Webhook/Steps/Line/LineEventGateStep.php`:

```php
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
```

- [ ] **Step 4: Create `LinePipelineStep` — verbatim body of `ProcessLINEWebhook::runPipeline()`**

Create `backend/app/Services/Webhook/Steps/Line/LinePipelineStep.php`. The `handle()` body between `$ctx = new LineCtx(...)` and the end is a **byte-for-byte copy** of `ProcessLINEWebhook::runPipeline()` lines 149–216 (from `Log::debug('LINE webhook pipeline.start'` to the final `$outputSvc->dispatch($ctx);`), with `$this->bot`/`$this->event` replaced by `$shared->bot`/`$shared->rawEvent` and service variables replaced by the constructor properties:

```php
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
```

(The two `$next($shared)` calls are the only additions — they let a future step run after output; nothing is chained after this step in PR-1.)

- [ ] **Step 5: Compose the LINE step list and use injected services in the job**

In `backend/app/Services/Webhook/WebhookPipeline.php` replace the `line()` method (and add imports `use App\Services\LineWebhook\LineWebhookContextService; use App\Services\LineWebhook\LineWebhookGatingService; use App\Services\LineWebhook\LineWebhookOutputService; use App\Services\LineWebhook\LineWebhookResponseService; use App\Services\Webhook\Channels\LINE\NonTextHandler; use App\Services\Webhook\Steps\Line\LineEventGateStep; use App\Services\Webhook\Steps\Line\LinePipelineStep;`):

```php
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
```

In `backend/app/Jobs/ProcessLINEWebhook.php`:

`handle()` lines 112–116 become:

```php
            if (WebhookPipelineV2Flag::enabledFor($this->bot)) {
                $this->runSharedPipeline($lineService, $gating, $contextSvc, $responseSvc, $outputSvc);

                return;
            }
```

`runSharedPipeline()` (lines 218–231) becomes:

```php
    protected function runSharedPipeline(
        LINEService $lineService,
        LineWebhookGatingService $gating,
        LineWebhookContextService $contextSvc,
        LineWebhookResponseService $responseSvc,
        LineWebhookOutputService $outputSvc,
    ): void {
        $context = new SharedWebhookContext($this->bot, $this->event, 'line');
        $context->metadata['user_id'] = $lineService->extractUserId($this->event);

        // Same construction as the legacy non-text branch of processEvent().
        $nonTextHandler = new NonTextHandler(
            $this->bot,
            app(ResponseHoursService::class),
            app(LeadRecoveryService::class),
            fn (string $userId, LINEService $svc) => $this->createNewConversation($userId, $svc),
            fn (Conversation $conversation, int $lastMessageId) => $this->updateStatsForUserMessageOnly($conversation, $lastMessageId),
            new StickerHandler($this->bot, app(StickerReplyService::class)),
        );

        app(WebhookPipeline::class)->run(
            $context,
            WebhookPipeline::line($lineService, $nonTextHandler, $gating, $contextSvc, $responseSvc, $outputSvc)
        );
    }
```

Remove the now-unused `use App\Services\AIService` only if `AIService` is no longer referenced in the file (it still is — `handle()` signature and `processEvent()` — so leave it).

- [ ] **Step 6: Run the pipeline test file, then the whole suite and Pint**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookPipelineTest && php artisan test --parallel --compact 2>&1 | grep -E "Tests:" && vendor/bin/pint --test`
Expected: pipeline test file all green (existing + 4 new); suite `1127 passed` (or more), `0 failed`; Pint clean.

- [ ] **Step 7: Prove the verbatim move**

```bash
cd backend && diff <(sed -n '149,216p' app/Jobs/ProcessLINEWebhook.php | sed -E 's/\$this->bot/\$shared->bot/g; s/\$gating->/\$this->gating->/g; s/\$contextSvc->/\$this->contextSvc->/g; s/\$responseSvc->/\$this->responseSvc->/g; s/\$outputSvc->/\$this->outputSvc->/g') <(sed -n '/Log::debug(.LINE webhook pipeline.start/,/^    }/p' app/Services/Webhook/Steps/Line/LinePipelineStep.php | grep -v '\$next(\$shared);' | sed '/^$/N;/^\n$/D')
```

Expected: no diff except the closing brace / blank-line normalization lines. Any diff inside a statement = not verbatim; fix the step, not the job.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/Webhook/Steps/Line backend/app/Services/Webhook/WebhookPipeline.php backend/app/Jobs/ProcessLINEWebhook.php backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php
git commit -m "refactor(webhook): v2 LINE ใช้ LineWebhook pipeline ที่ prod รันอยู่ (LineEventGateStep + LinePipelineStep verbatim)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 2: Record the step-shape change in the spec and open PR-1

**Files:**
- Modify: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §6.2 (the step table)

**Interfaces:** none.

- [ ] **Step 1: Replace the 4-row step table in §6.2**

Replace the table (from `| Step | Wraps | Short-circuits when |` through the `LineOutputStep` row) and the sentence after it with:

```markdown
| Step | Contains | Short-circuits when |
|---|---|---|
| `LineEventGateStep` | legacy `processEvent()` routing: non-message → log+drop; non-text/non-image → `NonTextHandler` | non-message or non-text event |
| `LinePipelineStep` | `ProcessLINEWebhook::runPipeline()` body **verbatim** (Gating → Context → response lock/aggregation fallback → Response → Output) | gating blocked, aggregation buffered, or response lock held |

`WebhookPipeline::line()` returns `[LineEventGateStep, LinePipelineStep]`. Two steps instead of the four originally sketched: the response-lock/aggregation-fallback logic sits between Context and Response and belongs to neither, so wrapping the four services separately would have meant re-writing it. One verbatim step carries zero drift; the four services stay individually testable as they are today. The `LineWebhook\WebhookContext` instance is exposed at `$ctx->metadata['line_ctx']`.
```

- [ ] **Step 2: Commit, push, open PR-1**

```bash
git add docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md
git commit -m "docs(spec): §6.2 — LINE v2 เป็น 2 step (gate + verbatim pipeline) แทน 4 wrapper

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
git push -u origin refactor/webhook-v2-line
gh pr create --base main --title "refactor(webhook): LINE on shared pipeline v2 via the production LineWebhook pipeline (Track 2 PR-1)" --body "$(cat <<'EOF'
## Summary
- `WebhookPipeline::line()` now runs `[LineEventGateStep, LinePipelineStep]`: the gate reproduces legacy `processEvent()` routing (non-message drop, non-text → `NonTextHandler`); the pipeline step is `ProcessLINEWebhook::runPipeline()` moved verbatim (diff-proven in plan Task 1 Step 7)
- With `WEBHOOK_PIPELINE_V2_ENABLED=true` + bot 26 whitelisted, LINE runs exactly the code path it has run since 2026-05-16 — no behavior change, only the flag that selects it
- Flag stays OFF by default; nothing changes in production until the owner flips it (runbook ships in PR-2)

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §6.1–6.2
Plan: `docs/superpowers/plans/2026-09-05-track2-webhook-v2.md` Tasks 1–2

## Test plan
- [x] `ProcessLINEWebhookPipelineTest`: +4 tests (v2 text order, v2 gating short-circuit, v2 non-message drop, v2 sticker → NonTextHandler)
- [x] full suite green, Pint clean

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

---

## PR-2 — Facebook / Telegram parity + `FacebookChannelAdapter` + runbook (`refactor/webhook-v2-parity`, from PR-1 head)

### Task 3: `FacebookChannelAdapter` + `FacebookService::sendTypingIndicator`

**Files:**
- Create: `backend/app/Services/Channel/FacebookChannelAdapter.php`
- Modify: `backend/app/Services/Channel/ChannelAdapterFactory.php:8-15` (constructor)
- Modify: `backend/app/Services/FacebookService.php` (add one method after `sendMessage`)
- Test: `backend/tests/Unit/Services/Channel/FacebookChannelAdapterTest.php`

**Interfaces:**
- Consumes: `FacebookService::sendMessage(Bot, string $recipientId, string $text): array`, `sendImage/sendVideo/sendAudio/sendFile(Bot, string $recipientId, string $url): array`.
- Produces: `ChannelAdapterFactory::make('facebook')` → `FacebookChannelAdapter`; `FacebookService::sendTypingIndicator(Bot $bot, string $recipientId, string $action): void` (used by Task 5).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Services/Channel/FacebookChannelAdapterTest.php`:

```php
<?php

namespace Tests\Unit\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\Channel\FacebookChannelAdapter;
use App\Services\FacebookService;
use Mockery;
use Tests\TestCase;

class FacebookChannelAdapterTest extends TestCase
{
    public function test_factory_registers_facebook(): void
    {
        $factory = app(ChannelAdapterFactory::class);

        $this->assertTrue($factory->supports('facebook'));
        $this->assertInstanceOf(FacebookChannelAdapter::class, $factory->make('facebook'));
    }

    public function test_text_message_goes_to_send_message_with_psid(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendMessage')->once()->with($bot, 'PSID-1', 'hello')->andReturn([]);

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'text', 'hello');
        $this->addToAssertionCount(1);
    }

    public function test_image_with_media_url_goes_to_send_image(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendImage')->once()->with($bot, 'PSID-1', 'https://x/img.jpg')->andReturn([]);
        $fb->shouldNotReceive('sendMessage');

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'image', 'caption', 'https://x/img.jpg');
        $this->addToAssertionCount(1);
    }

    public function test_image_without_media_url_falls_back_to_text(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendMessage')->once()->with($bot, 'PSID-1', 'caption')->andReturn([]);

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'image', 'caption', null);
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php artisan test --filter=FacebookChannelAdapterTest`
Expected: FAIL — class `FacebookChannelAdapter` not found.

- [ ] **Step 3: Implement the adapter, register it, add the typing helper**

Create `backend/app/Services/Channel/FacebookChannelAdapter.php`:

```php
<?php

namespace App\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\FacebookService;

class FacebookChannelAdapter implements ChannelAdapterInterface
{
    public function __construct(
        private FacebookService $facebookService
    ) {}

    public function getChannelType(): string
    {
        return 'facebook';
    }

    public function sendMessage(
        Bot $bot,
        Conversation $conversation,
        string $type,
        string $content,
        ?string $mediaUrl = null
    ): void {
        $psid = $conversation->external_customer_id;

        match ($type) {
            'photo', 'image' => $mediaUrl
                ? $this->facebookService->sendImage($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'video' => $mediaUrl
                ? $this->facebookService->sendVideo($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'audio', 'voice' => $mediaUrl
                ? $this->facebookService->sendAudio($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            'file' => $mediaUrl
                ? $this->facebookService->sendFile($bot, $psid, $mediaUrl)
                : $this->facebookService->sendMessage($bot, $psid, $content),
            default => $this->facebookService->sendMessage($bot, $psid, $content),
        };
    }

    public function supportsMedia(): bool
    {
        return true;
    }

    public function supportsHandover(): bool
    {
        return true;
    }

    public function getSupportedMessageTypes(): array
    {
        return ['text', 'image', 'photo', 'video', 'audio', 'voice', 'file'];
    }
}
```

`backend/app/Services/Channel/ChannelAdapterFactory.php` constructor becomes:

```php
    public function __construct(
        LINEChannelAdapter $lineAdapter,
        TelegramChannelAdapter $telegramAdapter,
        FacebookChannelAdapter $facebookAdapter
    ) {
        $this->adapters['line'] = $lineAdapter;
        $this->adapters['telegram'] = $telegramAdapter;
        $this->adapters['facebook'] = $facebookAdapter;
    }
```

In `backend/app/Services/FacebookService.php`, after `sendMessage()` (ends around line 169) add — body is the legacy `ProcessFacebookWebhook::sendTypingIndicator()` with `$this->bot` → `$bot`:

```php
    /**
     * Send typing indicator (sender_action) to Messenger. Failures are logged at debug and ignored.
     */
    public function sendTypingIndicator(Bot $bot, string $recipientId, string $action): void
    {
        try {
            $accessToken = $bot->channel_access_token;
            if (! $accessToken) {
                return;
            }

            Http::post('https://graph.facebook.com/v19.0/me/messages', [
                'recipient' => ['id' => $recipientId],
                'sender_action' => $action,
                'access_token' => $accessToken,
            ]);
        } catch (\Exception $e) {
            // Silently ignore typing indicator failures
            Log::debug('Failed to send typing indicator', [
                'bot_id' => $bot->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

(Check the file already imports `Illuminate\Support\Facades\Http` and `Log`; add if missing.)

- [ ] **Step 4: Run tests + Pint**

Run: `cd backend && php artisan test --filter="FacebookChannelAdapterTest|ChannelAdapter" && vendor/bin/pint --test`
Expected: 4 new tests pass, existing adapter tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Channel backend/app/Services/FacebookService.php backend/tests/Unit/Services/Channel/FacebookChannelAdapterTest.php
git commit -m "feat(webhook): FacebookChannelAdapter + ลงทะเบียนใน ChannelAdapterFactory + FacebookService::sendTypingIndicator

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 4: `TelegramMediaStep` + `PersistUserMessageStep` (dedup, message save, stats)

**Files:**
- Create: `backend/app/Services/Webhook/Steps/Telegram/TelegramMediaStep.php`
- Create: `backend/app/Services/Webhook/Steps/PersistUserMessageStep.php`
- Test: `backend/tests/Unit/Services/Webhook/Steps/PersistUserMessageStepTest.php`

**Interfaces:**
- Consumes: `$ctx->conversation`, `metadata['is_new_conversation']` (from `ResolveConversationStep`), Facebook metadata `mid`/`media_url`/`media_metadata`/`postback_payload`/`postback_title`, Telegram metadata `message_id`/`reply_to_message_id`/`file_id`/`media_metadata`, `TelegramService::downloadAndStoreFile(Bot, string $fileId): ?array{url,mime_type,file_size,path}`.
- Produces: `TelegramMediaStep` fills `metadata['media'] = ['url'=>?, 'mime_type'=>?, 'metadata'=>array]` and `metadata['placeholder'] = string`; `PersistUserMessageStep` sets `$ctx->userMessage` (or short-circuits on duplicate) and applies conversation + bot stats.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Unit/Services/Webhook/Steps/PersistUserMessageStepTest.php` (uses the Facebook fixtures; sqlite can insert `channel_type='facebook'`):

```php
<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Webhook\Steps\PersistUserMessageStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistUserMessageStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'facebook', 'status' => 'active']);
        $this->conversation = Conversation::create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'PSID-1',
            'channel_type' => 'facebook',
            'status' => 'active',
        ]);
    }

    private function ctx(array $metadata, string $eventType = 'message', ?string $text = 'hi'): WebhookContext
    {
        $raw = $eventType === 'postback'
            ? ['postback' => ['payload' => $metadata['postback_payload'] ?? 'P', 'title' => $metadata['postback_title'] ?? null]]
            : ['message' => ['type' => 'text', 'text' => $text]];
        $ctx = new WebhookContext($this->bot, $raw + ['type' => $eventType], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->metadata = ['is_new_conversation' => false, 'sender_id' => 'PSID-1', 'media_url' => null, 'media_metadata' => null] + $metadata;

        return $ctx;
    }

    public function test_saves_text_message_and_bumps_conversation_and_bot_stats(): void
    {
        $ctx = $this->ctx(['mid' => 'm-1']);
        $called = false;

        (new PersistUserMessageStep)->handle($ctx, function () use (&$called) { $called = true; });

        $this->assertTrue($called);
        $this->assertNotNull($ctx->userMessage);
        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'hi', 'external_message_id' => 'm-1']);
        $this->conversation->refresh();
        $this->assertSame(1, (int) $this->conversation->unread_count);
        $this->assertSame(1, (int) $this->conversation->message_count);
        $this->assertSame($ctx->userMessage->id, (int) $this->conversation->last_message_id);
        $this->bot->refresh();
        $this->assertSame(1, (int) $this->bot->total_messages);
        $this->assertNotNull($this->bot->last_active_at);
    }

    public function test_new_conversation_increments_bot_total_conversations(): void
    {
        $ctx = $this->ctx(['mid' => 'm-2', 'is_new_conversation' => true]);

        (new PersistUserMessageStep)->handle($ctx, fn () => null);

        $this->assertSame(1, (int) $this->bot->refresh()->total_conversations);
    }

    public function test_duplicate_external_message_id_short_circuits_without_writes(): void
    {
        Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'old', 'type' => 'text', 'external_message_id' => 'dup']);
        $ctx = $this->ctx(['mid' => 'dup']);
        $called = false;

        (new PersistUserMessageStep)->handle($ctx, function () use (&$called) { $called = true; });

        $this->assertFalse($called);
        $this->assertNull($ctx->userMessage);
        $this->assertSame(1, Message::where('conversation_id', $this->conversation->id)->count());
    }

    public function test_facebook_postback_saves_title_as_content_with_postback_type(): void
    {
        $ctx = $this->ctx(['mid' => null, 'postback_payload' => 'BUY_NOW', 'postback_title' => 'ซื้อเลย'], 'postback', null);

        (new PersistUserMessageStep)->handle($ctx, fn () => null);

        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'type' => 'postback', 'content' => 'ซื้อเลย']);
        $this->assertSame(['postback_payload' => 'BUY_NOW'], $ctx->userMessage->media_metadata);
    }

    public function test_telegram_placeholder_and_media_are_used_when_present(): void
    {
        $ctx = $this->ctx(['mid' => null, 'message_id' => 77, 'reply_to_message_id' => null,
            'media' => ['url' => 'https://s3/x.jpg', 'mime_type' => 'image/jpeg', 'metadata' => ['file_id' => 'f1']],
            'placeholder' => '[Photo]'], 'message', null);
        // Telegram contexts carry channelType 'telegram'; emulate via a fresh context object
        $tg = new WebhookContext($this->bot, ['message' => ['type' => 'photo']], 'telegram');
        $tg->conversation = $this->conversation;
        $tg->metadata = $ctx->metadata;

        (new PersistUserMessageStep)->handle($tg, fn () => null);

        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'content' => '[Photo]', 'type' => 'photo', 'media_url' => 'https://s3/x.jpg', 'media_type' => 'image/jpeg', 'external_message_id' => '77']);
    }
}
```

If `Bot::factory()` requires other attributes, copy the factory usage from `tests/Unit/Jobs/ProcessFacebookWebhookPostbackTest.php` `setUp`.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php artisan test --filter=PersistUserMessageStepTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `TelegramMediaStep` (bodies from `ProcessTelegramWebhook::processMedia/generateMediaPlaceholder`)**

Create `backend/app/Services/Webhook/Steps/Telegram/TelegramMediaStep.php`:

```php
<?php

namespace App\Services\Webhook\Steps\Telegram;

use App\Services\TelegramService;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Downloads Telegram media and computes the placeholder content for non-text
 * messages. Bodies moved from ProcessTelegramWebhook::processMedia() and
 * ::generateMediaPlaceholder(); results are written to $ctx->metadata['media']
 * and $ctx->metadata['placeholder'] for PersistUserMessageStep.
 */
class TelegramMediaStep
{
    public function __construct(private readonly TelegramService $telegramService) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $mediaData = $this->processMedia($ctx);
        $ctx->metadata['media'] = $mediaData;
        $ctx->metadata['placeholder'] = $ctx->messageType() !== 'text'
            ? $this->generateMediaPlaceholder((string) $ctx->messageType(), $mediaData)
            : null;

        $next($ctx);
    }

    private function processMedia(WebhookContext $context): array
    {
        if ($context->messageType() === 'text') {
            return [];
        }

        $fileId = $context->metadata['file_id'] ?? null;

        if (! $fileId) {
            // For location, contact, poll - extract metadata only
            return [
                'metadata' => $context->metadata['media_metadata'] ?? [],
            ];
        }

        // Download and store the file
        $fileData = $this->telegramService->downloadAndStoreFile($context->bot, $fileId);

        if (! $fileData) {
            Log::warning('Failed to download Telegram media', [
                'bot_id' => $context->bot->id,
                'file_id' => $fileId,
                'type' => $context->messageType(),
            ]);

            return [
                'metadata' => array_merge(
                    $context->metadata['media_metadata'] ?? [],
                    ['file_id' => $fileId, 'download_failed' => true]
                ),
            ];
        }

        return [
            'url' => $fileData['url'],
            'mime_type' => $fileData['mime_type'],
            'metadata' => array_merge(
                $context->metadata['media_metadata'] ?? [],
                [
                    'file_id' => $fileId,
                    'file_size' => $fileData['file_size'],
                    'storage_path' => $fileData['path'],
                ]
            ),
        ];
    }

    private function generateMediaPlaceholder(string $type, array $mediaData): string
    {
        $metadata = $mediaData['metadata'] ?? [];

        return match ($type) {
            'photo' => '[Photo]',
            'video', 'video_note', 'animation' => '[Video]',
            'voice' => '[Voice message]',
            'audio' => isset($metadata['title'])
                ? "[Audio: {$metadata['title']}]"
                : '[Audio]',
            'file' => isset($metadata['file_name'])
                ? "[File: {$metadata['file_name']}]"
                : '[File]',
            'sticker' => isset($metadata['emoji'])
                ? "[Sticker {$metadata['emoji']}]"
                : '[Sticker]',
            'location' => isset($metadata['title'])
                ? "[Location: {$metadata['title']}]"
                : '[Location shared]',
            'contact' => isset($metadata['first_name'])
                ? "[Contact: {$metadata['first_name']}]"
                : '[Contact shared]',
            'poll' => isset($metadata['question'])
                ? "[Poll: {$metadata['question']}]"
                : '[Poll]',
            default => '[Media]',
        };
    }
}
```

- [ ] **Step 4: Create `PersistUserMessageStep` (adapted from FB `handleMessage`/`handlePostback` and TG `processUpdate`)**

Create `backend/app/Services/Webhook/Steps/PersistUserMessageStep.php`:

```php
<?php

namespace App\Services\Webhook\Steps;

use App\Models\Message;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dedup on external message id, save the user message, bump conversation and bot
 * stats — the shared part of the legacy Facebook/Telegram transaction bodies.
 * Short-circuits (does not call $next) on a duplicate.
 */
class PersistUserMessageStep
{
    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = $ctx->conversation;
        $externalId = $this->externalMessageId($ctx);

        if ($externalId !== null && Message::where('conversation_id', $conversation->id)
            ->where('external_message_id', $externalId)
            ->exists()) {
            Log::info('Duplicate '.ucfirst($ctx->channelType).' message ignored', [
                'conversation_id' => $conversation->id,
                'message_id' => $externalId,
            ]);

            return;
        }

        $userMessage = $conversation->messages()->create($this->messageAttributes($ctx, $externalId));

        // Update conversation stats
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $userMessage->id,
        ]);

        // Update bot stats
        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ];
        if (! empty($ctx->metadata['is_new_conversation'])) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }
        $ctx->bot->update($botUpdate);

        $ctx->userMessage = $userMessage;

        $next($ctx);
    }

    private function externalMessageId(WebhookContext $ctx): ?string
    {
        $id = match ($ctx->channelType) {
            'facebook' => $ctx->metadata['mid'] ?? null,
            'telegram' => $ctx->metadata['message_id'] ?? null,
            default => null,
        };

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function messageAttributes(WebhookContext $ctx, ?string $externalId): array
    {
        if ($ctx->channelType === 'facebook' && $ctx->eventType() === 'postback') {
            $payload = $ctx->metadata['postback_payload'] ?? null;
            $title = $ctx->metadata['postback_title'] ?? null;

            return [
                'sender' => 'user',
                'content' => $title ?: $payload,
                'type' => 'postback',
                'media_metadata' => ['postback_payload' => $payload],
            ];
        }

        if ($ctx->channelType === 'telegram') {
            $media = $ctx->metadata['media'] ?? [];
            $content = $ctx->text();
            if (! $content && $ctx->messageType() !== 'text') {
                $content = $ctx->metadata['placeholder'] ?? '[Media]';
            }

            return [
                'sender' => 'user',
                'content' => $content,
                'type' => $ctx->messageType(),
                'media_url' => $media['url'] ?? null,
                'media_type' => $media['mime_type'] ?? null,
                'media_metadata' => $media['metadata'] ?? null,
                'external_message_id' => $externalId,
                'reply_to_message_id' => $ctx->metadata['reply_to_message_id'] ?? null,
            ];
        }

        // Facebook message event
        return [
            'sender' => 'user',
            'content' => $ctx->text(),
            'type' => $ctx->messageType(),
            'media_url' => $ctx->metadata['media_url'] ?? null,
            'media_type' => null,
            'media_metadata' => $ctx->metadata['media_metadata'] ?? null,
            'external_message_id' => $externalId,
        ];
    }
}
```

- [ ] **Step 5: Run the tests + Pint**

Run: `cd backend && php artisan test --filter=PersistUserMessageStepTest && vendor/bin/pint --test`
Expected: 5 tests pass; Pint clean. (If `test_telegram_placeholder...` fails on the `type => 'photo'` enum on sqlite, change the test's message type to `'image'` **only if** the `messages.type` CHECK in `2025_12_23_145426_create_messages_table` lacks `photo`; note it in the commit message.)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Webhook/Steps/PersistUserMessageStep.php backend/app/Services/Webhook/Steps/Telegram/TelegramMediaStep.php backend/tests/Unit/Services/Webhook/Steps/PersistUserMessageStepTest.php
git commit -m "feat(webhook): PersistUserMessageStep (dedup + save + stats) และ TelegramMediaStep สำหรับ v2

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 5: Guarded generation, bot-message stats, Facebook typing, Telegram plugins

**Files:**
- Modify: `backend/app/Services/Webhook/Steps/GenerateResponseStep.php` (whole `handle()`)
- Create: `backend/app/Services/Webhook/Steps/Facebook/FacebookTypingStep.php`
- Create: `backend/app/Services/Webhook/Steps/FlowPluginStep.php`
- Test: `backend/tests/Unit/Services/Webhook/Steps/GenerateResponseStepTest.php`

**Interfaces:**
- Consumes: `$ctx->userMessage` (Task 4), `metadata['is_handover']` (resolve step), `AIService::generateAndSaveResponse(Bot, Conversation, Message): Message`, `FacebookService::sendTypingIndicator(Bot, string, string)` (Task 3), `FlowPluginService::executePlugins(Bot, Conversation, Message)`.
- Produces: `metadata['bot_message_id']`, `metadata['bot_message']` (unchanged contract for `SendResponseStep`); conversation `message_count`/`last_message_at`/`last_message_id` and bot `total_messages` bumped for the bot message exactly as legacy.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Services/Webhook/Steps/GenerateResponseStepTest.php`:

```php
<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateResponseStepTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private Message $userMessage;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'facebook', 'status' => 'active']);
        $this->conversation = Conversation::create(['bot_id' => $this->bot->id, 'external_customer_id' => 'PSID-1', 'channel_type' => 'facebook', 'status' => 'active', 'message_count' => 1]);
        $this->userMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'user', 'content' => 'hi', 'type' => 'text']);
    }

    private function ctx(array $metadata = []): WebhookContext
    {
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => 'text', 'text' => 'hi']], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->userMessage;
        $ctx->metadata = ['is_handover' => false] + $metadata;

        return $ctx;
    }

    public function test_generates_and_bumps_bot_message_stats(): void
    {
        $botMessage = Message::create(['conversation_id' => $this->conversation->id, 'sender' => 'bot', 'content' => 'hello', 'type' => 'text']);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andReturn($botMessage);
        $ctx = $this->ctx();

        (new GenerateResponseStep($ai))->handle($ctx, fn () => null);

        $this->assertSame($botMessage->id, $ctx->metadata['bot_message_id']);
        $this->assertSame('hello', $ctx->metadata['bot_message']['content']);
        $this->conversation->refresh();
        $this->assertSame(2, (int) $this->conversation->message_count);
        $this->assertSame($botMessage->id, (int) $this->conversation->last_message_id);
        $this->assertSame(1, (int) $this->bot->refresh()->total_messages);
    }

    public function test_skips_when_handover(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');
        $ctx = $this->ctx(['is_handover' => true]);
        $called = false;

        (new GenerateResponseStep($ai))->handle($ctx, function () use (&$called) { $called = true; });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }

    public function test_skips_when_bot_inactive(): void
    {
        $this->bot->update(['status' => 'inactive']);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');

        (new GenerateResponseStep($ai))->handle($this->ctx(), fn () => null);
        $this->addToAssertionCount(1);
    }

    public function test_skips_non_text_non_postback(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateAndSaveResponse');
        $ctx = $this->ctx();
        $ctx = new WebhookContext($this->bot, ['type' => 'message', 'message' => ['type' => 'image']], 'facebook');
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->userMessage;
        $ctx->metadata = ['is_handover' => false];

        (new GenerateResponseStep($ai))->handle($ctx, fn () => null);
        $this->addToAssertionCount(1);
    }

    public function test_generation_exception_is_swallowed_like_legacy(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andThrow(new \RuntimeException('boom'));
        $ctx = $this->ctx();
        $called = false;

        (new GenerateResponseStep($ai))->handle($ctx, function () use (&$called) { $called = true; });

        $this->assertTrue($called);
        $this->assertArrayNotHasKey('bot_message', $ctx->metadata);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php artisan test --filter=GenerateResponseStepTest`
Expected: `test_generates_and_bumps_bot_message_stats` fails on `message_count` (still 1), `test_skips_when_handover`/`inactive`/`non_text` fail because the current step generates unconditionally, `exception` test fails with the exception propagating.

- [ ] **Step 3: Rewrite `GenerateResponseStep::handle()`**

Replace the `handle()` method in `backend/app/Services/Webhook/Steps/GenerateResponseStep.php` with (add `use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Log;`):

```php
    public function handle(WebhookContext $ctx, Closure $next): void
    {
        if ($this->shouldGenerate($ctx)) {
            try {
                $botMessage = $this->aiService->generateAndSaveResponse(
                    $ctx->bot,
                    $ctx->conversation,
                    $ctx->userMessage
                );

                // Legacy FB/TG: bump conversation + bot stats for the bot message
                // (AIService already increments bot total_messages once; legacy adds one more — preserved).
                $ctx->conversation->update([
                    'message_count' => DB::raw('message_count + 1'),
                    'last_message_at' => now(),
                    'last_message_id' => $botMessage->id,
                ]);
                $ctx->bot->update([
                    'total_messages' => DB::raw('total_messages + 1'),
                ]);

                $ctx->metadata['bot_message_id'] = $botMessage->id;
                $ctx->metadata['bot_message'] = [
                    'content' => $botMessage->content,
                    'type' => $botMessage->type,
                    'media_url' => $botMessage->media_url,
                ];
            } catch (\Exception $e) {
                // Legacy generateAIResponse() swallowed and logged; the user message is already saved.
                Log::error('Failed to generate AI response for '.ucfirst($ctx->channelType), [
                    'conversation_id' => $ctx->conversation?->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $next($ctx);
    }

    private function shouldGenerate(WebhookContext $ctx): bool
    {
        if ($ctx->userMessage === null || $ctx->conversation === null) {
            return false;
        }
        if (! empty($ctx->metadata['is_handover']) || $ctx->bot->status !== 'active') {
            return false;
        }
        if ($ctx->channelType === 'facebook' && $ctx->eventType() === 'postback') {
            return true;
        }

        return $ctx->messageType() === 'text' && (string) $ctx->userMessage->content !== '';
    }
```

Note on the test fixture: in `test_generates_and_bumps_bot_message_stats` the mocked `generateAndSaveResponse` does not increment bot stats (real one does), so the expected bot `total_messages` is 1 (only the step's own increment). The Facebook postback job test (Task 6) asserts the real end-to-end count of 2.

- [ ] **Step 4: Create `FacebookTypingStep` and `FlowPluginStep`**

`backend/app/Services/Webhook/Steps/Facebook/FacebookTypingStep.php`:

```php
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
```

`backend/app/Services/Webhook/Steps/FlowPluginStep.php`:

```php
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
```

- [ ] **Step 5: Run tests + Pint**

Run: `cd backend && php artisan test --filter=GenerateResponseStepTest && vendor/bin/pint --test`
Expected: 5 tests pass; Pint clean.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Webhook/Steps backend/tests/Unit/Services/Webhook/Steps/GenerateResponseStepTest.php
git commit -m "feat(webhook): GenerateResponseStep ตาม guard/stats ของ legacy + FacebookTypingStep + FlowPluginStep

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 6: `BroadcastStep`, transaction scope, and the Facebook/Telegram step lists; end-to-end v2 tests

**Files:**
- Create: `backend/app/Services/Webhook/Steps/BroadcastStep.php`
- Modify: `backend/app/Services/Webhook/WebhookPipeline.php` (`facebook()`, `telegram()`, add `transactional()` helper)
- Modify: `backend/app/Jobs/ProcessFacebookWebhook.php:170-177` and `backend/app/Jobs/ProcessTelegramWebhook.php:290-297` (`runSharedPipeline` pass the injected services)
- Test: `backend/tests/Unit/Jobs/ProcessFacebookWebhookPostbackTest.php` (add v2-on twin tests), `backend/tests/Unit/Jobs/ProcessFacebookWebhookV2Test.php` (new: text message end-to-end with `Http::fake` + `Event::fake`)

**Interfaces:**
- Consumes: Tasks 3–5 steps; `LeadRecoveryService::markCustomerResponded(Conversation)`; events `App\Events\MessageSent(Message, ?array $conversationData)`, `App\Events\ConversationUpdated(Conversation, string $updateType)`.
- Produces: `WebhookPipeline::facebook(AIService, FacebookService): array`, `WebhookPipeline::telegram(TelegramService, AIService): array`, `WebhookPipeline::transactional(array $steps): Closure` (a step that runs the given inner steps inside `DB::transaction`).

- [ ] **Step 1: Write the failing end-to-end test**

Create `backend/tests/Unit/Jobs/ProcessFacebookWebhookV2Test.php`:

```php
<?php

namespace Tests\Unit\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\ProcessFacebookWebhook;
use App\Models\Bot;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ProcessFacebookWebhookV2Test extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'channel_type' => 'facebook',
            'status' => 'active',
            'channel_access_token' => 'tok',
        ]);
        config(['webhook_pipeline_v2.enabled' => true, 'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'PSID-1', 'message_id' => 'mid.out'], 200)]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
    }

    public function test_text_message_saves_user_and_bot_messages_sends_reply_and_broadcasts(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturnUsing(function ($bot, $conversation, $userMessage) {
            return $conversation->messages()->create(['sender' => 'bot', 'content' => 'สวัสดีค่ะ', 'type' => 'text']);
        });

        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService);

        $this->assertSame(1, Message::where('sender', 'user')->count());
        $this->assertSame(1, Message::where('sender', 'bot')->count());
        $conversation = Message::where('sender', 'user')->first()->conversation;
        $this->assertSame(2, (int) $conversation->message_count);
        $this->assertSame(1, (int) $conversation->unread_count);

        // Reply was sent through the Graph API (FacebookChannelAdapter → FacebookService::sendMessage)
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com') && ($req['message']['text'] ?? null) === 'สวัสดีค่ะ');

        Event::assertDispatched(MessageSent::class, 2);
        Event::assertDispatched(ConversationUpdated::class, fn ($e) => $e->updateType === 'created');
    }

    public function test_duplicate_mid_is_ignored_end_to_end(): void
    {
        $payload = include base_path('tests/fixtures/facebook-text-message.php');
        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturnUsing(
            fn ($bot, $conversation) => $conversation->messages()->create(['sender' => 'bot', 'content' => 'x', 'type' => 'text'])
        );

        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService);
        (new ProcessFacebookWebhook($this->bot, $payload))->handle($aiService); // same mid

        $this->assertSame(1, Message::where('sender', 'user')->count());
    }
}
```

Check `App\Events\ConversationUpdated` exposes the update type as a public property; if the property is named differently (e.g. `$type`), adjust the closure. If `Bot::factory()` lacks `channel_access_token`, add it via `->create([...])` as shown (the column exists — `ProcessFacebookWebhook::sendFacebookMessage` reads it).

Also add to `ProcessFacebookWebhookPostbackTest.php` a v2 twin of `test_postback_message_saves_with_postback_type_and_increments_stats`: copy the test body into `test_postback_v2_path_matches_legacy_stats()` and prefix it with `config(['webhook_pipeline_v2.enabled' => true, 'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id]]);`. Keep the same assertions (`total_messages === 2`, postback row, conversation stats).

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php artisan test --filter="ProcessFacebookWebhookV2Test|ProcessFacebookWebhookPostbackTest"`
Expected: v2 tests FAIL — no user message saved (current v2 has no persist step), Graph API not called (`Unsupported channel type: facebook` logged), no broadcasts.

- [ ] **Step 3: Create `BroadcastStep`**

`backend/app/Services/Webhook/Steps/BroadcastStep.php` (body from the post-transaction block shared by both legacy jobs):

```php
<?php

namespace App\Services\Webhook\Steps;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Message;
use App\Services\LeadRecoveryService;
use App\Services\Webhook\WebhookContext;
use Closure;

/**
 * Post-commit side effects shared by the legacy Facebook/Telegram jobs:
 * refresh conversation, mark lead recovery responded, broadcast MessageSent
 * (user + bot) and ConversationUpdated (created | message_received).
 * Must run OUTSIDE the DB transaction (see WebhookPipeline::transactional()).
 */
class BroadcastStep
{
    public function __construct(private readonly LeadRecoveryService $leadRecovery) {}

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = $ctx->conversation;
        $userMessage = $ctx->userMessage;
        $botMessage = isset($ctx->metadata['bot_message_id']) ? Message::find($ctx->metadata['bot_message_id']) : null;
        $conversationData = null;

        if ($conversation) {
            $conversation->refresh();
            $conversationData = [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ];

            // Mark lead recovery as responded when customer sends a message
            $this->leadRecovery->markCustomerResponded($conversation);
        }
        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData))->toOthers();
        }
        if ($botMessage) {
            broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
        }
        if ($conversation) {
            $updateType = ! empty($ctx->metadata['is_new_conversation']) ? 'created' : 'message_received';
            broadcast(new ConversationUpdated($conversation, $updateType))->toOthers();
        }

        $next($ctx);
    }
}
```

- [ ] **Step 4: Transaction helper + Facebook/Telegram step lists**

In `backend/app/Services/Webhook/WebhookPipeline.php` add (imports: `use App\Services\FacebookService; use App\Services\FlowPluginService; use App\Services\LeadRecoveryService; use App\Services\Webhook\Steps\BroadcastStep; use App\Services\Webhook\Steps\Facebook\FacebookTypingStep; use App\Services\Webhook\Steps\FlowPluginStep; use App\Services\Webhook\Steps\PersistUserMessageStep; use App\Services\Webhook\Steps\Telegram\TelegramMediaStep; use Illuminate\Support\Facades\DB;`):

```php
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

    public static function facebook(AIService $aiService, ?FacebookService $facebookService = null): array
    {
        $facebookService ??= app(FacebookService::class);

        return [
            self::transactional([
                new ResolveConversationStep,
                new PersistUserMessageStep,
            ]),
            new FacebookTypingStep($facebookService),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
            new BroadcastStep(app(LeadRecoveryService::class)),
        ];
    }

    public static function telegram(TelegramService $telegramService, AIService $aiService): array
    {
        return [
            self::transactional([
                new ResolveConversationStep(null, $telegramService),
                new TelegramMediaStep($telegramService),
                new PersistUserMessageStep,
            ]),
            new GenerateResponseStep($aiService),
            new SendResponseStep(app(ChannelAdapterFactory::class)),
            new FlowPluginStep(app(FlowPluginService::class)),
            new BroadcastStep(app(LeadRecoveryService::class)),
        ];
    }
```

`WebhookPipeline::run()` must accept callables that are plain closures as well as step objects. Its current reducer calls `$step($c, $next)` — a step object works only if it is invokable. Check: if the existing steps are called as `$step($c, $next)` and they only define `handle()`, then `run()` already normalises (read it). If not, change the reducer line to:

```php
            fn (Closure $next, callable|object $step) => fn (WebhookContext $c) => is_callable($step) ? $step($c, $next) : $step->handle($c, $next),
```

(Read `WebhookPipeline::run()` first — Task 1's LINE steps rely on the same dispatch rule.)

Legacy TG saved media **inside** the transaction; `TelegramMediaStep` (network download) is therefore also inside — identical scope to legacy. Facebook `runSharedPipeline` in `ProcessFacebookWebhook.php` becomes `WebhookPipeline::facebook($aiService, app(FacebookService::class))`; Telegram's stays `WebhookPipeline::telegram($telegramService, $aiService)`.

- [ ] **Step 5: Run the v2 tests, the full suite, Pint**

Run: `cd backend && php artisan test --filter="ProcessFacebookWebhookV2Test|ProcessFacebookWebhookPostbackTest|ProcessTelegramWebhookMapperTest|Webhook" && php artisan test --parallel --compact 2>&1 | grep -E "Tests:" && vendor/bin/pint --test`
Expected: all green; suite ≥ 1140 passed, 0 failed; Pint clean.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Webhook backend/app/Jobs/ProcessFacebookWebhook.php backend/app/Jobs/ProcessTelegramWebhook.php backend/tests/Unit/Jobs/ProcessFacebookWebhookV2Test.php backend/tests/Unit/Jobs/ProcessFacebookWebhookPostbackTest.php
git commit -m "feat(webhook): v2 FB/TG ครบ parity — transaction scope, BroadcastStep, step list ใหม่ + e2e test

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 7: Rollout runbook + PR-2

**Files:**
- Create: `docs/superpowers/runbooks/2026-09-XX-webhook-v2-rollout.md` — name it with the actual date the PR is opened (e.g. `2026-09-06-webhook-v2-rollout.md`)
- Modify: `docs/webhook-pipeline-v2-rollout.md` — replace the "Facebook — DO NOT enable" section and the "Additional v2 limitations" paragraph (both now false)

**Interfaces:** none.

- [ ] **Step 1: Write the runbook**

```markdown
# Runbook — Webhook pipeline v2 rollout (LINE bot 26 → all)

Owner-operated. Claude does not touch Railway env (spec D3).

## Pre-flight
- PR-1 (#<n>) and PR-2 (#<n>) merged and deployed (Railway shows the new deployment healthy; `/up` = 200).
- `php artisan config:show webhook_pipeline_v2` in the backend shell prints `enabled => false`.

## Step 1 — LINE bot 26 only
Railway backend service → Variables:
- `WEBHOOK_PIPELINE_V2_ENABLED=true`
- `WEBHOOK_PIPELINE_V2_BOT_IDS=26`
Redeploy (config is cached at boot by `config:cache`).

Verify within 5 minutes: send a LINE text message to bot 26 from a test account → reply arrives; in logs (`railway logs`) the line `LINE webhook pipeline.start` still appears (same LineWebhook pipeline), no `Unsupported channel type` and no new exception class.

## Soak: 7 days
Check daily:
1. Sentry backend project — filter `Jobs\ProcessLINEWebhook`: **no new issue** since the flag flip. Any new issue → rollback.
2. Circuit breaker — `railway logs | grep "Circuit breaker for"`: state changes ≤ the week before the flip.
3. Queue depth — Railway metrics for the backend service: no sustained growth on `llm`/`webhooks`.

## Step 2 — all bots (48 h)
Clear `WEBHOOK_PIPELINE_V2_BOT_IDS` (empty) → v2 for every bot on every channel. Facebook/Telegram bots have no customers (spec D4); this step only proves nothing crashes for them. Watch the same three signals for 48 h.

## Rollback (any time before PR-3)
Set `WEBHOOK_PIPELINE_V2_ENABLED=false` and redeploy. Legacy paths resume with zero code change.

## Exit → PR-3
After Step 2 is clean for 48 h, tell Claude "soak ผ่าน" → PR-3 deletes the legacy paths and both flags (plan Task 8).
```

- [ ] **Step 2: Fix the stale statements in `docs/webhook-pipeline-v2-rollout.md`**

Replace the `### 3. Facebook — DO NOT enable` section with:

```markdown
### 3. Facebook — enabled together with "all bots"

`FacebookChannelAdapter` is registered in `ChannelAdapterFactory` (Track 2 PR-2). Facebook bots follow the same flag; no separate step.
```

Replace the paragraph starting `Additional v2 limitations to be aware of` with:

```markdown
Parity status (Track 2 PR-2): dedup, stats, post-commit broadcasts, `LeadRecoveryService::markCustomerResponded`, Facebook typing indicator, Telegram media/placeholder and flow plugins are implemented in the v2 steps. LINE runs its production `LineWebhook/*` pipeline unchanged via `LinePipelineStep`.
```

- [ ] **Step 3: Commit, push, open PR-2**

```bash
git add docs/superpowers/runbooks docs/webhook-pipeline-v2-rollout.md
git commit -m "docs(webhook): runbook rollout v2 (bot 26 → all) และอัปเดตสถานะ parity

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
git push -u origin refactor/webhook-v2-parity
gh pr create --base refactor/webhook-v2-line --title "feat(webhook): Facebook/Telegram parity on pipeline v2 + FacebookChannelAdapter + rollout runbook (Track 2 PR-2)" --body "$(cat <<'EOF'
## Summary
Stacked on PR-1. Gives the shared v2 pipeline everything the legacy Facebook/Telegram jobs did:
- `PersistUserMessageStep` (dedup on external id, save user message, conversation + bot stats), `TelegramMediaStep` (download + placeholder), FB postback content/type
- `GenerateResponseStep` now honours handover / inactive bot / text-or-postback guards, bumps bot-message stats, swallows generation errors like legacy
- `FacebookTypingStep` (typing_on/off), `FlowPluginStep` (Telegram plugins), `BroadcastStep` (refresh, lead recovery, MessageSent ×2, ConversationUpdated) — post-commit
- `WebhookPipeline::transactional()` keeps resolve+persist(+TG media) in one transaction; generation/send run after commit (as LINE already does)
- `FacebookChannelAdapter` registered; `FacebookService::sendTypingIndicator`
- Runbook: `docs/superpowers/runbooks/<date>-webhook-v2-rollout.md`

Flag remains OFF. Nothing changes in production until the owner follows the runbook.

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §6.3–6.4
Plan: `docs/superpowers/plans/2026-09-05-track2-webhook-v2.md` Tasks 3–7

## Test plan
- [x] `FacebookChannelAdapterTest`, `PersistUserMessageStepTest`, `GenerateResponseStepTest`, `ProcessFacebookWebhookV2Test`, postback v2 twin — all green
- [x] full suite, Pint
- [ ] owner: runbook Step 1 (bot 26) → 7-day soak → Step 2 (all) → 48 h → "soak ผ่าน"

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

---

## PR-3 — delete legacy (after the soak; `refactor/webhook-v2-remove-legacy` from `main`)

### Task 8: Remove legacy paths, both flags, config, stale docs and tests

**Precondition:** owner has said "soak ผ่าน" after runbook Step 2. Do not start before.

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php` — delete `runPipeline()` (143–216), `processEvent()` and every helper only it uses (`botAlreadyRespondedTo`, `handleRateLimitExceeded`, `handleOutsideResponseHours`), keep `createNewConversation`, `updateStatsForUserMessageOnly`, `findOrCreateCustomerProfile` if still referenced by `NonTextHandler` closures / `LineWebhookContextService` (grep before deleting); `handle()` keeps only `runSharedPipeline(...)`; drop now-unused constructor/handle parameters and imports
- Modify: `backend/app/Jobs/ProcessFacebookWebhook.php` — delete `processMessagingEvent`, `handleMessage`, `handlePostback`, `createNewConversation`, `findOrCreateCustomerProfile`, `fetchFacebookProfile`, `generateAIResponse`, `sendFacebookMessage`, `sendTypingIndicator`; `processPayload()` always calls `runSharedPipeline`
- Modify: `backend/app/Jobs/ProcessTelegramWebhook.php` — delete the inline transaction path in `processUpdate()` and `createNewConversation`, `processMedia`, `generateMediaPlaceholder`, `findOrCreateCustomerProfile`, `fetchUserProfilePhoto`, `generateAIResponse`
- Delete: `backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php`, `backend/app/Services/Webhook/WebhookPipelineV2Flag.php`, `backend/config/line_webhook.php`, `backend/config/webhook_pipeline_v2.php`, `backend/tests/Unit/Services/LineWebhook/LineWebhookPipelineFlagTest.php`, `backend/tests/Unit/Services/Webhook/WebhookPipelineV2FlagTest.php`, `docs/webhook-pipeline-v2-rollout.md`
- Modify: `backend/.env.example` — remove `PROCESS_LINE_PIPELINE_ENABLED`, `PROCESS_LINE_PIPELINE_BOT_IDS` (and add/remove `WEBHOOK_PIPELINE_V2_*` lines if present)
- Modify tests that set the flags: `ProcessLINEWebhookPipelineTest.php` (delete `test_legacy_path_runs_when_flag_off`; remove `config([...pipeline_enabled...])` lines from the rest), `PipelineImageRoutingTest.php:41`, `ProcessFacebookWebhookPostbackTest.php` (the legacy test and its v2 twin collapse into one), `ProcessFacebookWebhookV2Test.php` (drop the `config([...])` line)

**Interfaces:** none new. `WebhookPipeline::line/facebook/telegram` unchanged.

- [ ] **Step 1: Write the guard test first**

Create `backend/tests/Unit/Services/Webhook/NoLegacyWebhookPathTest.php`:

```php
<?php

namespace Tests\Unit\Services\Webhook;

use Tests\TestCase;

class NoLegacyWebhookPathTest extends TestCase
{
    public function test_legacy_symbols_and_flags_are_gone(): void
    {
        $line = file_get_contents(app_path('Jobs/ProcessLINEWebhook.php'));
        $fb = file_get_contents(app_path('Jobs/ProcessFacebookWebhook.php'));
        $tg = file_get_contents(app_path('Jobs/ProcessTelegramWebhook.php'));

        $this->assertStringNotContainsString('function processEvent(', $line);
        $this->assertStringNotContainsString('function runPipeline(', $line);
        $this->assertStringNotContainsString('function processMessagingEvent(', $fb);
        $this->assertStringNotContainsString('function generateAIResponse(', $fb);
        $this->assertStringNotContainsString('function generateAIResponse(', $tg);
        $this->assertStringNotContainsString('DB::transaction', $tg);

        $this->assertFalse(class_exists(\App\Services\LineWebhook\LineWebhookPipelineFlag::class));
        $this->assertFalse(class_exists(\App\Services\Webhook\WebhookPipelineV2Flag::class));
        $this->assertFileDoesNotExist(config_path('line_webhook.php'));
        $this->assertFileDoesNotExist(config_path('webhook_pipeline_v2.php'));
    }

    public function test_each_job_is_thin(): void
    {
        foreach (['ProcessLINEWebhook', 'ProcessFacebookWebhook', 'ProcessTelegramWebhook'] as $job) {
            $lines = count(file(app_path("Jobs/{$job}.php")));
            $this->assertLessThanOrEqual(150, $lines, "{$job} has {$lines} lines (target ≤ 150)");
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --filter=NoLegacyWebhookPathTest`
Expected: both tests FAIL.

- [ ] **Step 3: Delete in this order, running the suite after each bullet**

1. Jobs: make `handle()` unconditional (`$this->runSharedPipeline(...)`), delete the methods listed in Files. In `ProcessLINEWebhook`, the `NonTextHandler` closures still need `createNewConversation()` and `updateStatsForUserMessageOnly()` — keep those two (they are ~55 lines) unless `grep -rn "createNewConversation\|updateStatsForUserMessageOnly" app/Services` shows an equivalent already exists in `LineWebhookContextService`; if it does, point the closures there and delete the job copies. `findOrCreateCustomerProfile` is used only by `createNewConversation` — keep or delete with it.
2. Flags + config files + `.env.example` lines + flag tests + `docs/webhook-pipeline-v2-rollout.md`.
3. Tests that set flags (list in Files) — remove the `config([...])` lines; delete `test_legacy_path_runs_when_flag_off`; merge the postback twin.
4. `vendor/bin/pint` (fix mode) to drop unused imports, then `vendor/bin/pint --test`.

Run after each bullet: `cd backend && php artisan test --parallel --compact 2>&1 | grep -E "Tests:"` → `0 failed`.

- [ ] **Step 4: Line-count check + guard test**

Run: `cd backend && wc -l app/Jobs/ProcessLINEWebhook.php app/Jobs/ProcessFacebookWebhook.php app/Jobs/ProcessTelegramWebhook.php && php artisan test --filter=NoLegacyWebhookPathTest`
Expected: each ≤ 150 lines; guard test green. If LINE stays above 150 because of the two kept helpers, move them verbatim into `app/Services/Webhook/Channels/LINE/LineConversationFactory.php` (two public methods with the same signatures) and construct `NonTextHandler` with closures over that class.

- [ ] **Step 5: Commit, push, PR-3**

```bash
git add -A backend docs
git commit -m "refactor(webhook): ลบ legacy path ทั้ง 3 job และ flag/config ของ pipeline — v2 เป็นทางเดียว

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
git push -u origin refactor/webhook-v2-remove-legacy
gh pr create --base main --title "refactor(webhook): remove legacy webhook paths and pipeline flags (Track 2 PR-3)" --body "$(cat <<'EOF'
## Summary
After the production soak (bot 26 7 days, all bots 48 h) v2 is the only path:
- `ProcessLINEWebhook` / `ProcessFacebookWebhook` / `ProcessTelegramWebhook` are mapper → `WebhookPipeline::run` (each ≤ 150 lines)
- Deleted `LineWebhookPipelineFlag`, `WebhookPipelineV2Flag`, `config/line_webhook.php`, `config/webhook_pipeline_v2.php`, `.env.example` flag lines, `docs/webhook-pipeline-v2-rollout.md`
- Guard test `NoLegacyWebhookPathTest` keeps them gone

Rollback = revert this PR (flags no longer exist; the previous deploy had v2 on for all bots already, so reverting changes nothing at runtime except restoring dead code).

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §6.5
Plan: `docs/superpowers/plans/2026-09-05-track2-webhook-v2.md` Task 8

## Test plan
- [x] full suite green, Pint clean
- [ ] owner: Railway variables `WEBHOOK_PIPELINE_V2_*` / `PROCESS_LINE_PIPELINE_*` can be deleted after merge (they are ignored)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

---

## Self-review

- **Spec coverage (§6):** 6.2 PR-1 → Tasks 1–2 (shape deviation recorded in the spec by Task 2); 6.3 PR-2 dedup/stats/broadcast/lead recovery → Tasks 4 and 6; `FacebookChannelAdapter` + factory → Task 3; postback (schema fixed in #250) → Task 4; tests → Tasks 3–6; 6.4 runbook + monitoring signals → Task 7; 6.5 PR-3 deletions + ≤150 LOC + flag/config removal → Task 8. Items the spec did not list but legacy has (typing indicator, Telegram media/placeholder, Telegram flow plugins, guarded generation, swallowed generation errors, double `total_messages` increment) → Tasks 4–5, called out in the "Verified facts" section.
- **Placeholders:** the runbook filename carries `<date>`/`XX` by design (fill with the PR date); PR numbers `#<n>` in the runbook are filled at Task 7. No other unknowns.
- **Consistency:** `WebhookContext` (shared) vs `LineCtx` alias used consistently; `metadata` keys (`is_new_conversation`, `is_handover`, `bot_message_id`, `bot_message`, `media`, `placeholder`, `line_ctx`, `sender_id`, `mid`, `message_id`) match between producer and consumer tasks; `WebhookPipeline::line()` signature in Task 1 Step 5 equals the one the job calls; `transactional()` returns a closure and `run()` accepts closures (Task 6 Step 4 says to verify/normalise the reducer).
