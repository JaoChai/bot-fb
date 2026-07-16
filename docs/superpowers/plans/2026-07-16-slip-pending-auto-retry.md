# Slip Pending Auto-Retry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เมื่อ EasySlip คืน `SLIP_PENDING` ให้ระบบตรวจสลิป (R2 URL เดิม) ซ้ำเองแบบหน่วงเวลา หลายรอบ — ผ่านแล้วจองของ+สร้างออเดอร์อัตโนมัติ, ครบรอบยัง pending แล้วแจ้งแอดมิน

**Architecture:** เพิ่ม job `RetrySlipVerification` (delayed, queued) ที่เรียก service `SlipRetryService` ให้ re-verify รูปเดิมกับ EasySlip แล้วแตกผล (passed → emit success + reserve, pending → re-dispatch/แจ้งแอดมิน, fail อื่น → ตอบลูกค้า+แจ้งแอดมิน). จุด trigger อยู่ที่ `LineWebhookResponseService::trySlipVerification` branch `pending`. Success side-effects เขียนใน service ใหม่ (push LINE) โดย **ไม่แตะ** `ManualPaymentConfirmService`.

**Tech Stack:** Laravel 12 queued jobs (`ShouldQueue` + `->delay()`), Http::fake / Bus::fake tests (PHPUnit), Redis queue

## Global Constraints

- Success side-effects ต้อง **push** (ไม่มี reply token) ผ่าน `LINEService::replyWithFallback($bot, null, $userId, [$flex], $retryKey)` — mirror `ManualPaymentConfirmService::pushToLine`
- **ห้ามแก้** `ManualPaymentConfirmService` (surgical; ยอม duplication)
- ทุก path ที่มี side-effect (LINE/Telegram/broadcast/plugin) ต้อง best-effort — พังต้องไม่ทำ job crash ลาม
- Feature-flag: `config('delivery.pending_retry.enabled')` — ปิด = พฤติกรรมเดิม (ไม่ dispatch, ข้อความ pending เดิม)
- `delays` (`config('delivery.pending_retry.delays')`) = ระยะรอ "ก่อน verify แต่ละรอบ" (incremental) ไม่ใช่ offset สะสม; `attempt` เริ่มที่ 1
- Job `tries = 1` (เหมือน `ReserveAccountStock`) — ไม่ retry ระดับ framework เพราะจัดการ retry เองด้วย re-dispatch
- `AccountDeliveryService`/EasySlip endpoint/บัญชีร้าน อ้างอิงค่าจาก bot settings เดิม ห้าม hardcode

---

### Task 1: Config + Job/Service scaffolding + pending re-dispatch + exhausted → notifyAdmin

**Files:**
- Modify: `backend/config/delivery.php`
- Create: `backend/app/Jobs/RetrySlipVerification.php`
- Create: `backend/app/Services/Payment/SlipRetryService.php`
- Test: `backend/tests/Feature/SlipRetryServiceTest.php`

**Interfaces:**
- Produces:
  - `RetrySlipVerification::__construct(int $botId, int $conversationId, int $messageId, string $imageUrl, int $attempt)`
  - `RetrySlipVerification::handle(SlipRetryService $service): void`
  - `SlipRetryService::retry(Bot $bot, Conversation $conversation, Message $message, string $imageUrl, int $attempt): void`
- Consumes: `SlipVerificationService::verify()`, `::notifyAdmin()` (มีอยู่แล้ว); `config('delivery.pending_retry')`

- [ ] **Step 1: เพิ่ม config block ใน `backend/config/delivery.php`**

เพิ่มก่อน `];` ปิดท้าย array:

```php
    // Auto-retry ตรวจสลิปซ้ำเมื่อ EasySlip คืน SLIP_PENDING (ธนาคารยังไม่ขึ้นธุรกรรม)
    // รูปสลิปเก็บถาวรบน R2 → re-verify URL เดิมได้โดยลูกค้าไม่ต้องส่งซ้ำ
    'pending_retry' => [
        'enabled' => (bool) env('SLIP_PENDING_RETRY_ENABLED', true),
        // วินาที: ระยะรอก่อน verify แต่ละรอบ (incremental). จำนวน element = จำนวนรอบ
        // ตรวจครบทุกรอบยัง pending → แจ้งแอดมิน. verify ที่ ~t+1.5น, +4.5น, +9.5น
        'delays' => [90, 180, 300],
    ],
```

- [ ] **Step 2: เขียน failing test — pending ทุกรอบ → re-dispatch จนครบแล้ว notifyAdmin**

