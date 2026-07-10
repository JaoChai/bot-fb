# Auto Account Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** หลังยืนยันเงิน (EasySlip ผ่าน / เจ้าของกดยืนยัน) ระบบจองบัญชีจาก `mhha_acc_db` แบบ atomic ทันที ส่งการ์ด + ปุ่มเข้า Telegram แล้วส่ง credential ให้ลูกค้าใน LINE เมื่อเจ้าของกดยืนยัน

**Architecture:** ของหนึ่งชิ้นอยู่ได้ที่เดียวเสมอ (`items_available` → `items_reserved` → `items_sold`) ผ่าน `DELETE ... RETURNING` + `FOR UPDATE SKIP LOCKED` บน connection ใหม่ `mhha_acc` — ฝั่ง bot-fb เก็บงานส่งของใน `account_deliveries`/`account_delivery_items` (เก็บแค่ id อ้างอิง ไม่ก๊อป credential) ปุ่ม Telegram ต่อยอด `TelegramAlertCallbackController` เดิมด้วย action `dv`/`dx`/`dz`

**Tech Stack:** Laravel 13, PHPUnit 12 (class-based, sqlite `:memory:`), Postgres 17 (Neon) x2 โปรเจกต์, Telegram Bot API (ผ่าน `TelegramAlertBotService` เดิม), LINE Messaging API (ผ่าน `LINEService` เดิม)

**Spec:** `docs/superpowers/specs/2026-07-10-auto-account-delivery-design.md`

## Global Constraints

- **ห้าม log ค่า `detail` (credential) ทุกกรณี** — log ได้แค่ id / stock_code
- ทุก query ไป `mhha_acc_db` ใช้ `DB::connection('mhha_acc')` เท่านั้น และแตะได้แค่ `items_available` / `items_reserved` / `items_sold` (additive — ห้ามแก้โครงตารางเดิม)
- คอลัมน์ mhha เป็น camelCase (`createdAt`, `viaId`, …) — query builder จะ quote ให้เอง แต่ใน raw SQL ต้องใส่ double quote เช่น `"createdAt"`
- Feature ปิดโดย default: `ACCOUNT_DELIVERY_ENABLED=false` + จำกัด bot ผ่าน `ACCOUNT_DELIVERY_BOT_IDS` (prod = id ของ bot 26)
- callback_data ของ Telegram มีรูปแบบ 3 ส่วนเสมอ `action|id|extra` (controller เดิม reject ถ้าไม่ใช่ 3 ส่วน) — action ใหม่: `dv` (ส่ง), `dx` (ยกเลิกขั้น 1), `dz` (ยกเลิกขั้น 2)
- สถานะ `account_deliveries.status`: `reserving → reserved → delivering → delivered | canceled | failed`
- สถานะ `account_delivery_items.status`: `reserved | delivered | shortage | unmapped | returned`
- ข้อความ template ลิงก์ Support ต้องตรง verbatim (ดู Task 2)
- Deviation จาก spec ที่จงใจ (เหตุผล: fail-safe ง่ายกว่า): job จองไม่ retry (`tries=1`) — ถ้า mhha DB ล่ม ทุกชิ้นกลายเป็น `shortage` และการ์ดบอก "ส่งเอง" แทนการ retry; และไม่มีคอลัมน์ `order_id`/`telegram_message_id` (YAGNI — reminder ส่งการ์ดใหม่แทนการ edit)
- รันเทสต์จาก `backend/`: `php artisan test --filter=<ชื่อ>`
- ทุก commit ทำจากราก repo; ใช้ prefix path `backend/...`

## File Structure

| ไฟล์ | หน้าที่ |
|---|---|
| `backend/database/migrations/2026_07_10_200000_create_account_deliveries_tables.php` | สร้าง 2 ตาราง + เพิ่มคอลัมน์ `product_stocks` |
| `backend/app/Models/AccountDelivery.php`, `AccountDeliveryItem.php` | โมเดลงานส่งของ |
| `backend/config/database.php` | connection `mhha_acc` |
| `backend/config/delivery.php` | flag, bot ids, remind interval, support template |
| `backend/app/Services/Delivery/StockPoolService.php` | จอง/คืน/ขาย/นับ ของใน mhha |
| `backend/app/Services/Delivery/ProductMapper.php` | ชื่อสินค้าในออเดอร์ → `ProductStock` (stock_code) |
| `backend/app/Services/Delivery/AccountDeliveryService.php` | createFromPayment / deliver / cancel / sendCard |
| `backend/app/Exceptions/DeliveryAlreadyHandledException.php` | กันกดซ้ำ |
| `backend/app/Jobs/ReserveAccountStock.php` | queue job จองหลังยืนยันเงิน |
| `backend/app/Services/Payment/SlipVerificationResult.php` (แก้) | + `slipVerificationId`, `orderItems` |
| `backend/app/Services/Payment/SlipVerificationService.php` (แก้) | findExpectedPayment คืน items, record() ใส่ id |
| `backend/app/Services/LineWebhook/LineWebhookResponseService.php` (แก้) | dispatch job ใน branch passed |
| `backend/app/Services/Payment/ManualPaymentConfirmService.php` (แก้) | dispatch job หลัง confirm |
| `backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php` (แก้) | action `dv`/`dx`/`dz` |
| `backend/app/Console/Commands/RemindPendingDeliveries.php` | `delivery:remind` |
| `backend/app/Console/Commands/ReconcileDeliveries.php` | `delivery:reconcile` |
| `backend/app/Console/Commands/SyncProductStockFromPool.php` | `stock:sync-pool` |
| `backend/routes/console.php` (แก้) | schedule 3 คำสั่ง |
| `backend/tests/Support/InteractsWithStockPool.php` | trait สร้างตาราง mhha จำลองบน sqlite |

---

### Task 1: ตาราง + โมเดลฝั่ง bot-fb

**Files:**
- Create: `backend/database/migrations/2026_07_10_200000_create_account_deliveries_tables.php`
- Create: `backend/app/Models/AccountDelivery.php`
- Create: `backend/app/Models/AccountDeliveryItem.php`
- Modify: `backend/app/Models/ProductStock.php`
- Test: `backend/tests/Feature/AccountDeliveryModelTest.php`

