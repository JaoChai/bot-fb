# ProcessLINEWebhook Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `backend/app/Jobs/ProcessLINEWebhook.php` (1432 LOC) into a pipeline of 4 stage services behind a feature flag, with behavior identical to legacy.

**Architecture:** Job entry point branches on `PROCESS_LINE_PIPELINE_ENABLED` + bot whitelist. New path runs 4 sequential stages (Gating → Context → Response → Output) sharing a `WebhookContext` DTO. Legacy `processEvent()` and helpers stay untouched until cleanup phase post-rollout.

**Tech Stack:** Laravel 12, PHP 8.4, PHPUnit, Redis (queue + cache), PostgreSQL (Neon).

**Spec:** `docs/superpowers/specs/2026-05-16-process-line-webhook-refactor-design.md`

---

## File Structure

| File | Responsibility | Phase |
|------|----------------|-------|
| `backend/config/line_webhook.php` | Config binding env vars | 1 |
| `backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php` | Flag resolution | 1 |
| `backend/app/Services/LineWebhook/GateDecision.php` | Enum: ALLOW / RATE_LIMITED / OUTSIDE_HOURS | 1 |
| `backend/app/Services/LineWebhook/ResponseEnvelope.php` | DTO {type, payload} | 1 |
| `backend/app/Services/LineWebhook/WebhookContext.php` | Carrier DTO | 1 |
| `backend/app/Services/LineWebhook/LineWebhookGatingService.php` | Stage 1 | 2 |
| `backend/app/Services/LineWebhook/LineWebhookContextService.php` | Stage 2 | 2 |
| `backend/app/Services/LineWebhook/LineWebhookResponseService.php` | Stage 3 | 2 |
| `backend/app/Services/LineWebhook/LineWebhookOutputService.php` | Stage 4 | 2 |
| `backend/app/Jobs/ProcessLINEWebhook.php` | Flag branch in `handle()` | 3 |
| `backend/tests/Unit/Services/LineWebhook/*Test.php` | Per-service unit tests | with each task |
| `backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` | End-to-end pipeline test | 3 |

---

## Phase 1 — Foundation (no behavior change)

### Task 1: Config + env flag

**Files:**
- Create: `backend/config/line_webhook.php`
- Modify: `backend/.env.example`

- [ ] **Step 1: Create config file**

`backend/config/line_webhook.php`:
```php
<?php

return [
    'pipeline_enabled' => env('PROCESS_LINE_PIPELINE_ENABLED', false),
    'pipeline_bot_ids' => array_filter(array_map(
        'trim',
        explode(',', (string) env('PROCESS_LINE_PIPELINE_BOT_IDS', ''))
    )),
];
```

- [ ] **Step 2: Document env vars in `.env.example`**

Append to `backend/.env.example`:
```
# Dark-launch new ProcessLINEWebhook pipeline. Default false.
PROCESS_LINE_PIPELINE_ENABLED=false
# Comma-separated bot IDs to opt into pipeline. Empty + ENABLED=true => all bots.
PROCESS_LINE_PIPELINE_BOT_IDS=
```

- [ ] **Step 3: Verify config loads**

Run from `backend/`: `php artisan tinker --execute='dump(config("line_webhook"));'`
Expected: `["pipeline_enabled" => false, "pipeline_bot_ids" => []]`

- [ ] **Step 4: Commit**

```bash
git add backend/config/line_webhook.php backend/.env.example
git commit -m "feat(line-webhook): add pipeline feature flag config"
```

---

### Task 2: LineWebhookPipelineFlag

**Files:**
- Create: `backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php`
- Create: `backend/tests/Unit/Services/LineWebhook/LineWebhookPipelineFlagTest.php`

- [ ] **Step 1: Write failing tests**

`backend/tests/Unit/Services/LineWebhook/LineWebhookPipelineFlagTest.php`:
```php
<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Services\LineWebhook\LineWebhookPipelineFlag;
use Tests\TestCase;

class LineWebhookPipelineFlagTest extends TestCase
{
    public function test_returns_false_when_master_flag_off(): void
    {
        config(['line_webhook.pipeline_enabled' => false]);
        config(['line_webhook.pipeline_bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 26;

        $this->assertFalse(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_true_when_master_on_and_whitelist_empty(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => []]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertTrue(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_true_when_bot_in_whitelist(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => ['26', '28']]);

        $bot = new Bot;
        $bot->id = 28;

        $this->assertTrue(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_false_when_bot_not_in_whitelist(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertFalse(LineWebhookPipelineFlag::enabledFor($bot));
    }
}
```

- [ ] **Step 2: Run test to verify fail**

