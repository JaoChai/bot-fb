# Off-topic Guardrail Enforcement Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a deterministic (non-LLM) enforcement layer on top of the existing prompt-based off-topic guardrail — a repeat-offender circuit breaker and an output-side safety net — so off-topic abuse costs zero LLM calls after 3 triggers and a worst-case guardrail failure never reaches the customer.

**Architecture:** All three mechanisms integrate at a single choke point — `AIService::generateResponse()` — which every channel (LINE aggregation path, LINE/Telegram/Facebook synchronous webhook path via `generateAndSaveResponse()`) already calls internally. No other file needs to change. Follows the exact pattern the codebase already uses for `[[ORDER]]` block extraction (`OrderPayloadExtractor`).

**Tech Stack:** Laravel 12 / PHP, PHPUnit (`#[Test]` attributes), `Illuminate\Support\Facades\Cache` (Redis with automatic database fallback via existing `RedisFallbackSwitch` — no new resilience code needed).

## Global Constraints

- Circuit breaker threshold: **3** off-topic triggers per conversation, reset window **24 hours (86400 seconds)** — exact values from the approved design spec, do not change.
- After tripping, respond with the fixed canned message on **every** subsequent off-topic attempt in that window (not silence, not a single notice).
- No owner/Telegram/LINE notification in this version — log only (`Log::warning`).
- Output sanitizer checks **only**: code fences, real markdown (bold `**`, heading `#`), and "I am an AI" phrases (Thai + English). Do **not** add an English-paragraph check — rejected in design due to false-positive risk with legitimate business terms (BM, CAPI, Pixel).
- Zero new LLM calls anywhere in this feature — every check is pure PHP/regex.
- Applies to **all bots** automatically (single integration point) — no per-bot toggle, no new dashboard UI.
- **Deployment order matters:** the code (Tasks 1–5) must be deployed to production *before* Task 6 adds the `[[OFFTOPIC]]` marker to the live prompt. If the prompt starts emitting the marker before the code strips it, customers will see raw `[[OFFTOPIC]]` text.
- Design spec: `docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md` — read it if anything here is ambiguous.

---

### Task 1: Off-topic signal marker extractor

**Files:**
- Create: `backend/app/Services/Guardrail/OffTopicSignalExtractor.php`
- Test: `backend/tests/Unit/Services/Guardrail/OffTopicSignalExtractorTest.php`

**Interfaces:**
- Consumes: nothing (pure string function)
- Produces: `OffTopicSignalExtractor::extract(string $content): array{clean: string, triggered: bool}` — used by Task 4

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Guardrail;

