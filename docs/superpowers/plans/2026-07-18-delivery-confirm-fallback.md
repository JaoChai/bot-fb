# Confirm-Message Fallback สำหรับ Manual Payment Confirm — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เมื่อเจ้าของกดยืนยันรับเงินแต่ระบบหาข้อความสรุปยอด+เลขบัญชีไม่เจอ (ลูกค้าโอนข้ามขั้นตอน) ให้ถอยไปอ่านรายการสินค้าจากข้อความยืนยันขั้น 2 ของบอท เพื่อให้ auto delivery มีรายการจอง stock ได้ แทนการ์ด "ไม่มีรายการที่ส่งอัตโนมัติได้"

**Architecture:** เพิ่ม method `findExpectedFromConfirmMessage()` ใน `SlipVerificationService` (ใช้ `isConfirmMessage`/`parseConfirmData` ที่มีอยู่แล้วใน `PaymentMessageDetector` + `LLMOrderItemExtractor` ตัวเดิมสำหรับ prose) แล้วต่อท่อจาก `ManualPaymentConfirmService::confirm()` เฉพาะตอน `findExpectedPayment()` คืน null — path EasySlip auto-verify ไม่แตะเลย

**Tech Stack:** Laravel 13, PHPUnit 12 (class-style tests), PostgreSQL (RefreshDatabase ใช้ sqlite ใน test)

**Spec:** `docs/superpowers/specs/2026-07-18-delivery-confirm-fallback-design.md`

## Global Constraints

- ห้ามแตะ `SlipVerificationService::verify()` (EasySlip check 1–4), `SlipRetryService`, `AccountDeliveryService`, `ReserveAccountStock`, `TelegramAlertCallbackController`
- ไม่มี migration / ไม่แก้ DB schema
- LLM fallback ต้องอยู่ใต้ config flag เดิม `delivery.llm_item_fallback_enabled` (default true)
- fallback ห้าม throw เด็ดขาด — ทุก error path คืน null แล้วพฤติกรรมเท่าเดิม
- Amount guard: ยอดจากข้อความยืนยันต้องตรง `$confirmedAmount` ± `slip_amount_tolerance` ของบอท (default 0)
- กรณีไม่มี amountOverride และ `findExpectedPayment()` คืน null → ยังคง throw `NoPendingPaymentException` เหมือนเดิม
- รันเทสต์จาก `backend/` ด้วย `php artisan test --filter=<ชื่อ>`

---

