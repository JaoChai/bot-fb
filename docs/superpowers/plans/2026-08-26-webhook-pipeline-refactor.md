# Unified Webhook Pipeline Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract duplicated webhook orchestration from the three channel jobs (LINE 1,531 / FB 728 / Telegram 561 lines) into a shared `app/Services/Webhook/` pipeline, preserving behavior exactly (verified by the existing suite).

**Architecture:** Generalize the existing `LineWebhook/` pipeline pattern (Gating→Context→Response→Output services behind `LineWebhookPipelineFlag`) into a channel-agnostic pipeline with shared steps. Channel jobs become thin dispatchers that map provider payloads onto a `WebhookContext` DTO and delegate. Cross-cutting concerns (circuit breaker, fallback) move into a Laravel job middleware.

**Tech Stack:** Laravel 11+ (queued jobs, job middleware, service container), PHPUnit (existing `tests/Feature/LINEWebhookTest.php`, `tests/Unit/*` suites).

**Spec:** `docs/superpowers/specs/2026-08-26-webhook-pipeline-refactor-design.md`

## Global Constraints

- Zero behavior change on every response path — pure refactor.
- All 72 existing backend test files stay green after EVERY task; run full suite before each commit.
- No changes to queue names, RAGService, prompts, or frontend in this plan.
- Feature-flag semantics preserved: `line_webhook.pipeline_enabled` + `pipeline_bot_ids` config keys unchanged.
- Circuit-open → channel fallback message, no retry. Unexpected exception → log (trace only outside production) → rethrow for queue retry. Fallback send must not touch DB.
- Each task = one commit, conventional-commit message, CI green per commit.
- Baseline: record full-suite pass count BEFORE Task 1 and compare after every phase.

---

### Task 1: Record baseline & add suite-run helper

**Files:**
- Modify: none (verification only)
- Create: `docs/superpowers/plans/webhook-pipeline-baseline.md`

**Interfaces:**
- Produces: baseline pass/fail/skip counts recorded in `docs/superpowers/plans/webhook-pipeline-baseline.md`; later tasks compare against this.

- [ ] **Step 1: Run full backend suite**

```bash
cd backend && php artisan test 2>&1 | tail -15
```
Record: tests count, pass count, fail count, skip count.

- [ ] **Step 2: Write baseline file**

Write the numbers plus date/commit SHA into `docs/superpowers/plans/webhook-pipeline-baseline.md`. Example content:

```markdown
# Webhook Pipeline Refactor — Test Baseline
- Date: <date>
- Commit: <sha>
- Tests: <N>, Passed: <N>, Failed: <N>, Skipped: <N>
```

- [ ] **Step 3: Commit**

```bash
git add docs/superpowers/plans/webhook-pipeline-baseline.md
git commit -m "docs(plan): record test baseline before webhook pipeline refactor"
```

### Task 2: CircuitBreakerJobMiddleware

Extract the identical circuit-breaker try-catch wrapper from all three jobs into one Laravel job middleware (`CircuitBreakerService::execute('database', …)` + `sendFallbackMessage` callback + `CircuitOpenException` handling).

**Files:**
- Create: `app/Jobs/Middleware/CircuitBreakerJobMiddleware.php`
- Modify: `app/Jobs/ProcessLINEWebhook.php:94-143` (handle method), `app/Jobs/ProcessFacebookWebhook.php:58-86`, `app/Jobs/ProcessTelegramWebhook.php:57-91`
- Test: `tests/Unit/Jobs/CircuitBreakerJobMiddlewareTest.php`