**Interfaces:**
- Produces: model `AccountDelivery` (const `STATUS_RESERVING/RESERVED/DELIVERING/DELIVERED/CANCELED/FAILED`, relations `items()`, `bot()`, `conversation()`), model `AccountDeliveryItem` (const `KIND_STOCK/SUPPORT_LINK/MANUAL`, `ST_RESERVED/DELIVERED/SHORTAGE/UNMAPPED/RETURNED`), `ProductStock` fillable + `stock_code`, `delivery_method`

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeliveryModelTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        return [$bot, $conversation, $slip];
    }

    public function test_creates_delivery_with_items(): void
    {
        [$bot, $conv, $slip] = $this->seed();

        $delivery = AccountDelivery::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVING,
            'amount' => 1100,
        ]);
        $delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP',
            'kind' => 'stock', 'qty' => 1, 'stock_item_id' => 42, 'status' => 'reserved',
        ]);

        $this->assertSame(1, $delivery->items()->count());
        $this->assertSame('NLMP', $delivery->items->first()->stock_code);
    }

    public function test_slip_verification_id_is_unique(): void
    {
        [$bot, $conv, $slip] = $this->seed();
        $attrs = [
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => 'reserving',
        ];
        AccountDelivery::create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        AccountDelivery::create($attrs);
    }

    public function test_product_stock_has_delivery_columns(): void
    {
        $p = ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal',
            'aliases' => ['Nolimit', 'NLM ส่วนตัว'], 'in_stock' => true,
            'display_order' => 1, 'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);

        $this->assertSame('NLMP', $p->fresh()->stock_code);
        $this->assertSame('stock', $p->fresh()->delivery_method);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=AccountDeliveryModelTest`
Expected: FAIL — `Class "App\Models\AccountDelivery" not found`

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
        Schema::create('account_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('slip_verification_id')->unique()
                ->constrained('slip_verifications')->onDelete('cascade');
            // reserving|reserved|delivering|delivered|canceled|failed
            $table->string('status', 20)->default('reserving');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('confirmed_by')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('account_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_delivery_id')->constrained()->onDelete('cascade');
            $table->string('product_name');
            $table->string('stock_code', 20)->nullable();
            $table->string('kind', 20); // stock|support_link|manual
            $table->unsignedInteger('qty')->default(1);
            // id ของแถวใน mhha_acc_db (items_reserved/items_sold) — ไม่ใช่ FK ข้าม DB
            $table->integer('stock_item_id')->nullable();
            // reserved|delivered|shortage|unmapped|returned
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->string('stock_code', 20)->nullable();
            $table->string('delivery_method', 20)->default('none'); // none|stock|support_link
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn(['stock_code', 'delivery_method']);
        });
        Schema::dropIfExists('account_delivery_items');
        Schema::dropIfExists('account_deliveries');
    }
};
```

- [ ] **Step 4: สร้างโมเดล**

`backend/app/Models/AccountDelivery.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountDelivery extends Model
{
    public const STATUS_RESERVING = 'reserving';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'bot_id', 'conversation_id', 'slip_verification_id', 'status',
        'amount', 'confirmed_by', 'delivered_at', 'last_reminded_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'delivered_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountDeliveryItem::class);
    }
}
```

`backend/app/Models/AccountDeliveryItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDeliveryItem extends Model
{
    public const KIND_STOCK = 'stock';

    public const KIND_SUPPORT_LINK = 'support_link';

    public const KIND_MANUAL = 'manual';

    public const ST_RESERVED = 'reserved';

    public const ST_DELIVERED = 'delivered';

    public const ST_SHORTAGE = 'shortage';

    public const ST_UNMAPPED = 'unmapped';

    public const ST_RETURNED = 'returned';

    protected $fillable = [
        'account_delivery_id', 'product_name', 'stock_code', 'kind',
        'qty', 'stock_item_id', 'status',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AccountDelivery::class, 'account_delivery_id');
    }
}
```

แก้ `backend/app/Models/ProductStock.php` — เพิ่มใน `$fillable`:

```php
    protected $fillable = [
        'name',
        'slug',
        'aliases',
        'in_stock',
        'display_order',
        'stock_code',
        'delivery_method',
    ];
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=AccountDeliveryModelTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_07_10_200000_create_account_deliveries_tables.php backend/app/Models/AccountDelivery.php backend/app/Models/AccountDeliveryItem.php backend/app/Models/ProductStock.php backend/tests/Feature/AccountDeliveryModelTest.php
git commit -m "feat(delivery): account_deliveries tables + models"
```

---

### Task 2: connection `mhha_acc` + config delivery + test trait ตารางจำลอง

**Files:**
- Modify: `backend/config/database.php` (หลัง block `'pgsql' => [...]`)
- Create: `backend/config/delivery.php`
- Create: `backend/tests/Support/InteractsWithStockPool.php`
- Test: `backend/tests/Feature/StockPoolConnectionTest.php`

**Interfaces:**
- Produces: connection name `'mhha_acc'`, `config('delivery.enabled'|'bot_ids'|'remind_after_minutes'|'support_link_template')`, trait method `setUpStockPool(): void` และ `seedAvailable(int $id, string $code, string $detail = 'uid|pass|mail|2fa', string $type = 'x'): void`

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class StockPoolConnectionTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    public function test_trait_creates_pool_tables_and_seeds(): void
    {
        $this->setUpStockPool();
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'G3D', 'fbuid|fbpass|2FAKEY');

        $this->assertSame(2, DB::connection('mhha_acc')->table('items_available')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_delivery_config_has_defaults(): void
    {
        $this->assertFalse(config('delivery.enabled'));
        $this->assertIsArray(config('delivery.bot_ids'));
        $this->assertStringContainsString('lin.ee/sTD5TQL', config('delivery.support_link_template'));
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=StockPoolConnectionTest`
Expected: FAIL — `Trait "Tests\Support\InteractsWithStockPool" not found`

- [ ] **Step 3: เพิ่ม connection ใน `config/database.php`** (วางต่อจาก block `'pgsql'`)

```php
        // Stock DB บัญชีโฆษณา (Neon โปรเจกต์แยก mhha_acc_db) — ใช้โดยระบบ Auto Account Delivery
        // แตะได้แค่ items_available / items_reserved / items_sold เท่านั้น
        'mhha_acc' => [
            'driver' => 'pgsql',
            'url' => env('MHHA_ACC_DATABASE_URL'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('MHHA_ACC_DB_SSLMODE', 'require'),
        ],
```

- [ ] **Step 4: สร้าง `config/delivery.php`**

```php
<?php

// Auto Account Delivery — ส่งบัญชีจาก stock mhha_acc_db ให้ลูกค้าอัตโนมัติ
return [
    'enabled' => (bool) env('ACCOUNT_DELIVERY_ENABLED', false),

    // จำกัดเฉพาะ bot ที่เปิดใช้ (คั่นด้วย comma เช่น "26")
    'bot_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('ACCOUNT_DELIVERY_BOT_IDS', '')),
    ))),

    'remind_after_minutes' => (int) env('ACCOUNT_DELIVERY_REMIND_MINUTES', 30),

    // ข้อความที่ส่งให้ลูกค้าแทน credential เมื่อสินค้าเป็นเพจ (PAGE)
    'support_link_template' => env('ACCOUNT_DELIVERY_SUPPORT_TEMPLATE') ?: "รบกวนคุณพี่อ่าน แล้วทำความเข้าใจตามขั้นตอนด้านล่างด้วยนะครับ\n\nรบกวนพี่ Support เพิ่มเพจให้ได้เลยพร้อมกับจำนวนที่พี่ซื้อเพจไปนะครับ\n\nLINK LINE -> https://lin.ee/sTD5TQL\n\nID LINE SUPPORT -> @743ddeqy\n\nท่านพี่มีคำถามเพิ่มเติมแจ้งทีมงาน Support ได้เลยครับ",
];
```

- [ ] **Step 5: สร้าง trait `tests/Support/InteractsWithStockPool.php`**

```php
<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สร้างตารางจำลองของ mhha_acc_db บน sqlite :memory: สำหรับเทสต์
 * (ของจริงเป็น Postgres — ส่วน FOR UPDATE SKIP LOCKED ทดสอบใน manual E2E, ดู Task 12)
 */
trait InteractsWithStockPool
{
    protected function setUpStockPool(): void
    {
        config(['database.connections.mhha_acc' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('mhha_acc');

        $schema = Schema::connection('mhha_acc');

        $base = function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->text('name');
            $t->text('detail');
            $t->text('type')->default('account');
            $t->text('viaId')->nullable();
            $t->text('bmId')->nullable();
            $t->text('adsId')->nullable();
            $t->decimal('cost', 12, 2)->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->timestamp('createdAt')->nullable();
            $t->timestamp('updatedAt')->nullable();
        };

        $schema->create('items_available', $base);
        $schema->create('items_reserved', function (Blueprint $t) use ($base) {
            $base($t);
            $t->text('order_ref')->nullable();
            $t->timestamp('reservedAt')->nullable();
        });
        $schema->create('items_sold', function (Blueprint $t) use ($base) {
            $base($t);
            $t->boolean('isAgent')->default(false);
            $t->text('first_name')->nullable();
            $t->text('username')->nullable();
        });
    }

    protected function seedAvailable(int $id, string $code, string $detail = 'uid|pass|mail|2fa', string $type = 'x'): void
    {
        DB::connection('mhha_acc')->table('items_available')->insert([
            'id' => $id, 'name' => $code, 'detail' => $detail, 'type' => $type,
            'cost' => 0, 'price' => 0, 'createdAt' => now(), 'updatedAt' => now(),
        ]);
    }
}
```

- [ ] **Step 6: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=StockPoolConnectionTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add backend/config/database.php backend/config/delivery.php backend/tests/Support/InteractsWithStockPool.php backend/tests/Feature/StockPoolConnectionTest.php
git commit -m "feat(delivery): mhha_acc connection + delivery config + stock pool test trait"
```

---

### Task 3: StockPoolService (จอง/คืน/ขาย/นับ)

**Files:**
- Create: `backend/app/Services/Delivery/StockPoolService.php`
- Test: `backend/tests/Feature/StockPoolServiceTest.php`

**Interfaces:**
- Consumes: connection `mhha_acc`, trait `InteractsWithStockPool`
- Produces:
  - `reserveOne(string $stockCode, string $orderRef): ?array` — คืนแถว (assoc array มี key `id`, `name`, `detail`, …) หรือ `null` เมื่อหมด
  - `getReserved(array $stockItemIds): array` — map `id => assoc row` จาก `items_reserved`
  - `markSold(array $stockItemIds, string $firstName, string $username): void`
  - `returnToAvailable(array $stockItemIds): void`
  - `countAvailable(): array` — `['NLMP' => 20, ...]`
  - `orphanedReservedRows(array $activeOrderRefs): array` — แถว `items_reserved` ที่ `order_ref` ไม่อยู่ใน list

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Services\Delivery\StockPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class StockPoolServiceTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private StockPoolService $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        $this->pool = app(StockPoolService::class);
    }

    public function test_reserve_one_moves_row_out_of_available(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->seedAvailable(11, 'NLMP', 'uid11|pass11');

        $row = $this->pool->reserveOne('NLMP', '99');

        $this->assertSame(10, (int) $row['id']); // FIFO: id ต่ำสุดก่อน
        $this->assertSame('uid10|pass10', $row['detail']);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
        $reserved = DB::connection('mhha_acc')->table('items_reserved')->first();
        $this->assertSame(10, (int) $reserved->id);
        $this->assertSame('99', $reserved->order_ref);
    }

    public function test_reserve_one_returns_null_when_code_out_of_stock(): void
    {
        $this->seedAvailable(10, 'G3D');

        $this->assertNull($this->pool->reserveOne('NLMP', '99'));
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
    }

    public function test_get_reserved_returns_rows_keyed_by_id(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->pool->reserveOne('NLMP', '99');

        $rows = $this->pool->getReserved([10]);

        $this->assertArrayHasKey(10, $rows);
        $this->assertSame('uid10|pass10', $rows[10]['detail']);
    }

    public function test_mark_sold_moves_to_items_sold_with_names(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->pool->reserveOne('NLMP', '99');

        $this->pool->markSold([10], 'บูม', 'bot-fb');

        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $sold = DB::connection('mhha_acc')->table('items_sold')->first();
        $this->assertSame(10, (int) $sold->id);
        $this->assertSame('บูม', $sold->first_name);
        $this->assertSame('bot-fb', $sold->username);
    }

    public function test_return_to_available_restores_row(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->pool->reserveOne('NLMP', '99');

        $this->pool->returnToAvailable([10]);

        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $avail = DB::connection('mhha_acc')->table('items_available')->first();
        $this->assertSame(10, (int) $avail->id);
        $this->assertSame('uid10|pass10', $avail->detail);
    }

    public function test_count_available_groups_by_code(): void
    {
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'NLMP');
        $this->seedAvailable(3, 'G3D');

        $this->assertSame(['G3D' => 1, 'NLMP' => 2], $this->pool->countAvailable());
    }

    public function test_orphaned_reserved_rows(): void
    {
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'NLMP');
        $this->pool->reserveOne('NLMP', '7');
        $this->pool->reserveOne('NLMP', '8');

        $orphans = $this->pool->orphanedReservedRows(['7']);

        $this->assertCount(1, $orphans);
        $this->assertSame('8', $orphans[0]['order_ref']);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=StockPoolServiceTest`
Expected: FAIL — `Class "App\Services\Delivery\StockPoolService" not found`

- [ ] **Step 3: implement `StockPoolService`**

```php
<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\DB;

/**
 * ชั้นเดียวที่คุยกับ mhha_acc_db (stock บัญชีโฆษณา)
 * หลักการ: ของหนึ่งชิ้นอยู่ได้ที่เดียวเสมอ — available → reserved → sold
 * ห้าม log ค่า detail (credential) เด็ดขาด
 */
class StockPoolService
{
    public const CONNECTION = 'mhha_acc';

    private const COLUMNS = [
        'id', 'name', 'detail', 'type', 'viaId', 'bmId', 'adsId',
        'cost', 'price', 'createdAt', 'updatedAt',
    ];

