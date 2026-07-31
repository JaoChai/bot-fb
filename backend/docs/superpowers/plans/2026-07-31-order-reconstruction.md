# Order Reconstruction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เมื่อลูกค้าโอนเงินข้ามขั้นตอนจนระบบไม่รู้ว่าสั่งอะไร ให้ระบบสรุปออเดอร์เองจากบทสนทนา โดยใช้ "ราคา × จำนวน = ยอดที่โอน" เป็นตัวตรวจ แทนการเด้งให้เจ้าของไปเปิดแชทไล่อ่านเอง

**Architecture:** เพิ่มด่านที่ 3 ต่อจากด่าน regex 2 ด่านเดิมในเช็คข้อ 3 ของ `SlipVerificationService::verify()` ด่านใหม่เรียก LLM (utility model ต่อบอท) อ่านบทสนทนาทั้งสองฝั่ง + ราคาสินค้าจาก `product_stocks` แล้วผลลัพธ์ต้องผ่านตัวตรวจที่เป็นโค้ดธรรมดา 3 ชั้นก่อนถูกเชื่อ ผลออกมาเป็น 3 ชั้น: มั่นใจ → ส่งของ + Telegram เงียบ · กำกวม → การ์ดปุ่มตัวเลือก · ไม่รู้ → พฤติกรรมเดิมแต่การ์ดมีข้อมูลมากขึ้น

**Tech Stack:** Laravel 13 · PHP 8.4 · Pest 4 / PHPUnit 12 · PostgreSQL (Neon) · OpenRouter (utility model) · Telegram Bot API

**Spec:** `backend/docs/superpowers/specs/2026-07-31-order-reconstruction-design.md`

## Global Constraints

- ทุกคำสั่งรันจาก `backend/` — เทสต์: `php artisan test --filter=<ชื่อ>`
- ห้ามแตะเส้นทางสลิปที่ผ่านปกติ (93 ใบใน prod) — โค้ดใหม่ทั้งหมดทำงานหลังด่าน 1 และ 2 พลาดเท่านั้น
- ทุกอย่างที่เรียก LLM ต้อง **ไม่ throw** — ทุก error path คืน `null`/`[]` แล้วปล่อยให้ระบบตกไปพฤติกรรมเดิม (ตามแบบ `LLMOrderItemExtractor`)
- ห้าม log ค่า credential/detail ของบัญชีเด็ดขาด
- `items` ที่ไหลต่อไปยัง delivery ต้องเป็น shape เดิมเป๊ะ: `array{name: string, total: string, price?: string, qty?: int}` (ดู `AccountDeliveryService::createFromPayment` docblock)
- ราคาสินค้าที่ใช้ (ยืนยันจากข้อมูลจริงแล้ว): Personal 1,100 · BM 1,100 · Page 199 · G3D 50
- ข้อความไทยทั้งหมดต้องสะกดถูก มีวรรณยุกต์ครบ
- commit เป็นภาษาไทยตามแบบ repo (`fix(payment): ...` / `feat(payment): ...`)
- **ไม่ทำ UI ราคา** (ตัดจาก spec ตามหลัก YAGNI — ราคาคงที่ แก้ผ่าน DB/Claude ได้เหมือนที่ทำกับ KB)

---

### Task 1: ให้ด่าน 2 เห็นข้อความที่ใช้คำว่า "ราคา" และไม่ยอมแพ้กลางลูป