**Interfaces:**
- Consumes: `App\Services\CircuitBreakerService::execute(string $service, callable $operation, ?callable $fallback = null): mixed` (exists at line 39).
- Produces: `CircuitBreakerJobMiddleware::handle(object $job, Closure $next): void` — wraps `$next($job)` in circuit breaker using `$job->circuitFallback()` (channel-specific, DB-independent) and logs with `$job->bot->id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Middleware\CircuitBreakerJobMiddleware;
use App\Services\CircuitBreakerService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CircuitBreakerJobMiddlewareTest extends TestCase
{
    public function test_calls_next_when_circuit_closed(): void
    {
        $cb = Mockery::mock(CircuitBreakerService::class);
        $cb->shouldReceive('execute')
            ->once()
            ->with('database', Mockery::on(fn ($op) => is_callable($op)), null)
            ->andReturnUsing(function ($service, $operation, $fallback) {
                return $operation();
            });

        $executed = false;
        $job = new class {
            public $bot;
            public bool $ran = false;
            public function circuitFallback(): void {}
        };
        $middleware = new CircuitBreakerJobMiddleware($cb);

        $middleware->handle($job, function ($j) use (&$executed) {
            $j->ran = true;
        });

        $this->assertTrue($job->ran);
    }

    public function test_sends_fallback_on_circuit_open(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'Webhook circuit breaker open',
            Mockery::on(fn ($ctx) => isset($ctx['service']))
        );

        $cb = Mockery::mock(CircuitBreakerService::class);
        $cb->shouldReceive('execute')->once()->andThrow(new \App\Exceptions\CircuitOpenException('database'));

        $fallbackCalled = false;
        $job = new class {
            public $bot;
            public function circuitFallback(): void { $this->fallbackCalled = true; }
        };

        $middleware = new CircuitBreakerJobMiddleware($cb);
        $middleware->handle($job, fn () => null);

        $this->assertTrue($job->fallbackCalled);
    }
}
```