สร้าง `backend/tests/Feature/SlipRetryServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RetrySlipVerification;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\Payment\SlipRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private Message $slipMessage;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->owner()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'channel_type' => 'line',
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        // Telegram alert plugin บน default flow (สำหรับ path แจ้งแอดมิน)
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งแอดมิน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-tok', 'chat_id' => '-100999'],
        ]);

        $profile = CustomerProfile::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $profile->id,
            'channel_type' => 'line',
            'external_customer_id' => 'U123',
            'is_handover' => false,
        ]);
        $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nบัญชี 223-3-24880-3",
        ]);
        $this->slipMessage = $this->conversation->messages()->create([
            'sender' => 'user', 'type' => 'image', 'content' => '[รูปภาพ]',
            'media_url' => 'https://pub-xxx.r2.dev/line/26/slip.jpg',
        ]);
    }

    private function fakePending(): void
    {
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_still_pending_redispatches_next_attempt(): void
    {
        Bus::fake([RetrySlipVerification::class]);
        $this->fakePending();
        config(['delivery.pending_retry.delays' => [90, 180, 300]]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        Bus::assertDispatched(RetrySlipVerification::class, function (RetrySlipVerification $job) {
            return $job->attempt === 2 && $job->conversationId === $this->conversation->id;
        });
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_pending_on_final_attempt_alerts_admin_and_stops(): void
    {
        Bus::fake([RetrySlipVerification::class]);
        $this->fakePending();
        config(['delivery.pending_retry.delays' => [90, 180, 300]]);

        // attempt 3 = รอบสุดท้าย (count(delays) === 3)
        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 3
        );

        Bus::assertNotDispatched(RetrySlipVerification::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }
}
```

- [ ] **Step 3: รัน test ให้ FAIL**

Run: `cd backend && php artisan test --filter=SlipRetryServiceTest`
Expected: FAIL — `Class "App\Jobs\RetrySlipVerification"` / `SlipRetryService` not found

- [ ] **Step 4: สร้าง `backend/app/Services/Payment/SlipRetryService.php` (pending + exhausted paths)**

```php
<?php

namespace App\Services\Payment;

use App\Jobs\RetrySlipVerification;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use Illuminate\Support\Facades\Log;

/**
 * จัดการ retry ตรวจสลิปหลัง EasySlip คืน SLIP_PENDING — re-verify รูปเดิมกับ EasySlip
 * ตามตาราง delay. ผ่าน → emit success + จองของ, pending → re-dispatch/แจ้งแอดมิน,
 * fail อื่น → ตอบลูกค้า + แจ้งแอดมิน. เขียนแยกจาก ManualPaymentConfirmService โดยตั้งใจ.
 */
class SlipRetryService
{
    public function __construct(
        private readonly SlipVerificationService $slipVerification,
    ) {}

    /**
     * @param  int  $attempt  รอบปัจจุบัน (เริ่มที่ 1). count(delays) = รอบสุดท้าย
     */
    public function retry(Bot $bot, Conversation $conversation, Message $message, string $imageUrl, int $attempt): void
    {
        // ลูกค้าส่งสลิปซ้ำผ่านเอง / แอดมินยืนยันมือไปแล้ว → หยุด กันออเดอร์ซ้ำ
        if ($this->alreadyResolved($conversation, $message)) {
            return;
        }

        $history = $this->recentTextHistory($conversation);
        $result = $this->slipVerification->verify($bot, $conversation, $message, $imageUrl, $history);

        if ($result->passed) {
            // Task 2 เติม emitSuccess() — ชั่วคราวยังไม่ทำอะไร
            return;
        }

        if ($result->failReason === 'pending' || in_array($result->failReason, ['api_error', 'config_error'], true)) {
            $this->handlePendingOrTransient($bot, $conversation, $message, $imageUrl, $attempt, $result);

            return;
        }

        // Task 3 เติม fail อื่น (fake/amount_mismatch/...) — ชั่วคราวแจ้งแอดมิน
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
    }

    /**
     * มีสลิป passed/manual_confirmed ที่เกิดหลังข้อความสลิปนี้แล้วหรือยัง
     */
    private function alreadyResolved(Conversation $conversation, Message $message): bool
    {
        return SlipVerification::where('conversation_id', $conversation->id)
            ->whereIn('status', ['passed', 'manual_confirmed'])
            ->where('created_at', '>=', $message->created_at)
            ->exists();
    }

    private function handlePendingOrTransient(
        Bot $bot, Conversation $conversation, Message $message, string $imageUrl, int $attempt, SlipVerificationResult $result
    ): void {
        $delays = (array) config('delivery.pending_retry.delays', [90, 180, 300]);
        $maxAttempts = count($delays);

        if ($attempt < $maxAttempts) {
            $nextDelay = (int) $delays[$attempt]; // delays[attempt] = ระยะก่อนรอบ attempt+1
            RetrySlipVerification::dispatch($bot->id, $conversation->id, $message->id, $imageUrl, $attempt + 1)
                ->delay(now()->addSeconds($nextDelay));

            return;
        }

        // ครบทุกรอบยัง pending/ตรวจไม่ได้ → แจ้งแอดมินให้ตรวจมือ (backstop)
        Log::info('Slip pending retry exhausted, alerting admin', [
            'conversation_id' => $conversation->id, 'attempts' => $attempt, 'reason' => $result->failReason,
        ]);
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
    }

    /**
     * ประวัติ text ล่าสุด (mirror ManualPaymentConfirmService::recentTextHistory)
     *
     * @return array<int, array{sender: string, content: string}>
     */
    private function recentTextHistory(Conversation $conversation, int $limit = 15): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text');

        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        return $query->latest()->take($limit)->get()->reverse()
            ->map(fn (Message $msg) => ['sender' => $msg->sender, 'content' => $msg->content])
            ->values()->toArray();
    }
}
```

