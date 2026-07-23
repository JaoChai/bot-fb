# Stock Count Injection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** บอทรู้จำนวน stock คงเหลือจริง → ไม่รับออเดอร์เกินของที่มี ลูกค้าสั่งเกินให้เสนอขายเท่าที่มี

**Architecture:** เพิ่ม `available_count` ใน `product_stocks` → `stock:sync-pool` (cron ทุก 5 นาที) บันทึกจำนวนจริงจาก `items_available` → `StockInjectionService` ฉีดจำนวน + กติกาเข้า prompt (หัว + ท้าย double-injection) → แก้ gate ใน `RAGService` ให้ฉีดแม้ไม่มีสินค้าหมด

**Tech Stack:** Laravel 13, PHPUnit (sqlite :memory: + `InteractsWithStockPool` trait), Pest-style ไม่ใช้ — เป็น PHPUnit class เดิม

**Spec:** `docs/superpowers/specs/2026-07-23-stock-count-injection-design.md`

## Global Constraints

- บอทพูดเรื่องจำนวนคงเหลือกับลูกค้า **เฉพาะตอนลูกค้าสั่งเกิน** — กติกานี้ต้องอยู่ในข้อความ injection ตรงตาม spec
- ห้ามแตะระบบ delivery ปลายทาง (`AccountDeliveryService`, `StockPoolService`) และ `StockGuardService`
- `RagCache::purgeForProduct` ล้างเฉพาะตอน `in_stock` toggle เท่านั้น (จำนวนเปลี่ยนอย่างเดียวไม่ล้าง RagCache — ล้างแค่ `STOCK_CACHE_KEY`)
- ทุก comment ในโค้ดเป็นภาษาไทยตามสไตล์โปรเจกต์
- รันเทสต์จาก `/Users/jaochai/Code/bot-fb/backend` ด้วย `php artisan test --filter=<ชื่อ>`
- **ห้าม commit โดย executor** — Claude เป็นคนตรวจ + commit เอง (ตาม GLM Executor Mode)

---

### Task 1: Migration + Model — เพิ่ม `available_count`

**Files:**
- Create: `backend/database/migrations/2026_07_23_100000_add_available_count_to_product_stocks.php`
- Modify: `backend/app/Models/ProductStock.php` (fillable + casts)
- Test: `backend/tests/Feature/SyncProductStockFromPoolTest.php` (เทสต์ persist ชั่วคราวใน Task นี้ — เทสต์จริงจังอยู่ Task 2)

**Interfaces:**
- Produces: column `product_stocks.available_count` (integer, nullable, default null) — Task 2 เขียนค่า, Task 3 อ่านผ่าน model attribute `$product->available_count` (int|null)

- [ ] **Step 1: เขียน failing test** — เพิ่ม method นี้ท้าย class `SyncProductStockFromPoolTest`:

```php
public function test_available_count_column_persists(): void
{
    $p = ProductStock::where('slug', 'nolimit-personal')->first();
    $p->update(['available_count' => 5]);

    $this->assertSame(5, $p->fresh()->available_count);
}
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `php artisan test --filter=test_available_count_column_persists`
Expected: FAIL (column not found / ค่าไม่ persist เพราะไม่อยู่ใน fillable)

- [ ] **Step 3: สร้าง migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // จำนวนคงเหลือจริงจาก mhha items_available (sync โดย stock:sync-pool ทุก 5 นาที)
            // null = สินค้าไม่ใช่แบบ stock pool (เช่น support_link) ไม่เกี่ยวกับจำนวน
            $table->integer('available_count')->nullable()->after('manual_off');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('available_count');
        });
    }
};
```

- [ ] **Step 4: แก้ model** — `backend/app/Models/ProductStock.php` เพิ่มใน `$fillable` และ `$casts`:

```php
    protected $fillable = [
        'name',
        'slug',
        'aliases',
        'in_stock',
        'manual_off',
        'available_count',
        'display_order',
        'stock_code',
        'delivery_method',
    ];

    protected $casts = [
        'aliases' => 'array',
        'in_stock' => 'boolean',
        'manual_off' => 'boolean',
        'available_count' => 'integer',
    ];
```

หมายเหตุ: cast `integer` กับค่า null → Laravel คืน null (ไม่ใช่ 0) — พฤติกรรมที่ต้องการ

- [ ] **Step 5: รัน test ให้ผ่าน**

Run: `php artisan test --filter=test_available_count_column_persists`
Expected: PASS

---

