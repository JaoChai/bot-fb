# Order Quantity Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ปิดช่องที่ระบบส่งของ "ไม่ครบตามจำนวนที่ลูกค้าสั่ง" โดยที่ไม่มีใครรู้ — ทั้งแก้ regex ที่พังกับฟอร์แมตใหม่ (A), ปิดรูรั่วรอบข้างที่เงียบ (B), และเลิกให้ LLM เป็นเจ้าของตัวเลขจำนวนสินค้า (C)

**Architecture:** ปัจจุบันจำนวนสินค้าเดินทางผ่าน "ข้อความภาษาคนที่ LLM แต่งเอง" → regex อ่านกลับ → order_items + จำนวนบัญชีที่จอง. Phase A ทำให้ regex ทนฟอร์แมตที่โมเดล drift และเพิ่ม checksum (ผลรวมรายการต้องเท่ายอดโอน) เป็นตัวจับความผิดพลาดแบบทั่วไป. Phase B ทำให้ทุกการ "ลดจำนวนเงียบ" กลายเป็นการแจ้งเตือน. Phase C เพิ่มช่องทางข้อมูลแบบมีโครงสร้าง (`[[ORDER]]{json}[[/ORDER]]`) ที่บอทปล่อยมาพร้อมข้อความ ระบบตัดออกก่อนถึงลูกค้าและเก็บลง `messages.metadata` แล้วใช้เป็นแหล่งความจริงแทนการอ่านข้อความ — regex กลายเป็น fallback

**Tech Stack:** Laravel 13 (PHP 8.4), PHPUnit/Pest, PostgreSQL (Neon), Redis queue, OpenRouter (utility_model), Telegram Bot API, LINE Messaging API

## Global Constraints

- ทุก task ทำ TDD: เขียนเทสต์ที่ fail ก่อน → รันให้เห็น fail → เขียนโค้ดน้อยที่สุดให้ผ่าน → รันให้เห็น pass → commit
- รันเทสต์จาก `backend/`: `php artisan test --filter=<ClassOrMethod>`
- รัน Pint ก่อน commit: `./vendor/bin/pint --dirty`
- คอมเมนต์ในโค้ดเขียนภาษาไทยตามสไตล์ไฟล์เดิม อธิบาย "ทำไม" ไม่ใช่ "ทำอะไร"
- ห้าม log ค่า credential / เนื้อบัญชีที่ส่งลูกค้าเด็ดขาด
- ห้ามแก้โค้ดที่ไม่เกี่ยวกับ task (ดู CLAUDE.md §3 Surgical Changes)
- `Log::warning` บน production ถูกกลืนโดย `LOG_LEVEL` — อะไรที่ "เจ้าของต้องรู้" ต้องออกทาง Telegram ไม่ใช่ log
- เส้นทางที่แตะเรื่องเงิน 3 เส้นต้องได้พฤติกรรมเดียวกันเสมอ: `LineWebhookResponseService::trySlipVerification` (EasySlip auto), `SlipRetryService::emitSuccess` (auto-retry), `ManualPaymentConfirmService::confirm` (เจ้าของกดยืนยัน)
- ห้ามแก้ prompt ใน DB โดยไม่ backup ลง `flow_audit_logs` ก่อน และต้อง `cache:forget` หลังแก้เสมอ

---

## File Structure