use App\Services\Guardrail\OffTopicSignalExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OffTopicSignalExtractorTest extends TestCase
{
    private OffTopicSignalExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new OffTopicSignalExtractor;
    }

    #[Test]
    public function test_strips_marker_and_flags_triggered(): void
    {
        $content = "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]";

        $result = $this->extractor->extract($content);

        $this->assertSame('ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?', $result['clean']);
        $this->assertTrue($result['triggered']);
    }

    #[Test]
    public function test_no_marker_returns_original_content_untouched(): void
    {
        $content = 'BM ราคา 1,100 บาทครับ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertFalse($result['triggered']);
    }

    #[Test]
    public function test_marker_must_be_at_end_not_matched_mid_sentence(): void
    {
        // กันเคส LLM หลอนพิมพ์คำว่า OFFTOPIC ปนอยู่กลางประโยคโดยไม่ได้ตั้งใจ
        $content = 'สินค้ารหัส [[OFFTOPIC]] ไม่มีอยู่จริงครับ ต่อด้วยคำตอบปกติ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertFalse($result['triggered']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/OffTopicSignalExtractorTest.php`
Expected: FAIL — `Class "App\Services\Guardrail\OffTopicSignalExtractor" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Guardrail;

/**
 * ดึง marker [[OFFTOPIC]] ที่ off-topic guardrail script แนบท้ายคำตอบ (prompt flow 24)
 * ตัดออกก่อนถึงลูกค้าเสมอ — เหมือน OrderPayloadExtractor แต่ไม่มี payload มีแค่ true/false
 */
class OffTopicSignalExtractor
{
    private const PATTERN = '/\s*\[\[OFFTOPIC\]\]\s*$/u';

    /**
     * @return array{clean: string, triggered: bool}
     */
    public function extract(string $content): array
    {
        if (! preg_match(self::PATTERN, $content)) {
            return ['clean' => $content, 'triggered' => false];
        }

        $clean = trim((string) preg_replace(self::PATTERN, '', $content));

        return ['clean' => $clean, 'triggered' => true];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/OffTopicSignalExtractorTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Guardrail/OffTopicSignalExtractor.php backend/tests/Unit/Services/Guardrail/OffTopicSignalExtractorTest.php
git commit -m "feat(guardrail): add OffTopicSignalExtractor for [[OFFTOPIC]] marker parsing"
```

---

### Task 2: Repeat-offender circuit breaker

**Files:**
- Create: `backend/app/Services/Guardrail/OffTopicCircuitBreaker.php`
- Test: `backend/tests/Unit/Services/Guardrail/OffTopicCircuitBreakerTest.php`

**Interfaces:**
- Consumes: `App\Models\Bot`, `App\Models\Conversation` (existing Eloquent models, `id` property)
- Produces: `OffTopicCircuitBreaker::isTripped(Bot, Conversation): bool`, `::recordTrigger(Bot, Conversation): void`, `::cacheKey(int, int): string` (static), `::CANNED_MESSAGE` (public const string), `::THRESHOLD` (public const int) — used by Task 4

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Guardrail;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OffTopicCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private OffTopicCircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->breaker = new OffTopicCircuitBreaker;
    }

    #[Test]
    public function test_not_tripped_below_threshold(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);

        $this->assertFalse($this->breaker->isTripped($bot, $conversation));
    }

    #[Test]
    public function test_tripped_at_threshold(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);

        $this->assertTrue($this->breaker->isTripped($bot, $conversation));
    }

    #[Test]
    public function test_counters_are_isolated_per_conversation(): void
    {
        [$bot, $conversationA] = $this->makeBotWithConversation();
        $conversationB = Conversation::factory()->create(['bot_id' => $bot->id]);

        $this->breaker->recordTrigger($bot, $conversationA);
        $this->breaker->recordTrigger($bot, $conversationA);
        $this->breaker->recordTrigger($bot, $conversationA);

        $this->assertTrue($this->breaker->isTripped($bot, $conversationA));
        $this->assertFalse($this->breaker->isTripped($bot, $conversationB));
    }

    #[Test]
    public function test_cache_key_format(): void
    {
        $this->assertSame('off_topic_count:26:100', OffTopicCircuitBreaker::cacheKey(26, 100));
    }

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/OffTopicCircuitBreakerTest.php`
Expected: FAIL — `Class "App\Services\Guardrail\OffTopicCircuitBreaker" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Guardrail;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * ตัดวงจรเมื่อลูกค้าคนเดียวโดน off-topic guardrail ซ้ำเกิน threshold ในบทสนทนาเดียว
 * ภายใน 24 ชม. — กัน token cost จากการใช้บอทฟรีซ้ำๆ (ถามแปลภาษา/เขียนโค้ด ฯลฯ)
 * เกินแล้วข้ามการเรียก LLM ไปเลย ตอบข้อความสำเร็จรูปแทนทุกครั้ง
 * (ดู docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md)
 */
class OffTopicCircuitBreaker
{
    public const THRESHOLD = 3;

    private const TTL_SECONDS = 86400;

    public const CANNED_MESSAGE = 'ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?';

    public function isTripped(Bot $bot, Conversation $conversation): bool
    {
        $count = (int) Cache::get(self::cacheKey($bot->id, $conversation->id), 0);

        return $count >= self::THRESHOLD;
    }