- [ ] **Step 5: สร้าง `backend/app/Jobs/RetrySlipVerification.php`**

```php
<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Payment\SlipRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ตรวจสลิปซ้ำหลัง SLIP_PENDING (delayed). tries=1 — จัดการ retry เองผ่าน re-dispatch
 * ใน SlipRetryService ตามตาราง delay จึงไม่พึ่ง framework retry
 */
class RetrySlipVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $botId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $imageUrl,
        public readonly int $attempt,
    ) {}

    public function handle(SlipRetryService $service): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        $message = Message::find($this->messageId);
        if (! $bot || ! $conversation || ! $message) {
            return;
        }

        $service->retry($bot, $conversation, $message, $this->imageUrl, $this->attempt);
    }
}
```

- [ ] **Step 6: รัน test ให้ PASS**

Run: `cd backend && php artisan test --filter=SlipRetryServiceTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
cd backend && git add config/delivery.php app/Jobs/RetrySlipVerification.php app/Services/Payment/SlipRetryService.php tests/Feature/SlipRetryServiceTest.php
git commit -m "feat(slip): RetrySlipVerification job + pending re-dispatch/แจ้งแอดมิน backstop"
```

---

### Task 2: Success path — passed retry → emit success + จองของ (push LINE)

**Files:**
- Modify: `backend/app/Services/Payment/SlipRetryService.php`
- Test: `backend/tests/Feature/SlipRetryServiceTest.php`

**Interfaces:**
- Consumes: `PaymentFlexService::tryConvertToFlex(string $text, ?Conversation): string|array`, `LINEService::replyWithFallback(Bot, ?string $replyToken, string $userId, array $messages, ?string $retryKey)`, `LINEService::generateRetryKey(): string`, `FlowPluginService::executePlugins(Bot, Conversation, Message)`, `ReserveAccountStock::dispatchSafely(int,int,int,?float,array)`, `LineWebhookResponseService::SLIP_SUCCESS_TEMPLATE`
- Produces: `SlipRetryService::emitSuccess(...)` (private)

- [ ] **Step 1: เขียน failing test — pending รอบแรกแล้ว passed → จองของ + push success**

เพิ่ม method ใน `SlipRetryServiceTest`:

```php
    public function test_passed_retry_reserves_stock_and_pushes_success(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ReserveAccountStock::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'amountInSlip' => 1100,
                    'rawSlip' => [
                        'transRef' => 'TR-RETRY-1',
                        'amount' => ['amount' => 1100],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        // จองของ
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ReserveAccountStock::class, function ($job) {
            return $job->conversationId === $this->conversation->id && (float) $job->amount === 1100.0;
        });
        // push ข้อความ "เงินเข้าแล้ว" ไป LINE
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me'));
        // bot message ถูกสร้าง สถานะ passed
        $this->assertTrue(
            $this->conversation->messages()
                ->where('sender', 'bot')
                ->get()
                ->contains(fn ($m) => ($m->metadata['slip_status'] ?? null) === 'passed')
        );
    }
```

- [ ] **Step 2: รัน test ให้ FAIL**