**Phase A**
- Modify: `backend/app/Services/Payment/PaymentMessageDetector.php` — regex ชื่อสินค้า/ราคา×จำนวน + helper checksum
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:131-162` (`buildExpected`) — checksum guard + LLM re-read
- Modify: `backend/app/Services/Payment/SlipVerificationResult.php` — ฟิลด์ `itemsUnreliable`
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:574-584` — ไม่ dispatch reserve เมื่อ items เชื่อไม่ได้
- Modify: `backend/app/Services/Payment/SlipRetryService.php:100-108` — เหมือนกัน
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php:130-136` — เหมือนกัน
- Test: `backend/tests/Unit/Payment/PaymentMessageDetectorHardeningTest.php` (เพิ่ม Group D)
- Test: `backend/tests/Feature/Payment/OrderChecksumGuardTest.php` (ใหม่)

**Phase B**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:94-101` — cap แล้วต้องแจ้ง ไม่ใช่ log
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:261-293` (`cardText`) — โชว์ "สั่ง N จองได้ M"
- Test: `backend/tests/Feature/Delivery/QtyCapAlertTest.php` (ใหม่)

**Phase C**
- Create: `backend/app/Services/Payment/OrderPayloadExtractor.php` — ตัด/อ่านบล็อก `[[ORDER]]`
- Modify: `backend/app/Services/AIService.php:51-81` — sanitize ที่คอขวดเดียวของ text reply
- Modify: `backend/app/Services/AIService.php:100-123` — เก็บ payload ลง metadata
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php:372-382` — เก็บ payload ลง metadata
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:474-494` — history พก metadata
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php:170-187` — history พก metadata
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:87-121` — อ่าน payload ก่อน regex
- Modify: `backend/config/delivery.php` — flag `order_payload_enabled`
- Test: `backend/tests/Unit/Payment/OrderPayloadExtractorTest.php` (ใหม่)
- Test: `backend/tests/Feature/Payment/OrderPayloadEndToEndTest.php` (ใหม่)
- Modify (DB): `flows.system_prompt` id=24 + แถว backup ใน `flow_audit_logs`

---

# PHASE A — regex ทนฟอร์แมต + checksum กันพังเงียบ

### Task 1: [A1] regex อ่าน `(50 บาท x 20)` ได้ และชื่อสินค้าห้ามมีวงเล็บเปิดค้าง

**เหตุผล (เคสจริง 10 ส.ค. 2026, conversation #46 / order #1724):** โมเดลเขียน `1. G3D (50 บาท x 20) = 1,000 บาท` — กลุ่ม `(ราคา x จำนวน)` ของ regex เดิมไม่รองรับคำว่า "บาท" ในวงเล็บ ตัว `(.+?)` แบบ lazy จึงหยุดที่ "บาท" ตัวแรก ได้ชื่อสินค้า `G3D (` ราคา `50` และ **จำนวนหายทั้งหมด** → ลูกค้าจ่าย 1,000 ได้ของ 1 ตัวจาก 20

**Files:**
- Modify: `backend/app/Services/Payment/PaymentMessageDetector.php:78-104`
- Test: `backend/tests/Unit/Payment/PaymentMessageDetectorHardeningTest.php`

**Interfaces:**
- Consumes: ไม่มี (task แรก)
- Produces: `PaymentMessageDetector::parsePaymentData(string $text): ?array{items: array, total: string}` — พฤติกรรมเดิม แต่ items ได้ `qty` ถูกต้องกับฟอร์แมตวงเล็บที่มีหน่วย

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail (Group D ต่อท้ายไฟล์เดิม ก่อนปีกกาปิดคลาส)**

```php
    // ────────────────────────────────────────────────────────
    // Group D — ฟอร์แมตวงเล็บที่มีหน่วยปน (เคสจริง 2026-08-10)
    // ────────────────────────────────────────────────────────

    #[Test]
    public function test_d1_paren_with_baht_unit_keeps_qty(): void
    {
        // เคสจริง conversation #46 (10 ส.ค. 2026): "(50 บาท x 20)" ทำให้ชื่อกลายเป็น
        // "G3D (" ราคา 50 และ qty หาย → ระบบส่งบัญชีให้ลูกค้า 1 ตัวจาก 20 ที่จ่ายมา
        $text = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D (50 บาท x 20) = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(1, $data['items']);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
        $this->assertEquals('50', $data['items'][0]['price']);
        $this->assertEquals('1,000', $data['items'][0]['total']);
    }

    #[Test]
    public function test_d2_paren_with_baht_symbol_keeps_qty(): void
    {
        $text = "1. G3D (50฿ x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_d3_paren_with_unit_word_after_qty_keeps_qty(): void
    {
        $text = "1. G3D (50 x 20 ตัว) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('G3D', $data['items'][0]['name']);
        $this->assertEquals(20, $data['items'][0]['qty']);
    }

    #[Test]
    public function test_d4_paren_with_comma_price_and_baht_keeps_qty(): void
    {
        $text = "1. Nolimit Level Up+ BM (ผูกบัตร) (1,100 บาท x 2) = 2,200 บาท\nรวมยอดโอน: 2,200 บาท";

        $data = $this->detector->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertEquals('Nolimit Level Up+ BM (ผูกบัตร)', $data['items'][0]['name']);
        $this->assertEquals(2, $data['items'][0]['qty']);
        $this->assertEquals('1,100', $data['items'][0]['price']);
    }

    #[Test]
    public function test_d5_item_name_never_ends_with_open_paren(): void
    {
        // ตาข่ายกันตระกูลบั๊กนี้ทั้งหมด: ชื่อสินค้าที่มีวงเล็บเปิดค้าง = สัญญาณว่า regex
        // หยุดผิดที่ ต้องไม่มีทางเกิดได้ ไม่ว่าโมเดลจะแต่งประโยคยังไง
        $text = "1. G3D (50 บาท x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท";

        $data = $this->detector->parsePaymentData($text);

        foreach ($data['items'] as $item) {
            $this->assertStringNotContainsString('(', rtrim($item['name'], ')'));
        }
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=PaymentMessageDetectorHardeningTest`
Expected: D1-D5 FAIL (D1 จะฟ้องว่าได้ `'G3D ('` แทน `'G3D'`), A/B/C ทั้งหมด PASS

- [ ] **Step 3: แก้ regex — สองจุดพร้อมกัน (แก้จุดเดียวไม่พอ)**

ใน `PaymentMessageDetector.php` เพิ่ม const ใหม่ถัดจาก `UNIT_WORDS` (บรรทัด 41):

```php
    /**
     * ชื่อสินค้าในบรรทัดรายการ: อักขระทั่วไป หรือวงเล็บที่ "ปิดครบ" เท่านั้น
     * (เช่น "(ผูกบัตร)" ยังอยู่ในชื่อได้ตามเดิม) — ห้ามให้ชื่อจบด้วยวงเล็บเปิดค้าง
     * ไม่อย่างนั้น lazy match จะหยุดที่ "บาท" ตัวแรกในวงเล็บราคา แล้วกลืน qty หายทั้งก้อน
     * (เคสจริง 10 ส.ค. 2026: "1. G3D (50 บาท x 20)" → ชื่อ "G3D (" ราคา 50 qty หาย
     * → ลูกค้าจ่าย 1,000 ได้ของ 1 ตัวจาก 20)
     */
    private const ITEM_NAME = '((?:[^\n(]|\([^)\n]*\))+?)';

    /**
     * รูป "(ราคา x จำนวน)" ที่พรอมต์สั่งไว้ — ยอมให้มีหน่วยปนได้ทั้งฝั่งราคา ("50 บาท", "50฿")
     * และฝั่งจำนวน ("20 ตัว") เพราะโมเดลแชท drift ใส่หน่วยเองเป็นระยะ
     */
    private const PAREN_PRICE_QTY = '(?:\(\s*([\d,]+(?:\.\d+)?)\s*(?:บาท|฿)?\s*[x×]\s*(\d+)\s*(?:'.self::UNIT_WORDS.')?\s*\)\s*=\s*)?';
```

แล้วแทนที่ `preg_match_all` ตัวแรกใน `parseItems()` (บรรทัด 85-90) ด้วย:

```php
        preg_match_all(
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|[-•]\s*)'.self::ITEM_NAME.'\s*'
                .self::PAREN_PRICE_QTY.'(?:[:=\-]\s*)?([\d,]+(?:\.\d+)?)\s*บาท/u',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );
```

หมายเหตุ: ลำดับ capture group ไม่เปลี่ยน (1=name, 2=price, 3=qty, 4=total) โค้ดใต้ลงมาจึงไม่ต้องแก้

- [ ] **Step 4: รันเทสต์ทั้งไฟล์ให้ผ่านหมด**

Run: `cd backend && php artisan test --filter=PaymentMessageDetectorHardeningTest`
Expected: PASS ทุกตัว (A1-A6, B1-B4, C1-C4, D1-D5)

- [ ] **Step 5: รันเทสต์ที่เกี่ยวข้องทั้งหมด กัน regression**

Run: `cd backend && php artisan test --filter="SlipVerification|PaymentFlex|ConfirmMessageFallback|LLMOrderItemFallback"`
Expected: PASS ทั้งหมด

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/PaymentMessageDetector.php tests/Unit/Payment/PaymentMessageDetectorHardeningTest.php
git commit -m "fix(payment): อ่านจำนวนจากใบสรุปรูป \"(50 บาท x 20)\" ได้ — เคสส่งของ 1 จาก 20"
```

---

### Task 2: [A2] helper เช็คว่า "ผลรวมรายการ = ยอดโอน" ไหม

**Files:**
- Modify: `backend/app/Services/Payment/PaymentMessageDetector.php` (เพิ่ม static method ถัดจาก `isZeroPriceItem`, บรรทัด 147)
- Test: `backend/tests/Unit/Payment/PaymentMessageDetectorHardeningTest.php`

**Interfaces:**
- Consumes: `PaymentMessageDetector` จาก Task 1
- Produces: `PaymentMessageDetector::itemsMatchTotal(array $items, float $total, float $tolerance = 0.5): ?bool` — `true` = ผลรวมตรง, `false` = ไม่ตรง, `null` = ตรวจไม่ได้ (item บางตัวไม่มีราคาเป็นตัวเลข → ผู้เรียกต้อง fail-open)

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail (ต่อท้าย Group D)**

```php
    #[Test]
    public function test_d6_items_match_total_detects_broken_parse(): void
    {
        // เคสจริง 10 ส.ค.: parse ได้ item เดียวราคา 50 ขณะที่ยอดโอน 1,000 → ต่างกัน 20 เท่า
        $items = [['name' => 'G3D (', 'total' => '50']];

        $this->assertFalse(PaymentMessageDetector::itemsMatchTotal($items, 1000.0));
    }

    #[Test]
    public function test_d7_items_match_total_accepts_correct_sum(): void
    {
        $items = [
            ['name' => 'Nolimit Level Up+ BM', 'total' => '1,100', 'qty' => 1],
            ['name' => 'Page', 'total' => '199', 'qty' => 1],
        ];

        $this->assertTrue(PaymentMessageDetector::itemsMatchTotal($items, 1299.0));
    }

    #[Test]
    public function test_d8_items_match_total_counts_zero_price_freebies(): void
    {
        $items = [
            ['name' => 'Nolimit Level Up+ Personal', 'total' => '1,100', 'qty' => 1],
            ['name' => 'บริการเสริม Page', 'total' => '0'],
        ];

        $this->assertTrue(PaymentMessageDetector::itemsMatchTotal($items, 1100.0));
    }

    #[Test]
    public function test_d9_items_match_total_returns_null_when_price_missing(): void
    {
        // LLM คืน item โดยไม่ใส่ total → ตรวจไม่ได้ ต้องคืน null ให้ผู้เรียก fail-open
        // (ห้ามเดาว่าผิด ไม่งั้นออเดอร์ปกติจะโดนหยุดเพราะข้อมูลไม่ครบ)
        $items = [['name' => 'Page', 'total' => '']];

        $this->assertNull(PaymentMessageDetector::itemsMatchTotal($items, 199.0));
    }

    #[Test]
    public function test_d10_items_match_total_returns_null_for_empty_items(): void
    {
        $this->assertNull(PaymentMessageDetector::itemsMatchTotal([], 1000.0));
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=PaymentMessageDetectorHardeningTest`
Expected: D6-D10 FAIL ด้วย `Call to undefined method ...::itemsMatchTotal()`

- [ ] **Step 3: เขียน implementation**

เพิ่มใน `PaymentMessageDetector.php` ถัดจาก `isZeroPriceItem()`:

```php
    /**
     * ผลรวมราคารายการ = ยอดโอนไหม — ตาข่ายจับ "parse เพี้ยน" แบบไม่ต้องรู้จักฟอร์แมตล่วงหน้า
     *
     * เกิดขึ้นจริงเมื่อ regex หยุดผิดที่แล้วได้ item ขยะ (10 ส.ค. 2026: item เดียวราคา 50
     * ขณะยอดโอน 1,000) ซึ่งด่านอื่นมองไม่เห็นเลยเพราะ "ยอดโอนตรงกับสลิป" อยู่แล้ว
     *
     * คืน null = ตรวจไม่ได้ (ไม่มีรายการ / ราคาบางตัวไม่ใช่ตัวเลข) — ผู้เรียกต้อง fail-open
     * ห้ามตีเป็น "ไม่ตรง" เด็ดขาด ไม่อย่างนั้นออเดอร์ปกติที่ LLM ไม่คืนราคาจะถูกหยุดทิ้ง
     *
     * @param  array<int, array{name: string, total?: string, qty?: int}>  $items
     */
    public static function itemsMatchTotal(array $items, float $total, float $tolerance = 0.5): ?bool
    {
        if ($items === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($items as $item) {
            $value = str_replace(',', '', (string) ($item['total'] ?? ''));
            if (! is_numeric($value)) {
                return null;
            }
            $sum += (float) $value;
        }

        return abs($sum - $total) <= $tolerance;
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=PaymentMessageDetectorHardeningTest`
Expected: PASS ทุกตัว

- [ ] **Step 5: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/PaymentMessageDetector.php tests/Unit/Payment/PaymentMessageDetectorHardeningTest.php
git commit -m "feat(payment): helper เช็คผลรวมรายการเทียบยอดโอน (ตาข่ายจับ parse เพี้ยน)"
```

---

### Task 3: [A3] checksum ไม่ผ่าน → ให้ LLM อ่านซ้ำ (แม้ items ไม่ว่าง)

**เหตุผล:** ตอนนี้ LLM fallback (`buildExpected` บรรทัด 141-144) ถูกเรียกเฉพาะตอน `items === []` เท่านั้น — เคส 10 ส.ค. parse ได้ "ขยะ 1 ชิ้น" ระบบจึงคิดว่าสำเร็จ ไม่เรียกใครช่วยเลย

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:131-162` (`buildExpected`)
- Test: `backend/tests/Feature/Payment/OrderChecksumGuardTest.php` (สร้างใหม่)

**Interfaces:**
- Consumes: `PaymentMessageDetector::itemsMatchTotal()` จาก Task 2
- Produces: `buildExpected()` คืน `array{total: float, summary: string, items: array, items_unreliable: bool}` — คีย์ `items_unreliable` เป็นของใหม่ที่ Task 4 ใช้ต่อ

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail**

สร้าง `backend/tests/Feature/Payment/OrderChecksumGuardTest.php`:

```php
<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\OrderReconstructor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตาข่าย checksum: ผลรวมรายการต้องเท่ายอดโอน ไม่งั้นถือว่า parse เชื่อไม่ได้
 * → ให้ LLM อ่านซ้ำ → ยังไม่ตรงอีก = ห้ามส่งของเอง (Task 3/4)
 */
class OrderChecksumGuardTest extends TestCase
{
    use RefreshDatabase;

    /** ใบสรุปที่ regex อ่านเพี้ยน: ได้ item ราคา 50 ขณะยอดโอน 1,000 (สร้างจากฟอร์แมตสมมติที่ยังไม่รองรับ) */
    private const BROKEN_SUMMARY = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D ราคา 50 บาท ต่อชิ้น รวม 20 ชิ้น\n\nรวมยอดโอน: 1,000 บาท ✅\nโอนเข้าบัญชี 223-3-24880-3";

    private function history(string $summary): array
    {
        return [
            ['sender' => 'user', 'content' => 'ซื้อเฟสไก่ 20 ครับ'],
            ['sender' => 'bot', 'content' => $summary],
        ];
    }

    private function makeBot(): Bot
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'or-key-123']);

        return Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'openai/gpt-4o-mini',
        ]);
    }

    private function service(OpenRouterService $openRouter): SlipVerificationService
    {
        return new SlipVerificationService(
            new PaymentMessageDetector,
            new TelegramAlertBotService,
            new LLMOrderItemExtractor($openRouter),
            new OrderReconstructor($openRouter),
        );
    }

    public function test_calls_llm_when_item_sum_does_not_match_total(): void
    {
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"G3D","qty":20,"total":"1000"}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::BROKEN_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['total']);
        $this->assertSame(20, $result['items'][0]['qty']);
        $this->assertSame('G3D x20', $result['summary']);
        $this->assertFalse($result['items_unreliable']);
    }

    public function test_marks_items_unreliable_when_llm_also_fails_checksum(): void
    {
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"G3D","qty":1,"total":"50"}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::BROKEN_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['total'], 'ยอดโอนต้องยังใช้ยืนยันเงินได้');
        $this->assertTrue($result['items_unreliable'], 'รายการเชื่อไม่ได้ → ห้ามเอาไปส่งของเอง');
    }

    public function test_does_not_call_llm_when_checksum_passes(): void
    {
        $bot = $this->makeBot();
        $good = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D (50 บาท x 20) = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅\nโอนเข้าบัญชี 223-3-24880-3";

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history($good), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(20, $result['items'][0]['qty']);
        $this->assertFalse($result['items_unreliable']);
    }

    public function test_fail_open_when_checksum_cannot_be_computed(): void
    {
        // ราคาต่อรายการอ่านไม่ได้ (itemsMatchTotal คืน null) → ต้องไม่เรียก LLM และไม่ตีว่าเชื่อไม่ได้
        $bot = $this->makeBot();
        $noPrices = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3";

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history($noPrices), null, $bot);

        $this->assertNotNull($result);
        $this->assertFalse($result['items_unreliable']);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=OrderChecksumGuardTest`
Expected: FAIL — `Undefined array key "items_unreliable"` และเคสแรกจะฟ้องว่า LLM ไม่ถูกเรียก

- [ ] **Step 3: แก้ `buildExpected()`**

แทนที่ทั้งเมธอด `buildExpected` (บรรทัด 131-162) ด้วย:

```php
    private function buildExpected(array $data, string $content, ?Bot $bot, bool $requireItems = false): ?array
    {
        $items = array_map(function (array $item) {
            $item['name'] = rtrim(trim($item['name']), '= ');

            return $item;
        }, $data['items']);

        $total = (float) str_replace(',', '', $data['total']);
        $llmEnabled = $bot !== null && config('delivery.llm_item_fallback_enabled', true);

        // ชั้น 2 fallback เรียกเมื่อ regex ให้ผลที่ "เชื่อไม่ได้" 2 แบบ:
        //   (ก) ไม่ได้ items เลย (prose ล้วน / หลายสินค้าบรรทัดเดียว)
        //   (ข) ได้ items แต่ผลรวมไม่เท่ายอดโอน = regex หยุดผิดที่ (เคส 10 ส.ค. 2026:
        //       ได้ item เดียวราคา 50 ขณะยอดโอน 1,000 → qty หาย ส่งของ 1 จาก 20)
        // เดิมเช็คแค่ (ก) เคส (ข) จึงผ่านฉลุยทั้งที่ข้อมูลพัง
        $checksum = PaymentMessageDetector::itemsMatchTotal($items, $total);
        if ($llmEnabled && ($items === [] || $checksum === false)) {
            $llmItems = $this->itemExtractor->extract($content, $bot);
            if ($llmItems !== []) {
                $llmChecksum = PaymentMessageDetector::itemsMatchTotal($llmItems, $total);
                // ยอมรับผล LLM เมื่อดีขึ้นเท่านั้น — ตรงยอด หรือ ตรวจไม่ได้ทั้งที่ของเดิมผิดชัด
                if ($llmChecksum !== false) {
                    $items = $llmItems;
                    $checksum = $llmChecksum;
                }
            }
        }

        if ($items === [] && $requireItems) {
            return null;
        }

        // ตัดของแถมราคา 0 ออกจาก summary กันชื่อหลุดไปข้อความยืนยัน/Telegram/order_items —
        // แต่คืน 'items' เต็มชุดให้ delivery กรอง+log เองอีกชั้น
        $visibleItems = array_filter($items, fn (array $item) => ! PaymentMessageDetector::isZeroPriceItem($item));
        // summary เป็นต้นทางเดียวของจำนวนที่ไหลไปข้อความยืนยัน การ์ด Telegram และ order_items
        // (parseProductItems อ่านรูป "ชื่อ xN") รูปแบบรวมศูนย์ที่ formatItemSummary — ทิ้ง qty ที่นี่
        // = ออเดอร์ 2 ชุดถูกบันทึกเป็น 1 เงียบๆ

        return [
            'total' => $total,
            'summary' => $visibleItems === [] ? '-' : PaymentMessageDetector::formatItemSummary($visibleItems),
            'items' => $items,
            // true = ผลรวมรายการขัดกับยอดโอนแม้ให้ LLM อ่านซ้ำแล้ว → ห้ามส่งของอัตโนมัติ (Task 4)
            'items_unreliable' => $checksum === false,
        ];
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=OrderChecksumGuardTest`
Expected: PASS ทั้ง 4 เคส

- [ ] **Step 5: รันเทสต์ payment/delivery ทั้งหมด กัน regression**

Run: `cd backend && php artisan test --filter="Payment|Slip|Delivery"`
Expected: PASS ทั้งหมด

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/SlipVerificationService.php tests/Feature/Payment/OrderChecksumGuardTest.php
git commit -m "feat(payment): ผลรวมรายการไม่ตรงยอดโอน → ให้ LLM อ่านซ้ำ แทนเชื่อผลที่พังเงียบ"
```

---

### Task 4: [A4] รายการเชื่อไม่ได้ → รับเงินตามปกติ แต่ห้ามส่งของเอง

**เหตุผล:** ยอดโอนตรงสลิป = เงินเข้าจริง ลูกค้าต้องได้ข้อความยืนยันเหมือนเดิม แต่ "จะส่งอะไรกี่ชิ้น" ยังไม่รู้ → ต้องหยุดที่เจ้าของ ไม่ใช่เดาแล้วส่ง

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationResult.php`
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:371-377` (return path สุดท้ายของ `verify`) และ `418-494` (`notifyAdmin`)
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:574-584`
- Modify: `backend/app/Services/Payment/SlipRetryService.php:100-108`
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php:130-136`
- Test: `backend/tests/Feature/Payment/OrderChecksumGuardTest.php` (เพิ่มเคส)

**Interfaces:**
- Consumes: `buildExpected()['items_unreliable']` จาก Task 3
- Produces: `SlipVerificationResult->itemsUnreliable: bool` (public readonly, default false) — ผู้เรียกทั้ง 3 เส้นทางใช้ตัดสินว่าจะ dispatch `ReserveAccountStock` หรือไม่

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail (ต่อท้าย OrderChecksumGuardTest)**

```php
    public function test_result_carries_items_unreliable_flag(): void
    {
        $result = new \App\Services\Payment\SlipVerificationResult(
            isSlip: true,
            passed: true,
            amount: 1000.0,
            orderSummary: '-',
            orderItems: [['name' => 'G3D (', 'total' => '50']],
            itemsUnreliable: true,
        );

        $this->assertTrue($result->itemsUnreliable);
    }

    public function test_result_defaults_items_unreliable_to_false(): void
    {
        $result = new \App\Services\Payment\SlipVerificationResult(isSlip: true, passed: true);

        $this->assertFalse($result->itemsUnreliable);
    }
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderChecksumGuardTest`
Expected: FAIL — `Unknown named parameter $itemsUnreliable`

- [ ] **Step 3: เพิ่มฟิลด์ใน `SlipVerificationResult`**

เพิ่มพารามิเตอร์ท้ายสุดของ constructor (หลัง `$reconstruction`):

```php
        /** true = ผลรวมรายการขัดกับยอดโอน — รับเงินได้ แต่ห้ามส่งของอัตโนมัติ */
        public readonly bool $itemsUnreliable = false,
```

- [ ] **Step 4: ส่งค่าจาก `verify()` (SlipVerificationService บรรทัด 371-377)**

แทน return สุดท้ายด้วย:

```php
        return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
            isSlip: true, passed: true,
            amount: $slipAmount, transRef: $transRef,
            expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            orderItems: $expected['items'],
            orderSource: $orderSource, reconstruction: $reconstruction,
            // ยอดตรง = เงินเข้าจริง (ลูกค้าได้ข้อความยืนยันตามปกติ) แต่ "จะส่งอะไรกี่ชิ้น"
            // ยังไม่รู้ → ปลายทางต้องไม่จองสต๊อกเอง ให้เจ้าของกดจากการ์ดแทน
            itemsUnreliable: $expected['items_unreliable'] ?? false,
        ), $receiverAccount);