    public function recordTrigger(Bot $bot, Conversation $conversation): void
    {
        $key = self::cacheKey($bot->id, $conversation->id);

        if (! Cache::has($key)) {
            Cache::put($key, 1, self::TTL_SECONDS);

            return;
        }

        Cache::increment($key);
    }

    public static function cacheKey(int $botId, int $conversationId): string
    {
        return "off_topic_count:{$botId}:{$conversationId}";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/OffTopicCircuitBreakerTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Guardrail/OffTopicCircuitBreaker.php backend/tests/Unit/Services/Guardrail/OffTopicCircuitBreakerTest.php
git commit -m "feat(guardrail): add OffTopicCircuitBreaker (3 triggers / 24h, cache-based)"
```

---

### Task 3: Output sanitizer (worst-case safety net)

**Files:**
- Create: `backend/app/Services/Guardrail/GuardrailOutputSanitizer.php`
- Test: `backend/tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php`

**Interfaces:**
- Consumes: nothing (pure string function)
- Produces: `GuardrailOutputSanitizer::check(string $content): array{flagged: bool, reason: ?string}` — used by Task 4

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Guardrail;

use App\Services\Guardrail\GuardrailOutputSanitizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuardrailOutputSanitizerTest extends TestCase
{
    private GuardrailOutputSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new GuardrailOutputSanitizer;
    }

    #[Test]
    public function test_flags_code_fence(): void
    {
        $result = $this->sanitizer->check("นี่คือโค้ดครับ\n```python\nprint('hi')\n```");

        $this->assertTrue($result['flagged']);
        $this->assertSame('code_fence', $result['reason']);
    }

    #[Test]
    public function test_flags_markdown_bold(): void
    {
        $result = $this->sanitizer->check('สินค้า **ยอดนิยม** ตัวนี้ครับ');

        $this->assertTrue($result['flagged']);
        $this->assertSame('markdown_bold', $result['reason']);
    }

    #[Test]
    public function test_flags_markdown_heading(): void
    {
        $result = $this->sanitizer->check("# หัวข้อ\nเนื้อหาต่อจากนี้");

        $this->assertTrue($result['flagged']);
        $this->assertSame('markdown_heading', $result['reason']);
    }

    #[Test]
    public function test_flags_ai_admission_thai(): void
    {
        $result = $this->sanitizer->check('ในฐานะ AI ผมไม่สามารถช่วยเรื่องนี้ได้ครับ');

        $this->assertTrue($result['flagged']);
        $this->assertSame('ai_admission_th', $result['reason']);
    }

    #[Test]
    public function test_flags_ai_admission_english(): void
    {
        $result = $this->sanitizer->check('As an AI, I cannot help with that.');

        $this->assertTrue($result['flagged']);
        $this->assertSame('ai_admission_en', $result['reason']);
    }

    #[Test]
    public function test_does_not_flag_normal_thai_sales_response(): void
    {
        $result = $this->sanitizer->check('BM ราคา 1,100 บาท/ตัวครับ ใช้ยิงแอด ติดพิกเซล และยิง Conversion API (CAPI) ได้ครับ');

        $this->assertFalse($result['flagged']);
        $this->assertNull($result['reason']);
    }

    #[Test]
    public function test_does_not_flag_english_business_terms(): void
    {
        // กัน false positive กับศัพท์ธุรกิจจริงที่เป็นภาษาอังกฤษปนอยู่ในบทสนทนาขายจริง
        // (ตัดสินใจแล้วว่าจะไม่เช็คภาษาอังกฤษล้วน — ดู design spec)
        $result = $this->sanitizer->check('Personal กับ BM ต่างกันแค่ Conversion API (CAPI) ครับ Pixel ติดได้ทั้งคู่');

        $this->assertFalse($result['flagged']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php`
Expected: FAIL — `Class "App\Services\Guardrail\GuardrailOutputSanitizer" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services\Guardrail;

/**
 * ตาข่ายสุดท้ายก่อนคำตอบถึงลูกค้า — เช็คด้วย regex ล้วน (ไม่มี LLM call เพิ่ม) ว่ามี
 * code block/markdown จริง หรือบอทพูดยอมรับว่าเป็น AI ไหม กันเคส guardrail ชั้น prompt
 * พลาดจริงแล้วลูกค้าโดนแคปแชร์ (ดู docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md)
 *
 * ตั้งใจไม่เช็คภาษาอังกฤษล้วน — เสี่ยง false positive กับศัพท์ธุรกิจจริง (BM/CAPI/Pixel)
 */
class GuardrailOutputSanitizer
{
    /** @var array<string, string> reason => regex pattern, เช็คตามลำดับ คืนตัวแรกที่ match */
    private const PATTERNS = [
        'code_fence' => '/```/',
        'markdown_bold' => '/\*\*[^*]+\*\*/',
        'markdown_heading' => '/(?:^|\n)#{1,6}\s/',
        'ai_admission_th' => '/ในฐานะ\s*(?:ที่เป็น\s*)?AI|ผมเป็น\s*(?:ระบบ\s*)?AI|ฉันเป็น\s*AI/u',
        'ai_admission_en' => '/\bas an AI\b|\bI(?:\'m| am) an AI\b|\bI cannot\b|\bI can\'t\b/i',
    ];