    /**
     * หยิบของ 1 ชิ้นออกจาก items_available แบบ atomic แล้วย้ายเข้า items_reserved
     * DELETE ... RETURNING การันตีว่าสองฝั่ง (bot-fb กับบอทเบิก Telegram ภายนอก)
     * ไม่มีทางได้แถวเดียวกัน; SKIP LOCKED กันรอ lock ค้าง (เฉพาะ pgsql — sqlite ในเทสต์ไม่มี)
     */
    public function reserveOne(string $stockCode, string $orderRef): ?array
    {
        $conn = DB::connection(self::CONNECTION);

        return $conn->transaction(function () use ($conn, $stockCode, $orderRef) {
            $lock = $conn->getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : '';
            $rows = $conn->select(
                "DELETE FROM items_available WHERE id = (
                    SELECT id FROM items_available WHERE name = ? ORDER BY id LIMIT 1 {$lock}
                ) RETURNING *",
                [$stockCode],
            );

            if ($rows === []) {
                return null;
            }

            $row = (array) $rows[0];
            $conn->table('items_reserved')->insert(
                array_intersect_key($row, array_flip(self::COLUMNS))
                + ['order_ref' => $orderRef, 'reservedAt' => now()],
            );

            return $row;
        });
    }

    /** @return array<int, array> map id => row จาก items_reserved */
    public function getReserved(array $stockItemIds): array
    {
        return DB::connection(self::CONNECTION)->table('items_reserved')
            ->whereIn('id', $stockItemIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function markSold(array $stockItemIds, string $firstName, string $username): void
    {
        if ($stockItemIds === []) {
            return;
        }
        $conn = DB::connection(self::CONNECTION);
        $conn->transaction(function () use ($conn, $stockItemIds, $firstName, $username) {
            $rows = $conn->table('items_reserved')->whereIn('id', $stockItemIds)->get();
            foreach ($rows as $row) {
                $conn->table('items_sold')->insert(
                    array_intersect_key((array) $row, array_flip(self::COLUMNS))
                    + ['isAgent' => false, 'first_name' => $firstName, 'username' => $username],
                );
            }
            $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
        });
    }

    public function returnToAvailable(array $stockItemIds): void
    {
        if ($stockItemIds === []) {
            return;
        }
        $conn = DB::connection(self::CONNECTION);
        $conn->transaction(function () use ($conn, $stockItemIds) {
            $rows = $conn->table('items_reserved')->whereIn('id', $stockItemIds)->get();
            foreach ($rows as $row) {
                $conn->table('items_available')->insert(
                    array_intersect_key((array) $row, array_flip(self::COLUMNS)),
                );
            }
            $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
        });
    }

    /** @return array<string, int> จำนวนของคงเหลือต่อ stock code */
    public function countAvailable(): array
    {
        return DB::connection(self::CONNECTION)->table('items_available')
            ->selectRaw('name, count(*) as cnt')
            ->groupBy('name')
            ->orderBy('name')
            ->pluck('cnt', 'name')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** แถว reserved ที่ order_ref ไม่อยู่ในงานที่ยัง active — ใช้โดย delivery:reconcile */
    public function orphanedReservedRows(array $activeOrderRefs): array
    {
        return DB::connection(self::CONNECTION)->table('items_reserved')
            ->when($activeOrderRefs !== [],
                fn ($q) => $q->whereNotIn('order_ref', $activeOrderRefs))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=StockPoolServiceTest`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Delivery/StockPoolService.php backend/tests/Feature/StockPoolServiceTest.php
git commit -m "feat(delivery): StockPoolService atomic reserve/sold/return on mhha_acc"
```

---

### Task 4: ProductMapper (ชื่อในออเดอร์ → ProductStock)

**Files:**
- Create: `backend/app/Services/Delivery/ProductMapper.php`
- Test: `backend/tests/Feature/ProductMapperTest.php`

**Interfaces:**
- Consumes: `ProductStock` (คอลัมน์ `stock_code`, `delivery_method`, `aliases` จาก Task 1)
- Produces: `map(string $itemName): ?ProductStock` — คืนสินค้าที่ `delivery_method !== 'none'` ที่จับคู่ได้ หรือ `null`

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use App\Services\Delivery\ProductMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMapperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal',
            'aliases' => ['NLM ส่วนตัว', 'Nolimit Personal'], 'in_stock' => true,
            'display_order' => 1, 'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'Nolimit Share BM', 'slug' => 'nolimit-bm',
            'aliases' => ['Share BM', 'NLM BM'], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => 'NLMBM', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => ['เพจโฆษณา', 'PAGE'],
            'in_stock' => true, 'display_order' => 3,
            'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
        ProductStock::create([
            'name' => 'เฟสไก่', 'slug' => 'g3d', 'aliases' => ['G3D'],
            'in_stock' => true, 'display_order' => 4,
            'stock_code' => 'G3D', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'สินค้าไม่เปิดส่งอัตโนมัติ', 'slug' => 'other', 'aliases' => [],
            'in_stock' => true, 'display_order' => 5,
            'stock_code' => null, 'delivery_method' => 'none',
        ]);
    }

    public function test_maps_by_name(): void
    {
        $p = app(ProductMapper::class)->map('Nolimit ส่วนตัว (ผูกบัตร)');
        $this->assertSame('NLMP', $p->stock_code);
    }

    public function test_maps_by_alias(): void
    {
        $p = app(ProductMapper::class)->map('เพจโฆษณา 2 เพจ');
        $this->assertSame('support_link', $p->delivery_method);
    }

    public function test_prefers_longest_match_over_substring(): void
    {
        // "Nolimit Share BM" ต้องไม่ถูกจับเป็น NLMP แม้ขึ้นต้นด้วย "Nolimit"
        $p = app(ProductMapper::class)->map('Nolimit Share BM');
        $this->assertSame('NLMBM', $p->stock_code);
    }

    public function test_returns_null_for_unknown_or_none(): void
    {
        $this->assertNull(app(ProductMapper::class)->map('ของแปลกๆ ไม่มีในระบบ'));
        $this->assertNull(app(ProductMapper::class)->map('สินค้าไม่เปิดส่งอัตโนมัติ'));
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=ProductMapperTest`
Expected: FAIL — `Class "App\Services\Delivery\ProductMapper" not found`

- [ ] **Step 3: implement `ProductMapper`**

```php
<?php

namespace App\Services\Delivery;

use App\Models\ProductStock;

/**
 * จับคู่ชื่อสินค้าที่ parse จากข้อความสรุปยอด (PaymentMessageDetector)
 * กับ ProductStock ที่เปิดส่งอัตโนมัติ (delivery_method != 'none')
 *
 * เทียบแบบ substring สองทาง (ชื่อสินค้าอยู่ในชื่อรายการ) แล้วเลือก candidate
 * ที่ยาวที่สุดก่อน — กัน "Nolimit Share BM" ไปจับคู่กับ "Nolimit" ของ NLMP
 */
class ProductMapper
{
    public function map(string $itemName): ?ProductStock
    {
        $needle = mb_strtolower(trim($itemName));
        if ($needle === '') {
            return null;
        }

        $candidates = [];
        $products = ProductStock::where('delivery_method', '!=', 'none')->get();
        foreach ($products as $product) {
            $terms = array_merge([$product->name], $product->aliases ?? []);
            foreach ($terms as $term) {
                $term = mb_strtolower(trim((string) $term));
                if ($term !== '' && mb_strpos($needle, $term) !== false) {
                    $candidates[] = ['len' => mb_strlen($term), 'product' => $product];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['len'] <=> $a['len']);

        return $candidates[0]['product'];
    }
}
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=ProductMapperTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Delivery/ProductMapper.php backend/tests/Feature/ProductMapperTest.php
git commit -m "feat(delivery): ProductMapper order item name -> product stock"
```

---

### Task 5: AccountDeliveryService — createFromPayment + การ์ด Telegram

**Files:**
- Create: `backend/app/Services/Delivery/AccountDeliveryService.php`
- Create: `backend/app/Exceptions/DeliveryAlreadyHandledException.php`
- Test: `backend/tests/Feature/AccountDeliveryCreateTest.php`

**Interfaces:**
- Consumes: `StockPoolService::reserveOne()`, `ProductMapper::map()`, `TelegramAlertBotService::sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard)`, `LINEService` (constructor เท่านั้น — ใช้ใน Task 7)
- Produces:
  - `createFromPayment(Bot $bot, Conversation $conversation, int $slipVerificationId, ?float $amount, array $items): ?AccountDelivery` — `$items` รูปแบบเดียวกับ `PaymentMessageDetector::parseItems()` (`[['name' => ..., 'total' => ..., 'qty'? => int], ...]`)
  - `sendCard(AccountDelivery $delivery, string $prefix = ''): void`
  - `cardKeyboard(AccountDelivery $delivery): array`
  - exception ว่างเปล่า `App\Exceptions\DeliveryAlreadyHandledException extends \RuntimeException`

- [ ] **Step 1: สร้าง exception**

```php
<?php

namespace App\Exceptions;

class DeliveryAlreadyHandledException extends \RuntimeException {}
```

- [ ] **Step 2: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class AccountDeliveryCreateTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private SlipVerification $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $this->bot = $this->bot->fresh();
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $this->slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 1299, 'status' => 'passed',
        ]);

        config(['delivery.enabled' => true, 'delivery.bot_ids' => [$this->bot->id]]);

        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal', 'aliases' => [],
            'in_stock' => true, 'display_order' => 1,
            'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => [], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
    }

    private function create(array $items): ?AccountDelivery
    {
        return app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $this->slip->id, 1299.0, $items,
        );
    }

    public function test_reserves_stock_and_records_items(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        $delivery = $this->create([
            ['name' => 'Nolimit ส่วนตัว', 'total' => '2200', 'qty' => 2],
            ['name' => 'เพจ', 'total' => '199', 'qty' => 1],
        ]);

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->status);
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_available')->count());
        $this->assertSame(2, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(2, $delivery->items()->where('kind', 'stock')->where('status', 'reserved')->count());
        $this->assertSame(1, $delivery->items()->where('kind', 'support_link')->count());

        // การ์ดถูกส่งเข้า Telegram พร้อมปุ่ม dv/dx
        Http::assertSent(function ($request) use ($delivery) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['reply_markup'] ?? '', "dv|{$delivery->id}|x")
                && str_contains($request['reply_markup'] ?? '', "dx|{$delivery->id}|x");
        });
    }

    public function test_shortage_and_unmapped_are_recorded_not_guessed(): void
    {
        $this->seedAvailable(10, 'NLMP'); // มีชิ้นเดียว แต่สั่ง 2

        $delivery = $this->create([
            ['name' => 'Nolimit ส่วนตัว', 'total' => '2200', 'qty' => 2],
            ['name' => 'ของประหลาด', 'total' => '100'],
        ]);

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $delivery->status);
        $this->assertSame(1, $delivery->items()->where('status', 'reserved')->count());
        $this->assertSame(1, $delivery->items()->where('status', 'shortage')->count());
        $this->assertSame(1, $delivery->items()->where('status', 'unmapped')->count());
    }

    public function test_nothing_deliverable_marks_failed(): void
    {
        $delivery = $this->create([['name' => 'ของประหลาด', 'total' => '100']]);

        $this->assertSame(AccountDelivery::STATUS_FAILED, $delivery->status);
    }

    public function test_duplicate_slip_verification_returns_null_without_reserving(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]);

        $second = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]);

        $this->assertNull($second);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
    }

    public function test_disabled_or_wrong_bot_returns_null(): void
    {
        config(['delivery.enabled' => false]);
        $this->assertNull($this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]));

        config(['delivery.enabled' => true, 'delivery.bot_ids' => [999999]]);
        $this->assertNull($this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1100']]));
    }
}
```

- [ ] **Step 3: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=AccountDeliveryCreateTest`
Expected: FAIL — `Class "App\Services\Delivery\AccountDeliveryService" not found`

- [ ] **Step 4: implement `AccountDeliveryService` (ส่วน create + card)**

```php
<?php

namespace App\Services\Delivery;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Services\LINEService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * งานส่งบัญชีอัตโนมัติ: จองจาก stock (mhha_acc_db) → การ์ด Telegram → ส่ง LINE → sold
 * ห้าม log ค่า detail (credential) เด็ดขาด
 */
class AccountDeliveryService
{
    public function __construct(
        private readonly StockPoolService $pool,
        private readonly ProductMapper $mapper,
        private readonly TelegramAlertBotService $alertBot,
        private readonly LINEService $line,
    ) {}

    /**
     * สร้างงานส่งของ + จองทันที (เรียกจาก ReserveAccountStock job หลังยืนยันเงิน)
     * idempotent ด้วย unique(slip_verification_id) — เรียกซ้ำคืน null เฉยๆ
     *
     * @param  array<int, array{name: string, total: string, price?: string, qty?: int}>  $items
     */
    public function createFromPayment(
        Bot $bot,
        Conversation $conversation,
        int $slipVerificationId,
        ?float $amount,
        array $items,
    ): ?AccountDelivery {
        if (! config('delivery.enabled') || ! in_array($bot->id, config('delivery.bot_ids'), true)) {
            return null;
        }

        try {
            $delivery = AccountDelivery::create([
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'slip_verification_id' => $slipVerificationId,
                'status' => AccountDelivery::STATUS_RESERVING,
                'amount' => $amount,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // webhook ซ้ำ/job รันซ้ำ — งานนี้มีคนทำแล้ว
        }

        $deliverable = false;
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $product = $this->mapper->map($item['name']);

            if ($product === null) {
                $delivery->items()->create([
                    'product_name' => $item['name'], 'kind' => AccountDeliveryItem::KIND_MANUAL,
                    'qty' => $qty, 'status' => AccountDeliveryItem::ST_UNMAPPED,
                ]);

                continue;
            }

            if ($product->delivery_method === 'support_link') {
                $delivery->items()->create([
                    'product_name' => $product->name, 'kind' => AccountDeliveryItem::KIND_SUPPORT_LINK,
                    'qty' => $qty, 'status' => AccountDeliveryItem::ST_RESERVED,
                ]);
                $deliverable = true;

                continue;
            }

            for ($u = 0; $u < $qty; $u++) {
                try {
                    $row = $this->pool->reserveOne($product->stock_code, (string) $delivery->id);
                } catch (\Throwable $e) {
                    Log::error('Delivery: stock reserve failed', [
                        'delivery_id' => $delivery->id, 'stock_code' => $product->stock_code,
                        'error' => $e->getMessage(),
                    ]);
                    $row = null;
                }
                $delivery->items()->create([
                    'product_name' => $product->name,
                    'stock_code' => $product->stock_code,
                    'kind' => AccountDeliveryItem::KIND_STOCK,
                    'qty' => 1,
                    'stock_item_id' => $row['id'] ?? null,
                    'status' => $row === null
                        ? AccountDeliveryItem::ST_SHORTAGE
                        : AccountDeliveryItem::ST_RESERVED,
                ]);
                if ($row !== null) {
                    $deliverable = true;
                }
            }
        }

        $delivery->update([
            'status' => $deliverable ? AccountDelivery::STATUS_RESERVED : AccountDelivery::STATUS_FAILED,
        ]);

        $this->sendCard($delivery->fresh('items'));

        return $delivery;
    }

    /** ส่งการ์ดสรุป + ปุ่มเข้า Telegram (ใช้ตอนสร้างงาน และตอนเตือนซ้ำ) */
    public function sendCard(AccountDelivery $delivery, string $prefix = ''): void
    {
        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for card', ['delivery_id' => $delivery->id]);

            return;
        }

        $keyboard = $delivery->status === AccountDelivery::STATUS_RESERVED
            ? $this->cardKeyboard($delivery)
            : null;

        $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $prefix.$this->cardText($delivery),
            $keyboard,
        );
    }

    /** @return array<int, array<int, array{text: string, callback_data: string}>> */
    public function cardKeyboard(AccountDelivery $delivery): array
    {
        return [
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ];
    }

    private function cardText(AccountDelivery $delivery): string
    {
        $conv = $delivery->conversation;
        $customer = $conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}";
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';

        $lines = ["🚚 พร้อมส่งสินค้า — {$customer} (แชท #{$conv?->id}, ยอด {$amount} บาท, งาน #{$delivery->id})"];
        foreach ($delivery->items as $item) {
            $lines[] = match ($item->status) {
                AccountDeliveryItem::ST_RESERVED => $item->kind === AccountDeliveryItem::KIND_SUPPORT_LINK
                    ? "📦 {$item->product_name} ×{$item->qty} — จะส่งลิงก์ Support ให้ลูกค้า"
                    : "📦 {$item->product_name} — จองแล้ว (#{$item->stock_item_id})",
                AccountDeliveryItem::ST_SHORTAGE => "⚠️ {$item->product_name} — ของหมด ต้องส่งเอง",
                AccountDeliveryItem::ST_UNMAPPED => "⚠️ {$item->product_name} — ไม่รู้จักสินค้า ต้องส่งเอง",
                default => "• {$item->product_name} — {$item->status}",
            };
        }
        if ($delivery->status === AccountDelivery::STATUS_FAILED) {
            $lines[] = '❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ';
        }

        return implode("\n", $lines);
    }

    private function telegramPlugin(AccountDelivery $delivery): ?FlowPlugin
    {
        $bot = $delivery->bot;
        $flow = $delivery->conversation?->currentFlow ?? $bot?->defaultFlow;

        return $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();
    }
}
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=AccountDeliveryCreateTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/app/Exceptions/DeliveryAlreadyHandledException.php backend/tests/Feature/AccountDeliveryCreateTest.php
git commit -m "feat(delivery): createFromPayment reserve + telegram card"
```

---

### Task 6: ReserveAccountStock job + เสียบจุดยืนยันเงินทั้ง 2 ทาง

**Files:**
- Create: `backend/app/Jobs/ReserveAccountStock.php`
- Modify: `backend/app/Services/Payment/SlipVerificationResult.php`
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` (`findExpectedPayment`, `record`, จุดสร้าง result ที่ pass)
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php` (branch `passed` ใน `trySlipVerification`)
- Modify: `backend/app/Services/Payment/ManualPaymentConfirmService.php` (หลัง `runPlugins`)
- Test: `backend/tests/Feature/ReserveAccountStockDispatchTest.php`

**Interfaces:**
- Consumes: `AccountDeliveryService::createFromPayment()` (Task 5)
- Produces: job `ReserveAccountStock(int $botId, int $conversationId, int $slipVerificationId, ?float $amount, array $items)`; `SlipVerificationResult` มี `public ?int $slipVerificationId` (mutable) + constructor param `public readonly ?array $orderItems = null`; `findExpectedPayment()` คืน key `items` เพิ่ม (`array{total: float, summary: string, items: array}|null`)

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReserveAccountStockDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_expected_payment_returns_items(): void
    {
        $service = app(SlipVerificationService::class);
        $history = [[
            'sender' => 'bot',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว (1,100 x 2) = 2,200 บาท\nรวมยอดโอน: 2,200 บาท\nบัญชี 223-3-24880-3",
        ]];

        $expected = $service->findExpectedPayment($history);

        $this->assertSame(2200.0, $expected['total']);
        $this->assertSame('Nolimit ส่วนตัว', $expected['items'][0]['name']);
        $this->assertSame(2, $expected['items'][0]['qty']);
    }

    public function test_manual_confirm_dispatches_reserve_job(): void
    {
        Bus::fake([ReserveAccountStock::class]);

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);
        $conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nบัญชี 223-3-24880-3",
        ]);

        app(ManualPaymentConfirmService::class)->confirm($bot, $conversation, null, $user->id);

        Bus::assertDispatched(ReserveAccountStock::class, function (ReserveAccountStock $job) use ($bot, $conversation) {
            return $job->botId === $bot->id
                && $job->conversationId === $conversation->id
                && $job->slipVerificationId > 0
                && $job->items[0]['name'] === 'Nolimit ส่วนตัว';
        });
    }
}
```

หมายเหตุ: มีเทสต์เดิม `ManualPaymentConfirmTest` อยู่แล้ว — ถ้าการแก้ไปทำให้เทสต์เดิมแดง ต้องแก้ให้เขียวโดยไม่เปลี่ยนพฤติกรรมเดิม (การ dispatch เพิ่มไม่ควรกระทบ เพราะ `QUEUE_CONNECTION=sync` ใน phpunit แต่ `delivery.enabled=false` โดย default ทำให้ `createFromPayment` คืน null เฉยๆ)

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=ReserveAccountStockDispatchTest`
Expected: FAIL — `Class "App\Jobs\ReserveAccountStock" not found`

- [ ] **Step 3: สร้าง job**

```php
<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * จองบัญชีจาก stock หลังยืนยันเงิน (EasySlip ผ่าน / เจ้าของกดยืนยัน)
 *
 * tries=1 โดยตั้งใจ: ถ้า mhha DB มีปัญหา ชิ้นที่จองไม่ได้จะถูกบันทึกเป็น shortage
 * และการ์ด Telegram บอกให้ส่งเอง (fail-safe) — ไม่ retry เพื่อไม่ให้จองซ้ำครึ่งๆ กลางๆ
 */
class ReserveAccountStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $botId,
        public readonly int $conversationId,
        public readonly int $slipVerificationId,
        public readonly ?float $amount,
        public readonly array $items,
    ) {}

    public function handle(AccountDeliveryService $service): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        if (! $bot || ! $conversation) {
            return;
        }

        $service->createFromPayment($bot, $conversation, $this->slipVerificationId, $this->amount, $this->items);
    }
}
```

- [ ] **Step 4: แก้ `SlipVerificationResult`** — เพิ่ม 2 property:

```php
class SlipVerificationResult
{
    /** id แถว slip_verifications ที่บันทึกไป (ใส่โดย record()) — ใช้ผูกงานส่งของ */
    public ?int $slipVerificationId = null;