เคสจริงล่าสุด (แชท #92, 31 ก.ค. 16:02) หลุดเพราะบอทเขียน "ราคา 1,100 บาท" แทน "รวม 1,100 บาท"
และ `findExpectedFromConfirmMessage` เจอข้อความที่ยอดตรงแต่ดึงรายการไม่ได้แล้ว `return null` ทิ้งทั้งลูป
แทนที่จะไล่ดูข้อความถัดไป งานนี้ทำให้เคสวันนี้ผ่านได้โดยไม่ต้องเรียก LLM เลย

**Files:**
- Modify: `app/Services/Payment/PaymentMessageDetector.php:206` (`isConfirmMessage`), `:218-229` (`parseConfirmData`)
- Modify: `app/Services/Payment/SlipVerificationService.php:179-182` (`findExpectedFromConfirmMessage`)
- Test: `tests/Feature/SlipVerificationServiceTest.php`

**Interfaces:**
- Consumes: ไม่มี (งานแรก)
- Produces: ไม่เปลี่ยน signature ใดๆ — `isConfirmMessage(string): bool` และ `parseConfirmData(string): ?array` เหมือนเดิม

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดง — เคสจริงแชท #92 (31 ก.ค.)**

เพิ่มใน `tests/Feature/SlipVerificationServiceTest.php`:

```php
    public function test_slip_passes_against_confirm_message_using_word_ratcha(): void
    {
        // เคสจริง slip_verifications id=124 (แชท #92, 31 ก.ค. 16:02): บอทเขียน "ราคา 1,100 บาท"
        // แทน "รวม 1,100 บาท" → isConfirmMessage เดิมมองไม่เห็น กลายเป็น no_pending_order
        $this->paymentHistory = [
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal'],
            ['sender' => 'user', 'content' => 'ผูกบัตร'],
            ['sender' => 'bot', 'content' => 'เรียบร้อยครับพี่ เพิ่ม Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัว ราคา 1,500 บาทครับ|||ถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลยครับ'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertStringContainsString('Nolimit Level Up+ Personal', (string) $result->orderSummary);
    }

    public function test_confirm_fallback_keeps_looking_when_items_cannot_be_parsed(): void
    {
        // ข้อความยืนยันล่าสุดยอดตรงแต่เป็น prose ล้วน ดึงรายการไม่ได้ → ต้องไล่ดูข้อความก่อนหน้าต่อ
        // ไม่ใช่ยอมแพ้ทั้งลูป (เคสจริงแชท #1072)
        config(['delivery.llm_item_fallback_enabled' => false]);
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit Personal = 1,500 บาท\nรวม: 1,500 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'bot', 'content' => 'สรุปอีกครั้งนะครับ รวม 1,500 บาท พิมพ์ “ยืนยัน” ได้เลยครับ'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('Nolimit Personal', $result->orderSummary);
    }

    public function test_price_line_of_a_single_upsell_item_does_not_hijack_the_total(): void
    {
        // ข้อความมีทั้ง "Page ราคา 199 บาท" และยอดรวมจริง 1,500 → ต้องยึดยอดรวม ไม่ใช่ 199
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "รับ Page เพิ่มไหมครับ ราคา 199 บาท\n1. Nolimit Personal = 1,500 บาท\nรวม 1,500 บาท ถูกต้องไหมครับ? พิมพ์ “ยืนยัน”"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->expectedAmount);
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: FAIL 3 ตัวใหม่ — สองตัวแรกได้ `no_pending_order` แทน `passed`, ตัวที่สามจะแดงหลัง Step 3 ถ้าทำ regex ผิดลำดับ

- [ ] **Step 3: ให้ `isConfirmMessage` รับคำว่า "ราคา" ด้วย**

ใน `PaymentMessageDetector.php` แทนที่บล็อกที่บรรทัด 205-208:

```php
        // Must have total pattern: รวม...บาท (รองรับ รวม, รวมทั้งหมด, รวมยอด, รวมเป็นเงิน)
        // "ราคา ... บาท" ก็นับด้วย — LLM มัก drift เขียนข้อความยืนยันขั้น 2 แบบ
        // "เพิ่ม X 1 ตัว ราคา 1,100 บาท ... พิมพ์ยืนยัน" (เคสจริงแชท #92 31 ก.ค.)
        // คำว่า "ยืนยัน" ที่บังคับด้านล่างเป็นตัวกันข้อความเสนอราคาทั่วไปไม่ให้หลุดเข้ามา
        if (! preg_match(self::CONFIRM_TOTAL_PATTERN, $text)) {
            return false;
        }
```

และเพิ่ม const ไว้ใกล้ `TOTAL_KEYWORDS` (บรรทัด 29):

```php
    /** ยอดในข้อความยืนยันขั้น 2 — ใช้ร่วมกัน isConfirmMessage/parseConfirmData กัน drift */
    private const CONFIRM_TOTAL_PATTERN = '/(?:รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?|ราคา)\s*:?\s*([\d,]+)\s*บาท/u';

    /** เฉพาะรูป "รวม..." — ต้องลองก่อน "ราคา" เสมอ เพราะยอดรวมชนะราคาต่อชิ้น */
    private const CONFIRM_SUM_PATTERN = '/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*([\d,]+)\s*บาท/u';
```

- [ ] **Step 4: ให้ `parseConfirmData` ยึด "รวม" ก่อน แล้วค่อยถอยไป "ราคา"**

แทนที่ `parseConfirmData` ทั้งเมธอด:

```php
    /**
     * Parse confirm data from text.
     * ลองรูป "รวม X บาท" ก่อนเสมอ — ข้อความที่มีทั้งราคาต่อชิ้น (upsell "Page ราคา 199 บาท")
     * และยอดรวม ต้องได้ยอดรวม ไม่ใช่ราคาชิ้นแรกที่เจอ
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        $text = $this->normalize($text);

        if (! preg_match(self::CONFIRM_SUM_PATTERN, $text, $totalMatch)
            && ! preg_match(self::CONFIRM_TOTAL_PATTERN, $text, $totalMatch)) {
            return null;
        }

        return [
            'items' => $this->parseItems($text),
            'total' => $totalMatch[1],
        ];
    }
```

หมายเหตุ: เพิ่ม `normalize()` เข้ามาด้วย — ข้อความจริงเชื่อมบรรทัดด้วย `|||` (`parsePaymentData` ทำอยู่แล้ว แต่ `parseConfirmData` ไม่ได้ทำ ทำให้ `parseItems` มองไม่เห็นขอบบรรทัด)

- [ ] **Step 5: ให้ด่าน 2 ไล่ดูข้อความถัดไปแทนที่จะยอมแพ้**

ใน `SlipVerificationService::findExpectedFromConfirmMessage` แทนที่บรรทัด 179-182:

```php
            $expected = $this->buildExpected($data, $content, $bot, requireItems: true);
            if ($expected === null) {
                // ยอดตรงแต่ดึงรายการไม่ได้ (prose ล้วน) — ไล่ดูข้อความยืนยันก่อนหน้าต่อ
                // ห้ามหยุดทั้งลูป ไม่งั้นข้อความตะกร้าที่มีรายการครบจะไม่ถูกอ่าน (เคสจริงแชท #1072)
                continue;
            }
```

- [ ] **Step 6: รันเทสต์ทั้งไฟล์ให้ผ่านหมด**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: PASS ทุกตัว รวมของเดิม (`test_cart_confirm_with_different_amount_still_no_pending_order` และ `test_payment_summary_still_wins_over_cart_message` ต้องยังเขียว — เป็นตัวกันถอยหลัง)

- [ ] **Step 7: รันเทสต์ payment ทั้งชุดกันลูกหลง**

Run: `php artisan test --filter=Payment`
Expected: PASS ทั้งหมด

- [ ] **Step 8: commit**

```bash
git add app/Services/Payment/PaymentMessageDetector.php app/Services/Payment/SlipVerificationService.php tests/Feature/SlipVerificationServiceTest.php
git commit -m "fix(payment): ด่านอ่านออเดอร์เห็นคำว่า \"ราคา\" และไม่ยอมแพ้กลางลูป"
```

---

### Task 2: ราคาสินค้าใน `product_stocks`

ตัวตรวจ "ราคา × จำนวน = ยอดที่โอน" ต้องมีราคาที่ machine-readable ตอนนี้ราคาอยู่แต่ในข้อความ prompt

**Files:**
- Create: `database/migrations/2026_07_31_100000_add_price_to_product_stocks.php`
- Modify: `app/Models/ProductStock.php`
- Test: `tests/Feature/ProductStockPriceTest.php`

**Interfaces:**
- Consumes: ไม่มี
- Produces: `ProductStock->price` เป็น `?float` (cast `decimal:2` อ่านออกมาเป็น string จาก Eloquent จึงต้อง cast เป็น float ตอนใช้งาน) — Task 3 ใช้ตัวนี้

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดง**

สร้าง `tests/Feature/ProductStockPriceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_column_is_fillable_and_cast_to_number(): void
    {
        $product = ProductStock::create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'aliases' => ['Personal'],
            'in_stock' => true,
            'display_order' => 1,
            'delivery_method' => 'stock',
            'price' => 1100,
        ]);

        $this->assertSame(1100.0, (float) $product->fresh()->price);
    }

    public function test_price_defaults_to_null_for_products_without_a_price(): void
    {
        $product = ProductStock::create([
            'name' => 'สินค้าทดสอบ',
            'slug' => 'test-item',
            'in_stock' => true,
            'display_order' => 9,
            'delivery_method' => 'none',
        ]);

        $this->assertNull($product->fresh()->price);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=ProductStockPriceTest`
Expected: FAIL — `column "price" does not exist`

- [ ] **Step 3: เขียน migration**

สร้าง `database/migrations/2026_07_31_100000_add_price_to_product_stocks.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ราคาต่อชิ้นที่ยืนยันจากข้อมูลจริง (prompt flow 24 + order_items ย้อนหลัง) */
    private const PRICES = [
        'personal' => 1100,
        'bm' => 1100,
        'page' => 199,
        'g3d' => 50,
    ];

    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // ราคาต่อชิ้น — ใช้เป็นตัวตรวจว่าออเดอร์ที่ระบบสรุปเองรวมแล้วตรงยอดที่ลูกค้าโอนจริงไหม
            // null = ยังไม่ได้ตั้งราคา → สินค้านั้นจะไม่ถูกใช้ในการสรุปออเดอร์อัตโนมัติ
            $table->decimal('price', 10, 2)->nullable()->after('available_count');
        });

        foreach (self::PRICES as $slug => $price) {
            DB::table('product_stocks')->where('slug', $slug)->update(['price' => $price]);
        }
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
```

- [ ] **Step 4: เพิ่ม `price` ใน model**

ใน `app/Models/ProductStock.php` เพิ่ม `'price'` ต่อท้าย `$fillable` และเพิ่มใน `$casts`:

```php
        'price' => 'decimal:2',
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `php artisan test --filter=ProductStockPriceTest`
Expected: PASS ทั้ง 2 ตัว

- [ ] **Step 6: commit**

```bash
git add database/migrations/2026_07_31_100000_add_price_to_product_stocks.php app/Models/ProductStock.php tests/Feature/ProductStockPriceTest.php
git commit -m "feat(stock): เก็บราคาต่อชิ้นใน product_stocks ไว้ตรวจยอดออเดอร์"
```

---

### Task 3: `OrderReconstructor` — สรุปออเดอร์จากบทสนทนา + ด่านตรวจ

หัวใจของงาน LLM เดาอะไรมาก็ไม่เชื่อจนกว่าจะผ่านตัวตรวจที่เป็นโค้ดธรรมดา

**Files:**
- Create: `app/Services/Payment/OrderReconstruction.php` (DTO)
- Create: `app/Services/Payment/OrderReconstructor.php`
- Test: `tests/Feature/OrderReconstructorTest.php`

**Interfaces:**
- Consumes: `ProductStock->price` (Task 2) · `OpenRouterService::chat(messages:, model:, temperature:, maxTokens:, useFallback:, apiKeyOverride:)` · `Bot::resolvedUtilityModel(): ?string`
- Produces:
  - `OrderReconstruction` — `readonly array $items` (shape `{name, total, qty}`), `readonly float $total`, `readonly string $summary`, `readonly bool $ambiguous`, `readonly array $alternatives` (แต่ละตัวเป็น `array<int, array{name, total, qty}>`)
  - `OrderReconstructor::reconstruct(Bot $bot, array $history, float $slipAmount): ?OrderReconstruction` — `$history` shape `array<int, array{sender: string, content: string}>` คืน `null` เมื่อสรุปไม่ได้/ไม่ผ่านตัวตรวจ/เรียก LLM ไม่ได้