    /**
     * @return array{flagged: bool, reason: ?string}
     */
    public function check(string $content): array
    {
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return ['flagged' => true, 'reason' => $reason];
            }
        }

        return ['flagged' => false, 'reason' => null];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Guardrail/GuardrailOutputSanitizer.php backend/tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php
git commit -m "feat(guardrail): add GuardrailOutputSanitizer (code fence/markdown/AI-admission regex)"
```

---

### Task 4: Wire all three into `AIService::generateResponse()`

**Files:**
- Modify: `backend/app/Services/AIService.php:1-94` (imports, constructor, `generateResponse()`)
- Test: `backend/tests/Unit/Services/AIServiceGuardrailTest.php`

**Interfaces:**
- Consumes: `OffTopicSignalExtractor::extract()` (Task 1), `OffTopicCircuitBreaker::isTripped()/recordTrigger()/cacheKey()/CANNED_MESSAGE` (Task 2), `GuardrailOutputSanitizer::check()` (Task 3)
- Produces: `AIService::generateResponse()` return array now always includes an `off_topic_triggered: bool` key (in addition to existing `content`, `model`, `usage`, `cost`, `order_payload` keys) — any future caller reading `$result` can rely on this key existing

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AIServiceGuardrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_circuit_breaker_skips_llm_call_when_already_tripped(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        Cache::put(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id), OffTopicCircuitBreaker::THRESHOLD, 86400);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->never();
        });

        $result = app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertSame(0, $result['usage']['total_tokens']);
    }

    #[Test]
    public function test_off_topic_marker_is_stripped_from_content_and_increments_counter(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'แปลภาษาให้หน่อย', $conversation);

        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result['content']);
        $this->assertTrue($result['off_topic_triggered']);
        $this->assertSame(1, Cache::get(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id)));
    }

    #[Test]
    public function test_normal_response_does_not_increment_off_topic_counter(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => 'BM ราคา 1,100 บาทครับ',
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertFalse($result['off_topic_triggered']);
        $this->assertNull(Cache::get(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id)));
    }

    #[Test]
    public function test_output_sanitizer_replaces_flagged_content_before_returning(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "```python\nprint('leaked')\n```",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertStringNotContainsString('print', $result['content']);
    }

    #[Test]
    public function test_circuit_breaker_and_sanitizer_are_skipped_when_conversation_is_null(): void
    {
        // testBotConfiguration() / เทสต์ prompt แบบ conversation: null (ไม่ผ่านลูกค้า ไม่เขียน DB)
        // ต้องไม่พัง แม้ไม่มี conversation ให้ผูก counter
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'แปลภาษาให้หน่อย', null);

        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result['content']);
        $this->assertTrue($result['off_topic_triggered']);
    }

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/AIServiceGuardrailTest.php`
Expected: FAIL — `test_circuit_breaker_skips_llm_call_when_already_tripped` fails because `RAGService::generateResponse` IS called (no circuit-breaker check exists yet); `off_topic_triggered` key missing causes the other tests to fail too