### Task 2: `stock:sync-pool` บันทึกจำนวน + cache bust เมื่อจำนวนเปลี่ยน

**Files:**
- Modify: `backend/app/Console/Commands/SyncProductStockFromPool.php:34-55`
- Test: `backend/tests/Feature/SyncProductStockFromPoolTest.php`

**Interfaces:**
- Consumes: `available_count` จาก Task 1; `StockPoolService::countAvailable(): array` (map stock_code => จำนวน — มีอยู่แล้ว ห้ามแก้)
- Produces: `product_stocks.available_count` อัปเดตทุกรอบ cron; `Cache::forget(ProductStock::STOCK_CACHE_KEY)` เมื่อจำนวนหรือสวิตช์เปลี่ยน

- [ ] **Step 1: เขียน failing tests** — เพิ่ม 2 methods ใน `SyncProductStockFromPoolTest` และ**แก้เทสต์เดิม 1 ตัว**:

เพิ่มใหม่:

```php
public function test_records_available_count_for_stock_products(): void
{
    $this->seedAvailable(5, 'NLMP');

    $this->artisan('stock:sync-pool')->assertSuccessful();

    $this->assertSame(5, ProductStock::where('slug', 'nolimit-personal')->first()->available_count);
    // support_link ไม่เกี่ยวกับ pool — ต้องเป็น null เสมอ
    $this->assertNull(ProductStock::where('slug', 'page')->first()->available_count);
}

public function test_count_change_busts_cache_even_without_toggle(): void
{
    // in_stock true อยู่แล้วและยังมีของ (ไม่ toggle) แต่จำนวนเปลี่ยน 1 → 2 → ต้องล้าง cache
    $this->seedAvailable(2, 'NLMP');
    ProductStock::where('slug', 'nolimit-personal')->update(['available_count' => 1]);
    Cache::put(ProductStock::STOCK_CACHE_KEY, 'stale', 300);

    $this->artisan('stock:sync-pool')->assertSuccessful();

    $this->assertSame(2, ProductStock::where('slug', 'nolimit-personal')->first()->available_count);
    $this->assertNull(Cache::get(ProductStock::STOCK_CACHE_KEY));
}
```

แก้เทสต์เดิม `test_no_change_no_cache_bust` (บรรทัด 53-61) — ต้อง pre-set `available_count` ให้ตรงกับ pool ไม่งั้นรอบแรกจะนับเป็น "จำนวนเปลี่ยน (null→1)" แล้ว bust cache เสมอ:

```php
public function test_no_change_no_cache_bust(): void
{
    $this->seedAvailable(1, 'NLMP'); // มีของ + in_stock=true อยู่แล้ว
    ProductStock::where('slug', 'nolimit-personal')->update(['available_count' => 1]);
    Cache::put(ProductStock::STOCK_CACHE_KEY, 'keep', 300);

    $this->artisan('stock:sync-pool')->assertSuccessful();

    $this->assertSame('keep', Cache::get(ProductStock::STOCK_CACHE_KEY));
}
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `php artisan test --filter=SyncProductStockFromPoolTest`
Expected: 2 เทสต์ใหม่ FAIL (available_count ยังเป็น null / cache ไม่ถูกล้าง), เทสต์เดิมอื่นๆ PASS

- [ ] **Step 3: แก้ `SyncProductStockFromPool::handle()`** — แทน loop เดิม (บรรทัด 34-51) ด้วย:

```php
        $changed = 0;
        $products = ProductStock::where('delivery_method', 'stock')->whereNotNull('stock_code')->get();
        foreach ($products as $product) {
            $count = (int) ($counts[$product->stock_code] ?? 0);
            // ปิดค้างที่เจ้าของสั่งเองต้องชนะ pool (แต่ pool ว่างยังบังคับปิด กัน oversell)
            $shouldBeInStock = $count > 0 && ! $product->manual_off;
            $toggled = $product->in_stock !== $shouldBeInStock;
            if (! $toggled && $product->available_count === $count) {
                continue;
            }

            DB::transaction(function () use ($product, $shouldBeInStock, $count, $toggled) {
                $product->update(['in_stock' => $shouldBeInStock, 'available_count' => $count]);
                // RagCache ล้างเฉพาะตอนสวิตช์เปลี่ยน — จำนวนเปลี่ยนอย่างเดียวไม่กระทบคำตอบใน RAG cache
                if ($toggled) {
                    RagCache::purgeForProduct($product);
                }
            });
            $changed++;
            Log::info('stock:sync-pool updated', [
                'slug' => $product->slug, 'in_stock' => $shouldBeInStock, 'available_count' => $count,
            ]);
        }
