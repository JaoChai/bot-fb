# Split Delivery Messages (Option B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ส่งบัญชีให้ลูกค้าแยกข้อความละ 1 บัญชี และแยกข้อความ Support ออกเป็นใบของตัวเองเสมอ แทนการยำรวมเป็นก้อนยาว

**Architecture:** เปลี่ยนเฉพาะชั้น "จัดข้อความก่อนส่ง" ใน `AccountDeliveryService` — `buildCustomerMessages()` คืนค่าแยก accounts กับ support, และเขียน `packTexts()` ใหม่เป็นโมเดล "งบ 5 bubble/push ของ LINE โดยกัน 1 ช่องให้ support เสมอ". กลไก push เดียวแบบ all-or-nothing (กันขายซ้ำ) และ markSold ไม่ถูกแตะเลย

**Tech Stack:** Laravel 12, PHP 8, Pest/PHPUnit (รันบน sqlite)

## Global Constraints

- **ส่ง push เดียวแบบ all-or-nothing เท่านั้น** — ห้ามแตกหลาย push (push แรกผ่าน push สองพัง = ระบบคิดว่ายังไม่ส่ง → คืน stock → ขายซ้ำ). โค้ดใน `pushTextsToLine` ที่เรียก `replyWithFallback` ครั้งเดียวต้องคงไว้
- **LINE limit: ≤5 message object/push, ≤5000 ตัวอักษร/ข้อความ** — layout สุดท้ายต้อง ≤5 bubble และทุก bubble ≤5000 ตัวอักษร ไม่งั้น throw ก่อนส่ง (fail-safe: ยังไม่ส่งอะไรเลย)
- **ข้อความ Support แยกเป็น bubble ของตัวเองเสมอ** — ห้ามยำรวมกับข้อความบัญชี แม้ออเดอร์ใหญ่
- **ห้าม log ค่า `detail` (credential) เด็ดขาด** — โค้ดจัดข้อความห้ามเพิ่ม log ใดๆ ที่แตะเนื้อข้อความ
- **แต่ละบัญชียังต้องมีเลขลำดับ `(x/N)`** ที่สร้างไว้แล้วใน `buildCustomerMessages` — ไม่แตะส่วนนี้

---

## File Structure

- **Modify:** `backend/app/Services/Delivery/AccountDeliveryService.php`
  - `buildCustomerMessages()` — เปลี่ยน return type จาก `array<int,string>` เป็น `array{accounts: array<int,string>, support: ?string}`
  - `deliver()` — call site (บรรทัด ~298-299) ปรับให้เรียก `pushTextsToLine` ด้วย accounts + support
  - `pushTextsToLine()` — เปลี่ยน signature รับ `(AccountDelivery, array $accounts, ?string $support)`; เพิ่ม char-limit guard
  - `packTexts()` — เขียนใหม่: รับ `(array $accounts, ?string $support, int $maxLen)` คืน bubble ตามโมเดลงบ
  - เพิ่ม private `groupAccounts(array $accounts, int $max): array` (แจกบัญชีลง ≤max bubble)
  - เพิ่ม private `anyBubbleTooLong(array $messages, int $limit = 5000): bool`