- [ ] **Step 1: เขียน DTO**

สร้าง `app/Services/Payment/OrderReconstruction.php`:

```php
<?php

namespace App\Services\Payment;

/**
 * ออเดอร์ที่ระบบสรุปเองจากบทสนทนา (ด่าน 3) — ผ่านตัวตรวจครบแล้วเท่านั้นถึงถูกสร้างขึ้น
 *
 * @param  array<int, array{name: string, total: string, qty: int}>  $items
 * @param  array<int, array<int, array{name: string, total: string, qty: int}>>  $alternatives
 */
class OrderReconstruction
{
    public function __construct(
        public readonly array $items,
        public readonly float $total,
        public readonly string $summary,
        public readonly bool $ambiguous,
        public readonly array $alternatives = [],
    ) {}
}
```

- [ ] **Step 2: เขียนเทสต์ที่ต้องแดง**

สร้าง `tests/Feature/OrderReconstructorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\OrderReconstructor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderReconstructorTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'utility_model' => 'openai/gpt-4o-mini']);

        ProductStock::create(['name' => 'Nolimit Level Up+ Personal', 'slug' => 'personal', 'aliases' => ['Personal'], 'in_stock' => true, 'display_order' => 1, 'delivery_method' => 'stock', 'price' => 1100]);
        ProductStock::create(['name' => 'Nolimit Level Up+ BM', 'slug' => 'bm', 'aliases' => ['BM'], 'in_stock' => true, 'display_order' => 2, 'delivery_method' => 'stock', 'price' => 1100]);
        ProductStock::create(['name' => 'G3D', 'slug' => 'g3d', 'aliases' => ['ไก่'], 'in_stock' => true, 'display_order' => 3, 'delivery_method' => 'stock', 'price' => 50]);
    }

    private function fakeLLM(string $json): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->andReturn(['content' => $json]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    private function expectNoLLMCall(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldNotReceive('chat');
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_reconstructs_order_when_amount_matches_and_product_named_in_chat(): void
    {
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Nolimit Level Up+ Personal 1 ตัวครับ'],
        ], 1100.0);

        $this->assertNotNull($result);
        $this->assertFalse($result->ambiguous);
        $this->assertSame(1100.0, $result->total);
        $this->assertSame('Nolimit Level Up+ Personal', $result->summary);
        $this->assertSame([['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]], $result->items);
    }

    public function test_multiplies_quantity_and_writes_it_into_the_summary(): void
    {
        $this->fakeLLM('{"items":[{"slug":"personal","qty":2}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal 2 ตัวครับ'],
        ], 2200.0);

        $this->assertNotNull($result);
        $this->assertSame('Nolimit Level Up+ Personal x2', $result->summary);
        $this->assertSame(2, $result->items[0]['qty']);
        $this->assertSame('2200', $result->items[0]['total']);
    }

    public function test_rejects_when_total_does_not_match_the_slip(): void
    {
        // LLM เดา 1 ตัว (1,100) แต่ลูกค้าโอน 1,500 → ห้ามเชื่อ
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1500.0);

        $this->assertNull($result);
    }

    public function test_rejects_product_never_mentioned_in_the_conversation(): void
    {
        // ยอดตรง 50 บาท แต่ไม่มีใครพูดถึง G3D เลย → LLM แต่งเอง ห้ามเชื่อ
        $this->fakeLLM('{"items":[{"slug":"g3d","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
        ], 50.0);

        $this->assertNull($result);
    }

    public function test_flags_ambiguous_when_another_mentioned_product_has_the_same_price(): void
    {
        // ในแชทพูดถึงทั้ง BM และ Personal ซึ่งราคาเท่ากัน 1,100 → ห้ามส่งของเอง
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'bot', 'content' => 'รอบนี้จัด Nolimit Level Up+ BM 1 ตัว เซ็ตเดิมเลยไหมครับ?'],
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal'],
        ], 1100.0);

        $this->assertNotNull($result);
        $this->assertTrue($result->ambiguous);
        $this->assertCount(2, $result->alternatives);
        $this->assertSame('Nolimit Level Up+ Personal', $result->alternatives[0][0]['name']);
        $this->assertSame('Nolimit Level Up+ BM', $result->alternatives[1][0]['name']);
    }

    public function test_rejects_slug_that_does_not_exist(): void
    {
        $this->fakeLLM('{"items":[{"slug":"ไม่มีสินค้านี้","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอาอันนั้นครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_rejects_product_that_is_out_of_stock(): void
    {
        ProductStock::where('slug', 'personal')->update(['in_stock' => false]);
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_returns_null_and_never_calls_llm_without_a_utility_model(): void
    {
        $this->bot->update(['utility_model' => null]);
        $this->expectNoLLMCall();

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_survives_broken_llm_output(): void
    {
        $this->fakeLLM('ขอโทษครับ ผมไม่เข้าใจคำถาม');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_survives_llm_exception(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(OpenRouterService::class, $mock);

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }
}
```

- [ ] **Step 3: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=OrderReconstructorTest`
Expected: FAIL — `Class "App\Services\Payment\OrderReconstructor" not found`

- [ ] **Step 4: เขียน `OrderReconstructor`**

สร้าง `app/Services/Payment/OrderReconstructor.php`:

```php
<?php

namespace App\Services\Payment;

use App\Models\Bot;
use App\Models\ProductStock;
use App\Services\OpenRouterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ด่าน 3 ของการหาออเดอร์: ลูกค้าโอนข้ามขั้นตอนจน regex สองด่านแรกหาไม่เจอ
 * → ให้ utility model อ่านบทสนทนาทั้งสองฝั่งแล้วสรุปว่าสั่งอะไร
 *
 * LLM เป็นแค่ผู้เสนอ — ตัวตัดสินคือด่านตรวจในคลาสนี้ทั้งหมด:
 *   1. slug ต้องมีจริงและเปิดขายอยู่
 *   2. ผลรวม ราคา × จำนวน ต้องเท่ายอดในสลิป (± tolerance ของบอท)
 *   3. ชื่อหรือ alias ต้องถูกพูดถึงในบทสนทนาจริง (กัน LLM แต่งของขึ้นมา)
 * ไม่ throw — ทุกทางที่พลาดคืน null ให้ผู้เรียกตกไปพฤติกรรมเดิม
 */
class OrderReconstructor
{
    /** จำนวนต่อรายการที่ยอมรับ — เกินนี้ถือว่า LLM เพี้ยน (สอดคล้อง delivery.max_qty) */
    private const MAX_QTY = 20;

    private const SYSTEM_PROMPT = <<<'PROMPT'
คุณคือผู้ช่วยร้านค้า อ่านบทสนทนาแล้วสรุปว่าลูกค้าสั่งซื้ออะไร ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่น:
{"items":[{"slug":"...","qty":1}],"confidence":"high|low"}

กติกา:
- slug ต้องเลือกจากรายการสินค้าที่ให้ไว้เท่านั้น ห้ามคิดขึ้นเอง
- qty คือจำนวนชิ้น
- ผลรวม (ราคา × qty) ต้องเท่ากับยอดที่ลูกค้าโอนมาพอดี
- ยึดสิ่งที่ลูกค้าพูดล่าสุดเป็นหลัก ถ้าลูกค้าเปลี่ยนใจระหว่างคุย ให้ใช้ตัวหลังสุด
- ถ้าสรุปไม่ได้หรือรวมยอดไม่ลงตัว ตอบ {"items":[],"confidence":"low"}
PROMPT;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @param  array<int, array{sender: string, content: string}>  $history
     */
    public function reconstruct(Bot $bot, array $history, float $slipAmount): ?OrderReconstruction
    {
        $products = ProductStock::where('in_stock', true)->whereNotNull('price')->get();
        if ($products->isEmpty()) {
            return null;
        }

        $transcript = $this->transcript($history);
        if (trim($transcript) === '') {
            return null;
        }

        $raw = $this->ask($bot, $products, $transcript, $slipAmount);
        if ($raw === []) {
            return null;
        }

        $items = $this->validate($raw, $products, $transcript, $slipAmount, $bot);
        if ($items === null) {
            return null;
        }

        $alternatives = $this->alternatives($items, $products, $transcript);

        return new OrderReconstruction(
            items: $items,
            total: $slipAmount,
            summary: $this->summarize($items),
            ambiguous: count($alternatives) > 1,
            alternatives: count($alternatives) > 1 ? $alternatives : [],
        );
    }