    public function __construct(
        public readonly bool $isSlip,
        public readonly bool $passed,
        public readonly ?string $failReason = null,
        public readonly ?float $amount = null,
        public readonly ?string $transRef = null,
        public readonly ?float $expectedAmount = null,
        public readonly ?string $orderSummary = null,
        public readonly ?array $orderItems = null,
    ) {}
    // status() คงเดิม
}
```

- [ ] **Step 5: แก้ `SlipVerificationService`** — 3 จุด:

(a) `findExpectedPayment()` เพิ่ม `items` ใน return (แก้ docblock `@return array{total: float, summary: string, items: array}|null` ด้วย):

```php
            return [
                'total' => (float) str_replace(',', '', $data['total']),
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
                'items' => $data['items'],
            ];
```

(b) จุดสร้าง result ตอนผ่านทุกเช็ค (บรรทัด `passed: true` ท้าย `verify()`) เพิ่ม `orderItems`:

```php
        return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
            isSlip: true, passed: true,
            amount: $slipAmount, transRef: $transRef,
            expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            orderItems: $expected['items'],
        ), $receiverAccount);
```

(c) `record()` ใส่ id ให้ result (ใน try เดิม):

```php
        try {
            $created = SlipVerification::create([
                // ... payload เดิมทุก field ...
            ]);
            $result->slipVerificationId = $created->id;
        } catch (\Throwable $e) {
```

- [ ] **Step 6: dispatch ใน `LineWebhookResponseService::trySlipVerification`** — ใน branch `passed` หลังบรรทัด `$ctx->metadata['bot_message'] = $botMessage;` (ก่อน `$ctx->response = ...` ก็ได้ ให้อยู่หลังสร้าง `$botMessage`):

```php
            if ($result->passed && $result->slipVerificationId !== null) {
                ReserveAccountStock::dispatch(
                    $ctx->bot->id,
                    $ctx->conversation->id,
                    $result->slipVerificationId,
                    $result->amount,
                    $result->orderItems ?? [],
                );
            }
```

เพิ่ม `use App\Jobs\ReserveAccountStock;` ที่หัวไฟล์

- [ ] **Step 7: dispatch ใน `ManualPaymentConfirmService::confirm`** — หลังบรรทัด `$orderCreated = $this->runPlugins(...)`:

```php
        ReserveAccountStock::dispatch(
            $bot->id,
            $conversation->id,
            $slip->id,
            $amount,
            $expected['items'] ?? [],
        );
```

เพิ่ม `use App\Jobs\ReserveAccountStock;` ที่หัวไฟล์

- [ ] **Step 8: รันเทสต์ให้ผ่าน + เทสต์เดิมไม่แตก**

Run: `cd backend && php artisan test --filter=ReserveAccountStockDispatchTest`
Expected: PASS (2 tests)

Run: `cd backend && php artisan test --filter="ManualPaymentConfirmTest|SlipVerification"`
Expected: PASS ทั้งหมด (เทสต์เดิมต้องไม่แตก)

- [ ] **Step 9: Commit**

```bash
git add backend/app/Jobs/ReserveAccountStock.php backend/app/Services/Payment/SlipVerificationResult.php backend/app/Services/Payment/SlipVerificationService.php backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/app/Services/Payment/ManualPaymentConfirmService.php backend/tests/Feature/ReserveAccountStockDispatchTest.php
git commit -m "feat(delivery): dispatch ReserveAccountStock from both payment-confirm paths"
```

---

### Task 7: deliver() — ส่ง credential ให้ลูกค้าใน LINE + ย้ายเข้า items_sold

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php`
- Test: `backend/tests/Feature/AccountDeliveryDeliverTest.php`

**Interfaces:**
- Consumes: `StockPoolService::getReserved()/markSold()`, `LINEService::replyWithFallback(Bot $bot, ?string $replyToken, string $userId, array $messages, ?string $retryKey)`, `LINEService::generateRetryKey(): string`
- Produces: `deliver(AccountDelivery $delivery, string $confirmedByName): void` — throws `DeliveryAlreadyHandledException` เมื่อสถานะไม่ใช่ `reserved`, throw `\RuntimeException` เมื่อส่ง LINE ไม่สำเร็จ (ของยังอยู่ reserved)

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class AccountDeliveryDeliverTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private AccountDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id, 'channel_type' => 'line',
            'external_customer_id' => 'Uabc123',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 1299, 'status' => 'passed',
        ]);

        // จองของไว้แล้ว 1 บัญชี + เพจ 1 รายการ
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10|mail|2fa');
        app(\App\Services\Delivery\StockPoolService::class)->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1299,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved',
        ]);
        $this->delivery->items()->create([
            'product_name' => 'เพจ', 'kind' => 'support_link', 'qty' => 2, 'status' => 'reserved',
        ]);
    }

    public function test_deliver_pushes_credentials_and_marks_sold(): void
    {
        $pushed = [];
        $this->mock(LINEService::class, function (MockInterface $mock) use (&$pushed) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()
                ->withArgs(function ($bot, $token, $userId, $messages) use (&$pushed) {
                    $pushed = $messages;

                    return $userId === 'Uabc123' && $token === null;
                })->andReturn(['method' => 'push', 'success' => true]);
        });

        app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');

        // credential ดิบ + ลิงก์ support อยู่ในข้อความ
        $all = implode("\n", array_column($pushed, 'text'));
        $this->assertStringContainsString('uid10|pass10|mail|2fa', $all);
        $this->assertStringContainsString('lin.ee/sTD5TQL', $all);

        // ของย้ายเข้า items_sold
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $sold = DB::connection('mhha_acc')->table('items_sold')->first();
        $this->assertSame('บูม', $sold->first_name);

        // สถานะ + ประวัติแชท
        $fresh = $this->delivery->fresh();
        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $fresh->status);
        $this->assertSame('บูม', $fresh->confirmed_by);
        $this->assertSame(2, $fresh->items()->where('status', 'delivered')->count());
        $msg = $this->conversation->messages()->latest('id')->first();
        $this->assertTrue((bool) ($msg->metadata['account_delivery'] ?? false));
    }

    public function test_deliver_twice_throws_already_handled(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $service = app(AccountDeliveryService::class);
        $service->deliver($this->delivery, 'บูม');

        $this->expectException(DeliveryAlreadyHandledException::class);
        $service->deliver($this->delivery->fresh(), 'บูม');
    }

    public function test_line_failure_keeps_stock_reserved(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->andThrow(new \RuntimeException('LINE down'));
        });

        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'บูม');
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_sold')->count());
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=AccountDeliveryDeliverTest`
Expected: FAIL — `Call to undefined method ... deliver()`

- [ ] **Step 3: เพิ่ม `deliver()` + helpers ใน `AccountDeliveryService`**

```php
    /**
     * ส่งของให้ลูกค้า (เรียกตอนเจ้าของกดปุ่ม ✅ ใน Telegram)
     * ลำดับ: lock สถานะ → push LINE → ย้ายเข้า items_sold → บันทึกประวัติ
     * push พังของยังอยู่ reserved กดใหม่ได้; markSold พังหลัง push = log error ให้ reconcile เจอ
     *
     * @throws DeliveryAlreadyHandledException สถานะไม่ใช่ reserved (กดซ้ำ/ยกเลิกแล้ว)
     */
    public function deliver(AccountDelivery $delivery, string $confirmedByName): void
    {
        // จองสิทธิ์ส่ง: reserved → delivering ใน transaction เดียว กันกดพร้อมกัน
        $delivery = DB::transaction(function () use ($delivery) {
            $locked = AccountDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AccountDelivery::STATUS_RESERVED) {
                throw new DeliveryAlreadyHandledException($locked->status);
            }
            $locked->update(['status' => AccountDelivery::STATUS_DELIVERING]);

            return $locked;
        });

        try {
            $stockItems = $delivery->items()
                ->where('kind', AccountDeliveryItem::KIND_STOCK)
                ->where('status', AccountDeliveryItem::ST_RESERVED)
                ->get();
            $supportItems = $delivery->items()
                ->where('kind', AccountDeliveryItem::KIND_SUPPORT_LINK)
                ->where('status', AccountDeliveryItem::ST_RESERVED)
                ->get();

            $reservedRows = $this->pool->getReserved($stockItems->pluck('stock_item_id')->all());
            foreach ($stockItems as $item) {
                if (! isset($reservedRows[$item->stock_item_id])) {
                    throw new \RuntimeException("reserved row missing: #{$item->stock_item_id}");
                }
            }

            $texts = $this->buildCustomerMessages($stockItems, $supportItems, $reservedRows);
            $this->pushTextsToLine($delivery, $texts);
        } catch (\Throwable $e) {
            $delivery->update(['status' => AccountDelivery::STATUS_RESERVED]);
            throw $e;
        }

        // ลูกค้าได้ของแล้ว — จากนี้ห้าม throw กลับไปเป็น "ยังไม่ส่ง"
        try {
            $this->pool->markSold($stockItems->pluck('stock_item_id')->all(), $confirmedByName, 'bot-fb');
        } catch (\Throwable $e) {
            Log::error('Delivery: markSold failed AFTER customer push — reconcile will flag', [
                'delivery_id' => $delivery->id, 'error' => $e->getMessage(),
            ]);
        }

        $delivery->update([
            'status' => AccountDelivery::STATUS_DELIVERED,
            'confirmed_by' => $confirmedByName,
            'delivered_at' => now(),
        ]);
        $delivery->items()
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->update(['status' => AccountDeliveryItem::ST_DELIVERED]);

        $this->recordConversationMessage($delivery, $texts);
    }

    /** @return array<int, string> ข้อความที่จะส่งให้ลูกค้า (เรียงตามลำดับ) */
    private function buildCustomerMessages($stockItems, $supportItems, array $reservedRows): array
    {
        $texts = [];
        $n = $stockItems->count();
        foreach ($stockItems->values() as $i => $item) {
            $detail = $reservedRows[$item->stock_item_id]['detail'];
            $no = $i + 1;
            $texts[] = "✅ {$item->product_name} ({$no}/{$n})\n{$detail}";
        }
        if ($supportItems->isNotEmpty()) {
            $texts[] = (string) config('delivery.support_link_template');
        }

        return $texts;
    }

    /** push เป็น text ล้วน (ห้ามผ่าน LLM/Flex) — LINE จำกัด 5 ข้อความต่อ push */
    private function pushTextsToLine(AccountDelivery $delivery, array $texts): void
    {
        $conversation = $delivery->conversation;
        $externalId = $conversation?->external_customer_id;
        if ($conversation?->channel_type !== 'line' || ! $externalId) {
            throw new \RuntimeException('delivery target is not a LINE conversation');
        }
        if ($texts === []) {
            throw new \RuntimeException('nothing to deliver');
        }

        foreach (array_chunk($texts, 5) as $chunk) {
            $messages = array_map(fn (string $t) => ['type' => 'text', 'text' => $t], $chunk);
            $this->line->replyWithFallback(
                $delivery->bot, null, $externalId, $messages, $this->line->generateRetryKey(),
            );
        }
    }

    /** บันทึกสิ่งที่ส่งเข้าประวัติแชท (บอท/หน้าเว็บเห็นว่าส่งอะไรไปแล้ว) — best effort */
    private function recordConversationMessage(AccountDelivery $delivery, array $texts): void
    {
        try {
            $delivery->conversation?->messages()->create([
                'sender' => 'bot',
                'type' => 'text',
                'content' => implode("\n\n", $texts),
                'metadata' => [
                    'account_delivery' => true,
                    'delivery_id' => $delivery->id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Delivery: failed to record conversation message', [
                'delivery_id' => $delivery->id, 'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=AccountDeliveryDeliverTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Feature/AccountDeliveryDeliverTest.php
git commit -m "feat(delivery): deliver credentials to LINE + move stock to items_sold"
```

---

### Task 8: cancel() + ปุ่ม Telegram (dv/dx/dz) ใน callback controller

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (เพิ่ม `cancel()`)
- Modify: `backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php`
- Test: `backend/tests/Feature/DeliveryCallbackTest.php`

**Interfaces:**
- Consumes: `AccountDeliveryService::deliver()/cancel()/cardKeyboard()`, `StockPoolService::returnToAvailable()`
- Produces: `cancel(AccountDelivery $delivery, string $byName): void` (throws `DeliveryAlreadyHandledException`); callback actions `dv|{deliveryId}|x`, `dx|{deliveryId}|x`, `dz|{deliveryId}|x` บน endpoint `/api/webhook/telegram-alert/{token}` เดิม

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\LINEService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class DeliveryCallbackTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private Bot $bot;

    private AccountDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $this->bot = $this->bot->fresh();
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id, 'channel_type' => 'line',
            'external_customer_id' => 'Uabc123',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        app(\App\Services\Delivery\StockPoolService::class)->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1100,
        ]);
        $this->delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved',
        ]);
    }

    private function press(string $data): TestResponse
    {
        config(['services.telegram_alert.secret' => 'SEC']);

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson('/api/webhook/telegram-alert/TOK', ['callback_query' => [
                'id' => 'cb1',
                'data' => $data,
                'from' => ['first_name' => 'บูม'],
                'message' => ['message_id' => 55, 'chat' => ['id' => 999]],
            ]]);
    }

    public function test_dv_delivers(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'ส่งให้ลูกค้าแล้ว'));
    }

    public function test_dx_asks_second_step_without_touching_stock(): void
    {
        $this->press("dx|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['reply_markup'] ?? '', "dz|{$this->delivery->id}|x"));
    }

    public function test_dz_cancels_and_returns_stock(): void
    {
        $this->press("dz|{$this->delivery->id}|x")->assertOk();

        $fresh = $this->delivery->fresh();
        $this->assertSame(AccountDelivery::STATUS_CANCELED, $fresh->status);
        $this->assertSame('returned', $fresh->items()->first()->status);
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
    }

    public function test_dv_after_delivered_reports_already_handled(): void
    {
        $this->mock(LINEService::class, function (MockInterface $mock) {
            $mock->shouldReceive('generateRetryKey')->andReturn('rk');
            $mock->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'push', 'success' => true]);
        });
        $this->press("dv|{$this->delivery->id}|x");

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        // ไม่ส่งซ้ำ (mock once ด้านบนพิสูจน์แล้ว) + แจ้งว่าจัดการไปแล้ว
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', 'จัดการไปแล้ว'));
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_delivery_of_other_bot_is_rejected(): void
    {
        $otherUser = User::factory()->owner()->create();
        $otherBot = Bot::factory()->create(['user_id' => $otherUser->id]);
        $this->delivery->update(['bot_id' => $otherBot->id]);

        $this->press("dv|{$this->delivery->id}|x")->assertOk();

        $this->assertSame(AccountDelivery::STATUS_RESERVED, $this->delivery->fresh()->status);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=DeliveryCallbackTest`
Expected: FAIL (action `dv` ยังไม่รู้จัก — controller ตอบ ok เฉยๆ, assert สถานะ delivered จะพัง)

- [ ] **Step 3: เพิ่ม `cancel()` ใน `AccountDeliveryService`**

```php
    /**
     * ยกเลิกงาน คืนของเข้า items_available (manual escape hatch — ระบบไม่คืนอัตโนมัติ)
     * mark canceled ก่อนคืนของ: ถ้าคืนพังกลางทาง แถวค้างใน items_reserved ให้ reconcile เจอ
     *
     * @throws DeliveryAlreadyHandledException สถานะไม่ใช่ reserved
     */
    public function cancel(AccountDelivery $delivery, string $byName): void
    {
        $delivery = DB::transaction(function () use ($delivery, $byName) {
            $locked = AccountDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AccountDelivery::STATUS_RESERVED) {
                throw new DeliveryAlreadyHandledException($locked->status);
            }
            $locked->update(['status' => AccountDelivery::STATUS_CANCELED, 'confirmed_by' => $byName]);

            return $locked;
        });

        $ids = $delivery->items()
            ->where('kind', AccountDeliveryItem::KIND_STOCK)
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->pluck('stock_item_id')
            ->all();
        $this->pool->returnToAvailable($ids);
        $delivery->items()
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->update(['status' => AccountDeliveryItem::ST_RETURNED]);
    }
```

- [ ] **Step 4: แก้ controller** — (a) เพิ่ม dependency + import, (b) branch action ใหม่หลัง parse parts

(a) constructor + imports:

```php
use App\Exceptions\DeliveryAlreadyHandledException;
use App\Models\AccountDelivery;
use App\Services\Delivery\AccountDeliveryService;
// ...
    public function __construct(
        private readonly ManualPaymentConfirmService $confirmService,
        private readonly TelegramAlertBotService $alertBot,
        private readonly AccountDeliveryService $deliveryService,
    ) {}
```

(b) ใน `handle()` — แทรกหลังบรรทัด `if (! is_numeric($convId)) { return ...; }` และก่อน `Conversation::find(...)`:

```php
        // action งานส่งของ: ส่วนที่สองของ callback_data เป็น delivery id ไม่ใช่ conversation id
        if (in_array($act, ['dv', 'dx', 'dz'], true)) {
            return $this->handleDeliveryAction($act, (int) $convId, $plugin, $cb, $token);
        }
```

(c) method ใหม่ท้าย class:

```php
    private function handleDeliveryAction(
        string $act,
        int $deliveryId,
        FlowPlugin $plugin,
        array $cb,
        string $token,
    ): JsonResponse {
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $fromName = $cb['from']['first_name'] ?? 'admin';
        $cbId = $cb['id'] ?? '';

        $delivery = AccountDelivery::find($deliveryId);
        if (! $delivery) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ไม่พบงานส่งของ');

            return response()->json(['ok' => true]);
        }
        if ($delivery->bot_id !== $plugin->flow?->bot_id) {
            Log::warning('Delivery callback: delivery/plugin bot mismatch', [
                'delivery_id' => $delivery->id, 'plugin_id' => $plugin->id,
            ]);

            return response()->json(['ok' => true]);
        }

        // ยกเลิกขั้นแรก: แค่เปลี่ยนปุ่มเป็นยืนยันชั้นสอง (pattern เดียวกับ pa)
        if ($act === 'dx') {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "⚠️ ยืนยันยกเลิก คืนของเข้า stock? (งาน #{$delivery->id})\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง",
                [[['text' => '❗ กดอีกครั้งเพื่อคืนของเข้า stock', 'callback_data' => "dz|{$delivery->id}|x"]]],
            );
            $this->alertBot->answerCallbackQuery($token, $cbId, 'กดอีกครั้งเพื่อยืนยัน');

            return response()->json(['ok' => true]);
        }

        try {
            if ($act === 'dz') {
                $this->deliveryService->cancel($delivery, $fromName);
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "↩️ คืนของเข้า stock แล้ว โดย {$fromName} (งาน #{$delivery->id})");
                $this->alertBot->answerCallbackQuery($token, $cbId, 'คืนของแล้ว');
            } else { // dv
                $this->deliveryService->deliver($delivery, $fromName);
                $this->alertBot->editMessageText($token, $chatId, $messageId,
                    "✅ ส่งให้ลูกค้าแล้ว โดย {$fromName} (งาน #{$delivery->id})");
                $this->alertBot->answerCallbackQuery($token, $cbId, 'ส่งแล้ว');
            }
        } catch (DeliveryAlreadyHandledException $e) {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "✅ งาน #{$delivery->id} ถูกจัดการไปแล้ว (สถานะ: {$delivery->fresh()->status})");
            $this->alertBot->answerCallbackQuery($token, $cbId, 'จัดการไปแล้ว');
        } catch (\Throwable $e) {
            Log::error('Delivery callback action failed', [
                'delivery_id' => $delivery->id, 'action' => $act, 'error' => $e->getMessage(),
            ]);
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "❌ ทำไม่สำเร็จ — กดลองใหม่ได้ (งาน #{$delivery->id})",
                $this->deliveryService->cardKeyboard($delivery));
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่');
        }

        return response()->json(['ok' => true]);
    }
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน + เทสต์ callback เดิมไม่แตก**

Run: `cd backend && php artisan test --filter="DeliveryCallbackTest|TelegramAlertCallbackTest"`
Expected: PASS ทั้งหมด

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php backend/tests/Feature/DeliveryCallbackTest.php
git commit -m "feat(delivery): telegram dv/dx/dz actions + cancel with stock return"
```

---

### Task 9: คำสั่งเตือนซ้ำ `delivery:remind`

**Files:**
- Create: `backend/app/Console/Commands/RemindPendingDeliveries.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/RemindPendingDeliveriesTest.php`

**Interfaces:**
- Consumes: `AccountDeliveryService::sendCard($delivery, $prefix)`
- Produces: artisan command `delivery:remind` (schedule ทุก 30 นาที)

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindPendingDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(array $attrs = []): AccountDelivery
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $conv = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1100, 'status' => 'passed',
        ]);

        return AccountDelivery::create(array_merge([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => 'reserved', 'amount' => 1100,
        ], $attrs));
    }

    public function test_reminds_stale_reserved_delivery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '⏰'));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }

    public function test_skips_fresh_and_recently_reminded(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->makeDelivery(['created_at' => now()->subMinutes(5)]); // ยังใหม่

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=RemindPendingDeliveriesTest`
Expected: FAIL — command not found

- [ ] **Step 3: implement command**

```php
<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Console\Command;

/**
 * เตือนงานส่งของที่เจ้าของยังไม่กดยืนยันใน Telegram (นโยบาย: ไม่คืนของอัตโนมัติ เตือนจนกว่าจะกด)
 */
class RemindPendingDeliveries extends Command
{
    protected $signature = 'delivery:remind';

    protected $description = 'เตือนงานส่งบัญชีที่ค้างกดยืนยันใน Telegram';

    public function handle(AccountDeliveryService $service): int
    {
        $threshold = now()->subMinutes((int) config('delivery.remind_after_minutes'));

        $pending = AccountDelivery::with('items', 'bot', 'conversation')
            ->where('status', AccountDelivery::STATUS_RESERVED)
            ->where('created_at', '<=', $threshold)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', $threshold))
            ->get();

        foreach ($pending as $delivery) {
            $ageMinutes = (int) $delivery->created_at->diffInMinutes(now());
            $service->sendCard($delivery, "⏰ เตือน: งานส่งของค้างมา {$ageMinutes} นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
            $delivery->update(['last_reminded_at' => now()]);
        }

        $this->info("reminded: {$pending->count()}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: เพิ่ม schedule ใน `routes/console.php`** (ท้ายไฟล์)

```php
// Auto Account Delivery — เตือนงานค้างกดยืนยัน
Schedule::command('delivery:remind')->everyThirtyMinutes();
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=RemindPendingDeliveriesTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/RemindPendingDeliveries.php backend/routes/console.php backend/tests/Feature/RemindPendingDeliveriesTest.php
git commit -m "feat(delivery): delivery:remind command every 30 minutes"
```

---

### Task 10: คำสั่งตรวจของค้าง `delivery:reconcile`

**Files:**
- Create: `backend/app/Console/Commands/ReconcileDeliveries.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/ReconcileDeliveriesTest.php`

**Interfaces:**
- Consumes: `StockPoolService::orphanedReservedRows()`, `AccountDeliveryService` (ใช้แค่ Telegram plugin resolve ผ่าน `sendCard`? — ไม่ใช่: command ส่งข้อความเตือนเองผ่าน `TelegramAlertBotService` โดยหา plugin จาก delivery แรกที่เจอ หรือ log อย่างเดียวถ้าไม่มี)
- Produces: artisan command `delivery:reconcile` (schedule รายชั่วโมง) — แจ้งเตือน 3 กรณี: (1) delivery ค้าง `reserving` เกิน 10 นาที (job ตายกลางคัน) (2) delivery ค้าง `delivering` เกิน 10 นาที (process ตายระหว่างส่ง — ห้ามคืน stock อัตโนมัติ ให้เจ้าของเช็คแชทว่าลูกค้าได้ของหรือยัง) (3) แถว `items_reserved` ที่ order_ref ไม่ตรงกับงานสถานะ `reserved`/`delivering` ใดๆ (ของค้างใน limbo)

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class ReconcileDeliveriesTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $this->bot = $bot->fresh();
        $this->conv = Conversation::factory()->create(['bot_id' => $bot->id]);
    }

    private Bot $bot;

    private Conversation $conv;

    private function makeDelivery(string $status, array $attrs = []): AccountDelivery
    {
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conv->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        return AccountDelivery::create(array_merge([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conv->id,
            'slip_verification_id' => $slip->id, 'status' => $status, 'amount' => 1100,
        ], $attrs));
    }

    public function test_alerts_on_stuck_reserving_delivery(): void
    {
        $this->makeDelivery('reserving', ['created_at' => now()->subMinutes(30)]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'reserving'));
    }

    public function test_alerts_on_orphaned_reserved_rows(): void
    {
        // แถว reserved ที่ชี้ order_ref = 9999 ซึ่งไม่มีงาน active
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            'id' => 77, 'name' => 'NLMP', 'detail' => 'x|y', 'type' => 'x',
            'order_ref' => '9999', 'reservedAt' => now(),
            'createdAt' => now(), 'updatedAt' => now(),
        ]);
        // ต้องมี delivery อย่างน้อย 1 งานเพื่อ resolve telegram plugin
        $this->makeDelivery('delivered');

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '#77'));
    }

    public function test_quiet_when_all_clean(): void
    {
        $this->makeDelivery('reserved'); // ปกติ — ไม่ orphan ไม่ stuck

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=ReconcileDeliveriesTest`
Expected: FAIL — command not found

- [ ] **Step 3: implement command**

```php
<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Services\Delivery\StockPoolService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ตรวจของค้างระหว่าง bot-fb กับ mhha_acc_db แล้วแจ้งเตือน (ไม่แก้เองอัตโนมัติ):
 * 1. งานค้าง 'reserving' > 10 นาที = job ตายกลางคัน
 * 2. แถว items_reserved ที่ไม่มีงาน active (reserved/delivering) ชี้อยู่ = ของหลุดอยู่ใน limbo
 */
class ReconcileDeliveries extends Command
{
    protected $signature = 'delivery:reconcile';

    protected $description = 'ตรวจงานส่งบัญชี/ของจองที่ค้างผิดปกติ แล้วแจ้ง Telegram';

    public function handle(StockPoolService $pool, TelegramAlertBotService $alertBot): int
    {
        $problems = [];

        $stuck = AccountDelivery::whereIn('status', [
            AccountDelivery::STATUS_RESERVING,
            AccountDelivery::STATUS_DELIVERING,
        ])
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->get();
        foreach ($stuck as $d) {
            $hint = $d->status === AccountDelivery::STATUS_DELIVERING
                ? 'process อาจตายระหว่างส่ง — เช็คแชทก่อนว่าลูกค้าได้ของหรือยัง ห้ามรีบคืน stock'
                : 'job อาจตายกลางคัน';
            $problems[] = "งาน #{$d->id} ค้างสถานะ {$d->status} ตั้งแต่ {$d->updated_at} ({$hint})";
        }

        $activeRefs = AccountDelivery::whereIn('status', [
            AccountDelivery::STATUS_RESERVING,
            AccountDelivery::STATUS_RESERVED,
            AccountDelivery::STATUS_DELIVERING,
        ])->pluck('id')->map(fn ($id) => (string) $id)->all();

        try {
            foreach ($pool->orphanedReservedRows($activeRefs) as $row) {
                $problems[] = "ของจองค้าง #{$row['id']} ({$row['name']}) order_ref={$row['order_ref']} ไม่มีงาน active";
            }
        } catch (\Throwable $e) {
            Log::error('Reconcile: cannot read items_reserved', ['error' => $e->getMessage()]);
            $problems[] = 'อ่าน items_reserved ไม่ได้ — เช็ค mhha_acc_db';
        }

        if ($problems === []) {
            $this->info('clean');

            return self::SUCCESS;
        }

        $this->alert(implode("\n", $problems));
        $this->notifyTelegram($alertBot, $problems);

        return self::SUCCESS;
    }

    /** ส่งเข้า Telegram ผ่าน plugin ของงานล่าสุด (ไม่มีก็แค่ log) */
    private function notifyTelegram(TelegramAlertBotService $alertBot, array $problems): void
    {
        $delivery = AccountDelivery::with('bot', 'conversation')->latest('id')->first();
        $flow = $delivery?->conversation?->currentFlow ?? $delivery?->bot?->defaultFlow;
        $plugin = $flow?->plugins()->where('type', 'telegram')->where('enabled', true)->first();
        if (! $plugin) {
            Log::warning('Reconcile: no telegram plugin to notify', ['problems' => $problems]);

            return;
        }

        $alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            "🧯 ตรวจพบของค้างในระบบส่งบัญชี:\n".implode("\n", $problems)."\nรบกวนเช็คใน DB/แจ้งทีม dev",
        );
    }
}
```

- [ ] **Step 4: เพิ่ม schedule ใน `routes/console.php`**

```php
Schedule::command('delivery:reconcile')->hourly()->withoutOverlapping();
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=ReconcileDeliveriesTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/ReconcileDeliveries.php backend/routes/console.php backend/tests/Feature/ReconcileDeliveriesTest.php
git commit -m "feat(delivery): delivery:reconcile hourly limbo check"
```

---

### Task 11: `stock:sync-pool` — เปิด/ปิดสวิตช์ขายตามของจริง

**Files:**
- Create: `backend/app/Console/Commands/SyncProductStockFromPool.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/SyncProductStockFromPoolTest.php`

**Interfaces:**
- Consumes: `StockPoolService::countAvailable()`, `ProductStock` (`stock_code`, `delivery_method`, `in_stock`), `RagCache` model, `ProductStock::STOCK_CACHE_KEY`
- Produces: artisan command `stock:sync-pool` (schedule ทุก 5 นาที) — sync เฉพาะ `delivery_method='stock'` ที่มี `stock_code`; ล้าง cache แบบเดียวกับ `ProductStockController::update()` (แต่ operator LIKE/ILIKE เลือกตาม driver เพราะ sqlite ไม่มี ILIKE)

- [ ] **Step 1: เขียนเทสต์ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class SyncProductStockFromPoolTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal', 'aliases' => [],
            'in_stock' => true, 'display_order' => 1,
            'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => [], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
    }

    public function test_turns_off_when_pool_empty_and_busts_cache(): void
    {
        Cache::put(ProductStock::STOCK_CACHE_KEY, 'stale', 300);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertFalse(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
        $this->assertNull(Cache::get(ProductStock::STOCK_CACHE_KEY));
        // support_link ไม่ถูกแตะ
        $this->assertTrue(ProductStock::where('slug', 'page')->first()->in_stock);
    }

    public function test_turns_back_on_when_restocked(): void
    {
        ProductStock::where('slug', 'nolimit-personal')->update(['in_stock' => false]);
        $this->seedAvailable(1, 'NLMP');

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertTrue(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
    }

    public function test_no_change_no_cache_bust(): void
    {
        $this->seedAvailable(1, 'NLMP'); // มีของ + in_stock=true อยู่แล้ว
        Cache::put(ProductStock::STOCK_CACHE_KEY, 'keep', 300);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertSame('keep', Cache::get(ProductStock::STOCK_CACHE_KEY));
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=SyncProductStockFromPoolTest`
Expected: FAIL — command not found