```

- [ ] **Step 5: เพิ่มเทสต์ว่าเส้นทางส่งของถูกหยุด**

ต่อท้ายไฟล์เดิม:

```php
    public function test_reserve_job_not_dispatched_when_items_unreliable(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $bot = $this->makeBot();
        $bot->update(['auto_delivery_enabled' => true]);
        $conversation = \App\Models\Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => 'line',
        ]);
        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1000,
            'status' => 'passed',
        ]);

        $result = new \App\Services\Payment\SlipVerificationResult(
            isSlip: true, passed: true, amount: 1000.0,
            orderSummary: '-', orderItems: [['name' => 'G3D (', 'total' => '50']],
            itemsUnreliable: true,
        );
        $result->slipVerificationId = $slip->id;

        \App\Jobs\ReserveAccountStock::dispatchIfItemsTrusted($bot->id, $conversation->id, $result);

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_reserve_job_dispatched_when_items_trusted(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $bot = $this->makeBot();
        $bot->update(['auto_delivery_enabled' => true]);
        $conversation = \App\Models\Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => 'line',
        ]);
        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1000,
            'status' => 'passed',
        ]);

        $result = new \App\Services\Payment\SlipVerificationResult(
            isSlip: true, passed: true, amount: 1000.0,
            orderSummary: 'G3D x20', orderItems: [['name' => 'G3D', 'total' => '1000', 'qty' => 20]],
        );
        $result->slipVerificationId = $slip->id;

        \App\Jobs\ReserveAccountStock::dispatchIfItemsTrusted($bot->id, $conversation->id, $result);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ReserveAccountStock::class);
    }