    /** @param  array<int, array{sender: string, content: string}>  $history */
    private function transcript(array $history): string
    {
        $lines = [];
        foreach ($history as $msg) {
            $sender = ($msg['sender'] ?? '') === 'user' ? 'ลูกค้า' : 'ร้าน';
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content !== '') {
                $lines[] = "{$sender}: {$content}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array{slug: string, qty: int}>
     */
    private function ask(Bot $bot, Collection $products, string $transcript, float $slipAmount): array
    {
        $model = $bot->resolvedUtilityModel();
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey();
        if ($model === null || empty($apiKey)) {
            Log::debug('OrderReconstructor: no utility model or API key, skipping', ['bot_id' => $bot->id]);

            return [];
        }

        $catalog = $products
            ->map(fn (ProductStock $p) => "- slug: {$p->slug} | ชื่อ: {$p->name} | ราคา: ".(float) $p->price.' บาท')
            ->implode("\n");

        $user = "สินค้าที่ขาย:\n{$catalog}\n\nยอดที่ลูกค้าโอนมา: {$slipAmount} บาท\n\nบทสนทนา:\n{$transcript}";

        try {
            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $user],
                ],
                model: $model,
                temperature: 0.1,
                maxTokens: 300,
                useFallback: false,
                apiKeyOverride: $apiKey,
            );
        } catch (\Throwable $e) {
            Log::warning('OrderReconstructor: LLM call failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }

        return $this->decode($response['content'] ?? '');
    }

    /** @return array<int, array{slug: string, qty: int}> */
    private function decode(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            Log::debug('OrderReconstructor: JSON parse failed', ['content' => mb_substr($content, 0, 200)]);

            return [];
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (! is_array($item) || empty($item['slug']) || ! is_string($item['slug'])) {
                continue;
            }
            $items[] = ['slug' => trim($item['slug']), 'qty' => max(1, (int) ($item['qty'] ?? 1))];
        }

        return $items;
    }

    /**
     * ด่านตรวจทั้งสามชั้น — ผ่านครบเท่านั้นถึงคืนรายการออกไป
     *
     * @param  array<int, array{slug: string, qty: int}>  $raw
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array{name: string, total: string, qty: int}>|null
     */
    private function validate(array $raw, Collection $products, string $transcript, float $slipAmount, Bot $bot): ?array
    {
        if ($raw === []) {
            return null;
        }

        $items = [];
        $sum = 0.0;
        foreach ($raw as $entry) {
            $product = $products->firstWhere('slug', $entry['slug']);
            if ($product === null) {
                Log::info('OrderReconstructor: unknown slug', ['slug' => $entry['slug']]);

                return null;
            }
            if ($entry['qty'] > self::MAX_QTY) {
                Log::info('OrderReconstructor: qty out of range', ['qty' => $entry['qty']]);

                return null;
            }
            if (! $this->mentioned($product, $transcript)) {
                Log::info('OrderReconstructor: product never mentioned in chat', ['slug' => $product->slug]);

                return null;
            }

            $lineTotal = (float) $product->price * $entry['qty'];
            $sum += $lineTotal;
            $items[] = [
                'name' => $product->name,
                'total' => rtrim(rtrim(number_format($lineTotal, 2, '.', ''), '0'), '.'),
                'qty' => $entry['qty'],
            ];
        }

        $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
        if (abs($sum - $slipAmount) > $tolerance) {
            Log::info('OrderReconstructor: checksum mismatch', ['sum' => $sum, 'slip' => $slipAmount]);

            return null;
        }

        return $items;
    }