- [ ] **Step 3: implement command**

```php
<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Models\RagCache;
use App\Services\Delivery\StockPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * sync สวิตช์ product_stocks.in_stock จากจำนวนของจริงใน mhha items_available
 * (บอทจะหยุด/กลับมาเชียร์ขายเองผ่านกลไก stock injection เดิม)
 * ล้าง cache ตาม pattern ของ ProductStockController::update()
 */
class SyncProductStockFromPool extends Command
{
    protected $signature = 'stock:sync-pool';

    protected $description = 'เปิด/ปิดสวิตช์ขายตามจำนวนของจริงใน stock DB';

    public function handle(StockPoolService $pool): int
    {
        try {
            $counts = $pool->countAvailable();
        } catch (\Throwable $e) {
            Log::error('stock:sync-pool cannot read pool', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $changed = 0;
        $products = ProductStock::where('delivery_method', 'stock')->whereNotNull('stock_code')->get();
        foreach ($products as $product) {
            $shouldBeInStock = ($counts[$product->stock_code] ?? 0) > 0;
            if ($product->in_stock === $shouldBeInStock) {
                continue;
            }

            DB::transaction(function () use ($product, $shouldBeInStock) {
                $product->update(['in_stock' => $shouldBeInStock]);
                $this->clearRagCacheFor($product);
            });
            $changed++;
            Log::info('stock:sync-pool toggled', [
                'slug' => $product->slug, 'in_stock' => $shouldBeInStock,
            ]);
        }

        if ($changed > 0) {
            Cache::forget(ProductStock::STOCK_CACHE_KEY);
        }
        $this->info("changed: {$changed}");

        return self::SUCCESS;
    }

    private function clearRagCacheFor(ProductStock $product): void
    {
        // sqlite (เทสต์) ไม่มี ILIKE — LIKE ของ sqlite case-insensitive อยู่แล้ว
        $op = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $escapedName = '%'.addcslashes($product->name, '%_').'%';
        $query = RagCache::where('query_text', $op, $escapedName);
        foreach ($product->aliases ?? [] as $alias) {
            $query->orWhere('query_text', $op, '%'.addcslashes($alias, '%_').'%');
        }
        $query->delete();
    }
}
```