Run: `cd backend && php artisan test --filter=LineWebhookPipelineFlagTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php`:
```php
<?php

namespace App\Services\LineWebhook;

use App\Models\Bot;

class LineWebhookPipelineFlag
{
    public static function enabledFor(Bot $bot): bool
    {
        if (! config('line_webhook.pipeline_enabled', false)) {
            return false;
        }

        $whitelist = config('line_webhook.pipeline_bot_ids', []);
        if (empty($whitelist)) {
            return true;
        }

        return in_array((string) $bot->id, $whitelist, true);
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `cd backend && php artisan test --filter=LineWebhookPipelineFlagTest && vendor/bin/pint --test app/Services/LineWebhook/LineWebhookPipelineFlag.php`
Expected: 4 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookPipelineFlag.php backend/tests/Unit/Services/LineWebhook/LineWebhookPipelineFlagTest.php
git commit -m "feat(line-webhook): add LineWebhookPipelineFlag with whitelist support"
```

---

### Task 3: GateDecision enum

**Files:**
- Create: `backend/app/Services/LineWebhook/GateDecision.php`

- [ ] **Step 1: Implement enum**

`backend/app/Services/LineWebhook/GateDecision.php`:
```php
<?php

namespace App\Services\LineWebhook;

enum GateDecision: string
{
    case ALLOW = 'allow';
    case RATE_LIMITED = 'rate_limited';
    case OUTSIDE_HOURS = 'outside_hours';

    public function isBlocked(): bool
    {
        return $this !== self::ALLOW;
    }
}
```

- [ ] **Step 2: Pint check**

Run: `cd backend && vendor/bin/pint --test app/Services/LineWebhook/GateDecision.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/app/Services/LineWebhook/GateDecision.php
git commit -m "feat(line-webhook): add GateDecision enum"
```

---

### Task 4: ResponseEnvelope DTO

**Files:**
- Create: `backend/app/Services/LineWebhook/ResponseEnvelope.php`
- Create: `backend/tests/Unit/Services/LineWebhook/ResponseEnvelopeTest.php`

- [ ] **Step 1: Write failing tests**

`backend/tests/Unit/Services/LineWebhook/ResponseEnvelopeTest.php`:
```php
<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Services\LineWebhook\ResponseEnvelope;
use Tests\TestCase;

class ResponseEnvelopeTest extends TestCase
{
    public function test_text_envelope(): void
    {
        $env = ResponseEnvelope::text('hello');

        $this->assertSame('text', $env->type);
        $this->assertSame('hello', $env->payload);
    }

    public function test_sticker_envelope_carries_package_and_sticker_ids(): void
    {
        $env = ResponseEnvelope::sticker('11537', '52002734');

        $this->assertSame('sticker', $env->type);
        $this->assertSame(['package_id' => '11537', 'sticker_id' => '52002734'], $env->payload);
    }

    public function test_flex_envelope_holds_array_payload(): void
    {
        $payload = ['type' => 'bubble', 'body' => []];
        $env = ResponseEnvelope::flex($payload);

        $this->assertSame('flex', $env->type);
        $this->assertSame($payload, $env->payload);
    }
}
```

- [ ] **Step 2: Run test, verify fail**

Run: `cd backend && php artisan test --filter=ResponseEnvelopeTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`backend/app/Services/LineWebhook/ResponseEnvelope.php`:
```php
<?php

namespace App\Services\LineWebhook;

class ResponseEnvelope
{
    /** @param  'text'|'sticker'|'flex'  $type */
    public function __construct(
        public readonly string $type,
        public readonly mixed $payload,
    ) {}

    public static function text(string $content): self
    {
        return new self('text', $content);
    }

    public static function sticker(string $packageId, string $stickerId): self
    {
        return new self('sticker', [
            'package_id' => $packageId,
            'sticker_id' => $stickerId,
        ]);
    }

    public static function flex(array $payload): self
    {
        return new self('flex', $payload);
    }
}
```

- [ ] **Step 4: Run test, verify pass**

Run: `cd backend && php artisan test --filter=ResponseEnvelopeTest && vendor/bin/pint --test app/Services/LineWebhook/ResponseEnvelope.php`
Expected: 3 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/LineWebhook/ResponseEnvelope.php backend/tests/Unit/Services/LineWebhook/ResponseEnvelopeTest.php
git commit -m "feat(line-webhook): add ResponseEnvelope DTO with factory methods"
```

---

### Task 5: WebhookContext DTO

**Files:**
- Create: `backend/app/Services/LineWebhook/WebhookContext.php`
- Create: `backend/tests/Unit/Services/LineWebhook/WebhookContextTest.php`

- [ ] **Step 1: Write failing tests**