(Adjust anonymous-class syntax so `fallbackCalled` is settable — e.g. use a small named fixture class inside the test file.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Jobs/CircuitBreakerJobMiddlewareTest.php`
Expected: FAIL — class `App\Jobs\Middleware\CircuitBreakerJobMiddleware` does not exist.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Jobs\Middleware;

use App\Exceptions\CircuitOpenException;
use App\Services\CircuitBreakerService;
use Closure;
use Illuminate\Support\Facades\Log;

class CircuitBreakerJobMiddleware
{
    public function __construct(protected CircuitBreakerService $circuitBreaker) {}

    public function handle(object $job, Closure $next): void
    {
        try {
            $this->circuitBreaker->execute('database', fn () => $next($job));
        } catch (CircuitOpenException $e) {
            Log::warning('Webhook circuit breaker open', [
                'service' => $e->getService(),
            ]);
            $job->circuitFallback();
        }
    }
}
```

Then in each of the three jobs:
1. Add `public function middleware(): array { return [app(CircuitBreakerJobMiddleware::class)]; }`
2. Slim `handle()` to just the processing call (remove the try-catch + circuit breaker wrapper).
3. Rename existing `sendFallbackMessage(...)` to satisfy the contract as `public function circuitFallback(): void` (keep its DB-independence — do not change its body).
4. Keep the outer generic `\Exception` catch (log + rethrow) inside `handle()` exactly as today, including the per-channel log message strings.

- [ ] **Step 4: Run new test + affected job tests**

Run: `php artisan test tests/Unit/Jobs/CircuitBreakerJobMiddlewareTest.php tests/Feature/LINEWebhookTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite + commit**

Run full suite; compare against baseline. Then:

```bash
git add -A && git commit -m "refactor(jobs): extract circuit breaker into shared job middleware"
```

### Task 3: Extract LINE VisionHandler verbatim

Cut image-analysis logic (~lines 1076–1345: `handleImageAnalysis`, `getVisionModel`, `buildVisionSystemPrompt`, `getImageAnalysisPrompt`) out of ProcessLINEWebhook into a handler class. NO logic edits.

**Files:**
- Create: `app/Services/Webhook/Channels/LINE/VisionHandler.php`
- Modify: `app/Jobs/ProcessLINEWebhook.php` (call site replaces inline code)
- Test: `tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php`

**Interfaces:**
- Consumes: existing private methods' exact bodies from ProcessLINEWebhook (move, don't rewrite); conversation history array shape unchanged.
- Produces: `VisionHandler::analyze(WebhookContext-like payload): string` — signature must match how the job currently invokes `handleImageAnalysis` internally; return value flows into the same reply path as before.

- [ ] **Step 1: Characterization test first**

Before moving code, write a test pinning current output for one canned image event (reuse fixture payloads from `tests/Feature/PipelineImageRoutingTest.php`). Run it against the CURRENT implementation to green, keep it green after extraction.

```php
<?php

namespace Tests\Unit\Services\Webhook\LINE;

use App\Models\Bot;
use App\Services\Webhook\Channels\LINE\VisionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VisionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_placeholder_text_for_image_event(): void
    {
        $bot = Bot::factory()->create();
        $handler = app(VisionHandler::class);

        // Mirror the fixture used by PipelineImageRoutingTest for an image event
        $event = include base_path('tests/fixtures/line-image-event.php');

        $result = $handler->analyze($bot, $event['message']);

        $this->assertIsString($result);
        $this->assertNotSame('', $result);
    }
}
```

(Create `tests/fixtures/line-image-event.php` if PipelineImageRoutingTest builds events inline instead — copy its array verbatim.)

- [ ] **Step 2: Verify test passes against current code path**

Run: `php artisan test tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php`
If it can't reach the logic without the new class yet, instead assert via the existing feature route (dispatch job with mocked LINE SDK) — same assertion.

- [ ] **Step 3: Move methods verbatim**

Create VisionHandler with the four methods moved byte-for-byte (adjust only `$this->x` property references to injected dependencies). Replace the call sites in the job with `$visionHandler->analyze(...)`. Delete moved code from the job.

- [ ] **Step 4: Suite + commit**

Full suite vs baseline, then:

```bash
git add -A && git commit -m "refactor(line): extract vision analysis into VisionHandler (verbatim move)"
```

### Task 4: Extract LINE StickerHandler + NonTextHandler verbatim

Same pattern as Task 3 for sticker reply (~lines 997–1076) and non-text handling (~lines 796–994).

**Files:**
- Create: `app/Services/Webhook/Channels/LINE/StickerHandler.php`
- Create: `app/Services/Webhook/Channels/LINE/NonTextHandler.php`
- Modify: `app/Jobs/ProcessLINEWebhook.php`
- Test: `tests/Unit/Services/Webhook/LINE/StickerHandlerTest.php`, `tests/Unit/Services/Webhook/LINE/NonTextHandlerTest.php`

**Interfaces:**
- Produces: `StickerHandler::reply(Bot $bot, array $event): void` and `NonTextHandler::handle(Bot $bot, Conversation $conversation, array $event): void` — signatures mirroring current internal calls; both called from the same spots in the job's `processEvent` flow.

- [ ] **Step 1: Characterization tests** — one per handler pinning current side effects (sticker → reply API called with expected package/id; non-text → placeholder Message row created), using fixtures copied from `tests/Feature/LINEWebhookTest.php`.
- [ ] **Step 2: Verify red/green against current path** before moving.
- [ ] **Step 3: Move methods verbatim**, update call sites, delete originals.
- [ ] **Step 4: Full suite vs baseline + commit**

```bash
git add -A && git commit -m "refactor(line): extract sticker and non-text handlers (verbatim move)"
```

### Task 5: Shared WebhookContext DTO (channel-agnostic)

Promote a generalized context DTO. The existing `App\Services\LineWebhook\WebhookContext` stays untouched this task (it keeps working for the LINE pipeline path); the new one lives in the shared namespace and FB/TG will use it.

**Files:**
- Create: `app/Services/Webhook/WebhookContext.php`
- Test: `tests/Unit/Services/Webhook/WebhookContextTest.php`

**Interfaces:**
- Produces: `new WebhookContext(Bot $bot, array $rawEvent, string $channelType)` with accessors `messageType(): ?string`, `userId(): ?string`, `replyToken(): ?string`, plus mutable fields `?CustomerProfile $profile`, `?Conversation $conversation`, `?Message $userMessage`, `array $metadata`, `bool $aggregationBuffered` — mirroring the LINE DTO shape so Task 8's mappers have one contract.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;
use Tests\TestCase;

class WebhookContextTest extends TestCase
{
    public function test_accessors_pull_from_raw_event(): void
    {
        $bot = Bot::factory()->make();
        $ctx = new WebhookContext($bot, ['type' => 'message', 'text' => 'hi'], 'facebook');

        $this->assertSame('facebook', $ctx->channelType);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('hi', $ctx->text());
        $this->assertNull($ctx->conversation);
    }

    public function test_line_shape_accessors(): void
    {
        $bot = Bot::factory()->make();
        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'replyToken' => 'tok123',
            'source' => ['userId' => 'U123'],
            'message' => ['type' => 'text', 'text' => 'hello'],
        ], 'line');

        $this->assertSame('tok123', $ctx->replyToken());
        $this->assertSame('U123', $ctx->userId());
        $this->assertSame('text', $ctx->messageType());
        $this->assertSame('hello', $ctx->text());
    }
}
```

- [ ] **Step 2: Red** — run, expect class-not-found.
- [ ] **Step 3: Implement** — plain readonly-ish DTO, no framework magic; text() reads `$rawEvent['message']['text'] ?? $rawEvent['text'] ?? null`.
- [ ] **Step 4: Green + full suite + commit**