- [ ] **Step 4: เพิ่ม schedule ใน `routes/console.php`**

```php
Schedule::command('stock:sync-pool')->everyFiveMinutes()->withoutOverlapping();
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน + รัน suite เต็มก่อนปิดงาน dev**

Run: `cd backend && php artisan test --filter=SyncProductStockFromPoolTest`
Expected: PASS (3 tests)

Run: `cd backend && php artisan test`
Expected: PASS ทั้ง suite (ไม่มีเทสต์เดิมแตก)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/SyncProductStockFromPool.php backend/routes/console.php backend/tests/Feature/SyncProductStockFromPoolTest.php
git commit -m "feat(delivery): stock:sync-pool auto toggle product_stocks"
```

---

### Task 12: Ops — DDL บน mhha prod, mapping, env, webhook, manual E2E

งาน ops ทำตอน deploy (ไม่ใช่โค้ด) — ทำตามลำดับ ทุกขั้นมี verify

- [ ] **Step 1: สร้างตาราง `items_reserved` บน mhha_acc_db prod** (Neon project `muddy-mountain-79902399` — รันผ่าน Neon MCP/console; additive ไม่แตะตารางเดิม)

```sql
CREATE TABLE IF NOT EXISTS items_reserved (
    id integer PRIMARY KEY,
    name text NOT NULL,
    detail text NOT NULL,
    type text NOT NULL DEFAULT 'account',
    "viaId" text,
    "bmId" text,
    "adsId" text,
    cost numeric DEFAULT 0,
    price numeric DEFAULT 0,
    "createdAt" timestamp DEFAULT now(),
    "updatedAt" timestamp DEFAULT now(),
    order_ref text,
    "reservedAt" timestamp
);
CREATE INDEX IF NOT EXISTS items_reserved_order_ref_idx ON items_reserved (order_ref);
```