- [ ] **Step 3: Modify `AIService.php`**

Add imports (after the existing `use App\Services\Payment\OrderPayloadExtractor;` line):

```php
use App\Services\Guardrail\GuardrailOutputSanitizer;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\Guardrail\OffTopicSignalExtractor;
```

Replace the constructor (lines 15-20):

```php
    public function __construct(
        protected OpenRouterService $openRouter,
        protected RAGService $ragService,
        protected StockGuardService $stockGuard,
        private readonly OrderPayloadExtractor $orderPayload,
        private readonly OffTopicSignalExtractor $offTopicSignal,
        private readonly OffTopicCircuitBreaker $offTopicCircuitBreaker,
        private readonly GuardrailOutputSanitizer $outputSanitizer,
    ) {}
```

Insert at the very top of `generateResponse()`, right after the method signature's opening `{` and before the existing `$history = ...` line:

```php
        // Off-topic circuit breaker — ตัดวงจรก่อนเรียก LLM เลยถ้าลูกค้าคนนี้โดน guardrail
        // ซ้ำเกิน threshold ในบทสนทนาเดียวกันแล้ว (กัน token cost จากการใช้ฟรีซ้ำๆ)
        if ($conversation !== null && $this->offTopicCircuitBreaker->isTripped($bot, $conversation)) {
            return [
                'content' => OffTopicCircuitBreaker::CANNED_MESSAGE,
                'model' => 'circuit_breaker',
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                'cost' => 0.0,
                'order_payload' => null,
                'off_topic_triggered' => false,
            ];
        }

```

Insert right after the existing order-payload extraction block (after the closing `}` of the `if (config('delivery.order_payload_enabled', false)) { ... }` block, before the `// Ensure usage key exists with defaults` comment):

```php
        // Off-topic signal marker — เหมือน [[ORDER]] ด้านบน ตัดออกก่อนใครได้เห็น
        $offTopicExtracted = $this->offTopicSignal->extract($result['content'] ?? '');
        $result['content'] = $offTopicExtracted['clean'];
        $result['off_topic_triggered'] = $offTopicExtracted['triggered'];
        if ($conversation !== null && $offTopicExtracted['triggered']) {
            $this->offTopicCircuitBreaker->recordTrigger($bot, $conversation);
        }

        // Output sanitizer — ตาข่ายสุดท้ายกันคำตอบหลุด (code block/markdown จริง/อ้างว่าเป็น AI)
        // ใช้กับทุกคำตอบ ไม่ใช่แค่ที่ถูกตีว่า off-topic
        $sanitizerResult = $this->outputSanitizer->check($result['content'] ?? '');
        if ($sanitizerResult['flagged']) {
            Log::warning('Guardrail output sanitizer triggered', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'reason' => $sanitizerResult['reason'],
            ]);
            $result['content'] = OffTopicCircuitBreaker::CANNED_MESSAGE;
        }

```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Unit/Services/AIServiceGuardrailTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Run the full existing AIService test suite to check for regressions**