Run: `cd backend && php artisan test --filter=test_passed_retry_reserves_stock_and_pushes_success`
Expected: FAIL — ไม่มี ReserveAccountStock dispatched (passed branch ยัง `return` เปล่า)

- [ ] **Step 3: เติม dependencies + `emitSuccess()` ใน `SlipRetryService`**

แก้ constructor เพิ่ม deps:

```php
    public function __construct(
        private readonly SlipVerificationService $slipVerification,
        private readonly \App\Services\PaymentFlexService $paymentFlex,
        private readonly \App\Services\LINEService $line,
        private readonly \App\Services\FlowPluginService $flowPlugin,
    ) {}
```

แทนที่ passed branch (`if ($result->passed) { ... return; }`) ด้วย:

```php
        if ($result->passed) {
            $this->emitSuccess($bot, $conversation, $result);

            return;
        }
```

เพิ่ม method (วางก่อน `alreadyResolved`):

```php
    /**
     * ปล่อย success side-effects แบบ push (นอก webhook ไม่มี reply token) —
     * mirror ManualPaymentConfirmService post-commit path แต่ใช้ผล EasySlip 'passed'
     * ที่ verify() บันทึกไว้แล้ว (มี slipVerificationId + trans_ref)
     */
    private function emitSuccess(Bot $bot, Conversation $conversation, SlipVerificationResult $result): void
    {
        $template = $bot->settings?->slip_success_message
            ?: \App\Services\LineWebhook\LineWebhookResponseService::SLIP_SUCCESS_TEMPLATE;
        $text = str_replace(
            ['{amount}', '{order_summary}'],
            [number_format($result->amount ?? 0), $result->orderSummary ?? '-'],
            $template,
        );

        $botMessage = $conversation->messages()->create([
            'sender' => 'bot',
            'content' => $text,
            'type' => 'text',
            'metadata' => [
                'slip_verification' => true,
                'slip_status' => 'passed',
                'slip_trans_ref' => $result->transRef,
                'slip_retry' => true,
            ],
        ]);

        $this->pushToLine($bot, $conversation, $text);
        $this->runPlugins($bot, $conversation, $botMessage);

        if ($result->slipVerificationId !== null) {
            \App\Jobs\ReserveAccountStock::dispatchSafely(
                $bot->id,
                $conversation->id,
                $result->slipVerificationId,
                $result->amount,
                $result->orderItems ?? [],
            );
        }

        $this->broadcast($conversation, $botMessage);
    }

    private function pushToLine(Bot $bot, Conversation $conversation, string $text): void
    {
        $externalId = $conversation->external_customer_id;
        if ($conversation->channel_type !== 'line' || ! $externalId) {
            return;
        }

        try {
            $transformed = $this->paymentFlex->tryConvertToFlex($text, $conversation);
            $this->line->replyWithFallback($bot, null, $externalId, [$transformed], $this->line->generateRetryKey());
        } catch (\Throwable $e) {
            Log::error('Slip retry: LINE push failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function runPlugins(Bot $bot, Conversation $conversation, Message $botMessage): void
    {
        try {
            $this->flowPlugin->executePlugins($bot, $conversation, $botMessage);
        } catch (\Throwable $e) {
            Log::warning('Slip retry: plugin execution failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function broadcast(Conversation $conversation, Message $botMessage): void
    {
        $conversation->update(['last_message_at' => now(), 'last_message_id' => $botMessage->id]);
        $conversation->increment('message_count');
        $conversation->refresh();

        try {
            broadcast(new \App\Events\MessageSent($botMessage, [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ]))->toOthers();
            broadcast(new \App\Events\ConversationUpdated($conversation, 'message_received'))->toOthers();
        } catch (\Throwable $e) {
            Log::error('Slip retry: broadcast failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }
```

- [ ] **Step 4: รัน test ให้ PASS**

Run: `cd backend && php artisan test --filter=SlipRetryServiceTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Services/Payment/SlipRetryService.php tests/Feature/SlipRetryServiceTest.php
git commit -m "feat(slip): retry ผ่าน → จองของ + push success (นอก webhook)"
```

---

### Task 3: Fail-อื่น path — retry เจอ fake/amount_mismatch → ตอบลูกค้า + แจ้งแอดมิน

**Files:**
- Modify: `backend/app/Services/Payment/SlipRetryService.php`
- Test: `backend/tests/Feature/SlipRetryServiceTest.php`