Verify: `SELECT count(*) FROM items_reserved;` → `0`

- [ ] **Step 2: ตั้ง mapping บน `product_stocks` prod (Neon bot-facebook)** — ก่อนรันให้ `SELECT id, name, slug, aliases FROM product_stocks;` ดูของจริงก่อน แล้วปรับ slug ในคำสั่งให้ตรง:

```sql
UPDATE product_stocks SET stock_code = 'NLMP',  delivery_method = 'stock'        WHERE slug = '<slug ของ Nolimit ส่วนตัว>';
UPDATE product_stocks SET stock_code = 'NLMBM', delivery_method = 'stock'        WHERE slug = '<slug ของ Share BM>';
UPDATE product_stocks SET stock_code = 'G3D',   delivery_method = 'stock'        WHERE slug = '<slug ของ เฟสไก่>';
UPDATE product_stocks SET stock_code = NULL,    delivery_method = 'support_link' WHERE slug = '<slug ของ เพจ>';
```

Verify: `SELECT slug, stock_code, delivery_method FROM product_stocks;` — 4 แถวถูกต้อง แถวอื่น `none`

- [ ] **Step 3: ตั้ง env บน Railway (service backend + worker)**

```
MHHA_ACC_DATABASE_URL=<connection string ของ mhha_acc_db จาก Neon>
ACCOUNT_DELIVERY_ENABLED=true
ACCOUNT_DELIVERY_BOT_IDS=26
```