```

- [ ] **Step 6: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderChecksumGuardTest`
Expected: FAIL — `Call to undefined method ...ReserveAccountStock::dispatchIfItemsTrusted()`

- [ ] **Step 7: เพิ่ม `dispatchIfItemsTrusted` ใน `ReserveAccountStock`**

เพิ่มเมธอดต่อจาก `dispatchSafely` ใน `backend/app/Jobs/ReserveAccountStock.php`:

```php
    /**
     * ประตูเดียวของทั้ง 3 เส้นทางที่ยืนยันเงินแล้วจะจองสต๊อก (EasySlip auto / auto-retry /
     * เจ้าของกดยืนยัน) — กติกา "รายการเชื่อไม่ได้ = ห้ามส่งของเอง" เขียนที่นี่ที่เดียว
     * ไม่งั้นเพิ่มเส้นทางที่สี่วันหลังแล้วลืมเช็ค = กลับไปส่งของผิดจำนวนเงียบอีก
     */
    public static function dispatchIfItemsTrusted(int $botId, int $conversationId, SlipVerificationResult $result): void
    {
        if ($result->slipVerificationId === null) {
            return;
        }

        if ($result->itemsUnreliable) {
            Log::warning('Delivery: skipped auto-reserve — order items failed checksum', [
                'conversation_id' => $conversationId,
                'slip_verification_id' => $result->slipVerificationId,
                'amount' => $result->amount,
            ]);

            return;
        }

        self::dispatchSafely($botId, $conversationId, $result->slipVerificationId, $result->amount, $result->orderItems ?? []);
    }
```

เพิ่ม `use App\Services\Payment\SlipVerificationResult;` ที่หัวไฟล์

- [ ] **Step 8: เปลี่ยนผู้เรียกทั้ง 3 เส้นทางให้ผ่านประตูเดียวกัน**

`LineWebhookResponseService.php` (บรรทัด 574-584) แทนบล็อก `if ($result->passed && $result->slipVerificationId !== null) { ReserveAccountStock::dispatchSafely(...); }` ด้วย:

```php
            if ($result->passed) {
                // dispatch พังห้ามหลุดไปโดน catch ใหญ่ (จะกลายเป็น fallback vision ทั้งที่ตอบสำเร็จไปแล้ว)
                // การจองที่พลาดมี delivery:reconcile เก็บตกทีหลัง + เจ้าของส่งเองได้
                ReserveAccountStock::dispatchIfItemsTrusted($ctx->bot->id, $ctx->conversation->id, $result);
            }
```

`SlipRetryService.php` (บรรทัด 100-108) แทนด้วย:

```php
        ReserveAccountStock::dispatchIfItemsTrusted($bot->id, $conversation->id, $result);
```

`ManualPaymentConfirmService.php` (บรรทัด 130-136): เส้นทางนี้ไม่มี `SlipVerificationResult` — เจ้าของกดยืนยันเองแล้ว ถือว่ารายการที่ระบบเดามาก็ยังต้องผ่านกติกาเดียวกัน แทนบล็อก dispatch เดิมด้วย:

```php
        // เจ้าของกดเลือกรายการเอง (itemsOverride) = เชื่อได้เสมอ; ถ้าเดาจากข้อความแล้วผลรวม
        // ขัดกับยอด ต้องไม่จองเอง — ให้เจ้าของกดเลือกจากการ์ดแทน (กติกาเดียวกับเส้นทาง EasySlip)
        $trusted = $itemsOverride !== null && $itemsOverride !== []
            ? true
            : ! ($expected['items_unreliable'] ?? false);

        if ($trusted) {
            ReserveAccountStock::dispatchSafely(
                $bot->id,
                $conversation->id,
                $slip->id,
                $amount,
                $expected['items'] ?? [],
            );
        } else {
            Log::warning('Manual confirm: skipped auto-reserve — order items failed checksum', [
                'conversation_id' => $conversation->id,
                'amount' => $amount,
            ]);
        }
```

- [ ] **Step 9: ให้เจ้าของรู้ — การ์ด Telegram ต้องออกเมื่อรายการเชื่อไม่ได้**

ใน `SlipVerificationService::notifyIfAutoReconstructed()` (บรรทัด 502-507) แทนเงื่อนไขด้วย:

```php
    public function notifyIfAutoReconstructed(Bot $bot, ?Conversation $conversation, SlipVerificationResult $result): void
    {
        // รายการเชื่อไม่ได้ = ระบบไม่ได้ส่งของให้ ต้องแจ้งเสมอ ไม่งั้นออเดอร์ค้างเงียบ
        // (log ไม่ช่วย — LOG_LEVEL บน prod กลืน warning ทิ้ง)
        if ($result->orderSource === 'llm' || $result->itemsUnreliable) {
            $this->notifyAdmin($bot, $conversation, $result);
        }
    }
```

และใน `notifyAdmin()` เพิ่มบรรทัดเตือนก่อนบรรทัดสรุปท้าย (ก่อน `$lines[] = $result->passed ? ... : ...;` บรรทัด 488):

```php
        if ($result->itemsUnreliable) {
            $lines[] = '⚠️ <b>ระบบอ่านจำนวนสินค้าไม่ชัด — ยังไม่ได้ส่งของ</b> รบกวนเปิดแชทเช็คจำนวนแล้วส่งเอง';
        }
```

- [ ] **Step 10: รันเทสต์ทั้งชุด**

Run: `cd backend && php artisan test --filter="OrderChecksumGuard|SlipVerification|ManualPaymentConfirm|SlipRetry"`
Expected: PASS ทั้งหมด

- [ ] **Step 11: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/ app/Jobs/ReserveAccountStock.php app/Services/LineWebhook/LineWebhookResponseService.php tests/Feature/Payment/OrderChecksumGuardTest.php
git commit -m "feat(delivery): รายการที่ผลรวมขัดกับยอดโอน — รับเงินได้ แต่ไม่ส่งของเอง + แจ้งเจ้าของ"
```

---

# PHASE B — ปิดรูรั่ว "ลดจำนวนเงียบ" รอบข้าง

### Task 5: [B1] qty ถูก cap แล้วต้องแจ้ง Telegram ไม่ใช่แค่ log

**เหตุผล:** `config('delivery.max_qty')` = 20 (ไม่มี `ACCOUNT_DELIVERY_MAX_QTY` ตั้งบน Railway จึงใช้ค่า default) ⇒ ลูกค้าสั่ง 30 ระบบจอง 20 แล้วเงียบ มีแค่ `Log::warning` ซึ่ง `LOG_LEVEL` บน prod กลืนทิ้ง

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:81-101` และ `261-293` (`cardText`)
- Test: `backend/tests/Feature/Delivery/QtyCapAlertTest.php` (สร้างใหม่)

**Interfaces:**
- Consumes: ไม่มี (อิสระจาก Phase A)
- Produces: `AccountDeliveryService::createFromPayment()` — พฤติกรรมเดิม แต่เมื่อมีการ cap การ์ด Telegram จะมีบรรทัด `⚠️ ลูกค้าสั่ง N แต่ระบบจองให้ได้ M`

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail**

สร้าง `backend/tests/Feature/Delivery/QtyCapAlertTest.php`:

```php
<?php

namespace Tests\Feature\Delivery;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * qty ที่เกินเพดาน (delivery.max_qty) ต้องปรากฏบนการ์ด Telegram —
 * log อย่างเดียวไม่พอ เพราะ LOG_LEVEL บน prod กลืน warning ทิ้ง
 */
class QtyCapAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_text_shows_capped_quantity_warning(): void
    {
        config(['delivery.enabled' => true, 'delivery.max_qty' => 20]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'auto_delivery_enabled' => true]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);

        // slip_verification_id เป็น NOT NULL + unique (invariant ที่ createFromPayment ใช้กัน
        // webhook ซ้ำ) — ต้องสร้างแถวจริงก่อน ตาม pattern ที่ AccountDeliverySendCardTest ใช้อยู่
        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1500,
            'status' => 'passed',
        ]);

        $delivery = AccountDelivery::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1500,
        ]);
        $delivery->items()->create([
            'product_name' => 'G3D',
            'stock_code' => 'G3D',
            'kind' => \App\Models\AccountDeliveryItem::KIND_STOCK,
            'qty' => 1,
            'status' => \App\Models\AccountDeliveryItem::ST_RESERVED,
            'requested_qty' => 30,
        ]);

        $text = app(AccountDeliveryService::class)->cardTextForTesting($delivery);

        $this->assertStringContainsString('30', $text);
        $this->assertStringContainsString('เกินเพดาน', $text);
    }
}
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=QtyCapAlertTest`
Expected: FAIL — คอลัมน์ `requested_qty` ไม่มี และ `cardTextForTesting` ไม่มี