```

(ส่วน `if ($changed > 0) { Cache::forget(...); }` ท้าย method คงเดิม — จำนวนเปลี่ยนก็นับเป็น `$changed` แล้ว bust cache อัตโนมัติ)

- [ ] **Step 4: รัน test ทั้งไฟล์ให้ผ่าน**

Run: `php artisan test --filter=SyncProductStockFromPoolTest`
Expected: PASS ทั้งหมด (รวม manual_off cases เดิม)

---

### Task 3: `StockInjectionService` — ฉีดจำนวน + กติกา (หัว + ท้าย)

**Files:**
- Modify: `backend/app/Services/StockInjectionService.php` (`buildStockInjection` บรรทัด 33-69, `buildStockReminder` บรรทัด 71-90)
- Test: Create `backend/tests/Unit/Services/StockInjectionServiceTest.php`

**Interfaces:**
- Consumes: `$product->available_count` (int|null) จาก Task 1
- Produces:
  - `buildStockInjection(Collection $stocks): string` — มี section `[จำนวนพร้อมส่ง]: ...` + กติกา เมื่อมีสินค้า in-stock ที่ `available_count !== null`
  - `buildStockReminder(Collection $stocks): string` — **เปลี่ยน contract:** คืนข้อความไม่ว่างเมื่อ (มีสินค้าหมด) หรือ (มีสินค้า in-stock ที่มีจำนวน) — เดิมคืน '' ถ้าไม่มีสินค้าหมด; Task 4 พึ่ง contract ใหม่นี้

- [ ] **Step 1: เขียน failing tests** — สร้างไฟล์ใหม่:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\ProductStock;
use App\Services\StockInjectionService;
use Tests\TestCase;

class StockInjectionServiceTest extends TestCase
{
    private StockInjectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockInjectionService;
    }

    /** สินค้า stock ที่มีจำนวน — ไม่ต้องแตะ DB ใช้ make() พอ */
    private function stockProduct(string $name, ?int $count, bool $inStock = true): ProductStock
    {
        return ProductStock::make([
            'name' => $name, 'slug' => str_replace(' ', '-', $name), 'aliases' => [],
            'in_stock' => $inStock, 'available_count' => $count,
            'stock_code' => 'X', 'delivery_method' => 'stock',
        ]);
    }

    public function test_injection_shows_qty_and_rules_for_counted_products(): void
    {
        $result = $this->service->buildStockInjection(collect([
            $this->stockProduct('BM แดง', 5),
        ]));

        $this->assertStringContainsString('[จำนวนพร้อมส่ง]: BM แดง = 5 ชิ้น', $result);
        $this->assertStringContainsString('ห้ามรับออเดอร์/เพิ่มตะกร้า/สรุปยอดเกินจำนวนพร้อมส่ง', $result);
        $this->assertStringContainsString('ห้ามพูดถึงจำนวนคงเหลือ', $result);
        $this->assertStringContainsString('เสนอขายเท่าที่มี', $result);
    }

    public function test_injection_skips_qty_for_null_count_and_out_of_stock(): void
    {
        $result = $this->service->buildStockInjection(collect([
            $this->stockProduct('เพจ', null),          // support_link ไม่มีจำนวน
            $this->stockProduct('BM เขียว', 7, false), // ของหมด — ห้ามโชว์เลข
        ]));

        $this->assertStringNotContainsString('[จำนวนพร้อมส่ง]', $result);
        $this->assertStringNotContainsString('= 7', $result);
    }

    public function test_reminder_includes_qty_line_when_counts_exist(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('BM แดง', 5),
        ]));

        $this->assertStringContainsString('QTY REMINDER', $result);
        $this->assertStringContainsString('BM แดง = 5', $result);
        $this->assertStringContainsString('เสนอขายเท่าที่มี', $result);
    }

    public function test_reminder_combines_out_of_stock_and_qty(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('BM แดง', 5),
            $this->stockProduct('BM เขียว', null, false),
        ]));

        $this->assertStringContainsString('STOCK REMINDER', $result); // ของหมดเดิม
        $this->assertStringContainsString('QTY REMINDER', $result);   // จำนวนใหม่
    }

    public function test_reminder_empty_when_nothing_to_say(): void
    {
        $result = $this->service->buildStockReminder(collect([
            $this->stockProduct('เพจ', null), // in stock, ไม่มีจำนวน
        ]));

        $this->assertSame('', $result);
    }
}
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `php artisan test --filter=StockInjectionServiceTest`
Expected: FAIL ทุกตัวยกเว้น `test_reminder_empty_when_nothing_to_say` (พฤติกรรมเดิมผ่านอยู่แล้ว)

- [ ] **Step 3: แก้ `buildStockInjection`** — เพิ่มก่อนบรรทัด `$lines[] = 'ห้ามขาย/เพิ่มตะกร้า...'` (บรรทัด 57):

```php
        $withQty = $inStock->filter(fn ($p) => $p->available_count !== null);
        if ($withQty->isNotEmpty()) {
            $lines[] = '[จำนวนพร้อมส่ง]: '
                .$withQty->map(fn ($p) => "{$p->name} = {$p->available_count} ชิ้น")->implode(', ');
            $lines[] = 'ห้ามรับออเดอร์/เพิ่มตะกร้า/สรุปยอดเกินจำนวนพร้อมส่งเด็ดขาด!';
            $lines[] = '- ลูกค้าสั่งไม่เกินจำนวนพร้อมส่ง → ขายปกติ ห้ามพูดถึงจำนวนคงเหลือ';
            $lines[] = '- ลูกค้าสั่งเกิน → เสนอขายเท่าที่มี บอกจำนวนที่พร้อมส่งตอนนี้'
                .' และแจ้งว่าส่วนที่เหลือของเข้าแล้วจะรีบแจ้ง (สรุปยอด/ราคาตามจำนวนที่ขายจริงเท่านั้น)';
        }