```bash
git add -A && git commit -m "feat(webhook): add channel-agnostic WebhookContext DTO"
```

### Task 6: EventMappers for Facebook + Telegram

Payload → WebhookContext translation for the two channels still on legacy paths. Pure functions over arrays; heavy profile-fetching stays OUT (mappers never hit network).

**Files:**
- Create: `app/Services/Webhook/Channels/Facebook/FacebookEventMapper.php`
- Create: `app/Services/Webhook/Channels/Telegram/TelegramEventMapper.php`
- Test: `tests/Unit/Services/Webhook/Facebook/FacebookEventMapperTest.php`
- Test: `tests/Unit/Services/Webhook/Telegram/TelegramEventMapperTest.php`

**Interfaces:**
- Produces: `map(array $payload, Bot $bot): ?WebhookContext` per mapper; returns null for ignorable events (FB: deliveries/reads; TG: non-message updates). `supports(array $payload): bool` companion.

- [ ] **Step 1: Failing tests** — build payloads from real shapes found in `ProcessFacebookWebhook::processMessagingEvent` (messaging array with sender/recipient/message/postback) and `ProcessTelegramWebhook::processUpdate` (`update_id` + `message` with chat/from). Assert: mapped channelType, userId (psid / chat id), text, messageType ('text'|'image'|…), null-return cases for read/delivery echoes and unsupported updates.
- [ ] **Step 2: Red** — expect class-not-found.
- [ ] **Step 3: Implement** both mappers reading their mapping rules directly from the parsing code inside the two jobs (`mapAttachmentType`, `mapMessageType`, attachment placeholders) — copy those helpers into the mappers verbatim rather than referencing the jobs.
- [ ] **Step 4: Green + full suite + commit**

```bash
git add -A && git commit -m "feat(webhook): facebook and telegram event mappers"
```

### Task 7: Wire Facebook job through mapper (thin dispatcher)

Convert ProcessFacebookWebhook to construct WebhookContext via its mapper and delegate to its existing process flow. Job shrinks below ~400 lines; remaining inline orchestration moves in Task 9.

**Files:**
- Modify: `app/Jobs/ProcessFacebookWebhook.php`
- Test: existing `tests/Feature/Api/**` FB webhook coverage (identify exact file during execution — search `grep -rl 'FacebookWebhook\|facebook.*webhook' tests/Feature`)

**Interfaces:**
- Consumes: `FacebookEventMapper::map()`, `WebhookContext` from Tasks 5-6.
- Produces: job whose `processMessagingEvent()` accepts `(WebhookContext $ctx)` instead of raw array.

- [ ] **Step 1: Update characterization coverage** — confirm existing FB webhook feature tests exercise message + postback paths; if postback lacks a test, add one FIRST (fixture from `handlePostback` body) so the rewiring is guarded.
- [ ] **Step 2: Rewire** — `handle()` calls mapper; null ctx → return early (same ignore semantics); thread WebhookContext through handleMessage/handlePostback signatures. No logic edits inside method bodies beyond parameter plumbing.
- [ ] **Step 3: Full suite vs baseline + commit**

```bash
git add -A && git commit -m "refactor(facebook): dispatch through FacebookEventMapper + WebhookContext"
```

### Task 8: Wire Telegram job through mapper (thin dispatcher)

Same conversion as Task 7 for ProcessTelegramWebhook.

**Files:**
- Modify: `app/Jobs/ProcessTelegramWebhook.php`
- Test: existing Telegram feature tests (locate via `grep -rl 'Telegram' tests/Feature | head`)

**Interfaces:**
- Consumes: `TelegramEventMapper::map()`, `WebhookContext`.

- [ ] **Step 1: Confirm media-path test exists** (`processMedia` covered; add fixture-first test if not).
- [ ] **Step 2: Rewire** — mapper at top of handle(); media placeholder generation now via mapper metadata; no body edits beyond plumbing.
- [ ] **Step 3: Full suite vs baseline + commit**

```bash
git add -A && git commit -m "refactor(telegram): dispatch through TelegramEventMapper + WebhookContext"
```

### Task 9: Shared WebhookPipeline + steps

Build the orchestrator and the shared step classes from the spec. At this point all three channels already share middleware + DTO; this task unifies the remaining transaction/AI/send sequence behind one pipeline class, wired per-channel behind flags (default OFF everywhere).