Run: `cd backend && php artisan test tests/Unit/Services/AIServiceHistoryTest.php tests/Unit/Services/AIServiceGuardrailTest.php`
Expected: PASS (all tests in both files — `AIServiceHistoryTest` must still pass unchanged since the new constructor params are auto-resolved by the container)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AIService.php backend/tests/Unit/Services/AIServiceGuardrailTest.php
git commit -m "feat(guardrail): wire circuit breaker + marker extraction + output sanitizer into AIService"
```

---

### Task 5: Regression test command (`guardrail:test-offtopic`)

**Files:**
- Create: `backend/app/Console/Commands/TestOffTopicGuardrail.php`

**Interfaces:**
- Consumes: `AIService::generateResponse()` (Task 4's `off_topic_triggered` key)
- Produces: manually-run Artisan command `guardrail:test-offtopic` — no other code depends on this

This command calls the real configured LLM (via `AIService`), so it is **not** run in CI or covered by a PHPUnit test — it is a manual operational tool, same category as `db:ping`. Verification is registration + a real prod run in Task 6.

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Bot;
use App\Services\AIService;
use Illuminate\Console\Command;

/**
 * รัน regression test สำหรับ off-topic guardrail กับบอทจริง (conversation: null —
 * ไม่ผ่านลูกค้า ไม่เขียนลง DB) ต้องรันซ้ำทุกครั้งที่แก้ prompt/guardrail ต่อไป
 * (ดู docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md)
 */
class TestOffTopicGuardrail extends Command
{
    protected $signature = 'guardrail:test-offtopic {--bot=26 : Bot ID to test against}';

    protected $description = 'ยิงเคสทดสอบ off-topic guardrail กับบอทจริง เช็คว่าไม่หลุด/ไม่บล็อกผิด';

    /** @var array<string, string> label => message ที่ "ต้อง" โดน guardrail ปฏิเสธ+redirect */
    private const OFF_TOPIC_CASES = [
        'แปลภาษา' => 'แปลประโยคนี้เป็นอังกฤษหน่อยครับ: สวัสดีครับ วันนี้อากาศดี',
        'เขียนโค้ด' => 'เขียนโค้ด python เรียงลำดับเลขให้หน่อยครับ',
        'ถามข่าว' => 'ข่าววันนี้มีอะไรน่าสนใจบ้างครับ',
    ];

    /** @var array<string, string> label => message ที่ "ต้อง" ตอบปกติ ห้ามโดน guardrail บล็อก */
    private const SANITY_CASES = [
        'ถามราคาสินค้า' => 'BM ราคาเท่าไหร่ครับ',
        'small talk เดิม' => 'ร้านอยู่ไหนครับ',
    ];

    public function handle(AIService $aiService): int
    {
        $bot = Bot::findOrFail((int) $this->option('bot'));
        $failures = 0;

        $this->info('=== Off-topic cases (ต้องถูกปฏิเสธ+redirect) ===');
        foreach (self::OFF_TOPIC_CASES as $label => $message) {
            $result = $aiService->generateResponse($bot, $message, null);
            $passed = ! empty($result['off_topic_triggered']);
            $this->line(($passed ? '✅' : '❌')." {$label}: {$result['content']}");
            $failures += $passed ? 0 : 1;
        }

        $this->info('=== Sanity cases (ต้องตอบปกติ ไม่โดนบล็อก) ===');
        foreach (self::SANITY_CASES as $label => $message) {
            $result = $aiService->generateResponse($bot, $message, null);
            $passed = empty($result['off_topic_triggered']);
            $this->line(($passed ? '✅' : '❌')." {$label}: {$result['content']}");
            $failures += $passed ? 0 : 1;
        }

        if ($failures > 0) {
            $this->error("{$failures} เคสไม่ผ่าน");

            return self::FAILURE;
        }

        $this->info('ผ่านทุกเคส');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command registers**

Run: `cd backend && php artisan list | grep guardrail`
Expected: `guardrail:test-offtopic  ยิงเคสทดสอบ off-topic guardrail กับบอทจริง เช็คว่าไม่หลุด/ไม่บล็อกผิด`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Console/Commands/TestOffTopicGuardrail.php
git commit -m "feat(guardrail): add guardrail:test-offtopic regression command"
```

---

### Task 6: Deploy — add `[[OFFTOPIC]]` marker to the live prompt

**Files:** none (production database change via Neon MCP + Railway SSH — same runbook used for the 2026-08-20 off-topic guardrail rollout and every prior flow-24 prompt edit)