    /** ชื่อหรือ alias ของสินค้าถูกพูดถึงในบทสนทนาไหม (ตัดคำสั้นกว่า 3 ตัวอักษรทิ้ง กัน match กว้างเกิน) */
    private function mentioned(ProductStock $product, string $transcript): bool
    {
        $haystack = mb_strtolower($transcript);
        foreach (array_merge([$product->name], $product->aliases ?? []) as $term) {
            $term = mb_strtolower(trim((string) $term));
            if (mb_strlen($term) >= 3 && mb_strpos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ชุดที่ประกอบยอดได้เท่ากันโดยสลับเป็นสินค้าราคาเดียวกันที่ถูกพูดถึงในแชทด้วย
     * เช่น 1,100 = Personal ×1 หรือ BM ×1 → ต้องให้เจ้าของเลือก ห้ามเดาเอง
     * ชุดที่ LLM เลือกอยู่ตำแหน่งแรกเสมอ
     *
     * @param  array<int, array{name: string, total: string, qty: int}>  $items
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array<int, array{name: string, total: string, qty: int}>>
     */
    private function alternatives(array $items, Collection $products, string $transcript): array
    {
        if (count($items) !== 1) {
            return [$items]; // ออเดอร์หลายรายการ: ไม่สลับให้ ความเสี่ยงจับคู่ผิดสูงเกิน
        }

        $chosen = $items[0];
        $sets = [$items];

        foreach ($products as $product) {
            if ($product->name === $chosen['name']) {
                continue;
            }
            $lineTotal = (float) $product->price * $chosen['qty'];
            if (abs($lineTotal - (float) $chosen['total']) > 0.001) {
                continue;
            }
            if (! $this->mentioned($product, $transcript)) {
                continue;
            }
            $sets[] = [['name' => $product->name, 'total' => $chosen['total'], 'qty' => $chosen['qty']]];
        }

        return $sets;
    }

    /** @param  array<int, array{name: string, total: string, qty: int}>  $items */
    private function summarize(array $items): string
    {
        return implode(', ', array_map(
            fn (array $item) => $item['qty'] > 1 ? "{$item['name']} x{$item['qty']}" : $item['name'],
            $items,
        ));
    }
}
```

- [ ] **Step 5: รันเทสต์ให้ผ่านทั้งหมด**

Run: `php artisan test --filter=OrderReconstructorTest`
Expected: PASS ทั้ง 10 ตัว

- [ ] **Step 6: commit**

```bash
git add app/Services/Payment/OrderReconstruction.php app/Services/Payment/OrderReconstructor.php tests/Feature/OrderReconstructorTest.php
git commit -m "feat(payment): สรุปออเดอร์จากบทสนทนาเมื่อลูกค้าโอนข้ามขั้นตอน (ยอดเป็นตัวตรวจ)"
```

---

### Task 4: ต่อด่าน 3 เข้ากับการตรวจสลิป

**Files:**
- Create: `database/migrations/2026_07_31_110000_add_order_source_to_slip_verifications.php`
- Modify: `app/Services/Payment/SlipVerificationResult.php`
- Modify: `app/Services/Payment/SlipVerificationService.php` (constructor + `verify()` เช็คข้อ 3 ที่ `:289-299` + `record()`)
- Test: `tests/Feature/SlipVerificationServiceTest.php`

**Interfaces:**
- Consumes: `OrderReconstructor::reconstruct(Bot, array, float): ?OrderReconstruction` (Task 3)
- Produces:
  - `SlipVerificationResult->orderSource` (`?string`: `summary` / `confirm` / `llm` / null) และ `->reconstruction` (`?OrderReconstruction`)
  - เมื่อกำกวม: `failReason = 'needs_choice'` และเก็บตัวเลือกลงคอลัมน์ `slip_verifications.reconstructed` (Task 5 อ่านไปสร้างปุ่ม)

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดง**

เพิ่มใน `tests/Feature/SlipVerificationServiceTest.php`:

```php
    private function fakeReconstructorLLM(string $json): void
    {
        $mock = Mockery::mock(\App\Services\OpenRouterService::class);
        $mock->shouldReceive('chat')->andReturn(['content' => $json]);
        $this->app->instance(\App\Services\OpenRouterService::class, $mock);
    }

    private function seedProducts(): void
    {
        \App\Models\ProductStock::create(['name' => 'Nolimit Level Up+ Personal', 'slug' => 'personal', 'aliases' => ['Personal'], 'in_stock' => true, 'display_order' => 1, 'delivery_method' => 'stock', 'price' => 1500]);
        \App\Models\ProductStock::create(['name' => 'Nolimit Level Up+ BM', 'slug' => 'bm', 'aliases' => ['BM'], 'in_stock' => true, 'display_order' => 2, 'delivery_method' => 'stock', 'price' => 1500]);
    }

    public function test_reconstructs_order_from_chat_when_both_regex_stages_fail(): void
    {
        // เคสจริงแชท #169: บอทตอบ error ไม่เคยพิมพ์ยอดเลย ลูกค้าโอนมาเฉยๆ
        $this->seedProducts();
        $this->bot->update(['utility_model' => 'openai/gpt-4o-mini']);
        $this$this->bot->user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);
        $this->fakeReconstructorLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');
        $this->paymentHistory = [
            ['sender' => 'user', 'content' => 'ซื้อ Nolimit Level Up+ Personal 1 ครับ'],
            ['sender' => 'bot', 'content' => 'I apologize, but I am having trouble processing your request.'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('Nolimit Level Up+ Personal', $result->orderSummary);
        $this->assertSame('llm', $result->orderSource);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'passed', 'order_source' => 'llm']);
    }

    public function test_ambiguous_reconstruction_does_not_deliver_and_asks_the_owner(): void
    {
        // ในแชทพูดถึงทั้ง BM และ Personal ราคาเท่ากัน → ห้ามส่งของ ต้องให้เจ้าของเลือก
        $this->seedProducts();
        $this->bot->update(['utility_model' => 'openai/gpt-4o-mini']);
        $this$this->bot->user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);
        $this->fakeReconstructorLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => 'รอบนี้จัด Nolimit Level Up+ BM เซ็ตเดิมเลยไหมครับ?'],
            ['sender' => 'user', 'content' => 'เอา Nolimit Level Up+ Personal ครับ'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('needs_choice', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'needs_choice']);
        $slip = \App\Models\SlipVerification::latest('id')->first();
        $this->assertCount(2, $slip->reconstructed['alternatives']);
    }

    public function test_regex_stages_win_and_never_call_the_llm(): void
    {
        // ด่าน 1 เจอออเดอร์อยู่แล้ว → ห้ามเสียเงินเรียก LLM (paymentHistory ตั้งไว้ใน setUp)
        $this->seedProducts();
        $this->bot->update(['utility_model' => 'openai/gpt-4o-mini']);
        $mock = Mockery::mock(\App\Services\OpenRouterService::class);
        $mock->shouldNotReceive('chat');
        $this->app->instance(\App\Services\OpenRouterService::class, $mock);
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('summary', $result->orderSource);
    }

    public function test_reconstruction_failure_falls_back_to_no_pending_order(): void
    {
        $this->seedProducts();
        $this->bot->update(['utility_model' => 'openai/gpt-4o-mini']);
        $this$this->bot->user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);
        $this->fakeReconstructorLLM('{"items":[],"confidence":"low"}');
        $this->paymentHistory = [['sender' => 'user', 'content' => 'สวัสดีครับ']];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
    }
```

เพิ่ม `use Mockery;` ที่หัวไฟล์ถ้ายังไม่มี

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: FAIL 4 ตัวใหม่ — `order_source` ไม่มีในตาราง / ได้ `no_pending_order` แทน `passed`

- [ ] **Step 3: เขียน migration**

สร้าง `database/migrations/2026_07_31_110000_add_order_source_to_slip_verifications.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_verifications', function (Blueprint $table) {
            // ด่านไหนที่หาออเดอร์เจอ: summary (regex สรุปยอด) | confirm (regex ข้อความยืนยัน)
            // | llm (ระบบสรุปเองจากบทสนทนา) | null (หาไม่เจอ) — ไว้วัดผลว่าด่านใหม่ช่วยจริงไหม
            $table->string('order_source', 16)->nullable()->after('status');
            // ออเดอร์ที่ระบบสรุปเอง + ตัวเลือกตอนกำกวม (ให้ปุ่ม Telegram อ่านไปสร้างตัวเลือก)
            $table->jsonb('reconstructed')->nullable()->after('order_source');
        });
    }

    public function down(): void
    {
        Schema::table('slip_verifications', function (Blueprint $table) {
            $table->dropColumn(['order_source', 'reconstructed']);
        });
    }
};
```

แล้วแก้ `app/Models/SlipVerification.php` — `$fillable` เป็นรายการตายตัว ถ้าไม่เพิ่มจะบันทึกไม่ลง:

```php
    protected $fillable = [
        'bot_id',
        'conversation_id',
        'message_id',
        'trans_ref',
        'amount',
        'receiver_account',
        'status',
        'raw_response',
        'order_source',
        'reconstructed',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'reconstructed' => 'array',
        'amount' => 'float',
    ];
```

- [ ] **Step 4: เพิ่มฟิลด์ใน `SlipVerificationResult`**

แทนที่ constructor ใน `app/Services/Payment/SlipVerificationResult.php`:

```php
    public function __construct(
        public readonly bool $isSlip,
        public readonly bool $passed,
        public readonly ?string $failReason = null,
        public readonly ?float $amount = null,
        public readonly ?string $transRef = null,
        public readonly ?float $expectedAmount = null,
        public readonly ?string $orderSummary = null,
        public readonly ?array $orderItems = null,
        /** ด่านที่หาออเดอร์เจอ: summary | confirm | llm | null */
        public readonly ?string $orderSource = null,
        /** ออเดอร์ที่ระบบสรุปเอง — มีค่าเฉพาะตอน orderSource = 'llm' */
        public readonly ?OrderReconstruction $reconstruction = null,
    ) {}
```

- [ ] **Step 5: ต่อด่าน 3 เข้า `verify()`**

ใน `SlipVerificationService.php` เพิ่ม dependency ใน constructor:

```php
    public function __construct(
        private readonly PaymentMessageDetector $detector,
        private readonly TelegramAlertBotService $alertBot,
        private readonly ?LLMOrderItemExtractor $itemExtractor = null,
        private readonly ?OrderReconstructor $reconstructor = null,
    ) {}
```

แทนที่บล็อกเช็ค 3 (บรรทัด 289-299) ด้วย:

```php
        // เช็ค 3: ต้องมีออเดอร์ค้างชำระใน history
        // ด่าน 1 ข้อความสรุปยอด+เลขบัญชี → ด่าน 2 ข้อความยืนยันขั้น 2 (ยอดต้องตรง)
        // → ด่าน 3 ให้ระบบสรุปออเดอร์เองจากบทสนทนา (เรียก LLM เฉพาะตอนสองด่านแรกพลาด)
        $orderSource = 'summary';
        $expected = $this->findExpectedPayment($conversationHistory, $configured, $bot);
        if ($expected === null) {
            $orderSource = 'confirm';
            $expected = $this->findExpectedFromConfirmMessage($conversationHistory, $bot, $slipAmount);
        }

        $reconstruction = null;
        if ($expected === null && $this->reconstructor !== null) {
            $reconstruction = $this->reconstructor->reconstruct($bot, $conversationHistory, $slipAmount);
            if ($reconstruction !== null) {
                $orderSource = 'llm';
                $expected = [
                    'total' => $reconstruction->total,
                    'summary' => $reconstruction->summary,
                    'items' => $reconstruction->items,
                ];
            }
        }

        if ($expected === null) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'no_pending_order',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // ระบบสรุปได้แต่ประกอบยอดได้หลายแบบ (เช่น 1,100 ตรงทั้ง Personal และ BM)
        // → ห้ามส่งของเอง ต้องให้เจ้าของกดเลือกจากการ์ด Telegram
        if ($reconstruction?->ambiguous) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'needs_choice',
                amount: $slipAmount, transRef: $transRef,
                expectedAmount: $reconstruction->total, orderSummary: $reconstruction->summary,
                orderSource: 'llm', reconstruction: $reconstruction,
            ), $receiverAccount);
        }