### Task 1: `findExpectedFromConfirmMessage()` ใน SlipVerificationService

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` (เพิ่ม method หลัง `findExpectedPayment` ที่จบบรรทัด ~121)
- Test (Create): `backend/tests/Feature/Payment/ConfirmMessageFallbackTest.php`

**Interfaces:**
- Consumes: `PaymentMessageDetector::isConfirmMessage(string): bool`, `PaymentMessageDetector::parseConfirmData(string): ?array{items: array, total: string}`, `LLMOrderItemExtractor::extract(string, Bot): array` (ไม่ throw — error คืน `[]`), `PaymentMessageDetector::isZeroPriceItem(array): bool`
- Produces: `public function findExpectedFromConfirmMessage(array $conversationHistory, ?Bot $bot, float $confirmedAmount): ?array` — คืน `{total: float, summary: string, items: array}` (shape เดียวกับ `findExpectedPayment`) หรือ `null`

- [ ] **Step 1: เขียน failing tests**

สร้าง `backend/tests/Feature/Payment/ConfirmMessageFallbackTest.php`:

```php
<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fallback ชั้น 3 (เฉพาะ manual confirm): อ่านออเดอร์จากข้อความยืนยันขั้น 2
 * เมื่อไม่มีข้อความสรุปยอด+เลขบัญชีใน history (เคสลูกค้าโอนข้ามขั้นตอน — delivery #38)
 */
class ConfirmMessageFallbackTest extends TestCase
{
    use RefreshDatabase;

    // ข้อความยืนยันขั้น 2 ที่ regex ดึง items ได้ (รูปแบบ "name xN = ราคา บาท")
    private const CONFIRM_PARSEABLE = "สรุปตะกร้าครับ\nNolimit BM x2 = 2,200 บาท\nรวมทั้งหมด 2,200 บาท ถูกต้องไหมครับ? พิมพ์ ยืนยัน ได้เลยครับ";

    // ข้อความยืนยันขั้น 2 แบบ prose (เคสจริง delivery #38 แชท #92) — regex ดึง items ไม่ได้
    private const CONFIRM_PROSE = 'กัปตันแอดขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ Personal แบบผูกบัตร 2 ตัว รวม 2,200 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลยครับ';

    private function history(string $botMessage): array
    {
        return [
            ['sender' => 'user', 'content' => 'Nolimit Personal ผูกบัตร'],
            ['sender' => 'bot', 'content' => $botMessage],
            ['sender' => 'user', 'content' => 'โอนแล้วครับ'],
        ];
    }

    private function makeBot(float $tolerance = 0): Bot
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'or-key-123']);

        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'openai/gpt-4o-mini',
        ]);
        BotSetting::create(['bot_id' => $bot->id, 'slip_amount_tolerance' => $tolerance]);

        return $bot;
    }

    private function service(OpenRouterService $openRouter): SlipVerificationService
    {
        return new SlipVerificationService(
            new PaymentMessageDetector,
            new TelegramAlertBotService,
            new LLMOrderItemExtractor($openRouter),
        );
    }

    public function test_returns_items_from_parseable_confirm_message(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 2200.0);

        $this->assertNotNull($result);
        $this->assertSame(2200.0, $result['total']);
        $this->assertSame('Nolimit BM', $result['items'][0]['name']);
        $this->assertSame(2, $result['items'][0]['qty']);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_returns_null_when_amount_mismatch(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 9999.0);

        $this->assertNull($result);
    }

    public function test_amount_within_tolerance_passes(): void
    {
        $bot = $this->makeBot(tolerance: 5);
        $openRouter = $this->createMock(OpenRouterService::class);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 2204.0);

        $this->assertNotNull($result);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_prose_confirm_uses_llm_extractor(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())->method('chat')->willReturn([
            'content' => '{"items":[{"name":"Nolimit Level Up+ Personal","qty":2,"total":"2200"}]}',
        ]);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PROSE), $bot, 2200.0);

        $this->assertNotNull($result);
        $this->assertSame(2200.0, $result['total']);
        $this->assertSame(
            [['name' => 'Nolimit Level Up+ Personal', 'qty' => 2, 'total' => '2200']],
            $result['items'],
        );
        $this->assertSame('Nolimit Level Up+ Personal', $result['summary']);
    }

    public function test_returns_null_when_llm_cannot_extract(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('chat')->willReturn(['content' => 'ขอโทษครับ ช่วยไม่ได้']);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PROSE), $bot, 2200.0);

        $this->assertNull($result);
    }

    public function test_returns_null_without_confirm_message(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedFromConfirmMessage(
            $this->history('รับทราบครับ สนใจรุ่นไหนดีครับ'),
            $bot,
            2200.0,
        );

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=ConfirmMessageFallbackTest`
Expected: FAIL ทุกเคสด้วย `Call to undefined method ...::findExpectedFromConfirmMessage()`

- [ ] **Step 3: implement method**

ใน `backend/app/Services/Payment/SlipVerificationService.php` เพิ่มต่อจาก `findExpectedPayment()` (หลังบรรทัด `return null;` ปิด method ~บรรทัด 121):

```php
    /**
     * Fallback ชั้น 3 (เฉพาะ manual confirm): อ่านออเดอร์จากข้อความยืนยันขั้น 2 ของบอท
     * ("...รวม X บาท ถูกต้องไหมครับ? พิมพ์ยืนยัน") เมื่อไม่มีข้อความสรุปยอด+เลขบัญชีใน history
     * — เคสลูกค้าโอนข้ามขั้นตอน. ห้ามใช้ตัดสิน EasySlip auto-pass: หลักฐานอ่อนกว่าสรุปยอด
     * จึงต้องมีคนกดยืนยันก่อนเสมอ แล้วยอดที่กด ($confirmedAmount) เป็นตัว guard
     * (ต้องตรงยอดในข้อความ ± slip_amount_tolerance) กันคว้าข้อความเก่าคนละออเดอร์.
     *
     * @param  array<int, array{sender: string, content: string}>  $conversationHistory
     * @return array{total: float, summary: string, items: array}|null
     */
    public function findExpectedFromConfirmMessage(array $conversationHistory, ?Bot $bot, float $confirmedAmount): ?array
    {
        $tolerance = (float) ($bot?->settings?->slip_amount_tolerance ?? 0);

        foreach (array_reverse($conversationHistory) as $msg) {
            if (($msg['sender'] ?? '') !== 'bot') {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (! $this->detector->isConfirmMessage($content)) {
                continue;
            }
            $data = $this->detector->parseConfirmData($content);
            if ($data === null) {
                continue;
            }

            $total = (float) str_replace(',', '', $data['total']);
            if (abs($total - $confirmedAmount) > $tolerance) {
                Log::info('Confirm fallback: amount mismatch, skipping message', [
                    'confirmed' => $confirmedAmount, 'found' => $total,
                ]);

                continue;
            }

            $items = array_map(function (array $item) {
                $item['name'] = rtrim(trim($item['name']), '= ');

                return $item;
            }, $data['items']);

            if ($items === [] && $bot !== null && $this->itemExtractor !== null
                && config('delivery.llm_item_fallback_enabled', true)) {
                $items = $this->itemExtractor->extract($content, $bot);
            }

            if ($items === []) {
                return null;
            }

            $visibleItems = array_filter($items, fn (array $item) => ! PaymentMessageDetector::isZeroPriceItem($item));
            $itemNames = array_column($visibleItems, 'name');

            Log::info('Confirm fallback: items extracted from step-2 confirm message', [
                'count' => count($items),
            ]);

            return [
                'total' => $total,
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
                'items' => $items,
            ];
        }

        return null;
    }
```

- [ ] **Step 4: รันให้ผ่าน**

Run: `cd backend && php artisan test --filter=ConfirmMessageFallbackTest`
Expected: PASS ทั้ง 6 เคส

- [ ] **Step 5: รัน regression ของ service เดิม**

Run: `cd backend && php artisan test --filter="SlipVerificationServiceTest|LLMOrderItemFallbackTest|SlipVerificationPipelineTest"`
Expected: PASS ทั้งหมด (method ใหม่เป็น additive ไม่แตะของเดิม)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/Payment/ConfirmMessageFallbackTest.php
git commit -m "feat(payment): fallback อ่านออเดอร์จากข้อความยืนยันขั้น 2 (findExpectedFromConfirmMessage)"
```

---

### Task 2: ต่อท่อ fallback เข้า ManualPaymentConfirmService + feature tests เคส #38

**Files:**
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php:48-55` (หลังบรรทัด `$expected = ...findExpectedPayment(...)` และ null-check ของ `$amount`)
- Test (Modify): `backend/tests/Feature/ManualPaymentConfirmTest.php` (เพิ่ม 2 เทสต์ท้ายคลาส + imports)

**Interfaces:**
- Consumes: `SlipVerificationService::findExpectedFromConfirmMessage(array, ?Bot, float): ?array` จาก Task 1
- Produces: ไม่มี consumer ต่อ — `$expected['summary']` ไหลเข้าข้อความ "เงินเข้าแล้ว... ออเดอร์: {summary}" และ `$expected['items']` ไหลเข้า `ReserveAccountStock::dispatchSafely()` ตามโค้ดเดิม

- [ ] **Step 1: เขียน failing tests**

ใน `backend/tests/Feature/ManualPaymentConfirmTest.php` เพิ่ม import 2 บรรทัดบนสุด (ใต้ `use App\Models\User;`):

```php
use App\Jobs\ReserveAccountStock;
use Illuminate\Support\Facades\Bus;
```

แล้วเพิ่ม 2 เทสต์ท้ายคลาส (ก่อนวงเล็บปิดคลาส):

```php
    public function test_confirm_falls_back_to_step2_confirm_message_when_no_payment_summary(): void
    {
        // เคส delivery #38: ลูกค้าโอนข้ามขั้นตอน — ไม่มีข้อความสรุปยอด+เลขบัญชีใน history
        // เหลือแต่ข้อความยืนยันขั้น 2 → ต้องดึงรายการจากข้อความนั้นแทน
        Bus::fake([ReserveAccountStock::class]);
        $this->conversation->messages()->where('sender', 'bot')->delete();
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปตะกร้าครับ\nNolimit BM x2 = 2,200 บาท\nรวมทั้งหมด 2,200 บาท ถูกต้องไหมครับ? พิมพ์ ยืนยัน ได้เลยครับ",
        ]);

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": false}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 2200]);

        $response->assertOk();

        $botMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->latest('id')
            ->first();
        $this->assertStringContainsString('Nolimit BM', $botMessage->content);
        $this->assertStringNotContainsString('ออเดอร์: -', $botMessage->content);

        Bus::assertDispatched(ReserveAccountStock::class, function (ReserveAccountStock $job) {
            return $job->items !== []
                && $job->items[0]['name'] === 'Nolimit BM'
                && $job->items[0]['qty'] === 2;
        });
    }

    public function test_confirm_without_any_order_context_keeps_current_behavior(): void
    {
        // ไม่มีทั้งสรุปยอดและข้อความยืนยันขั้น 2 → fallback คืน null → พฤติกรรมเดิม (items ว่าง)
        Bus::fake([ReserveAccountStock::class]);
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": false}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 2000]);

        $response->assertOk();

        Bus::assertDispatched(
            ReserveAccountStock::class,
            fn (ReserveAccountStock $job) => $job->items === [],
        );
    }