- [ ] **Step 3: เพิ่มคอลัมน์ `requested_qty` ลง `account_delivery_items`**

สร้าง migration:

```bash
cd backend && php artisan make:migration add_requested_qty_to_account_delivery_items_table
```

เนื้อใน migration:

```php
    public function up(): void
    {
        Schema::table('account_delivery_items', function (Blueprint $table) {
            // จำนวนที่ลูกค้าสั่งจริง — ต่างจาก qty (จำนวนที่ระบบจองให้จริง) เมื่อชน
            // เพดาน delivery.max_qty; เก็บไว้เพื่อให้การ์ดบอกเจ้าของได้ว่าขาดไปเท่าไร
            $table->unsignedInteger('requested_qty')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('account_delivery_items', function (Blueprint $table) {
            $table->dropColumn('requested_qty');
        });
    }
```

รัน: `cd backend && php artisan migrate`

เพิ่ม `'requested_qty'` เข้า `$fillable` ของ `backend/app/Models/AccountDeliveryItem.php`

- [ ] **Step 4: บันทึก requested_qty ตอนสร้าง item + เพิ่ม cardTextForTesting**

ใน `AccountDeliveryService::createFromPayment()` แทนบล็อก cap (บรรทัด 94-101) ด้วย:

```php
            $rawQty = max(1, (int) ($item['qty'] ?? 1));
            $qty = min($maxQty, $rawQty);
            // เกินเพดานแล้ว "จองให้น้อยกว่าที่สั่ง" — เดิมบอกแค่ผ่าน Log::warning ซึ่ง
            // LOG_LEVEL บน prod กลืนทิ้ง เจ้าของจึงไม่มีทางรู้ว่าลูกค้ายังขาดของ
            // จากนี้ผูกไว้กับ item เพื่อให้ขึ้นหน้าการ์ด Telegram (cardText)
            $requestedQty = $qty < $rawQty ? $rawQty : null;
```

แล้วใน `$delivery->items()->create([...])` ทั้ง 3 จุด (unmapped / support_link / stock) เพิ่มคีย์ `'requested_qty' => $requestedQty,`
สำหรับ loop stock (บรรทัด 123-132) ให้ใส่ `requested_qty` เฉพาะรอบแรก: `'requested_qty' => $u === 0 ? $requestedQty : null,`

เพิ่มเมธอด public บางๆ ต่อจาก `cardText()` เพื่อให้เทสต์เรียกได้โดยไม่ต้องยิง Telegram:

```php
    /** ทางเข้าสำหรับเทสต์เท่านั้น — cardText เป็น private โดยตั้งใจ (ห้ามผู้เรียกอื่นประกอบการ์ดเอง) */
    public function cardTextForTesting(AccountDelivery $delivery): string
    {
        return $this->cardText($delivery);
    }
```

- [ ] **Step 5: ให้ cardText แสดงคำเตือน**

ใน `cardText()` เพิ่มก่อน `$lines[] = '<blockquote>...` (บรรทัด 285-287):

```php
        $capped = $delivery->items->whereNotNull('requested_qty');
        foreach ($capped as $item) {
            $name = TelegramAlertBotService::esc($item->product_name);
            $reserved = $delivery->items->where('product_name', $item->product_name)->count();
            $lines[] = "⚠️ <b>{$name}: ลูกค้าสั่ง {$item->requested_qty} แต่จองได้ {$reserved}</b> (เกินเพดานระบบ) — ส่วนที่เหลือต้องส่งเอง";
        }
```

- [ ] **Step 6: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=QtyCapAlertTest`
Expected: PASS

- [ ] **Step 7: รันเทสต์ delivery ทั้งหมด**

Run: `cd backend && php artisan test --filter=Delivery`
Expected: PASS ทั้งหมด

- [ ] **Step 8: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Delivery/AccountDeliveryService.php app/Models/AccountDeliveryItem.php database/migrations tests/Feature/Delivery/QtyCapAlertTest.php
git commit -m "feat(delivery): จำนวนที่ถูก cap ขึ้นการ์ด Telegram แทนหายไปใน log ที่ไม่มีใครเห็น"
```

---

### Task 6: [B2] ตั้งเพดานจริงบน production

**Files:** ไม่มีไฟล์ — เป็น ops step ที่ต้องทำหลัง B1 deploy

**Interfaces:**
- Consumes: `config('delivery.max_qty')` จาก B1
- Produces: ค่า env บน Railway service `backend`

- [ ] **Step 1: ถามเจ้าของว่าเพดานต่อรายการควรเป็นเท่าไร**

คำถามที่ต้องได้คำตอบ: "ออเดอร์ G3D ก้อนใหญ่สุดที่เคยขายจริงคือกี่ตัว และอยากให้ระบบจองอัตโนมัติได้สูงสุดกี่ตัวต่อรายการ" (ค่าปัจจุบันคือ 20 ซึ่ง**ไม่เคยถูกตั้งใจเลือก** — เป็น default ในโค้ด)

- [ ] **Step 2: ตั้งค่าบน Railway**

```bash
railway variables --set "ACCOUNT_DELIVERY_MAX_QTY=<ค่าที่เจ้าของเลือก>" --service backend
```

- [ ] **Step 3: ยืนยันว่าค่าใหม่ทำงาน**

```bash
railway ssh --service backend "php artisan tinker --execute='echo config(\"delivery.max_qty\");'"
```
Expected: พิมพ์ค่าที่ตั้งไว้

---

# PHASE C — เลิกให้ LLM เป็นเจ้าของตัวเลข

**หลักการ:** บอทปล่อยข้อมูลออเดอร์เป็น JSON ต่อท้ายข้อความสรุปยอด ระบบตัดออกก่อนบันทึก/ส่ง แล้วเก็บลง `messages.metadata.order_payload` — เส้นทางเงินอ่านจาก payload ก่อน ถ้าไม่มีค่อยถอยไปใช้ regex เดิม ⇒ ข้อความที่ลูกค้าเห็น กับจำนวนที่ระบบส่งของ มาจากตัวเลขชุดเดียวกัน

### Task 7: [C1] OrderPayloadExtractor — ตัดบล็อกออกจากข้อความ + อ่าน JSON

**Files:**
- Create: `backend/app/Services/Payment/OrderPayloadExtractor.php`
- Test: `backend/tests/Unit/Payment/OrderPayloadExtractorTest.php`

**Interfaces:**
- Consumes: ไม่มี
- Produces: `OrderPayloadExtractor::extract(string $content): array{clean: string, payload: ?array{items: array<int, array{name: string, qty: int, total: string}>, total: float}}` — `payload` เป็น null เมื่อไม่มีบล็อก/JSON เสีย/รูปร่างไม่ถูก

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail**

สร้าง `backend/tests/Unit/Payment/OrderPayloadExtractorTest.php`:

```php
<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\OrderPayloadExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderPayloadExtractorTest extends TestCase
{
    private OrderPayloadExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new OrderPayloadExtractor;
    }

    #[Test]
    public function test_extracts_payload_and_strips_block_from_text(): void
    {
        $content = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D (50 x 20) = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅\n"
            .'[[ORDER]]{"items":[{"name":"G3D","qty":20,"price":50}],"total":1000}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
        $this->assertStringNotContainsString('G3D","qty"', $result['clean']);
        $this->assertStringEndsWith('รวมยอดโอน: 1,000 บาท ✅', trim($result['clean']));
        $this->assertNotNull($result['payload']);
        $this->assertSame(1000.0, $result['payload']['total']);
        $this->assertSame([['name' => 'G3D', 'qty' => 20, 'total' => '1000']], $result['payload']['items']);
    }

    #[Test]
    public function test_returns_null_payload_when_no_block(): void
    {
        $content = 'สวัสดีครับ สนใจตัวไหนดีครับ';

        $result = $this->extractor->extract($content);

        $this->assertSame($content, $result['clean']);
        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_strips_block_even_when_json_is_broken(): void
    {
        // JSON เสียก็ต้องไม่หลุดไปถึงลูกค้าเด็ดขาด — ตัดทิ้งแล้วถอยไปใช้ regex เดิม
        $content = "รวมยอดโอน: 1,000 บาท\n[[ORDER]]{\"items\":[{\"name\":\"G3D\",[[/ORDER]]";

        $result = $this->extractor->extract($content);

        $this->assertStringNotContainsString('[[ORDER]]', $result['clean']);
        $this->assertStringNotContainsString('items', $result['clean']);
        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_rejects_payload_whose_items_do_not_sum_to_total(): void
    {
        // ตาข่ายเดียวกับ Phase A: payload ที่ตัวเลขไม่สอดคล้องกันเอง = เชื่อไม่ได้
        $content = 'รวมยอดโอน: 1,000 บาท'
            .'[[ORDER]]{"items":[{"name":"G3D","qty":1,"price":50}],"total":1000}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNull($result['payload']);
    }

    #[Test]
    public function test_computes_item_total_from_price_times_qty(): void
    {
        $content = '[[ORDER]]{"items":[{"name":"Nolimit Level Up+ BM","qty":2,"price":1100}],"total":2200}[[/ORDER]]';

        $result = $this->extractor->extract($content);

        $this->assertNotNull($result['payload']);
        $this->assertSame('2200', $result['payload']['items'][0]['total']);
        $this->assertSame(2, $result['payload']['items'][0]['qty']);
    }

    #[Test]
    public function test_rejects_payload_with_empty_or_missing_items(): void
    {
        $content = '[[ORDER]]{"items":[],"total":1000}[[/ORDER]]';

        $this->assertNull($this->extractor->extract($content)['payload']);
    }
}
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderPayloadExtractorTest`
Expected: FAIL — คลาสไม่มี