Verify: `php artisan tinker --execute="dd(config('delivery'))"` บน Railway shell (หรือ log ตอน boot)

- [ ] **Step 4: Deploy + migrate** — merge PR → Railway auto deploy → `php artisan migrate` รันอัตโนมัติตาม pipeline เดิม
Verify: ตาราง `account_deliveries` เกิดใน Neon bot-facebook

- [ ] **Step 5: Webhook Telegram** — ไม่ต้องแก้ (ปุ่มใช้ `callback_query` ซึ่ง `allowed_updates` เดิมครอบคลุมแล้ว) แต่ verify ด้วยการกดปุ่มเก่า (ยืนยันรับเงิน) ว่ายังทำงาน

- [ ] **Step 6: ทดสอบ concurrency ของจริงบน Neon** (ทดสอบ `FOR UPDATE SKIP LOCKED` ที่ sqlite เทสต์ไม่ครอบ) — สร้าง Neon branch จาก mhha_acc_db แล้วยิงพร้อมกัน 2 session:

```sql
-- เตรียม: เหลือ NLMP ปลอม 1 แถวบน branch ทดสอบ
-- session A และ B รันพร้อมกัน:
DELETE FROM items_available WHERE id = (
  SELECT id FROM items_available WHERE name = 'NLMP' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED
) RETURNING id;
```

Expected: ฝั่งเดียวได้แถว อีกฝั่งได้ 0 แถว → ลบ branch ทิ้ง

- [ ] **Step 7: Manual E2E บน bot 26** (เจ้าของทำ พร้อม checklist)
  1. เพิ่มของทดสอบใน `items_available` (`name='G3D'`, detail ปลอม) + สินค้าทดสอบราคาต่ำ
  2. ลูกค้าทดสอบสั่งซื้อ → โอนจริง → สลิปผ่าน
  3. เช็ค: แถวหายจาก `items_available` → ไปอยู่ `items_reserved` พร้อม `order_ref`
  4. การ์ดขึ้นใน Telegram ถูกต้อง (ชื่อลูกค้า/รายการ/เลขจอง)
  5. กด "✅ ส่งให้ลูกค้าเลย" → ลูกค้าได้ credential ใน LINE + แถวย้ายเข้า `items_sold` (`username='bot-fb'`)
  6. ทำซ้ำอีกออเดอร์แล้วกด "↩️ ยกเลิก" 2 จังหวะ → ของกลับ `items_available`
  7. ทำออเดอร์เพจ → ลูกค้าได้ข้อความลิงก์ Support
  8. ปล่อยงานค้าง >30 นาที → มีเตือน ⏰ ใน Telegram
  9. ลบข้อมูลทดสอบทั้งหมด

---

## Self-Review (ทำแล้ว)

- **Spec coverage:** จอง atomic ตั้งแต่สลิปผ่าน (Task 3+5+6) / ปุ่ม Telegram + ยกเลิก 2 จังหวะ (Task 8) / PAGE ส่งลิงก์ Support + ออเดอร์ผสม (Task 5+7) / string ดิบ ไม่ผ่าน LLM (Task 7) / เตือนซ้ำไม่คืนอัตโนมัติ (Task 9) / reconcile (Task 10) / stock sync (Task 11) / จำกัด bot 26 + flag (Task 2+5) / ตารางกรณีผิดพลาดข้อ 9 ของ spec → มีเทสต์ครอบใน Task 5, 7, 8, 10
- **Deviations จาก spec (จงใจ, แจ้งใน Global Constraints):** job `tries=1` + shortage แทน queue retry; ไม่มีคอลัมน์ `order_id`/`telegram_message_id`; reminder ส่งการ์ดใหม่แทน edit
- **Type consistency:** `reserveOne(): ?array` ใช้สม่ำเสมอใน Task 3/5; `deliver()/cancel()` throw `DeliveryAlreadyHandledException` ตรงกันใน Task 7/8; callback 3 ส่วน `act|id|x` ตรงกับ parser เดิม