```

หมายเหตุ: เทสต์แรกใช้ข้อความยืนยันแบบ regex ดึงได้ (ไม่พึ่ง LLM) เพื่อไม่ให้ Http::fake ของ openrouter ชนกันระหว่าง plugin trigger กับ item extractor — path LLM ถูกคัฟเวอร์แล้วใน `ConfirmMessageFallbackTest`

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=ManualPaymentConfirmTest`
Expected: เทสต์ใหม่ตัวแรก FAIL (พบ "ออเดอร์: -" / job.items ว่าง เพราะยังไม่มี fallback) — เทสต์เดิม + regression ตัวที่สอง PASS

- [ ] **Step 3: ต่อท่อ fallback**

ใน `backend/app/Services/Payment/ManualPaymentConfirmService.php` แก้ช่วงบรรทัด 48–55 จาก:

```php
        $expected = $this->slipVerification->findExpectedPayment($history, $receiverAccount, $bot);

        $amount = $amountOverride ?? ($expected['total'] ?? null);
        if ($amount === null) {
            throw new NoPendingPaymentException;
        }

        $summary = $expected['summary'] ?? '-';
```

เป็น:

```php
        $expected = $this->slipVerification->findExpectedPayment($history, $receiverAccount, $bot);

        $amount = $amountOverride ?? ($expected['total'] ?? null);
        if ($amount === null) {
            throw new NoPendingPaymentException;
        }

        // Fallback ชั้น 3 (ลูกค้าโอนข้ามขั้นตอน): ไม่มีข้อความสรุปยอด+เลขบัญชีใน window
        // → อ่านออเดอร์จากข้อความยืนยันขั้น 2 โดยยอดต้องตรงกับยอดที่กดยืนยัน
        if ($expected === null) {
            $expected = $this->slipVerification->findExpectedFromConfirmMessage($history, $bot, (float) $amount);
        }

        $summary = $expected['summary'] ?? '-';
```