**Files:**
- Create: `app/Services/Webhook/WebhookPipeline.php`
- Create: `app/Services/Webhook/Steps/ResolveConversationStep.php`
- Create: `app/Services/Webhook/Steps/GenerateResponseStep.php`
- Create: `app/Services/Webhook/Steps/SendResponseStep.php`
- Modify: three jobs (opt-in dispatch when flag enabled)
- Test: `tests/Unit/Services/Webhook/WebhookPipelineTest.php`

**Interfaces:**
- Produces: `WebhookPipeline::run(WebhookContext $ctx, array $steps): void` — executes steps as onion (each step receives `(WebhookContext, Closure $next)`); single `DB::transaction` wraps ResolveConversation+persist portion exactly matching today's scope in each legacy job.
- Step contracts: `ResolveConversationStep` delegates to existing per-channel find-or-create logic (extracted verbatim from jobs); `GenerateResponseStep` delegates to the same `generateAIResponse` internals; `SendResponseStep` sends via `ChannelAdapterInterface::sendMessage`.

- [ ] **Step 1: Failing pipeline test**

```php
<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;
use App\Services\Webhook\WebhookPipeline;
use Tests\TestCase;

class WebhookPipelineTest extends TestCase
{
    public function test_runs_steps_in_order(): void
    {
        $order = [];
        $pipeline = app(WebhookPipeline::class);
        $ctx = new WebhookContext(Bot::factory()->make(), [], 'test');

        $pipeline->run($ctx, [
            function (WebhookContext $c, \Closure $next) use (&$order) {
                $order[] = 'a';
                $next($c);
            },
            function (WebhookContext $c, \Closure $next) use (&$order) {
                $order[] = 'b';
                $next($c);
            },
        ]);

        $this->assertSame(['a', 'b'], $order);
    }

    public function test_step_can_short_circuit(): void
    {
        $pipeline = app(WebhookPipeline::class);
        $ctx = new WebhookContext(Bot::factory()->make(), [], 'test');
        $reached = false;

        $pipeline->run($ctx, [
            function (WebhookContext $c, \Closure $next) { /* no $next call */ },
            function (WebhookContext $c, \Closure $next) use (&$reached) {
                $reached = true;
                $next($c);
            },
        ]);

        $this->assertFalse($reached);
    }
}
```

- [ ] **Step 2: Red** — class-not-found.
- [ ] **Step 3: Implement pipeline** (Laravel-style onion):

```php
<?php

namespace App\Services\Webhook;

use Closure;

class WebhookPipeline
{
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
}
```

- [ ] **Step 4: Extract ResolveConversation per channel verbatim** from each job's `createNewConversation`/`findOrCreateCustomerProfile` into the step (one class, switch on channelType calling channel-private extracted methods). Guard with characterization tests built from existing fixtures.
- [ ] **Step 5: Wire opt-in** — behind a new `webhook_pipeline_v2.enabled` + per-bot whitelist config (same pattern as `LineWebhookPipelineFlag`), default false; jobs check flag → pipeline path, else legacy path. Both paths live side by side.
- [ ] **Step 6: Full suite vs baseline + commit**

```bash
git add -A && git commit -m "feat(webhook): shared WebhookPipeline with resolve/response/send steps (flagged off)"
```

### Task 10: Enable v2 flag for LINE bots in staging + rollout docs

Final task: flip nothing in prod; prepare staged rollout.

**Files:**
- Modify: `config/webhook_pipeline_v2.php` (create with enabled=false defaults)
- Create: `docs/webhook-pipeline-v2-rollout.md`

**Interfaces:**
- Consumes: flag from Task 9.

- [ ] **Step 1: Config file** with `enabled`, `bot_ids` whitelist keys documented inline.
- [ ] **Step 2: Rollout doc** — per-channel enable order (LINE first since it has dual-path experience), monitoring signals (error rate, fallback trigger count via ResilienceMetricsService, queue depth), rollback = set flag false.
- [ ] **Step 3: Full suite + commit + push all phases**

```bash
git add -A && git commit -m "feat(webhook): v2 flag config and rollout plan docs"
git push origin main
```

---

## Out of scope (explicitly)

- Deleting legacy paths (Phase 5 cleanup happens after a release cycle proving parity — separate future plan).
- Migrating the existing `LineWebhook/*Service` pipeline onto the new shared pipeline.
- RAGService split, FlowController split, frontend work.