- **Create test:** `backend/tests/Unit/Services/Delivery/AccountDeliveryPackTextsTest.php` (unit บน pure method ผ่าน reflection — sqlite-independent เหมือน pattern PR #229 `buildDestRow`)
- **Touch (verify only, ไม่ควรต้องแก้):** `backend/tests/Feature/AccountDeliveryDeliverTest.php` — assert แบบ concat substring ทนต่อการเปลี่ยน layout อยู่แล้ว; รันให้ผ่าน

---

### Task 1: แยกข้อความส่งบัญชี + support (Option B budget model)

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (methods: `buildCustomerMessages`, `deliver` call site, `pushTextsToLine`, `packTexts`; add `groupAccounts`, `anyBubbleTooLong`)
- Create: `backend/tests/Unit/Services/Delivery/AccountDeliveryPackTextsTest.php`
- Verify: `backend/tests/Feature/AccountDeliveryDeliverTest.php`

**Interfaces:**
- Produces:
  - `buildCustomerMessages(AccountDelivery $delivery, $stockItems, $supportItems, array $reservedRows): array` → `['accounts' => array<int,string>, 'support' => ?string]`
  - `packTexts(array $accounts, ?string $support, int $maxLen = 4900): array<int,string>` (pure)
  - `groupAccounts(array $accounts, int $max): array<int,string>` (pure)
  - `anyBubbleTooLong(array $messages, int $limit = 5000): bool` (pure)
  - `pushTextsToLine(AccountDelivery $delivery, array $accounts, ?string $support): void`
- Consumes: `LINEService::replyWithFallback` (unchanged), `config_string('delivery.*')` (unchanged)

**Layout rule (budget model):**
- `budget = support !== null ? 4 : 5` (กัน 1 ช่องให้ support)
- accounts ≤ budget → แต่ละบัญชีคนละ bubble; accounts > budget → กระจายลงครบ `budget` ก้อนให้สมดุล (ก้อนแรกๆ ได้ +1 ถ้าหารไม่ลงตัว) คั่นด้วย `"\n\n"`
- ต่อ support เป็น bubble สุดท้าย (ถ้ามี)

---

- [ ] **Step 1: เขียน failing unit tests (layout + char guard)**

สร้าง `backend/tests/Unit/Services/Delivery/AccountDeliveryPackTextsTest.php`:

```php
<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\AccountDeliveryService;
use ReflectionMethod;
use Tests\TestCase;

class AccountDeliveryPackTextsTest extends TestCase
{
    /** @param array<int,string> $accounts */
    private function pack(array $accounts, ?string $support): array
    {
        $m = new ReflectionMethod(AccountDeliveryService::class, 'packTexts');
        $m->setAccessible(true);

        return $m->invoke(app(AccountDeliveryService::class), $accounts, $support, 4900);
    }

    public function test_each_account_is_its_own_bubble_with_support_last(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'SUP'], $this->pack(['A1', 'A2', 'A3'], 'SUP'));
    }

    public function test_four_accounts_plus_support_is_five_bubbles(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'SUP'], $this->pack(['A1', 'A2', 'A3', 'A4'], 'SUP'));
    }

    public function test_five_accounts_group_into_four_but_support_stays_separate(): void
    {
        $out = $this->pack(['A1', 'A2', 'A3', 'A4', 'A5'], 'SUP');
        $this->assertSame(["A1\n\nA2", 'A3', 'A4', 'A5', 'SUP'], $out);
        $this->assertStringNotContainsString('SUP', implode('', array_slice($out, 0, 4)));
    }

    public function test_eight_accounts_group_evenly_into_four_bubbles(): void
    {
        $out = $this->pack(['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'], 'SUP');
        $this->assertSame(["A1\n\nA2", "A3\n\nA4", "A5\n\nA6", "A7\n\nA8", 'SUP'], $out);
    }

    public function test_support_only_order_is_single_bubble(): void
    {
        $this->assertSame(['SUP'], $this->pack([], 'SUP'));
    }

    public function test_accounts_without_support_use_full_budget_of_five(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'A5'], $this->pack(['A1', 'A2', 'A3', 'A4', 'A5'], null));
    }

    public function test_any_bubble_too_long_is_detected(): void
    {
        $m = new ReflectionMethod(AccountDeliveryService::class, 'anyBubbleTooLong');
        $m->setAccessible(true);
        $svc = app(AccountDeliveryService::class);
        $this->assertTrue($m->invoke($svc, [str_repeat('x', 5001)], 5000));
        $this->assertFalse($m->invoke($svc, ['short', str_repeat('y', 5000)], 5000));
    }
}
```

- [ ] **Step 2: รันเทสต์ให้แน่ใจว่า fail**

Run: `php artisan test --filter=AccountDeliveryPackTextsTest`
Expected: FAIL — `packTexts` เดิมรับ argument เดียว (`ArgumentCountError` / behavior ไม่ตรง) และ `anyBubbleTooLong` ยังไม่มี (`ReflectionException`)

- [ ] **Step 3: เขียน `groupAccounts` + `anyBubbleTooLong` + เขียน `packTexts` ใหม่**

แทนที่ method `packTexts` เดิม (บรรทัด ~438-458) ด้วยโค้ดนี้ (comment เดิมของ packTexts ให้แทนด้วย comment ใหม่):

```php
    /**
     * จัด bubble สำหรับ push เดียว (≤5 ตาม LINE limit) โดยกัน 1 bubble ให้ support เสมอ:
     * บัญชี ≤ งบ → ตัวละ bubble; เกินงบ → กระจายลงครบงบให้สมดุล; support ต่อท้ายเป็น bubble ของตัวเอง
     *
     * @param  array<int, string>  $accounts
     * @return array<int, string>
     */
    private function packTexts(array $accounts, ?string $support, int $maxLen = 4900): array
    {
        $budget = $support !== null ? 4 : 5;
        $bubbles = $this->groupAccounts($accounts, $budget, $maxLen);
        if ($support !== null) {
            $bubbles[] = $support;
        }

        return $bubbles;
    }

    /**
     * แจกข้อความบัญชีลง bubble ให้แยกมากที่สุดแต่ไม่เกิน $max ก้อน:
     * ≤$max → ตัวละ bubble; เกิน → กระจายลงครบ $max ก้อนให้สมดุล (ก้อนแรกๆ ได้ +1 ถ้าหารไม่ลงตัว)
     * คั่นแต่ละบัญชีในก้อนเดียวกันด้วยบรรทัดว่าง
     *
     * @param  array<int, string>  $accounts
     * @return array<int, string>
     */
    private function groupAccounts(array $accounts, int $max, int $maxLen): array
    {
        $accounts = array_values($accounts);
        $count = count($accounts);
        if ($count <= $max) {
            return $accounts;
        }

        $base = intdiv($count, $max);
        $rem = $count % $max;
        $bubbles = [];
        $offset = 0;
        for ($g = 0; $g < $max; $g++) {
            $size = $base + ($g < $rem ? 1 : 0);
            $bubbles[] = implode("\n\n", array_slice($accounts, $offset, $size));
            $offset += $size;
        }

        return $bubbles;
    }

    /** มี bubble ไหนยาวเกิน limit ของ LINE ไหม (ใช้ตัดสิน throw ก่อนส่ง) */
    private function anyBubbleTooLong(array $messages, int $limit = 5000): bool
    {
        foreach ($messages as $message) {
            if (mb_strlen($message) > $limit) {
                return true;
            }
        }

        return false;
    }
```

หมายเหตุ: `$maxLen` ถูกส่งต่อเข้า `groupAccounts` เผื่ออนาคต แต่ Option B กระจายตามจำนวนก้อน; การกันยาวเกินทำที่ `anyBubbleTooLong` ใน `pushTextsToLine`

- [ ] **Step 4: แก้ `buildCustomerMessages` ให้คืน accounts/support แยก**

แทนที่ body ของ `buildCustomerMessages` (บรรทัด ~371-395) — เปลี่ยน `$texts` เป็น `$accounts` และคืน structured array; docblock เปลี่ยน return type:

```php
    /**
     * @return array{accounts: array<int, string>, support: ?string}
     *
     * แต่ละบัญชี = 1 ข้อความ; support แยกออกมาต่างหาก (null ถ้าไม่มี):
     * มีเพจ → ข้อความเพจ, บัญชีล้วน → ข้อความ Support เรื่องบัญชี/ตั้งค่า
     */
    private function buildCustomerMessages(AccountDelivery $delivery, $stockItems, $supportItems, array $reservedRows): array
    {
        $accounts = [];
        $n = $stockItems->count();
        foreach ($stockItems->values() as $i => $item) {
            $row = $reservedRows[$item->stock_item_id];
            $no = $i + 1;
            $text = "✅ {$item->product_name} ({$no}/{$n})\n{$row['detail']}";
            // แจ้ง id ตามข้อมูลจริงของแถวนั้น: BM มี bmId+adsId, ส่วนตัวมีแค่ adsId, G3D ไม่มี
            foreach (['BM ID' => 'bmId', 'Ads ID' => 'adsId'] as $label => $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $text .= "\n{$label}: {$value}";
                }
            }
            $accounts[] = $text;
        }

        $support = null;
        if ($supportItems->isNotEmpty()) {
            $support = $this->supportLinkText($delivery);
        } elseif ($stockItems->isNotEmpty()) {
            $support = config_string('delivery.account_support_template');
        }

        return ['accounts' => $accounts, 'support' => $support];
    }
```

- [ ] **Step 5: แก้ `pushTextsToLine` signature + guard + call site ใน `deliver()`**

แก้ call site ใน `deliver()` (บรรทัด ~298-299) จาก:

```php
            $texts = $this->buildCustomerMessages($delivery, $stockItems, $supportItems, $reservedRows);
            $this->pushTextsToLine($delivery, $texts);
```

เป็น:

```php
            $messages = $this->buildCustomerMessages($delivery, $stockItems, $supportItems, $reservedRows);
            $this->pushTextsToLine($delivery, $messages['accounts'], $messages['support']);
```

แทนที่ `pushTextsToLine` (บรรทัด ~415-436) ด้วย (docblock เดิมเรื่อง all-or-nothing คงไว้ได้ อัปเดตบรรทัดสุดท้ายเรื่อง guard):

```php
    /**
     * ส่งเป็น push เดียวแบบ all-or-nothing (text ล้วน ห้ามผ่าน LLM/Flex) — ห้ามแบ่งหลาย push:
     * ถ้า push แรกสำเร็จแล้ว push ถัดไปพัง ระบบจะคิดว่ายังไม่ส่งและอาจคืน stock
     * ทั้งที่ลูกค้าได้ credential ไปแล้ว → บัญชีเดิมถูกขายซ้ำได้
     * LINE ให้ 5 ข้อความ/push, ข้อความละ ~5000 ตัวอักษร
     * ถ้าจัดแล้วเกิน 5 bubble หรือ bubble ไหนยาวเกิน 5000 ให้ throw ก่อนส่ง (fail-safe: ยังไม่ส่งเลย)
     */
    private function pushTextsToLine(AccountDelivery $delivery, array $accounts, ?string $support): void
    {
        $conversation = $delivery->conversation;
        $externalId = $conversation?->external_customer_id;
        if ($conversation?->channel_type !== 'line' || ! $externalId) {
            throw new \RuntimeException('delivery target is not a LINE conversation');
        }
        if ($accounts === [] && $support === null) {
            throw new \RuntimeException('nothing to deliver');
        }

        $messages = $this->packTexts($accounts, $support);
        if (count($messages) > 5 || $this->anyBubbleTooLong($messages)) {
            throw new \RuntimeException('delivery message too large for a single LINE push');
        }

        $this->line->replyWithFallback(
            $delivery->bot, null, $externalId,
            array_map(fn (string $t) => ['type' => 'text', 'text' => $t], $messages),
            $this->line->generateRetryKey(),
        );
    }
```

- [ ] **Step 6: รัน unit tests → ผ่าน**

Run: `php artisan test --filter=AccountDeliveryPackTextsTest`
Expected: PASS (7 tests)

- [ ] **Step 7: รันเทสต์ delivery เดิมทั้งชุด → ยังเขียว (ยืนยันไม่ regress)**

Run: `php artisan test --filter=AccountDelivery`
Expected: PASS ทุกตัว — `AccountDeliveryDeliverTest` assert แบบ concat substring + `replyWithFallback ->once()` ยังจริง (Option B ส่ง push เดียวเหมือนเดิม)

- [ ] **Step 8: เพิ่ม assertion ยืนยัน layout จริงใน deliver test**

ใน `backend/tests/Feature/AccountDeliveryDeliverTest.php` เพิ่มเทสต์ใหม่ (setup เดิมมีบัญชี uid10 + เพจ = 1 บัญชี + support) ที่ยืนยันว่า bubble บัญชีกับ support แยกกัน:

```php
    public function test_account_and_support_are_separate_bubbles(): void
    {
        $pushed = [];
        $this->mock(\App\Services\LINEService::class, function ($mock) use (&$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('k');
            $mock->shouldReceive('replyWithFallback')->once()
                ->withArgs(function ($bot, $token, $userId, $messages) use (&$pushed) {
                    $pushed = $messages;

                    return true;
                });
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        $texts = array_column($pushed, 'text');
        // มีอย่างน้อย 1 bubble ที่เป็น "บัญชี" (มี credential) และ 1 bubble ที่เป็น support ล้วน
        $accountBubbles = array_filter($texts, fn ($t) => str_contains($t, 'uid10|pass10'));
        $supportBubbles = array_filter($texts, fn ($t) => str_contains($t, 'lin.ee/sTD5TQL'));
        $this->assertNotEmpty($accountBubbles);
        $this->assertNotEmpty($supportBubbles);
        // support ต้องไม่ปนอยู่ bubble เดียวกับ credential
        foreach ($accountBubbles as $b) {
            $this->assertStringNotContainsString('lin.ee/sTD5TQL', $b);
        }
    }
```

หมายเหตุ: ปรับ mock ให้ตรง pattern ที่ไฟล์นี้ใช้อยู่ (ดู `test_deliver_pushes_credentials_and_marks_sold` เป็นตัวอย่าง — อาจใช้ `Mockery` แบบเดียวกัน) ถ้า setup ของ `$this->delivery` ไม่มีทั้งบัญชีและเพจให้เพิ่ม/ปรับ item ตามไฟล์เทสต์เดิม

- [ ] **Step 9: รัน delivery tests อีกครั้ง → ผ่านหมด**

Run: `php artisan test --filter=AccountDelivery`
Expected: PASS ทุกตัว (รวมเทสต์ใหม่ layout)

- [ ] **Step 10: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Unit/Services/Delivery/AccountDeliveryPackTextsTest.php backend/tests/Feature/AccountDeliveryDeliverTest.php
git commit -m "feat(delivery): แยกข้อความส่งบัญชี 1 ตัว/ข้อความ + support แยก bubble (Option B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**1. Spec coverage:**
- "ส่งแต่ละบัญชีแยกข้อความ" → Step 3 `groupAccounts` (≤budget → ตัวละ bubble) ✅
- "support แยกออกเสมอ" → Step 3 `packTexts` ต่อ support เป็น bubble ท้าย + budget กัน 1 ช่อง ✅ (ทดสอบ Step 1 `test_five_accounts_group_into_four_but_support_stays_separate`)
- "ออเดอร์ใหญ่ (≥5 บัญชี) ไม่พังลิมิต LINE" → `groupAccounts` บีบลงครบ budget + `anyBubbleTooLong` guard ✅
- "ไม่กระทบกันขายซ้ำ" → `pushTextsToLine` ยังเรียก `replyWithFallback` ครั้งเดียว (push เดียว) ✅

**2. Placeholder scan:** ไม่มี TBD/TODO — โค้ดครบทุก step ✅

**3. Type consistency:** `buildCustomerMessages` → `['accounts','support']` ตรงกับ call site Step 5; `packTexts(array,?string,int)` ตรงกับ `pushTextsToLine` + reflection test; `groupAccounts(array,int,int)` ↔ ถูกเรียกใน `packTexts` ด้วย `($accounts,$budget,$maxLen)` ✅

**เคสต้องระวัง (fold ไว้ใน task):** ถ้า setup `$this->delivery` ในไฟล์เทสต์ delivery ไม่มีทั้งบัญชีและเพจพร้อมกัน → Step 8 ปรับ item ตาม pattern ไฟล์เดิม (implementer ดู `test_deliver_pushes_credentials_and_marks_sold` / `test_account_only_order_appends_account_support_message`)