(`$expected['items'] ?? []` ที่ส่งเข้า `ReserveAccountStock::dispatchSafely` บรรทัด ~101 ใช้ตัวแปรเดิม ไม่ต้องแก้)

- [ ] **Step 4: รันให้ผ่าน**

Run: `cd backend && php artisan test --filter=ManualPaymentConfirmTest`
Expected: PASS ทั้งหมดรวม 2 เทสต์ใหม่

- [ ] **Step 5: รัน suite ที่เกี่ยวทั้งหมด**

Run: `cd backend && php artisan test --filter="ManualPaymentConfirm|SlipVerification|ConfirmMessageFallback|LLMOrderItemFallback|ReserveAccountStockDispatch|AccountDelivery|SlipRetry|DeliveryCallback"`
Expected: PASS ทั้งหมด — โดยเฉพาะ `test_no_amount_available_returns_422` (ยืนยันว่าเคสไม่มียอดยังคง throw เหมือนเดิม) และ `SlipVerificationServiceTest::test_no_pending_order_fails` (ยืนยันว่า EasySlip ยังไม่ auto-pass จากข้อความยืนยันขั้น 2)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Payment/ManualPaymentConfirmService.php backend/tests/Feature/ManualPaymentConfirmTest.php
git commit -m "feat(payment): manual confirm ถอยอ่านข้อความยืนยันขั้น 2 เมื่อไม่มีสรุปยอด (แก้ delivery 0 items)"
```
