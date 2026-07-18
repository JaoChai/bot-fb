# Telegram Delivery Card Delay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** หน่วง job `ReserveAccountStock` ตาม config เพื่อให้การ์ดปุ่ม Telegram ("🚚 พร้อมส่งสินค้า" + ปุ่ม ✅/↩️) ถูกส่ง **หลัง** ข้อความ "🛒 ออเดอร์ใหม่!" เสมอ

**Architecture:** ทุกเส้นทางที่สร้างงานส่งของ (สลิปผ่าน / เจ้าของกดยืนยัน / auto-retry) เรียกผ่าน `ReserveAccountStock::dispatchSafely()` จุดเดียว จึงเพิ่ม `->delay()` ตรงนั้นจุดเดียวพอ ค่า delay อ่านจาก config `delivery.card_delay_seconds` (default 15 วิ)

**Tech Stack:** Laravel 13, PHPUnit (มี test file เดิม `ReserveAccountStockDispatchTest` ให้เพิ่มเคสต่อ)

**Spec:** `docs/superpowers/specs/2026-07-18-telegram-card-delay-design.md`

## Global Constraints

- ค่า delay ต้องมาจาก config `delivery.card_delay_seconds` — default `15`, override ได้ผ่าน env `ACCOUNT_DELIVERY_CARD_DELAY_SECONDS`
- ห้ามแตะ: `RemindPendingDeliveries`, `delivery:reconcile`, callback controller, เนื้อหาข้อความการ์ด/ออเดอร์ใหม่
- ห้ามแก้ signature ของ `dispatchSafely()` — call sites ทั้ง 3 ไม่ต้องเปลี่ยน
- Comment ภาษาไทยตามสไตล์ไฟล์เดิม

---

### Task 1: หน่วง dispatch ของ ReserveAccountStock ตาม config

**Files:**
- Modify: `backend/config/delivery.php` (เพิ่ม key ใหม่หลัง `max_qty`)
- Modify: `backend/app/Jobs/ReserveAccountStock.php:49-59` (เมธอด `dispatchSafely`)
- Test: `backend/tests/Feature/ReserveAccountStockDispatchTest.php` (เพิ่ม test method)

**Interfaces:**
- Consumes: `config('delivery.card_delay_seconds')` — int วินาที
- Produces: `ReserveAccountStock::dispatchSafely(int $botId, int $conversationId, int $slipVerificationId, ?float $amount, array $items): void` — signature เดิมทุกอย่าง แต่ job ที่ dispatch จะมี `$job->delay` เป็น int ตาม config

- [ ] **Step 1: เขียน failing test**

เพิ่ม method นี้ท้าย class `ReserveAccountStockDispatchTest` (ไฟล์ `backend/tests/Feature/ReserveAccountStockDispatchTest.php`):

```php
public function test_dispatch_safely_delays_job_per_config(): void
{
    // หน่วง job เพื่อให้ข้อความ "ออเดอร์ใหม่!" จาก plugin ไปถึง Telegram ก่อนการ์ดปุ่ม
    Bus::fake([ReserveAccountStock::class]);
    config(['delivery.card_delay_seconds' => 20]);

    ReserveAccountStock::dispatchSafely(1, 2, 3, 100.0, []);

    Bus::assertDispatched(
        ReserveAccountStock::class,
        fn (ReserveAccountStock $job) => $job->delay === 20,
    );
}
```

- [ ] **Step 2: รัน test ยืนยันว่า fail**

Run: `cd backend && php artisan test tests/Feature/ReserveAccountStockDispatchTest.php --filter=test_dispatch_safely_delays_job_per_config`
Expected: FAIL — `The expected [App\Jobs\ReserveAccountStock] job was not dispatched` ตามเงื่อนไข closure (เพราะ `$job->delay` ยังเป็น `null`)

- [ ] **Step 3: เพิ่ม config key**

ใน `backend/config/delivery.php` เพิ่มหลัง block `max_qty` (บรรทัด `'max_qty' => ...`):

```php
    // หน่วง job จองสต๊อก+ส่งการ์ดปุ่ม เพื่อให้ข้อความ "ออเดอร์ใหม่!" จาก Telegram plugin
    // (ส่งใน executePlugins หลังตอบลูกค้า) ไปถึงก่อนการ์ดเสมอ — ปุ่มจะได้อยู่ล่างสุดของแชท
    'card_delay_seconds' => (int) env('ACCOUNT_DELIVERY_CARD_DELAY_SECONDS', 15),
```

- [ ] **Step 4: แก้ dispatchSafely ให้ delay**

ใน `backend/app/Jobs/ReserveAccountStock.php` เมธอด `dispatchSafely` เปลี่ยนบรรทัด

```php
            self::dispatch($botId, $conversationId, $slipVerificationId, $amount, $items);
```

เป็น

```php
            // หน่วงให้ข้อความ "ออเดอร์ใหม่!" จาก plugin ไปก่อน การ์ดปุ่มจะได้อยู่ล่างสุดของแชท
            self::dispatch($botId, $conversationId, $slipVerificationId, $amount, $items)
                ->delay((int) config('delivery.card_delay_seconds', 15));
```

- [ ] **Step 5: รัน test ยืนยันว่า pass ทั้งไฟล์**

Run: `cd backend && php artisan test tests/Feature/ReserveAccountStockDispatchTest.php`
Expected: PASS ทั้ง 4 tests (3 เดิม + 1 ใหม่) — test เดิม `test_manual_confirm_survives_reserve_job_failure` ใช้ sync queue ซึ่ง ignore delay จึงไม่กระทบ

- [ ] **Step 6: รัน Pint จัด format**

Run: `cd backend && vendor/bin/pint app/Jobs/ReserveAccountStock.php config/delivery.php tests/Feature/ReserveAccountStockDispatchTest.php`
Expected: PASS / fixed style

- [ ] **Step 7: Commit**

```bash
git add backend/config/delivery.php backend/app/Jobs/ReserveAccountStock.php backend/tests/Feature/ReserveAccountStockDispatchTest.php
git commit -m "fix(delivery): หน่วงการ์ดปุ่ม Telegram ให้มาหลังข้อความออเดอร์ใหม่"
```