**Interfaces:**
- Consumes: `LineWebhook\LineWebhookResponseService::SLIP_FAIL_TEMPLATE`, `bot->settings->slip_fail_message`

- [ ] **Step 1: เขียน failing test — retry เจอ amount_mismatch → push fail message + telegram, ไม่จองของ**

เพิ่ม method:

```php
    public function test_retry_hard_fail_pushes_fail_message_and_alerts(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ReserveAccountStock::class, \App\Jobs\RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'amountInSlip' => 5, // ยอดไม่ตรงกับ 1,100
                    'rawSlip' => [
                        'transRef' => 'TR-FAIL-1',
                        'amount' => ['amount' => 5],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\ReserveAccountStock::class);
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\RetrySlipVerification::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me'));
    }
```

- [ ] **Step 2: รัน test ให้ FAIL**

Run: `cd backend && php artisan test --filter=test_retry_hard_fail_pushes_fail_message_and_alerts`
Expected: FAIL — ไม่มี push ไป api.line.me (fail branch แจ้งแอดมินอย่างเดียว ยังไม่ตอบลูกค้า)

- [ ] **Step 3: แทน fail branch สุดท้ายใน `retry()` ด้วยการตอบลูกค้า + แจ้งแอดมิน**

แทน:

```php
        // Task 3 เติม fail อื่น ...
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
```

ด้วย:

```php
        // fail อื่น (fake/amount_mismatch/wrong_account/duplicate/no_pending_order):
        // ตอบลูกค้าด้วย fail template + แจ้งแอดมิน (mirror webhook fail path)
        $failText = $bot->settings?->slip_fail_message
            ?: \App\Services\LineWebhook\LineWebhookResponseService::SLIP_FAIL_TEMPLATE;
        $conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => $failText,
            'metadata' => ['slip_verification' => true, 'slip_status' => $result->status(), 'slip_retry' => true],
        ]);
        $this->pushToLine($bot, $conversation, $failText);
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
```

> หมายเหตุ: `SLIP_FAIL_TEMPLATE` เป็น `private const` — ต้องเปลี่ยนเป็น `public const` ใน `LineWebhookResponseService` (บรรทัด 31). `SLIP_SUCCESS_TEMPLATE` เป็น `public` อยู่แล้ว

- [ ] **Step 4: เปลี่ยน `SLIP_FAIL_TEMPLATE` เป็น public ใน `backend/app/Services/LineWebhook/LineWebhookResponseService.php:31`**

```php
    public const SLIP_FAIL_TEMPLATE = 'ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏';
```

- [ ] **Step 5: รัน test ให้ PASS**

Run: `cd backend && php artisan test --filter=SlipRetryServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Services/Payment/SlipRetryService.php app/Services/LineWebhook/LineWebhookResponseService.php tests/Feature/SlipRetryServiceTest.php
git commit -m "feat(slip): retry เจอ fail จริง → ตอบลูกค้า + แจ้งแอดมิน"
```

---

### Task 4: Wire trigger — pending branch ใน trySlipVerification dispatch job + เปลี่ยนข้อความลูกค้า

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php` (const `SLIP_PENDING_TEMPLATE` บรรทัด 33 + branch `pending` บรรทัด ~541)
- Test: `backend/tests/Feature/SlipVerificationPipelineTest.php`

**Interfaces:**
- Consumes: `RetrySlipVerification::dispatch(int,int,int,string,int)` (Task 1)

- [ ] **Step 1: เขียน failing test — เจอ pending → dispatch RetrySlipVerification attempt=1 + ข้อความใหม่ (ไม่ขอส่งซ้ำ)**

เพิ่มใน `SlipVerificationPipelineTest`:

```php
    public function test_pending_slip_dispatches_retry_job_and_tells_customer_to_wait(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        // ข้อความใหม่: ไม่ขอให้ส่งสลิปซ้ำ
        $this->assertStringNotContainsString('ส่งสลิปเดิมมาอีกครั้ง', $ctx->response->payload);
        $this->assertStringContainsString('ตรวจให้อัตโนมัติ', $ctx->response->payload);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\RetrySlipVerification::class, function ($job) {
            return $job->attempt === 1
                && $job->conversationId === $this->conversation->id
                && $job->imageUrl === 'https://cdn.example.com/slip.jpg';
        });
    }

    public function test_pending_retry_disabled_keeps_legacy_behaviour(): void
    {
        config(['delivery.pending_retry.enabled' => false]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\RetrySlipVerification::class);
    }