`backend/tests/Unit/Services/LineWebhook/WebhookContextTest.php`:
```php
<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Services\LineWebhook\WebhookContext;
use Tests\TestCase;

class WebhookContextTest extends TestCase
{
    public function test_constructor_carries_bot_and_event(): void
    {
        $bot = new Bot;
        $bot->id = 26;
        $event = ['type' => 'message', 'source' => ['userId' => 'U123']];

        $ctx = new WebhookContext($bot, $event);

        $this->assertSame(26, $ctx->bot->id);
        $this->assertSame('message', $ctx->event['type']);
        $this->assertNull($ctx->profile);
        $this->assertNull($ctx->conversation);
        $this->assertNull($ctx->userMessage);
        $this->assertNull($ctx->response);
        $this->assertSame([], $ctx->metadata);
    }

    public function test_event_type_helper(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, ['message' => ['type' => 'sticker']]);

        $this->assertSame('sticker', $ctx->messageType());
    }

    public function test_event_type_returns_null_when_missing(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, []);

        $this->assertNull($ctx->messageType());
    }

    public function test_user_id_helper(): void
    {
        $bot = new Bot;
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U_abc']]);

        $this->assertSame('U_abc', $ctx->userId());
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd backend && php artisan test --filter=WebhookContextTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`backend/app/Services/LineWebhook/WebhookContext.php`:
```php
<?php

namespace App\Services\LineWebhook;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;

class WebhookContext
{
    public ?CustomerProfile $profile = null;

    public ?Conversation $conversation = null;

    public ?Message $userMessage = null;

    public ?ResponseEnvelope $response = null;

    /** @var array<string,mixed> */
    public array $metadata = [];

    public bool $aggregationBuffered = false;

    public function __construct(
        public readonly Bot $bot,
        public readonly array $event,
    ) {}

    public function messageType(): ?string
    {
        return $this->event['message']['type'] ?? null;
    }

    public function userId(): ?string
    {
        return $this->event['source']['userId'] ?? null;
    }
}
```

- [ ] **Step 4: Run, verify pass**

Run: `cd backend && php artisan test --filter=WebhookContextTest && vendor/bin/pint --test app/Services/LineWebhook/WebhookContext.php`
Expected: 4 tests pass, Pint clean.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/LineWebhook/WebhookContext.php backend/tests/Unit/Services/LineWebhook/WebhookContextTest.php
git commit -m "feat(line-webhook): add WebhookContext DTO carrier"
```

---

## Phase 2 — Stage services

### Task 6: LineWebhookGatingService

**Files:**
- Create: `backend/app/Services/LineWebhook/LineWebhookGatingService.php`
- Create: `backend/tests/Unit/Services/LineWebhook/LineWebhookGatingServiceTest.php`
- Reference: `backend/app/Jobs/ProcessLINEWebhook.php:1317` (handleRateLimitExceeded) and `backend/app/Jobs/ProcessLINEWebhook.php:1349` (handleOutsideResponseHours) for behavior parity.

**Behavior contract (must mirror legacy byte-for-byte):**
- Check `RateLimitService::shouldRateLimit($bot, $userId)` first
- If rate limited: dispatch fallback message via `LINEService::pushText()` with the same rate-limit message the legacy code uses, log warning, return `GateDecision::RATE_LIMITED`
- Then check `ResponseHoursService::isWithinResponseHours($bot)` 
- If outside hours: dispatch the bot's configured `offline_message` (from `bot_settings.offline_message`), return `GateDecision::OUTSIDE_HOURS`
- Otherwise return `GateDecision::ALLOW`

- [ ] **Step 1: Write failing test scaffolding (4 tests)**