- [ ] **Step 3: เขียน `OrderPayloadExtractor`**

สร้าง `backend/app/Services/Payment/OrderPayloadExtractor.php`:

```php
<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Log;

/**
 * ช่องทางข้อมูลออเดอร์แบบมีโครงสร้างที่บอทปล่อยมาพร้อมข้อความสรุปยอด
 *
 * ที่มา: จำนวนสินค้าเคยเดินทางผ่าน "ประโยคที่ LLM แต่งเอง" อย่างเดียว แล้วให้ regex
 * อ่านกลับ — โมเดล drift ฟอร์แมตเมื่อไร จำนวนก็หายเมื่อนั้น (17 ก.ค. 2026 "G3D x20",
 * 10 ส.ค. 2026 "(50 บาท x 20)" ลูกค้าจ่าย 1,000 ได้ของ 1 จาก 20)
 *
 * บล็อกนี้ถูกตัดออกก่อนบันทึกข้อความและก่อนส่งออกทุกช่องทาง ลูกค้าจึงไม่มีวันเห็น
 */
class OrderPayloadExtractor
{
    /** ตัวคั่นที่เลือกให้ไม่ชนกับ "|||" (ตัวแบ่ง bubble) และไม่โผล่ในภาษาไทยปกติ */
    private const PATTERN = '/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/su';

    /**
     * @return array{clean: string, payload: ?array{items: array<int, array{name: string, qty: int, total: string}>, total: float}}
     */
    public function extract(string $content): array
    {
        if (! preg_match(self::PATTERN, $content, $match)) {
            return ['clean' => $content, 'payload' => null];
        }

        // ตัดก่อนเสมอ ไม่ว่าจะ decode ได้หรือไม่ — JSON เสียห้ามหลุดถึงลูกค้า
        $clean = trim((string) preg_replace(self::PATTERN, '', $content));

        return ['clean' => $clean, 'payload' => $this->decode($match[1])];
    }

    /**
     * @return array{items: array<int, array{name: string, qty: int, total: string}>, total: float}|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode(trim($json), true);
        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items']) || $decoded['items'] === []) {
            Log::info('OrderPayload: block present but unusable', ['len' => mb_strlen($json)]);

            return null;
        }

        $total = (float) ($decoded['total'] ?? 0);
        $items = [];
        foreach ($decoded['items'] as $item) {
            if (! is_array($item) || empty($item['name']) || ! is_string($item['name'])) {
                return null;
            }
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $price = (float) ($item['price'] ?? 0);
            $items[] = [
                'name' => trim($item['name']),
                'qty' => $qty,
                // เก็บเป็น string ให้เข้ารูปเดียวกับ items ที่มาจาก regex (ผู้ใช้ปลายทางเดียวกัน)
                'total' => (string) ($price * $qty),
            ];
        }

        // payload ที่ตัวเลขไม่สอดคล้องกันเอง = โมเดลคำนวณพลาด เชื่อไม่ได้ ถอยไปใช้ regex
        if (PaymentMessageDetector::itemsMatchTotal($items, $total) === false) {
            Log::warning('OrderPayload: rejected — items do not sum to total', ['total' => $total]);

            return null;
        }

        return ['items' => $items, 'total' => $total];
    }
}
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=OrderPayloadExtractorTest`
Expected: PASS ทั้ง 6 เคส

- [ ] **Step 5: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/OrderPayloadExtractor.php tests/Unit/Payment/OrderPayloadExtractorTest.php
git commit -m "feat(payment): ตัวอ่าน/ตัดบล็อกออเดอร์แบบมีโครงสร้างจากข้อความบอท"
```

---

### Task 8: [C2] เสียบ extractor ที่คอขวดเดียวของ text reply

**เหตุผล:** `AIService::generateResponse()` เป็นจุดที่ทั้ง 2 เส้นทางข้อความ (webhook pipeline ผ่าน `generateAndSaveResponse`, และ `ProcessAggregatedMessages` ที่เรียกตรง) ผ่านเสมอ — sanitize ที่นี่ที่เดียวจึงครอบทุกทางออก (LINE push, Flex, bubbles, หน้าเว็บแชท)

**Files:**
- Modify: `backend/app/Services/AIService.php:51-81` (generateResponse) และ `100-123` (generateAndSaveResponse)
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php:372-382`
- Modify: `backend/config/delivery.php`
- Test: `backend/tests/Feature/Payment/OrderPayloadEndToEndTest.php` (สร้างใหม่)

**Interfaces:**
- Consumes: `OrderPayloadExtractor::extract()` จาก Task 7
- Produces: `AIService::generateResponse()` คืน array เดิม + คีย์ `order_payload: ?array`; ข้อความที่บันทึกลง `messages.content` สะอาดแล้ว และ `messages.metadata['order_payload']` เก็บ payload

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail**

สร้าง `backend/tests/Feature/Payment/OrderPayloadEndToEndTest.php`:

```php
<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPayloadEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);

        return [$bot, $conversation];
    }

    public function test_payload_block_never_reaches_saved_message_content(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        [$bot, $conversation] = $this->makeConversation();

        $rag = $this->createMock(RAGService::class);
        $rag->method('generateResponse')->willReturn([
            'content' => "รวมยอดโอน: 1,000 บาท ✅\n"
                .'[[ORDER]]{"items":[{"name":"G3D","qty":20,"price":50}],"total":1000}[[/ORDER]]',
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
        $this->app->instance(RAGService::class, $rag);

        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ซื้อเฟสไก่ 20 ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertStringNotContainsString('[[ORDER]]', $botMessage->content);
        $this->assertStringContainsString('รวมยอดโอน: 1,000 บาท', $botMessage->content);
        $this->assertSame(20, $botMessage->metadata['order_payload']['items'][0]['qty']);
        // assertEquals ไม่ใช่ assertSame โดยตั้งใจ — metadata cast array ผ่าน JSON ซึ่ง
        // json_encode(1000.0) ให้ 1000 (ไม่มี .0) แล้ว decode คืน int
        $this->assertEquals(1000.0, $botMessage->metadata['order_payload']['total']);
    }

    public function test_flag_off_leaves_content_untouched(): void
    {
        config(['delivery.order_payload_enabled' => false]);
        [$bot, $conversation] = $this->makeConversation();

        $rag = $this->createMock(RAGService::class);
        $rag->method('generateResponse')->willReturn([
            'content' => 'สวัสดีครับ',
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
        $this->app->instance(RAGService::class, $rag);

        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'สวัสดี',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('สวัสดีครับ', $botMessage->content);
        $this->assertArrayNotHasKey('order_payload', $botMessage->metadata ?? []);
    }
}
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: FAIL — `[[ORDER]]` ยังอยู่ใน content

- [ ] **Step 3: เพิ่ม flag ใน config**

ใน `backend/config/delivery.php` เพิ่มก่อนปิด array:

```php
    // บอทปล่อยบล็อก [[ORDER]]{json}[[/ORDER]] ท้ายข้อความสรุปยอด → ระบบตัดออกก่อนส่ง
    // แล้วใช้เป็นแหล่งความจริงของจำนวนสินค้าแทนการอ่านประโยคด้วย regex
    // ปิดไว้ก่อน เปิดพร้อมกับที่อัปเดตพรอมต์ flow 24 (ดู Task 11)
    'order_payload_enabled' => (bool) env('ORDER_PAYLOAD_ENABLED', false),
```

- [ ] **Step 4: เสียบ extractor ใน `AIService::generateResponse()`**

เพิ่ม `use App\Services\Payment\OrderPayloadExtractor;` ที่หัวไฟล์ และ inject ผ่าน constructor (เพิ่มพารามิเตอร์ `private readonly OrderPayloadExtractor $orderPayload` — **ห้ามใส่ default value** เพราะ Laravel จะไม่ resolve ให้ ดูคอมเมนต์เตือนที่ `SlipVerificationService:35-38`)

แล้วเพิ่มบล็อกนี้ต่อจาก Stock Guard (หลังบรรทัด 59 ก่อน `if (! isset($result['usage']))`):

```php
        // ตัดบล็อกออเดอร์ออกจากข้อความก่อนใครได้เห็น — ทำที่นี่จุดเดียวเพราะทั้ง webhook
        // pipeline และ ProcessAggregatedMessages ผ่านเมธอดนี้เสมอ (ทางออกอื่นทั้งหมด
        // — LINE push, Flex, bubbles, หน้าเว็บ — อ่านจาก content ที่ผ่านตรงนี้แล้ว)
        $result['order_payload'] = null;
        if (config('delivery.order_payload_enabled', false)) {
            $extracted = $this->orderPayload->extract($result['content'] ?? '');
            $result['content'] = $extracted['clean'];
            $result['order_payload'] = $extracted['payload'];
        }
```

- [ ] **Step 5: เก็บ payload ลง metadata ใน `generateAndSaveResponse()`**

ใน `AIService::generateAndSaveResponse()` แทนบล็อก RAG metadata (บรรทัด 115-120) ด้วย:

```php
            $metadata = [];
            if (! empty($result['rag']) && $result['rag']['enabled']) {
                $metadata['rag'] = $result['rag'];
            }
            // แหล่งความจริงของจำนวนสินค้า — เส้นทางเงินอ่านจากตรงนี้ก่อน regex (Task 10)
            if (! empty($result['order_payload'])) {
                $metadata['order_payload'] = $result['order_payload'];
            }
            if ($metadata !== []) {
                $messageData['metadata'] = $metadata;
            }