```

และแก้สอง `return` ที่เหลือ (เช็ค 4 amount_mismatch และ return สุดท้าย) ให้ส่ง `orderSource: $orderSource, reconstruction: $reconstruction` เพิ่มเข้าไปด้วย

- [ ] **Step 6: บันทึกลงฐานข้อมูลใน `record()`**

ใน `record()` เพิ่มสองฟิลด์เข้า `SlipVerification::create([...])`:

```php
                'order_source' => $result->orderSource,
                'reconstructed' => $result->reconstruction === null ? null : [
                    'items' => $result->reconstruction->items,
                    'alternatives' => $result->reconstruction->alternatives,
                ],
```

- [ ] **Step 7: เพิ่ม `needs_choice` เข้ารายการเหตุผล**

ใน `SlipVerificationService::FAIL_REASON_LABELS` เพิ่ม:

```php
        'needs_choice' => 'ลูกค้าโอนข้ามขั้นตอน — ระบบสรุปได้หลายแบบ กรุณาเลือกรายการที่ถูกต้อง',
```

ใน `app/Http/Resources/SlipResource.php:17` เพิ่ม `'needs_choice' => 'รอเลือกรายการ',`
ใน `app/Http/Controllers/Api/SlipController.php:17` เพิ่ม `'needs_choice'` เข้า `ABNORMAL`

- [ ] **Step 8: รันเทสต์ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: PASS ทุกตัว รวมของเดิมทั้งหมด

- [ ] **Step 9: รันเทสต์ payment + delivery ทั้งชุด**

Run: `php artisan test --filter=Payment && php artisan test --filter=Delivery && php artisan test --filter=Slip`
Expected: PASS ทั้งหมด

- [ ] **Step 10: commit**

```bash
git add database/migrations/2026_07_31_110000_add_order_source_to_slip_verifications.php app/Services/Payment/ app/Http/Resources/SlipResource.php app/Http/Controllers/Api/SlipController.php tests/Feature/SlipVerificationServiceTest.php
git commit -m "feat(payment): ต่อด่านสรุปออเดอร์เองเข้าการตรวจสลิป + บันทึกว่าด่านไหนหาเจอ"
```

---

### Task 5: การ์ด Telegram — แจ้งเงียบเมื่อสำเร็จ และปุ่มให้เลือกเมื่อกำกวม

**Files:**
- Modify: `app/Services/Payment/SlipVerificationService.php` (`notifyAdmin`, `buildConfirmKeyboard`)
- Modify: `app/Services/LineWebhook/LineWebhookResponseService.php:535-555` (branch หลัง verify)
- Modify: `app/Services/Payment/ManualPaymentConfirmService.php:44-113` (`confirm` รับ items ที่เลือกมา)
- Modify: `app/Http/Controllers/Webhook/TelegramAlertCallbackController.php:66-79` (action `po`)
- Test: `tests/Feature/SlipVerificationAlertTest.php`, `tests/Feature/TelegramAlertCallbackTest.php` (ถ้าไม่มีให้สร้าง)

**Interfaces:**
- Consumes: `SlipVerification->reconstructed['alternatives']` (Task 4) · `SlipVerificationResult->orderSource`
- Produces:
  - `ManualPaymentConfirmService::confirm(Bot $bot, Conversation $conversation, ?float $amountOverride, int $confirmedBy, ?array $itemsOverride = null)` — พารามิเตอร์ตัวที่ 5 ใหม่ ถ้าส่งมาจะข้ามการหา expected เอง
  - callback_data รูปแบบใหม่ `po|{slipVerificationId}|{index}` (pick option)

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดง**

`SlipVerificationAlertTest` **ไม่มี `setUp()`** — แต่ละเทสต์สร้างของเอง เพิ่ม helper นี้เข้าไปในคลาสก่อน:

```php
    /** @return array{0: \App\Models\Bot, 1: \App\Models\Conversation} */
    private function seedBotWithTelegramPlugin(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot->fresh(), $conversation];
    }
```

แล้วเพิ่มเทสต์:

```php
    public function test_successful_reconstruction_sends_a_silent_alert_without_buttons(): void
    {
        [$bot, $conversation] = $this->seedBotWithTelegramPlugin();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: true, amount: 1100.0, transRef: 'TR1',
            expectedAmount: 1100.0, orderSummary: 'Nolimit Level Up+ Personal',
            orderSource: 'llm',
        );

        app(SlipVerificationService::class)->notifyAdmin($bot, $conversation, $result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_contains($body['text'], 'ระบบสรุปออเดอร์เองแล้ว')
                && str_contains($body['text'], 'Nolimit Level Up+ Personal')
                && ! isset($body['reply_markup']);
        });
    }

    public function test_ambiguous_result_offers_one_button_per_option(): void
    {
        [$bot, $conversation] = $this->seedBotWithTelegramPlugin();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1100,
            'status' => 'needs_choice',
            'order_source' => 'llm',
            'reconstructed' => [
                'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                'alternatives' => [
                    [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                    [['name' => 'Nolimit Level Up+ BM', 'total' => '1100', 'qty' => 1]],
                ],
            ],
        ]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'needs_choice',
            amount: 1100.0, transRef: 'TR2', orderSource: 'llm',
        );
        $result->slipVerificationId = $slip->id;

        app(SlipVerificationService::class)->notifyAdmin($bot, $conversation, $result);

        Http::assertSent(function ($request) use ($slip) {
            $keyboard = json_decode($request->data()['reply_markup'] ?? '[]', true);
            return count($keyboard['inline_keyboard'] ?? []) === 2
                && $keyboard['inline_keyboard'][0][0]['callback_data'] === "po|{$slip->id}|0"
                && str_contains($keyboard['inline_keyboard'][1][0]['text'], 'BM');
        });
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=SlipVerificationAlertTest`
Expected: FAIL — ข้อความไม่มีคำว่า "ระบบสรุปออเดอร์เองแล้ว" และไม่มีปุ่ม `po|`

- [ ] **Step 3: แก้ `notifyAdmin` ให้มีสองโหมดใหม่**

ใน `SlipVerificationService::notifyAdmin` แทนที่ส่วนสร้าง `$header`/`$lines` (บรรทัด 379-401) ด้วย:

```php
        $reason = self::FAIL_REASON_LABELS[$result->failReason] ?? ($result->failReason ?? 'unknown');
        $botName = TelegramAlertBotService::esc($bot->name);

        $header = match (true) {
            $result->passed => "🤖 <b>ลูกค้าโอนข้ามขั้นตอน — ระบบสรุปออเดอร์เองแล้ว</b> ({$botName})",
            $result->failReason === 'needs_choice' => "🤔 <b>ลูกค้าโอนข้ามขั้นตอน — เลือกรายการที่ถูกต้อง</b> ({$botName})",
            in_array($result->failReason, self::FRAUD_REASONS, true) => "🚨 <b>สลิปมีปัญหา — อย่าเพิ่งส่งของ</b> ({$botName})",
            default => "⚠️ <b>ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ</b> ({$botName})",
        };

        $lines = [$header];
        if ($conversation !== null) {
            $displayName = $conversation->customerProfile?->display_name;
            $lines[] = $displayName !== null
                ? '👤 '.TelegramAlertBotService::esc($displayName)." · แชท #{$conversation->id}"
                : "👤 แชท #{$conversation->id}";
        }
        if (! $result->passed) {
            $lines[] = 'เหตุผล: <b>'.TelegramAlertBotService::esc($reason).'</b>';
        }
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: <code>'.self::formatBaht($result->amount).'</code> บาท';
        }
        if ($result->expectedAmount !== null && ! $result->passed) {
            $lines[] = 'ยอดออเดอร์: <code>'.self::formatBaht($result->expectedAmount).'</code> บาท';
        }
        if ($result->orderSummary !== null && $result->orderSummary !== '-') {
            $lines[] = 'ออเดอร์: <b>'.TelegramAlertBotService::esc($result->orderSummary).'</b>';
        }
        $lines[] = $result->passed
            ? 'ส่งของให้แล้ว ไม่ต้องทำอะไรครับ — ถ้าไม่ถูกต้องรบกวนเปิดแชทแก้'
            : 'กรุณาเช็คในแชทก่อนยืนยัน';

        $keyboard = $result->passed ? null : $this->buildConfirmKeyboard($conversation, $result);