`backend/tests/Unit/Services/LineWebhook/LineWebhookGatingServiceTest.php`:
```php
<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Services\LineWebhook\GateDecision;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\LINEService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use Mockery;
use Tests\TestCase;

class LineWebhookGatingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(int $id = 26, ?string $offlineMessage = 'we are closed'): Bot
    {
        $bot = Bot::factory()->create(['id' => $id, 'channel_access_token' => 'tok']);
        BotSetting::factory()->create([
            'bot_id' => $bot->id,
            'offline_message' => $offlineMessage,
        ]);

        return $bot->fresh();
    }

    public function test_allow_when_neither_blocked(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('shouldRateLimit')->andReturn(false);

        $hours = Mockery::mock(ResponseHoursService::class);
        $hours->shouldReceive('isWithinResponseHours')->andReturn(true);

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('pushText');

        $svc = new LineWebhookGatingService($rateLimit, $hours, $line);
        $bot = $this->makeBot();
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U1']]);

        $this->assertSame(GateDecision::ALLOW, $svc->check($ctx));
    }

    public function test_rate_limited_dispatches_fallback_and_returns_rate_limited(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('shouldRateLimit')->once()->andReturn(true);

        $hours = Mockery::mock(ResponseHoursService::class);
        $hours->shouldNotReceive('isWithinResponseHours');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('pushText')->once();

        $svc = new LineWebhookGatingService($rateLimit, $hours, $line);
        $bot = $this->makeBot();
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U1']]);

        $this->assertSame(GateDecision::RATE_LIMITED, $svc->check($ctx));
    }

    public function test_outside_hours_pushes_offline_message(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('shouldRateLimit')->andReturn(false);

        $hours = Mockery::mock(ResponseHoursService::class);
        $hours->shouldReceive('isWithinResponseHours')->andReturn(false);

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('pushText')
            ->once()
            ->withArgs(fn ($bot, $userId, $text) => $text === 'we are closed');

        $svc = new LineWebhookGatingService($rateLimit, $hours, $line);
        $bot = $this->makeBot(offlineMessage: 'we are closed');
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U1']]);

        $this->assertSame(GateDecision::OUTSIDE_HOURS, $svc->check($ctx));
    }

    public function test_outside_hours_skips_push_when_no_offline_message(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('shouldRateLimit')->andReturn(false);

        $hours = Mockery::mock(ResponseHoursService::class);
        $hours->shouldReceive('isWithinResponseHours')->andReturn(false);

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('pushText');

        $svc = new LineWebhookGatingService($rateLimit, $hours, $line);
        $bot = $this->makeBot(offlineMessage: null);
        $ctx = new WebhookContext($bot, ['source' => ['userId' => 'U1']]);

        $this->assertSame(GateDecision::OUTSIDE_HOURS, $svc->check($ctx));
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd backend && php artisan test --filter=LineWebhookGatingServiceTest`
Expected: 4 FAIL — class not found.

- [ ] **Step 3: Implement service**

`backend/app/Services/LineWebhook/LineWebhookGatingService.php`:
```php
<?php

namespace App\Services\LineWebhook;

use App\Services\LINEService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use Illuminate\Support\Facades\Log;

class LineWebhookGatingService
{
    public function __construct(
        private readonly RateLimitService $rateLimit,
        private readonly ResponseHoursService $responseHours,
        private readonly LINEService $line,
    ) {}

    public function check(WebhookContext $ctx): GateDecision
    {
        $userId = $ctx->userId();

        if ($userId && $this->rateLimit->shouldRateLimit($ctx->bot, $userId)) {
            Log::info('LINE webhook rate limited', [
                'bot_id' => $ctx->bot->id,
                'user_id' => $userId,
            ]);
            $this->line->pushText($ctx->bot, $userId, $this->rateLimitMessage());

            return GateDecision::RATE_LIMITED;
        }

        if (! $this->responseHours->isWithinResponseHours($ctx->bot)) {
            $offline = $ctx->bot->settings?->offline_message;
            if ($offline && $userId) {
                $this->line->pushText($ctx->bot, $userId, $offline);
            }

            return GateDecision::OUTSIDE_HOURS;
        }

        return GateDecision::ALLOW;
    }

    private function rateLimitMessage(): string
    {
        return 'ขออภัยครับ ระบบกำลังประมวลผลข้อความก่อนหน้า กรุณารอสักครู่';
    }
}
```

- [ ] **Step 4: Run, verify pass**

Run: `cd backend && php artisan test --filter=LineWebhookGatingServiceTest && vendor/bin/pint --test app/Services/LineWebhook/LineWebhookGatingService.php`
Expected: 4 PASS, Pint clean.

- [ ] **Step 5: Cross-check rate limit message against legacy**