```

- [ ] **Step 6: เก็บ payload ลง metadata ใน `ProcessAggregatedMessages`**

แทน `'metadata' => $result['rag_metadata'] ?? null,` (บรรทัด 381) ด้วย:

```php
            'metadata' => array_filter([
                ...($result['rag_metadata'] ?? []),
                'order_payload' => $result['order_payload'] ?? null,
            ]) ?: null,
```

- [ ] **Step 7: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: PASS ทั้ง 2 เคส

- [ ] **Step 8: รันเทสต์ AI/webhook ทั้งหมด**

Run: `cd backend && php artisan test --filter="AIService|Aggregat|LineWebhook"`
Expected: PASS ทั้งหมด

- [ ] **Step 9: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/AIService.php app/Jobs/ProcessAggregatedMessages.php config/delivery.php tests/Feature/Payment/OrderPayloadEndToEndTest.php
git commit -m "feat(payment): ตัดบล็อกออเดอร์ก่อนส่งลูกค้า + เก็บลง message metadata (ปิดด้วย flag)"
```

---

### Task 9: [C3] history ต้องพก metadata ไปด้วย

**เหตุผล:** ตอนนี้ history ที่ส่งให้เส้นทางตรวจสลิปเป็น `['sender' => ..., 'content' => ...]` เท่านั้น — payload ที่เก็บไว้ใน metadata จึงไปไม่ถึงคนอ่าน

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:474-494` (`getVisionConversationHistory`)
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php:170-187` (`recentTextHistory`)
- Test: `backend/tests/Feature/Payment/OrderPayloadEndToEndTest.php` (เพิ่มเคส)

**Interfaces:**
- Consumes: `messages.metadata['order_payload']` จาก Task 8
- Produces: history entry รูปใหม่ `array{sender: string, content: string, metadata: ?array}` — Task 10 อ่านคีย์ `metadata`

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail (ต่อท้าย OrderPayloadEndToEndTest)**

```php
    public function test_history_carries_metadata_for_payment_lookup(): void
    {
        [$bot, $conversation] = $this->makeConversation();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'รวมยอดโอน: 1,000 บาท',
            'metadata' => ['order_payload' => ['items' => [['name' => 'G3D', 'qty' => 20, 'total' => '1000']], 'total' => 1000.0]],
        ]);

        $service = app(\App\Services\Payment\ManualPaymentConfirmService::class);
        $method = new \ReflectionMethod($service, 'recentTextHistory');
        $history = $method->invoke($service, $conversation);

        $this->assertArrayHasKey('metadata', $history[0]);
        $this->assertSame(20, $history[0]['metadata']['order_payload']['items'][0]['qty']);
    }
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: FAIL — `Failed asserting that an array has the key 'metadata'`

- [ ] **Step 3: แก้ทั้งสองจุดให้พก metadata**

`LineWebhookResponseService::getVisionConversationHistory()` แทน map (บรรทัด 488-491):

```php
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
                // พก metadata ไปด้วยเพื่อให้เส้นทางตรวจสลิปอ่าน order_payload ได้
                // (vision ใช้แค่ sender/content เหมือนเดิม — คีย์เกินไม่กระทบ)
                'metadata' => $msg->metadata,
            ])
```

`ManualPaymentConfirmService::recentTextHistory()` แทน map (บรรทัด 184):

```php
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
                'metadata' => $msg->metadata,
            ])
```

- [ ] **Step 4: ตรวจว่า vision prompt ไม่ได้รับผลกระทบ**

Run: `cd backend && php artisan test --filter="LineWebhookResponse|Vision"`
Expected: PASS — ถ้ามีเทสต์ที่ assert โครง history แบบตายตัว ให้แก้เทสต์ให้รวมคีย์ `metadata` (โครงเดิมยังมี sender/content ครบ)

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/LineWebhook/LineWebhookResponseService.php app/Services/Payment/ManualPaymentConfirmService.php tests/Feature/Payment/OrderPayloadEndToEndTest.php
git commit -m "feat(payment): history พก metadata ไปให้เส้นทางตรวจสลิปอ่าน order payload"
```

---

### Task 10: [C4] เส้นทางเงินอ่าน payload ก่อน regex

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:87-121` (`findExpectedPayment`)
- Test: `backend/tests/Feature/Payment/OrderPayloadEndToEndTest.php` (เพิ่มเคส)

**Interfaces:**
- Consumes: history entry ที่มีคีย์ `metadata` จาก Task 9; รูป payload จาก Task 7
- Produces: `findExpectedPayment()` คืน shape เดิม `array{total, summary, items, items_unreliable}` แต่เมื่อมี payload จะไม่แตะ regex เลย

- [ ] **Step 1: เขียนเทสต์ที่ต้อง fail (ต่อท้าย OrderPayloadEndToEndTest)**

```php
    public function test_payment_lookup_prefers_payload_over_regex(): void
    {
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        // ข้อความพังแบบเคสจริง 10 ส.ค. (regex จะได้ qty ผิด) แต่ payload บอกจำนวนจริงไว้แล้ว
        $history = [[
            'sender' => 'bot',
            'content' => "1. G3D (50 บาท x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => ['order_payload' => [
                'items' => [['name' => 'G3D', 'qty' => 20, 'total' => '1000']],
                'total' => 1000.0,
            ]],
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot);

        $this->assertNotNull($expected);
        $this->assertSame(1000.0, $expected['total']);
        $this->assertSame(20, $expected['items'][0]['qty']);
        $this->assertSame('G3D x20', $expected['summary']);
        $this->assertFalse($expected['items_unreliable']);
    }

    public function test_payment_lookup_falls_back_to_regex_without_payload(): void
    {
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        $history = [[
            'sender' => 'bot',
            'content' => "1. G3D (50 x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => null,
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot);

        $this->assertNotNull($expected);
        $this->assertSame(20, $expected['items'][0]['qty']);
    }

    public function test_payload_is_ignored_when_amount_filter_does_not_match(): void
    {
        // ใบสรุปหลายใบค้างพร้อมกัน: payload ที่ยอดไม่ตรงสลิปต้องถูกข้ามเหมือน regex path
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        $history = [[
            'sender' => 'bot',
            'content' => "1. Page = 199 บาท\nรวมยอดโอน: 199 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => ['order_payload' => [
                'items' => [['name' => 'Page', 'qty' => 1, 'total' => '199']],
                'total' => 199.0,
            ]],
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot, 1000.0, 0.0);

        $this->assertNull($expected);
    }
```

- [ ] **Step 2: รันให้เห็น fail**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: FAIL — เคสแรกได้ qty 20 จาก regex ที่แก้ใน Task 1 แล้วก็จริง แต่ `items_unreliable` / ลำดับความสำคัญยังไม่ถูกบังคับ; ให้ยืนยันว่า fail จริงก่อนเขียนโค้ด (ถ้าเคสแรกผ่านไปแล้วเพราะ A1 ให้แก้ content ในเทสต์เป็นฟอร์แมตที่ regex อ่านไม่ออก เช่น `"G3D ยี่สิบตัว รวม 1,000 บาท"` แล้วรันใหม่ให้เห็น fail)

- [ ] **Step 3: ให้ `findExpectedPayment` อ่าน payload ก่อน**

ใน `findExpectedPayment()` เพิ่มหลังบรรทัด `$content = $msg['content'] ?? '';` (บรรทัด 98):

```php
            // ชั้น 0: ออเดอร์ที่บอทปล่อยมาเป็นโครงสร้าง — ตรงจากตัวเลขชุดที่บอทใช้คิดจริง
            // ไม่ผ่านการอ่านประโยคเลย จึงไม่มีทาง desync กับข้อความที่ลูกค้าเห็น
            $payload = $msg['metadata']['order_payload'] ?? null;
            if (is_array($payload) && ($payload['items'] ?? []) !== []) {
                if ($matchAmount !== null && abs((float) $payload['total'] - $matchAmount) > $tolerance) {
                    continue;
                }
                $visible = array_filter($payload['items'], fn (array $i) => ! PaymentMessageDetector::isZeroPriceItem($i));

                return [
                    'total' => (float) $payload['total'],
                    'summary' => $visible === [] ? '-' : PaymentMessageDetector::formatItemSummary($visible),
                    'items' => $payload['items'],
                    'items_unreliable' => false,
                ];
            }
```

**หมายเหตุลำดับ:** บล็อกนี้ต้องอยู่**หลัง** `isVerifySuccessMessage($content)` ที่ `break` (บรรทัด 99-101) เพื่อไม่ให้ใบที่จ่ายไปแล้วถูกหยิบกลับมา

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=OrderPayloadEndToEndTest`
Expected: PASS ทุกเคส

- [ ] **Step 5: รันเทสต์ payment ทั้งหมด**

Run: `cd backend && php artisan test --filter="Payment|Slip|Delivery"`
Expected: PASS ทั้งหมด

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add app/Services/Payment/SlipVerificationService.php tests/Feature/Payment/OrderPayloadEndToEndTest.php
git commit -m "feat(payment): ใช้ order payload เป็นแหล่งความจริงของจำนวน regex เหลือเป็น fallback"
```

---

### Task 11: [C5] อัปเดตพรอมต์ flow 24 ให้บอทปล่อยบล็อก

**Files:**
- Modify (DB): `flows.system_prompt` id=24 (Neon project `solitary-math-34010034`)
- Create (DB): แถว backup ใน `flow_audit_logs`

**Interfaces:**
- Consumes: รูปแบบบล็อกจาก Task 7 (`[[ORDER]]{"items":[{"name","qty","price"}],"total"}[[/ORDER]]`)
- Produces: ข้อความบอทที่มีบล็อกท้าย bubble สรุปยอด → Task 8 ตัดออก, Task 10 อ่าน

- [ ] **Step 1: Backup พรอมต์ปัจจุบันลง flow_audit_logs**

```sql
INSERT INTO flow_audit_logs (flow_id, user_id, action, field_changes, created_at, updated_at)
SELECT 24, (SELECT user_id FROM bots WHERE id = 26), 'backup_before_order_payload',
       json_build_object('system_prompt', system_prompt), NOW(), NOW()