```

- [ ] **Step 4: เพิ่มปุ่มตัวเลือกใน `buildConfirmKeyboard`**

แทรกไว้บนสุดของ `buildConfirmKeyboard` (หลังเช็ค `$conversation === null`):

```php
        // กำกวม: หนึ่งปุ่มต่อหนึ่งตัวเลือกที่ประกอบยอดได้ — เจ้าของกดปุ่มเดียวจบ
        if ($result->failReason === 'needs_choice' && $result->slipVerificationId !== null) {
            $slip = SlipVerification::find($result->slipVerificationId);
            $alternatives = $slip?->reconstructed['alternatives'] ?? [];
            $rows = [];
            foreach ($alternatives as $index => $set) {
                $label = implode(', ', array_map(
                    fn (array $item) => ((int) ($item['qty'] ?? 1)) > 1
                        ? "{$item['name']} x{$item['qty']}"
                        : $item['name'],
                    $set,
                ));
                $rows[] = [['text' => "✅ {$label}", 'callback_data' => "po|{$result->slipVerificationId}|{$index}"]];
            }
            if ($rows !== []) {
                return $rows;
            }
        }
```

- [ ] **Step 5: รันเทสต์การ์ดให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationAlertTest`
Expected: PASS

- [ ] **Step 6: เขียนเทสต์ปุ่ม `po` ที่ต้องแดง**

เพิ่มใน `tests/Feature/TelegramAlertCallbackTest.php` ซึ่งมีอยู่แล้ว ใช้ helper เดิมของไฟล์
(`seedPlugin()` คืน `[$bot, $conversation]` และ `postCallback(string $token, array $callback)`
ซึ่งใส่ header secret ให้เอง — token คือ `access_token` ของ plugin ไม่ใช่ id):

```php
    public function test_picking_an_option_confirms_the_payment_with_the_chosen_items(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'amount' => 1100,
            'status' => 'needs_choice',
            'order_source' => 'llm',
            'reconstructed' => [
                'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                'alternatives' => [
                    [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
                    [['name' => 'Nolimit Level Up+ BM', 'total' => '1100', 'qty' => 1]],
                ],
            ],
        ]);

        $this->postCallback('TOK', [
            'id' => 'cb1',
            'from' => ['id' => 111, 'first_name' => 'owner'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => "po|{$slip->id}|1",
        ])->assertOk();

        $this->assertDatabaseHas('slip_verifications', [
            'conversation_id' => $conv->id,
            'status' => 'manual_confirmed',
        ]);
    }

    public function test_picking_an_option_that_does_not_exist_is_ignored(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        $slip = \App\Models\SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'amount' => 1100,
            'status' => 'needs_choice',
            'reconstructed' => ['items' => [], 'alternatives' => []],
        ]);

        $this->postCallback('TOK', [
            'id' => 'cb2',
            'from' => ['id' => 111, 'first_name' => 'owner'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => "po|{$slip->id}|3",
        ])->assertOk();
    }
```

- [ ] **Step 7: ให้ `confirm()` รับรายการที่เลือกมาได้**

ใน `ManualPaymentConfirmService::confirm` แก้ signature และการหา expected:

```php
    public function confirm(
        Bot $bot,
        Conversation $conversation,
        ?float $amountOverride,
        int $confirmedBy,
        ?array $itemsOverride = null,
    ): array {
        $history = $this->recentTextHistory($conversation);
        $receiverAccount = $bot->settings?->slip_receiver_account ?: null;

        // เจ้าของกดเลือกรายการจากการ์ดแล้ว → ใช้ตามนั้น ไม่ต้องเดาจากข้อความอีก
        if ($itemsOverride !== null && $itemsOverride !== []) {
            $expected = [
                'total' => $amountOverride,
                'summary' => implode(', ', array_map(
                    fn (array $item) => ((int) ($item['qty'] ?? 1)) > 1
                        ? "{$item['name']} x{$item['qty']}"
                        : $item['name'],
                    $itemsOverride,
                )),
                'items' => $itemsOverride,
            ];
        } else {
            $expected = $this->slipVerification->findExpectedPayment($history, $receiverAccount, $bot);
        }

        $amount = $amountOverride ?? ($expected['total'] ?? null);
        if ($amount === null) {
            throw new NoPendingPaymentException;
        }

        // Fallback ชั้น 3 (ลูกค้าโอนข้ามขั้นตอน): ไม่มีข้อความสรุปยอด+เลขบัญชีใน window
        // → อ่านออเดอร์จากข้อความยืนยันขั้น 2 โดยยอดต้องตรงกับยอดที่กดยืนยัน
        if ($expected === null) {
            $expected = $this->slipVerification->findExpectedFromConfirmMessage($history, $bot, (float) $amount);
        }
```

ส่วนที่เหลือของเมธอดไม่ต้องแก้ — `$expected['items']` ไหลไป `ReserveAccountStock` อยู่แล้ว

- [ ] **Step 8: จัดการ action `po` ใน callback controller**

ใน `TelegramAlertCallbackController::handle()` หลังบล็อก `dv/dx/dz` (บรรทัด 77-79) แทรก:

```php
        // action เลือกรายการ: ส่วนที่สองเป็น slip_verifications id ไม่ใช่ conversation id
        if ($act === 'po') {
            return $this->handlePickOption((int) $convId, (int) $amt, $plugin, $cb, $token, $chatId);
        }
```

และเพิ่มเมธอดใหม่ในคลาสเดียวกัน:

```php
    /**
     * เจ้าของเลือกรายการที่ถูกต้องจากการ์ด "โอนข้ามขั้นตอน" → ยืนยันรับเงินด้วยรายการนั้น
     */
    private function handlePickOption(
        int $slipId,
        int $index,
        FlowPlugin $plugin,
        array $cb,
        string $token,
        string $chatId,
    ): \Illuminate\Http\JsonResponse {
        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $cbId = $cb['id'] ?? '';
        $fromName = $cb['from']['first_name'] ?? 'admin';

        $slip = SlipVerification::find($slipId);
        $conversation = $slip?->conversation_id ? Conversation::find($slip->conversation_id) : null;
        if ($slip === null || $conversation === null || $conversation->bot_id !== $plugin->flow?->bot_id) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ไม่พบรายการนี้');

            return response()->json(['ok' => true]);
        }

        $items = $slip->reconstructed['alternatives'][$index] ?? null;
        if ($items === null) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ตัวเลือกไม่ถูกต้อง');

            return response()->json(['ok' => true]);
        }

        $bot = $conversation->bot;

        try {
            $this->confirmService->confirm($bot, $conversation, (float) $slip->amount, $bot->user_id, $items);
            $summary = implode(', ', array_column($items, 'name'));
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                '✅ <b>ยืนยันแล้ว: '.TelegramAlertBotService::esc($summary).'</b> โดย '.TelegramAlertBotService::esc($fromName));
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันแล้ว');
        } catch (RecentManualConfirmException) {
            $this->alertBot->editMessageText($token, $chatId, $messageId, '✅ <b>ยืนยันไปแล้ว</b> (โดยคนอื่นหรือทางเว็บ)');
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันไปแล้ว');
        } catch (\Throwable $e) {
            Log::error('Telegram alert pick option failed', ['slip_id' => $slipId, 'error' => $e->getMessage()]);
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ');
        }

        return response()->json(['ok' => true]);
    }
```