Open `backend/app/Jobs/ProcessLINEWebhook.php:1317-1348` and compare the string passed to `LINEService::pushText` inside `handleRateLimitExceeded`. If it differs from `rateLimitMessage()` above, update `rateLimitMessage()` to match exactly. Re-run the test suite.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookGatingService.php backend/tests/Unit/Services/LineWebhook/LineWebhookGatingServiceTest.php
git commit -m "feat(line-webhook): add LineWebhookGatingService (Stage 1)"
```

---

### Task 7: LineWebhookContextService

**Files:**
- Create: `backend/app/Services/LineWebhook/LineWebhookContextService.php`
- Create: `backend/tests/Unit/Services/LineWebhook/LineWebhookContextServiceTest.php`
- Reference: `backend/app/Jobs/ProcessLINEWebhook.php:609` (createNewConversation), `:1384` (findOrCreateCustomerProfile), `:646-692` (stats), and the aggregation block inside `processEvent` (search `aggregationService->shouldAggregate` in `processEvent`).

**Behavior contract:**
- `resolve(WebhookContext)` must:
  1. Find or create `CustomerProfile` for `ctx.userId()` using `ProfilePictureService` (port from `findOrCreateCustomerProfile`)
  2. Find or create active `Conversation` for `(bot_id, external_customer_id=userId, channel_type='line')` with `FOR UPDATE` lock (port from `createNewConversation`)
  3. Persist user `Message` row with `sender='user'`, `type` from event, `content`, `external_message_id`, `webhook_event_id` (port from existing insert block in `processEvent`)
  4. Call `MessageAggregationService::shouldAggregate` (and `SmartAggregationAnalyzer::analyze` when smart_aggregation_enabled). If buffered, set `$ctx->aggregationBuffered = true`
  5. Populate `$ctx->profile`, `$ctx->conversation`, `$ctx->userMessage`
- Throws on DB errors (Laravel queue retry handles it)

- [ ] **Step 1: Inventory legacy behavior**

Read these slices and write a one-page contract in `docs/superpowers/notes/2026-05-16-context-service-contract.md` describing the order of DB operations, the exact column values set, and the aggregation decision branches. Commit the note.

Files to read:
- `backend/app/Jobs/ProcessLINEWebhook.php:609-645` (createNewConversation)
- `backend/app/Jobs/ProcessLINEWebhook.php:646-664` (updateStatsForUserMessageOnly)
- `backend/app/Jobs/ProcessLINEWebhook.php:665-692` (updateStatsInBatch)
- `backend/app/Jobs/ProcessLINEWebhook.php:1384-1424` (findOrCreateCustomerProfile)
- The aggregation block inside `processEvent` (grep for `aggregationService->shouldAggregate`)

- [ ] **Step 2: Write failing tests (5 tests minimum)**

`backend/tests/Unit/Services/LineWebhook/LineWebhookContextServiceTest.php` should cover:
1. New customer, new conversation, message saved, aggregation not buffered → all four ctx fields populated
2. Existing customer, existing conversation, message saved → no duplicate customer row
3. Aggregation buffered → ctx.aggregationBuffered = true, no further stage should run (caller checks this)
4. Webhook idempotency: same `external_message_id` inserted twice → second call no-ops (unique index throws → caller swallows or pre-checks)
5. ProfilePictureService throws (LINE 404 on profile lookup) → profile created with null avatar, conversation still resolves

Each test mocks `ProfilePictureService`, `MessageAggregationService`, and `SmartAggregationAnalyzer`. Use `RefreshDatabase` trait.

Write the actual test code mirroring the structure of `LineWebhookGatingServiceTest`. Stub mocks against the contract from Step 1.

- [ ] **Step 3: Run, verify fail**

Run: `cd backend && php artisan test --filter=LineWebhookContextServiceTest`
Expected: 5 FAIL — class not found.

- [ ] **Step 4: Implement service**

`backend/app/Services/LineWebhook/LineWebhookContextService.php`:

Port logic from referenced legacy slices. Constructor injects: `ProfilePictureService`, `MessageAggregationService`, `SmartAggregationAnalyzer`, `UserTypingStats`. Public method `resolve(WebhookContext $ctx): void`. Private helpers: `findOrCreateProfile`, `findOrCreateConversation`, `saveUserMessage`, `decideAggregation`.

Keep imports surgical — only services this stage genuinely uses.

- [ ] **Step 5: Run, verify pass**

Run: `cd backend && php artisan test --filter=LineWebhookContextServiceTest && vendor/bin/pint --test app/Services/LineWebhook/LineWebhookContextService.php`
Expected: 5 PASS, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookContextService.php backend/tests/Unit/Services/LineWebhook/LineWebhookContextServiceTest.php docs/superpowers/notes/2026-05-16-context-service-contract.md
git commit -m "feat(line-webhook): add LineWebhookContextService (Stage 2)"
```

---

### Task 8: LineWebhookResponseService

**Files:**
- Create: `backend/app/Services/LineWebhook/LineWebhookResponseService.php`
- Create: `backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php`
- Reference: `backend/app/Jobs/ProcessLINEWebhook.php:151-608` (text branch inside `processEvent`), `:693-893` (handleNonTextMessage), `:894-980` (handleStickerReply), `:981-1167` (handleImageAnalysis), `:1168-1316` (vision helpers).