FROM flows WHERE id = 24;
```

จดเลข `id` ของแถวที่เพิ่งสร้าง (ใช้ย้อนกลับได้ถ้าพัง):

```sql
SELECT id, action, created_at FROM flow_audit_logs WHERE flow_id = 24 ORDER BY id DESC LIMIT 1;
```

- [ ] **Step 2: แทรกกติกาบล็อกออเดอร์ต่อจากบล็อกสรุปรายการใน STEP 4**

หาข้อความ `ORDER LINE FORMAT (ห้ามเปลี่ยน):` ในพรอมต์ แล้วแทรกก่อนหน้านั้น:

```sql
UPDATE flows
SET system_prompt = replace(
      system_prompt,
      'ORDER LINE FORMAT (ห้ามเปลี่ยน):',
      E'⛔ บรรทัดสุดท้ายของ bubble สรุปยอด ต้องปิดท้ายด้วยบล็อกข้อมูลนี้เสมอ (ระบบตัดออกก่อนส่ง ลูกค้าไม่เห็น):\n'
      || E'[[ORDER]]{"items":[{"name":"ชื่อสินค้าตาม ORDER LINE FORMAT","qty":จำนวนเป็นตัวเลข,"price":ราคาต่อหน่วยเป็นตัวเลข}],"total":ยอดสุทธิเป็นตัวเลข}[[/ORDER]]\n'
      || E'- ตัวเลขห้ามมีคอมมา ห้ามมีคำว่าบาท (เขียน 1000 ไม่ใช่ 1,000 บาท)\n'
      || E'- ผลรวม price×qty ทุกรายการ ต้องเท่ากับ total เป๊ะ\n'
      || E'- ใส่ทุกรายการที่ลูกค้าจ่ายจริง (ของแถมราคา 0 ไม่ต้องใส่)\n'
      || E'- บล็อกนี้อยู่บรรทัดเดียวจบ ห้ามขึ้นบรรทัดใหม่กลางบล็อก ห้ามใส่ ||| ในบล็อก\n\n'
      || 'ORDER LINE FORMAT (ห้ามเปลี่ยน):'
    ),
    updated_at = NOW()
WHERE id = 24;
```

- [ ] **Step 3: ยืนยันว่าพรอมต์เปลี่ยนจริง + ล้าง cache**

```sql
SELECT position('[[ORDER]]' in system_prompt) > 0 AS has_block, length(system_prompt) FROM flows WHERE id = 24;
```
Expected: `has_block = true`

```bash
railway ssh --service backend "php artisan cache:forget flow_prompt_24 && php artisan cache:clear"
```

- [ ] **Step 4: เปิด flag บน production**

```bash
railway variables --set "ORDER_PAYLOAD_ENABLED=true" --service backend
```

- [ ] **Step 5: ทดสอบจริงกับบอท 26 (ต้องมีคนทำ ไม่ใช่เทสต์อัตโนมัติ)**

ทักบอทในไลน์ว่า "ซื้อเฟสไก่ 20 ครับ" → ยอมรับข้อตกลง → ดูข้อความสรุปยอด
Expected: ลูกค้า**ไม่เห็น** `[[ORDER]]` ในแชท

ตรวจใน DB ว่า payload ถูกเก็บ:

```sql
SELECT id, left(content, 80) AS content, metadata->'order_payload' AS payload
FROM messages
WHERE conversation_id = 46 AND sender = 'bot'
ORDER BY id DESC LIMIT 3;
```
Expected: `payload` มี `{"items":[{"name":"G3D","qty":20,...}],"total":1000}` และ `content` ไม่มีบล็อก

- [ ] **Step 6: ถ้าลูกค้าเห็นบล็อกหลุด — ปิด flag ทันที**

```bash
railway variables --set "ORDER_PAYLOAD_ENABLED=false" --service backend
```
แล้วกลับไปหาสาเหตุที่ Task 8 (แปลว่ามีทางออกที่ไม่ผ่าน `AIService::generateResponse`)

---

### Task 12: [C6] เฝ้าเคสจริง 3 ออเดอร์แรก

**Files:** ไม่มีไฟล์ — ops verification

**Interfaces:**
- Consumes: ทุก task ก่อนหน้า
- Produces: ยืนยันว่าจำนวนที่ลูกค้าสั่ง = จำนวนที่ระบบส่งจริง

- [ ] **Step 1: ตรวจ 3 ออเดอร์แรกหลัง deploy**

```sql
SELECT o.id, o.total_amount, oi.product_name, oi.quantity, oi.unit_price,
       (SELECT count(*) FROM account_delivery_items adi
         JOIN account_deliveries ad ON ad.id = adi.account_delivery_id
        WHERE ad.conversation_id = o.conversation_id
          AND ad.created_at BETWEEN o.created_at - interval '5 min' AND o.created_at + interval '10 min'
       ) AS accounts_sent
FROM orders o JOIN order_items oi ON oi.order_id = o.id
WHERE o.created_at >= '<วันที่ deploy>'
ORDER BY o.id DESC LIMIT 10;
```
Expected: `quantity` = `accounts_sent` ทุกแถว (ยกเว้นสินค้า support_link ที่ส่งลิงก์ใบเดียว)

- [ ] **Step 2: ตรวจว่ามีเคสที่ถูกหยุดเพราะ checksum ไหม**

```sql
SELECT id, conversation_id, amount, status, order_source, created_at
FROM slip_verifications
WHERE created_at >= '<วันที่ deploy>'
ORDER BY id DESC LIMIT 20;
```
ถ้าเจอเคสที่รับเงินแล้วแต่ไม่มี `account_deliveries` ผูก → เปิดแชทดูว่าการ์ด Telegram ออกจริงไหม (Task 4 Step 9)

- [ ] **Step 3: ตามเก็บออเดอร์ที่ค้างจากเคส 10 ส.ค.**

conversation #46 order #1724: ลูกค้าจ่าย 1,000 (G3D 20 ตัว) แต่ระบบส่งไปแค่ 1 บัญชี (`account_delivery_items#206`, stock_item #5401) — **ยังค้างอีก 19 ตัว** ลูกค้าขอทยอยส่งครั้งละ 10
ต้องส่งมือ และแก้ `order_items#2001` ให้ตรงความจริง:

```sql
UPDATE order_items SET quantity = 20, unit_price = 50 WHERE id = 2001;
```

---

## Self-Review

**Spec coverage**
- A (regex + ตาข่ายเช็คยอด) → Task 1 (regex 2 จุด), A2 (helper), A3 (LLM อ่านซ้ำ), A4 (หยุดส่งของ + แจ้งเจ้าของ) ✅
- B (ปิดรูรั่วรอบข้าง) → Task 5 (cap แจ้ง Telegram + การ์ดบอกจำนวนที่ขาด), B2 (ตั้งเพดานจริง) ✅
  - "ชื่อสินค้าผิดรูป ห้าม auto-deliver" → ครอบด้วย A1 (ชื่อมีวงเล็บเปิดค้างเกิดไม่ได้แล้ว) + A4 (checksum ไม่ผ่าน = ไม่ส่ง) + เส้นทาง `ST_UNMAPPED` เดิมที่ทำงานอยู่แล้ว
- C (structured order) → Task 7 (extractor), C2 (เสียบที่คอขวด + flag), C3 (history), C4 (consumer), C5 (prompt + rollout), C6 (เฝ้าเคสจริง) ✅

**Type consistency**
- `itemsMatchTotal(array, float, float): ?bool` — ใช้ตรงกันใน A3 (`buildExpected`) และ C1 (`OrderPayloadExtractor::decode`)
- `items_unreliable` (คีย์ array, A3) ↔ `itemsUnreliable` (property, A4) — ตั้งใจให้ต่างกันตาม convention ของแต่ละชั้น จุดแปลงอยู่ที่ `verify()` Task 4 Step 4 และ `ManualPaymentConfirmService` Task 4 Step 8
- payload item shape `{name: string, qty: int, total: string}` เหมือนกับ shape ที่ `LLMOrderItemExtractor` คืน (`parseItems` บรรทัด 104-110) → `formatItemSummary` และ `AccountDeliveryService` ใช้ได้เลยไม่ต้องแปลง
- `dispatchIfItemsTrusted(int, int, SlipVerificationResult)` — นิยามที่ A4 Step 7 ใช้ที่ Step 8 ทั้งสองเส้นทาง

**ความเสี่ยงที่รู้ตัว**
- checksum อาจ false-positive กับใบสรุปที่มีบรรทัดส่วนลด (ผลรวมรายการ > ยอดโอน) — พรอมต์ปัจจุบันไม่มีฟอร์แมตส่วนลด ถ้าเกิดขึ้นผลคือ "หยุดรอเจ้าของกด" ไม่ใช่ส่งผิด ซึ่งเป็นฝั่งที่ปลอดภัยกว่า
- Task C ครอบเฉพาะ text branch (`AIService::generateResponse`) — vision branch (ตอบรูป) ไม่ออกใบสรุปยอด จึงไม่ต้องมี payload; ถ้าอนาคตให้ vision ออกใบสรุปได้ ต้องเสียบ extractor ที่ `LineWebhookResponseService:287` ด้วย