```

- [ ] **Step 4: แก้ `buildStockReminder`** — แทนทั้ง method:

```php
    public function buildStockReminder(Collection $stocks): string
    {
        $parts = [];

        $outOfStock = $stocks->where('in_stock', false);
        if ($outOfStock->isNotEmpty()) {
            $names = $outOfStock->pluck('name')->implode(', ');
            $inStock = $stocks->where('in_stock', true);

            $reminder = "⛔ STOCK REMINDER: สินค้าหมด stock → {$names} — ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์เด็ดขาด! ตอบราคา/รายละเอียดได้ถ้าลูกค้าถาม + ต้องแจ้งว่าหมดชั่วคราว พร้อมบอกสาเหตุ (".$this->buildOutOfStockReason($names).')';

            if ($inStock->isNotEmpty()) {
                $inStockNames = $inStock->pluck('name')->implode(', ');
                $reminder .= " + แนะนำใช้ {$inStockNames} แทนก่อน";
            }
            $parts[] = $reminder;
        }

        // double-injection กติกาจำนวน — LLM มักลืมกติกาที่อยู่ต้น prompt
        $withQty = $stocks->where('in_stock', true)->filter(fn ($p) => $p->available_count !== null);
        if ($withQty->isNotEmpty()) {
            $qtyList = $withQty->map(fn ($p) => "{$p->name} = {$p->available_count}")->implode(', ');
            $parts[] = "⛔ QTY REMINDER: จำนวนพร้อมส่ง → {$qtyList} — ห้ามรับออเดอร์เกินจำนวนนี้!"
                .' ลูกค้าสั่งเกิน → เสนอขายเท่าที่มี + แจ้งว่าส่วนที่เหลือของเข้าแล้วจะรีบแจ้ง'
                .' (สั่งไม่เกิน → ขายปกติ ห้ามพูดถึงจำนวนคงเหลือ)';
        }

        return implode("\n", $parts);
    }