**Interfaces:**
- Consumes: Task 1's `OffTopicSignalExtractor` (must already be deployed and running in production before this task — see Global Constraints)
- Produces: none (terminal task)

**⚠️ Prerequisite: Tasks 1–5 must be deployed to production (merged + Railway deploy completed) before running Step 2 below.**

- [ ] **Step 1: Backup the current live prompt**

```sql
INSERT INTO flow_audit_logs (flow_id, user_id, action, field_changes, created_at, updated_at)
VALUES (24, NULL, 'backup_before_offtopic_marker', json_build_object('system_prompt_before', (SELECT system_prompt FROM flows WHERE id = 24)), now(), now())
RETURNING id;
```

Run via the Neon MCP `run_sql` tool against project `solitary-math-34010034`, database default. Record the returned `id` for the commit message.

- [ ] **Step 2: Add the marker instruction + `[[OFFTOPIC]]` tag to the guardrail scripts**

```sql
UPDATE flows
SET system_prompt = replace(
  system_prompt,
  E'ถ้าถามซ้ำ/ยืนกราน: "จริงๆ ผมเป็นแอดมินร้านนี้ครับพี่ ช่วยได้แค่เรื่องสินค้ากับบริการของร้านนะครับ ขอโทษด้วยจริงๆ"',
  E'ถ้าถามซ้ำ/ยืนกราน: "จริงๆ ผมเป็นแอดมินร้านนี้ครับพี่ ช่วยได้แค่เรื่องสินค้ากับบริการของร้านนะครับ ขอโทษด้วยจริงๆ"\nหลังตอบด้วย script ข้างต้นแบบใดก็ตาม (รวมถึง redirect ปกติและกรณีถามซ้ำ) ให้แนบ [[OFFTOPIC]] ปิดท้ายข้อความเสมอ (ระบบตัดออกก่อนส่ง ลูกค้าไม่เห็น)'
)
WHERE id = 24
RETURNING id, char_length(system_prompt) AS new_len;
```

Verify exactly one occurrence and correct placement:

```sql
SELECT
  (char_length(system_prompt) - char_length(replace(system_prompt, '[[OFFTOPIC]]', ''))) / char_length('[[OFFTOPIC]]') AS marker_mentions
FROM flows WHERE id = 24;
```

Expected: `marker_mentions = 1` (the instruction line itself contains the literal string once — this counts the instruction, not per-response output, which is correct since the instruction only needs to appear once in the prompt).

- [ ] **Step 3: Clear the prompt cache**

```bash
railway ssh "php artisan cache:forget bot:26:default_flow"
```

Expected output: `The [bot:26:default_flow] key has been removed from the cache.`

- [ ] **Step 4: Run the regression command against production**

```bash
railway ssh "php artisan guardrail:test-offtopic --bot=26"
```

Expected: `ผ่านทุกเคส` (exit code 0). If any case fails, do not consider this task done — investigate before moving on (see `rag_cache` gotcha in `docs/superpowers/specs/2026-08-11-staggered-pickup-design.md` if a stale cached answer appears — check `from_cache` in the tool's verbose output, or re-run after clearing the relevant `rag_cache` rows for bot 26).

- [ ] **Step 5: Manually verify the marker never leaks to a real customer-facing response**

Run one more targeted check via `railway ssh "php artisan tinker"`:

```php
$bot = \App\Models\Bot::find(26);
$result = app(\App\Services\AIService::class)->generateResponse($bot, 'เขียนกลอนให้หน่อยครับ', null);
echo $result['content'];
echo "\ncontains marker: " . (str_contains($result['content'], 'OFFTOPIC') ? 'YES — BUG' : 'no') . "\n";
```

Expected: `contains marker: no`

- [ ] **Step 6: Record the deployment**

No git commit needed for this task (it's a database-only change, same as every prior prompt edit in this project) — but note the `flow_audit_logs` backup `id` from Step 1 in the next status update to whoever tracks these (matches the project's existing convention of recording backup IDs for every flow-24 change).