```

- [ ] **Step 2: รัน test ให้ FAIL**

Run: `cd backend && php artisan test --filter=test_pending_slip_dispatches_retry_job_and_tells_customer_to_wait`
Expected: FAIL — ยังไม่ dispatch job + ข้อความยังมี "ส่งสลิปเดิมมาอีกครั้ง"

- [ ] **Step 3: เปลี่ยน `SLIP_PENDING_TEMPLATE` (บรรทัด 33)**

```php
    private const SLIP_PENDING_TEMPLATE = 'สลิปเพิ่งโอน ธนาคารกำลังประมวลผลครับ 🙏 ระบบจะตรวจให้อัตโนมัติใน 1-2 นาที รอสักครู่นะครับ';
```

- [ ] **Step 4: แก้ pending branch ใน `trySlipVerification` (บรรทัด ~541)**

แทน:

```php
            } elseif ($result->failReason === 'pending') {
                // ธนาคารยังประมวลผลไม่เสร็จ — ลูกค้าแก้เองได้ (รอแล้วส่งใหม่) ไม่ต้อง alert แอดมิน
                $text = self::SLIP_PENDING_TEMPLATE;
            } else {
```

ด้วย:

```php
            } elseif ($result->failReason === 'pending') {
                // ธนาคารยังประมวลผลไม่เสร็จ — ตั้ง auto-retry ตรวจ R2 URL เดิมซ้ำ (ลูกค้าไม่ต้องส่งใหม่)
                // ครบทุกรอบยัง pending ค่อยแจ้งแอดมิน (backstop ใน SlipRetryService)
                $text = self::SLIP_PENDING_TEMPLATE;
                if (config('delivery.pending_retry.enabled')) {
                    $delays = (array) config('delivery.pending_retry.delays', [90, 180, 300]);
                    \App\Jobs\RetrySlipVerification::dispatch(
                        $ctx->bot->id, $ctx->conversation->id, $ctx->userMessage->id, $imageUrl, 1
                    )->delay(now()->addSeconds((int) ($delays[0] ?? 90)));
                }
            } else {
```

- [ ] **Step 5: รัน test ให้ PASS (ทั้ง pipeline + retry suite)**

Run: `cd backend && php artisan test --filter=SlipVerificationPipelineTest && php artisan test --filter=SlipRetryServiceTest`
Expected: PASS ทั้งหมด

- [ ] **Step 6: รัน test ชุด slip/delivery ทั้งหมด กัน regression**

Run: `cd backend && php artisan test --filter='Slip|Delivery|Payment'`
Expected: PASS ทั้งหมด (ไม่มี regression บน path เดิม)

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Services/LineWebhook/LineWebhookResponseService.php tests/Feature/SlipVerificationPipelineTest.php
git commit -m "feat(slip): pending → dispatch auto-retry job + ข้อความใหม่ (flag-gated)"
```

---

## Manual verification (หลังผ่าน test ทั้งหมด — ก่อน merge/deploy)

1. `cd backend && ./vendor/bin/pint --dirty` — ฟอร์แมตผ่าน
2. ตรวจว่า queue worker รัน delayed jobs ได้ (Redis queue) — deploy แล้วดู Railway log ว่า `RetrySlipVerification` ถูก process ตาม delay
3. E2E (เจ้าของ): ส่งสลิปที่เพิ่งโอนจริง (Bangkok Bank <5นาที) เข้า bot 26 → ต้องเห็นข้อความ "ตรวจให้อัตโนมัติ" → รอ ~1.5น → ระบบตอบ "เงินเข้าแล้ว" + จองของเอง โดยไม่ต้องส่งสลิปซ้ำ
4. ตรวจ Neon: `slip_verifications` มีแถว pending (รอบแรก) + passed (รอบ retry); `account_deliveries` มีงานจอง

## Self-review notes (ผู้เขียน plan ตรวจแล้ว)

- **Spec coverage:** trigger(Task4) / job+config+pending backstop(Task1) / success emit+reserve(Task2) / fail อื่น(Task3) / feature-flag(Task4 test) / idempotency dedup(Task1 alreadyResolved + verify duplicate เดิม) — ครบ
- **duplicate guard:** `alreadyResolved` เช็ค passed/manual_confirmed หลัง message.created_at + `verify()` duplicate(trans_ref) เดิม = กันออเดอร์ซ้ำ  2 ชั้น
- **naming consistency:** `RetrySlipVerification`(botId,conversationId,messageId,imageUrl,attempt) + `SlipRetryService::retry(...)` ใช้ตรงกันทุก task