```

- [ ] **Step 5: รัน test ให้ผ่าน + เช็คไม่พังของเดิม**

Run: `php artisan test --filter=StockInjectionServiceTest && php artisan test --filter=RAGServiceTest && php artisan test --filter=StockGuardServiceTest`
Expected: PASS ทั้งหมด (RAGService/StockGuard ใช้ builder เดิม — ค่า available_count ใน test เดิมเป็น null จึงไม่มีอะไรเปลี่ยน)

---

### Task 4: `RAGService` gate — ฉีด stock เมื่อมีจำนวน แม้ไม่มีสินค้าหมด

**Files:**
- Modify: `backend/app/Services/RAGService.php:400-429`
- Test: `backend/tests/Unit/Services/RAGServiceTest.php` (เพิ่ม 2 เทสต์)

**Interfaces:**
- Consumes: contract ใหม่ของ `buildStockReminder` จาก Task 3 (คืนไม่ว่างเมื่อมีจำนวน)
- Produces: prompt สุดท้ายมีทั้ง `[จำนวนพร้อมส่ง]` (หัว) และ `QTY REMINDER` (ท้าย) เมื่อสินค้า in-stock มี `available_count`

- [ ] **Step 1: เขียน failing tests** — เพิ่มใน `RAGServiceTest`:

```php
    public function test_build_enhanced_prompt_injects_qty_when_all_in_stock(): void
    {
        // ไม่มีสินค้าหมดเลย แต่มีจำนวนคงเหลือ → ต้องฉีดทั้งหัวและท้าย
        ProductStock::create([
            'name' => 'BM แดง', 'slug' => 'bm-red', 'aliases' => [], 'in_stock' => true,
            'available_count' => 5, 'display_order' => 1,
            'stock_code' => 'BMRED', 'delivery_method' => 'stock',
        ]);
        Cache::forget(ProductStock::STOCK_CACHE_KEY);

        $result = $this->callBuildEnhancedPrompt('Base prompt.', '');

        $this->assertStringContainsString('[จำนวนพร้อมส่ง]: BM แดง = 5 ชิ้น', $result);
        $this->assertStringContainsString('QTY REMINDER', $result);
    }

    public function test_build_enhanced_prompt_no_stock_section_without_counts_or_outage(): void
    {
        // ของครบทุกตัวและไม่มีจำนวน (เช่น support_link) → พฤติกรรมเดิม: ไม่ฉีดอะไร
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => [], 'in_stock' => true,
            'display_order' => 1, 'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
        Cache::forget(ProductStock::STOCK_CACHE_KEY);

        $result = $this->callBuildEnhancedPrompt('Base prompt.', '');

        $this->assertStringNotContainsString('STOCK STATUS', $result);
        $this->assertStringNotContainsString('QTY REMINDER', $result);
    }
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `php artisan test --filter=RAGServiceTest::test_build_enhanced_prompt_injects_qty_when_all_in_stock`
Expected: FAIL (gate `$hasOutOfStock` กันไว้ — ไม่มีของหมด = ไม่ฉีดเลย)

- [ ] **Step 3: แก้ gate ใน `buildEnhancedPrompt`** — บรรทัด 400-429 เปลี่ยนเป็น:

```php
        // Always inject stock — conditional injection caused sales of out-of-stock products
        $stocks = $this->stockInjectionService->getStockStatus();
        $hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();
        // มีจำนวนคงเหลือให้คุมโควตาการขาย แม้ของจะยังไม่หมดก็ต้องฉีด (กันรับออเดอร์เกิน stock)
        $hasQty = $stocks->where('in_stock', true)->filter(fn ($p) => $p->available_count !== null)->isNotEmpty();

        if ($hasOutOfStock || $hasQty) {
            $stockInjection = $this->stockInjectionService->buildStockInjection($stocks);
            if (! empty($stockInjection)) {
                $prompt .= "\n\n".$stockInjection;
            }
        }
```

และ gate ของ reminder (บรรทัด 424 เดิม) เปลี่ยน `if ($hasOutOfStock)` เป็น `if ($hasOutOfStock || $hasQty)` (โค้ดข้างในคงเดิม)

- [ ] **Step 4: รัน test ให้ผ่านทั้งไฟล์**

Run: `php artisan test --filter=RAGServiceTest`
Expected: PASS ทั้งหมด (เทสต์เดิมสร้าง ProductStock โดยไม่มี available_count → null → gate เดิมไม่เปลี่ยนพฤติกรรม)

- [ ] **Step 5: รัน backend test suite เต็ม**

Run: `php artisan test`
Expected: PASS ทั้งหมด — ถ้ามีเทสต์อื่น fail ที่เกี่ยวกับ stock injection ให้รายงาน ห้ามแก้เทสต์ที่ไม่เกี่ยวกับงานนี้เอง

---

## หลัง implement เสร็จ (Claude ทำเอง ไม่ใช่ executor)

1. `/simplify` ก่อน commit (ตาม workflow เจ้าของ)
2. Commit แยกตาม task + push + deploy Railway
3. รัน migration บน prod
4. Manual E2E: ผ่าน emulator — ตั้ง stock สินค้าให้เหลือน้อย แล้วสั่งเกิน → บอทต้องเสนอขายเท่าที่มี ไม่สรุปยอดเกิน / สั่งไม่เกิน → ต้องไม่พูดถึงจำนวนคงเหลือ
5. เฝ้ารอบ cron `stock:sync-pool` แรกบน prod ว่า `available_count` ขึ้นครบทุกสินค้า stock