เพิ่ม `use App\Models\SlipVerification;` ที่หัวไฟล์ถ้ายังไม่มี

- [ ] **Step 9: ให้ webhook ส่งการ์ดตอน `needs_choice` และตอนสรุปเองสำเร็จ**

ใน `LineWebhookResponseService::trySlipVerification` แก้บล็อกผลลัพธ์ (บรรทัด 535-555):

```php
            if ($result->passed) {
                $template = $settings->slip_success_message ?: self::SLIP_SUCCESS_TEMPLATE;
                $text = str_replace(
                    ['{amount}', '{order_summary}'],
                    [number_format($result->amount ?? 0), $result->orderSummary ?? '-'],
                    $template,
                );
                // ระบบสรุปออเดอร์เองจากบทสนทนา → แจ้งเจ้าของแบบเงียบ (ไม่มีปุ่ม) ให้รู้ว่าเกิดอะไรขึ้น
                if ($result->orderSource === 'llm') {
                    $this->slipVerification->notifyAdmin($ctx->bot, $ctx->conversation, $result);
                }
            } elseif ($result->failReason === 'pending') {
```

(ส่วน `pending` และ `else` เดิมคงไว้เหมือนเดิม — `needs_choice` ตกเข้า `else` ซึ่งเรียก `notifyAdmin` อยู่แล้ว)

- [ ] **Step 10: รันเทสต์ทั้งชุด**

Run: `php artisan test --filter=Telegram && php artisan test --filter=Slip && php artisan test --filter=Payment`
Expected: PASS ทั้งหมด

- [ ] **Step 11: commit**

```bash
git add app/Services/Payment/ app/Services/LineWebhook/LineWebhookResponseService.php app/Http/Controllers/Webhook/TelegramAlertCallbackController.php tests/Feature/
git commit -m "feat(telegram): การ์ดแจ้งเงียบเมื่อระบบสรุปออเดอร์เอง + ปุ่มเลือกรายการเมื่อกำกวม"
```

---

### Task 6: การ์ด "ไม่รู้" แนบข้อความล่าสุดจากแชท

เมื่อระบบสรุปไม่ได้จริงๆ เจ้าของยังต้องตัดสินเอง แต่ไม่ควรต้องเปิดแอปไปไล่อ่าน

**Files:**
- Modify: `app/Services/Payment/SlipVerificationService.php` (`notifyAdmin`)
- Test: `tests/Feature/SlipVerificationAlertTest.php`

**Interfaces:**
- Consumes: `Conversation->messages()` · `SlipVerificationResult->failReason`
- Produces: ไม่มี interface ใหม่

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดง**

```php
    public function test_no_pending_order_alert_quotes_the_last_chat_messages(): void
    {
        [$bot, $conversation] = $this->seedBotWithTelegramPlugin();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $conversation->messages()->create(['sender' => 'user', 'content' => 'เอาเฟส 1 ตัวครับ', 'type' => 'text']);
        $conversation->messages()->create(['sender' => 'bot', 'content' => 'รับแบบผูกบัตรหรือเติมเงินครับ?', 'type' => 'text']);

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'no_pending_order',
            amount: 1100.0, transRef: 'TR3',
        );

        app(SlipVerificationService::class)->notifyAdmin($bot, $conversation, $result);

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];
            return str_contains($text, 'เอาเฟส 1 ตัวครับ')
                && str_contains($text, 'รับแบบผูกบัตรหรือเติมเงินครับ?');
        });
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `php artisan test --filter=test_no_pending_order_alert_quotes_the_last_chat_messages`
Expected: FAIL — ข้อความไม่มีบทสนทนา

- [ ] **Step 3: แนบข้อความล่าสุดเข้าไปในการ์ด**

ใน `notifyAdmin` ก่อนบรรทัดสรุปท้าย (`$lines[] = $result->passed ? ... : 'กรุณาเช็คในแชทก่อนยืนยัน';`) แทรก:

```php
        // ระบบหาออเดอร์ไม่เจอเลย → แนบบทสนทนาล่าสุดมาให้ตัดสินได้จากในการ์ด ไม่ต้องเปิดแอป
        if ($result->failReason === 'no_pending_order' && $conversation !== null) {
            foreach ($this->recentChatQuotes($conversation) as $quote) {
                $lines[] = $quote;
            }
        }
```

และเพิ่มเมธอดใหม่:

```php
    /**
     * สามข้อความล่าสุดในแชท (ตัดให้สั้น) สำหรับแปะในการ์ดตอนระบบหาออเดอร์ไม่เจอ
     *
     * @return array<int, string>
     */
    private function recentChatQuotes(Conversation $conversation, int $limit = 3): array
    {
        $messages = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text')
            ->latest('id')
            ->take($limit)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return [];
        }

        $quotes = ['💬 <i>ข้อความล่าสุดในแชท</i>'];
        foreach ($messages as $message) {
            $who = $message->sender === 'user' ? 'ลูกค้า' : 'บอท';
            $text = mb_substr(str_replace('|||', ' ', (string) $message->content), 0, 120);
            $quotes[] = "· {$who}: ".TelegramAlertBotService::esc($text);
        }

        return $quotes;
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationAlertTest`
Expected: PASS ทุกตัว

- [ ] **Step 5: รันเทสต์ทั้งโปรเจกต์**

Run: `php artisan test`
Expected: PASS ทั้งหมด (ถ้ามีเทสต์ที่แดงอยู่ก่อนเริ่มงาน ให้เทียบกับผลตอนเริ่ม — ต้องไม่มีตัวใหม่แดงเพิ่ม)

- [ ] **Step 6: commit**

```bash
git add app/Services/Payment/SlipVerificationService.php tests/Feature/SlipVerificationAlertTest.php
git commit -m "feat(telegram): แนบข้อความล่าสุดในแชทไปกับการ์ดตอนระบบหาออเดอร์ไม่เจอ"
```

---

## หลัง merge — สิ่งที่ต้องทำบน production

1. รัน migration บน Railway: `php artisan migrate --force` (สองตัว: `price`, `order_source` + `reconstructed`)
2. ตรวจว่าราคาลงครบ:
   ```sql
   SELECT slug, name, price FROM product_stocks ORDER BY display_order;
   ```
   ต้องได้ personal 1100 · bm 1100 · page 199 · g3d 50 — **ให้เจ้าของยืนยันตัวเลขก่อน**
3. ตรวจว่า bot 26 ตั้ง utility model ไว้แล้ว (ถ้าไม่ตั้ง ด่าน 3 จะไม่ทำงานเลย เงียบๆ)
4. เฝ้าเคสถัดไปด้วย query นี้:
   ```sql
   SELECT status, order_source, COUNT(*), SUM(amount)
   FROM slip_verifications
   WHERE created_at > '2026-08-01'
   GROUP BY status, order_source ORDER BY 3 DESC;
   ```
   สิ่งที่อยากเห็น: `no_pending_order` ลดลง และมี `passed` ที่ `order_source = 'llm'` โผล่มาแทน
5. ถ้าเจอ `needs_choice` บ่อยเกินไป (เกิน 1 ครั้ง/สัปดาห์) ค่อยพิจารณา Phase 2 (ให้บอทถามลูกค้าเอง) ตามที่ระบุไว้ในสเปก