**Behavior contract:**
- `generate(WebhookContext $ctx): void` branches on `$ctx->messageType()`:
  - `'text'`: build conversation history (use `ConversationContextService`), call `AIService::generateBotResponse` (or `RAGService::generateResponse` if that's what legacy uses — verify by reading lines 151-608), wrap result in `ResponseEnvelope::text(...)`
  - `'sticker'`: delegate to `StickerReplyService::reply($bot, $event)` — returns `ResponseEnvelope` (either text bonus reply or sticker reply)
  - `'image'`: run vision pipeline — port `handleImageAnalysis` lines 981-1167 plus helpers. Returns `ResponseEnvelope::text(...)` with vision analysis result
- `OpenRouterException`: catch internally, log warning, set `$ctx->response = ResponseEnvelope::text($aiService->getErrorMessage($e))` — use the EXISTING `AIService::getErrorMessage` so the fallback string is identical
- Vision helpers `getVisionModel`, `buildVisionSystemPrompt`, `getImageAnalysisPrompt`, `detectPendingOrder`, `getVisionConversationHistory` move from Job to private methods here

- [ ] **Step 1: Inventory branches and helpers**

Read `backend/app/Jobs/ProcessLINEWebhook.php:151-1316` and document in `docs/superpowers/notes/2026-05-16-response-service-contract.md`:
- The exact call signature of the text path's LLM call (which service, which params)
- The branches inside `handleNonTextMessage` (lines 693-893) that route to sticker vs image
- The decision logic in `handleImageAnalysis` (when to use vision vs fallback)
- Where `detectPendingOrder` is consulted

Commit the note.

- [ ] **Step 2: Write failing tests (6+ tests)**

`backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php` covering:
1. Text event → AIService called, ctx.response is `text` envelope with returned content
2. Sticker event → StickerReplyService called, ctx.response carries its return
3. Image event with pending order detected → vision pipeline runs, vision system prompt used
4. Image event without pending order → falls back to standard image-acknowledge message
5. `OpenRouterException` during text generation → ctx.response carries `AIService::getErrorMessage($e)` string
6. Unknown message type → ctx.response stays null and a warning is logged

Mock `AIService`, `OpenRouterService`, `StickerReplyService`, `ConversationContextService`, `ModelCapabilityService`. The 6 vision helpers can be tested via image-event paths or extracted into separate test methods.

- [ ] **Step 3: Run, verify fail**

Run: `cd backend && php artisan test --filter=LineWebhookResponseServiceTest`
Expected: all FAIL — class not found.

- [ ] **Step 4: Implement service**

`backend/app/Services/LineWebhook/LineWebhookResponseService.php`:

Constructor injects: `AIService`, `OpenRouterService`, `StickerReplyService`, `ConversationContextService`, `ModelCapabilityService`. Public method `generate(WebhookContext $ctx): void`. Private methods mirror legacy helpers (`getVisionModel`, `buildVisionSystemPrompt`, etc).

For the text branch: copy the LLM call code from `backend/app/Jobs/ProcessLINEWebhook.php` text path verbatim (lines 151-608) — change ONLY: data plumbing (read from ctx instead of method params), and writes (set `$ctx->response` instead of pushing to LINE directly). DO NOT alter retry/timeout/model selection logic.

Same surgery for image and sticker branches.

- [ ] **Step 5: Run, verify pass**

Run: `cd backend && php artisan test --filter=LineWebhookResponseServiceTest && vendor/bin/pint --test app/Services/LineWebhook/LineWebhookResponseService.php`
Expected: PASS, Pint clean.

- [ ] **Step 6: Diff vision constants**

Confirm `ProcessLINEWebhook::ORDER_CONTEXT_KEYWORDS` constant (line 46) is mirrored into the new service (or referenced via `ProcessLINEWebhook::ORDER_CONTEXT_KEYWORDS` — pick whichever causes less churn; if mirroring, copy the array verbatim).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php docs/superpowers/notes/2026-05-16-response-service-contract.md
git commit -m "feat(line-webhook): add LineWebhookResponseService (Stage 3)"
```

---

### Task 9: LineWebhookOutputService

**Files:**
- Create: `backend/app/Services/LineWebhook/LineWebhookOutputService.php`
- Create: `backend/tests/Unit/Services/LineWebhook/LineWebhookOutputServiceTest.php`
- Reference: `backend/app/Jobs/ProcessLINEWebhook.php:151-608` (the section after AI response that pushes to LINE and saves bot Message), `:665-692` (updateStatsInBatch), `:646-664` (updateStatsForUserMessageOnly).

**Behavior contract:**
- `dispatch(WebhookContext $ctx): void` assumes `$ctx->response` is set
- Dispatch by envelope type:
  - `text`: `MultipleBubblesService::split` (when enabled on bot) then `LINEService::pushText` per bubble; OR single `pushText` when disabled
  - `sticker`: `LINEService::pushSticker($bot, $userId, $packageId, $stickerId)`
  - `flex`: `LINEService::pushFlex($bot, $userId, $payload)`
- After successful push, save bot `Message` row mirroring legacy: `sender='bot'`, `content` = response text (for sticker/flex, store JSON representation matching legacy)
- Call `updateStatsInBatch($conversation, $isNewConversation)` (or `updateStatsForUserMessageOnly` based on legacy branch logic — read code to confirm which case fires when)
- Dispatch `MessageSent` + `ConversationUpdated` events with the same payload shape legacy uses
- Call `AutoAssignmentService::tryAssign` and `LeadRecoveryService::analyze` if and only if legacy calls them at this point (re-read the post-push section in lines 500-608)
- If LINE push throws → bubble the exception (do NOT save bot Message; mirror legacy ordering)

- [ ] **Step 1: Inventory legacy post-push logic**

Document in `docs/superpowers/notes/2026-05-16-output-service-contract.md` the exact sequence after the LLM call returns in `processEvent`, including:
- Order of: push → save bot Message → stats update → event broadcast → auto-assign → lead recovery
- Which conditions skip each step
- Exact event payload shapes

Commit the note.

- [ ] **Step 2: Write failing tests (5+ tests)**

`backend/tests/Unit/Services/LineWebhook/LineWebhookOutputServiceTest.php` covering:
1. text envelope, multiple bubbles disabled → 1 `pushText` call + 1 Message row
2. text envelope, multiple bubbles enabled with 3 bubbles → 3 `pushText` calls (delays as configured) + 1 Message row containing the joined content
3. sticker envelope → `pushSticker` called with correct ids + Message row with `type='sticker'`
4. push throws → exception bubbles, no Message row created
5. New conversation → `updateStatsInBatch` called with `isNewConversation=true`; events broadcast with new flag

Mock `LINEService`, `MultipleBubblesService`, `AutoAssignmentService`, `LeadRecoveryService`. Use `RefreshDatabase` for Message inserts.

- [ ] **Step 3: Run, verify fail**

Run: `cd backend && php artisan test --filter=LineWebhookOutputServiceTest`
Expected: FAIL.

- [ ] **Step 4: Implement**

`backend/app/Services/LineWebhook/LineWebhookOutputService.php`:

Constructor injects: `LINEService`, `MultipleBubblesService`, `AutoAssignmentService`, `LeadRecoveryService`. Public method `dispatch(WebhookContext $ctx): void`. Private helpers: `pushByEnvelope`, `saveBotMessage`, `broadcastEvents`, `postProcess`.

Port lines from `processEvent`'s post-LLM section. Surgical edits only — read from ctx, write side effects exactly as legacy.

- [ ] **Step 5: Run, verify pass**

Run: `cd backend && php artisan test --filter=LineWebhookOutputServiceTest && vendor/bin/pint --test app/Services/LineWebhook/LineWebhookOutputService.php`
Expected: PASS, Pint clean.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookOutputService.php backend/tests/Unit/Services/LineWebhook/LineWebhookOutputServiceTest.php docs/superpowers/notes/2026-05-16-output-service-contract.md
git commit -m "feat(line-webhook): add LineWebhookOutputService (Stage 4)"
```

---

## Phase 3 — Integration

### Task 10: Wire pipeline into ProcessLINEWebhook job

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php:79` (handle method)
- Create: `backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php`

- [ ] **Step 1: Write failing end-to-end test**

`backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php`:

Use `RefreshDatabase`. Two test methods minimum:

```php
public function test_legacy_path_runs_when_flag_off(): void
{
    config(['line_webhook.pipeline_enabled' => false]);
    // ... arrange Bot, BotSetting, faked LINE event ...
    // Mock so processEvent is invoked (assert by spying on a known legacy-only call)
    // OR: assert that the new stage services were NOT resolved from the container.
}

public function test_pipeline_path_runs_when_flag_on_for_whitelisted_bot(): void
{
    config(['line_webhook.pipeline_enabled' => true]);
    config(['line_webhook.pipeline_bot_ids' => ['26']]);
    // Arrange a text-message event for bot 26
    // Mock LineWebhookGatingService::check to return ALLOW
    // Mock the other three stages and assert each is invoked once
    // Assert the legacy processEvent is NOT called
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookPipelineTest`
Expected: FAIL.

- [ ] **Step 3: Modify `handle()` to branch on flag**

In `backend/app/Jobs/ProcessLINEWebhook.php:79`, after the existing constructor and before `processEvent`, inject the four pipeline services via the same `handle` signature (Laravel queues resolve method params from container). Add a branch:

```php
public function handle(
    LINEService $lineService,
    AIService $aiService,
    RateLimitService $rateLimitService,
    MessageAggregationService $aggregationService,
    ResponseHoursService $responseHoursService,
    CircuitBreakerService $circuitBreaker,
    LineWebhookGatingService $gating,
    LineWebhookContextService $contextSvc,
    LineWebhookResponseService $responseSvc,
    LineWebhookOutputService $outputSvc,
): void {
    try {
        $circuitBreaker->execute(
            'database',
            function () use ($lineService, $aiService, $rateLimitService, $aggregationService, $responseHoursService, $gating, $contextSvc, $responseSvc, $outputSvc) {
                if (LineWebhookPipelineFlag::enabledFor($this->bot)) {
                    $this->runPipeline($gating, $contextSvc, $responseSvc, $outputSvc);

                    return;
                }
                $this->processEvent($lineService, $aiService, $rateLimitService, $aggregationService, $responseHoursService);
            },
            fn () => $this->sendFallbackMessage($lineService)
        );
    } catch (CircuitOpenException $e) {
        Log::warning('Circuit breaker open for LINE webhook', [
            'bot_id' => $this->bot->id,
            'service' => $e->getService(),
        ]);
        $this->sendFallbackMessage($lineService);
    } catch (\Exception $e) {
        Log::error('LINE webhook processing failed', [
            'bot_id' => $this->bot->id,
            'event_type' => $this->event['type'] ?? 'unknown',
            'error' => $e->getMessage(),
            ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
        ]);

        throw $e;
    }
}

private function runPipeline(
    LineWebhookGatingService $gating,
    LineWebhookContextService $contextSvc,
    LineWebhookResponseService $responseSvc,
    LineWebhookOutputService $outputSvc,
): void {
    $ctx = new WebhookContext($this->bot, $this->event);

    Log::debug('LINE webhook pipeline.start', [
        'bot_id' => $this->bot->id,
        'event_type' => $ctx->messageType(),
    ]);

    if ($gating->check($ctx)->isBlocked()) {
        return;
    }

    $contextSvc->resolve($ctx);
    if ($ctx->aggregationBuffered) {
        return;
    }

    $responseSvc->generate($ctx);
    if ($ctx->response === null) {
        return;
    }

    $outputSvc->dispatch($ctx);
}
```

Add imports at top:
```php
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookPipelineFlag;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
```

- [ ] **Step 4: Run new test + ensure legacy tests still pass**

Run: `cd backend && php artisan test --filter=LINE` (covers all LINE-related test files)
Expected: All PASS (including legacy `ProcessLINEWebhookOfflineTest` and `LINEWebhookTest`).

- [ ] **Step 5: Pint pass**

Run: `cd backend && vendor/bin/pint --test app/Jobs/ProcessLINEWebhook.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Jobs/ProcessLINEWebhook.php backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php
git commit -m "feat(line-webhook): wire pipeline into ProcessLINEWebhook with feature flag"
```

---

### Task 11: PR + smoke test prep

- [ ] **Step 1: Verify full test suite**

Run: `cd backend && php artisan test`
Expected: All PASS. Note the count.

- [ ] **Step 2: Push branch + open PR**

```bash
git push -u origin feat/line-webhook-pipeline
gh pr create --title "feat: ProcessLINEWebhook pipeline refactor (#9, dark-launch)" --body "$(cat <<'EOF'
## Summary
- Split 1432-LOC ProcessLINEWebhook job into 4 pipeline stage services
- Behavior identical to legacy; new path gated behind PROCESS_LINE_PIPELINE_ENABLED
- Default OFF: zero traffic impact at merge time
- Spec: docs/superpowers/specs/2026-05-16-process-line-webhook-refactor-design.md

## Test plan
- [ ] CI green (Backend Tests + Pint)
- [ ] Manual: set PROCESS_LINE_PIPELINE_BOT_IDS=26 on Railway, send LINE text/sticker/image messages, verify identical bot replies
- [ ] 24h Sentry watch: error rate on bot 26 ≤ bot 28

## Rollback
railway variables --set PROCESS_LINE_PIPELINE_ENABLED=false

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: After CI green, request user review before merge**

Pause for human approval. Do not auto-merge — this is core webhook code.

---

## Phase 4 — Rollout (operational, outside this plan)

Tracked in spec rollout table. After merge:
1. Set `PROCESS_LINE_PIPELINE_BOT_IDS=26` on Railway → smoke test
2. 24h Sentry watch
3. Add bot 28 → full traffic
4. Cleanup PR removes legacy `processEvent` + helpers + flag

## Out of scope (do not do in this plan)

- Latency optimizations
- New retry/timeout/circuit-breaker logic
- Refactoring downstream services (`AIService`, `LINEService`, etc.)
- Removing legacy code (deferred to cleanup PR after rollout)
