# ใบเตือนงานส่งของไม่มีปุ่ม — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ทำให้งานส่งของ 1 งาน มีปุ่มกดใน Telegram อยู่ชุดเดียวตลอด โดยใบเตือนซ้ำเปลี่ยนเป็นข้อความไม่มีปุ่มที่ quote การ์ดใบแรก

**Architecture:** เก็บ `message_id` ของการ์ดใบแรกไว้บนแถว `account_deliveries` ตอนส่งการ์ดสำเร็จ · คำสั่ง `delivery:remind` เลิกเรียก `sendCard()` แล้วเรียก `sendReminder()` ที่ส่งข้อความสั้นไม่มี `reply_markup` พร้อม `reply_to_message_id` · ถ้าไม่มี `card_message_id` (การ์ดใบแรกไม่เคยออก) ให้ตกกลับไปส่งการ์ดเต็มพร้อมปุ่มเหมือนเดิม

**Tech Stack:** Laravel 13 (PHP 8.3+), Pest 4 / PHPUnit 12, PostgreSQL (prod) / SQLite (test), Telegram Bot API

**Spec:** `docs/superpowers/specs/2026-08-12-delivery-reminder-single-button-design.md`

## Global Constraints

- ห้ามแตะ `app/Http/Controllers/Webhook/TelegramAlertCallbackController.php` — guard `DeliveryAlreadyHandledException` เป็นตาข่ายชั้นสุดท้าย ต้องคงเดิมทุกบรรทัด
- ห้ามเปลี่ยนพฤติกรรม quiet hours / `last_reminded_at` ใน `RemindPendingDeliveries` — โดยเฉพาะกฎ "ห้ามประทับ `last_reminded_at` เมื่อส่งไม่ออก" และ "เตือนรอบแรกทะลุช่วงเงียบได้"
- `AccountDeliveryService::sendCard()` ต้องคงชนิดค่าคืนเป็น `bool` (`SendDeliveryCard` job พึ่งค่านี้เพื่อ retry)
- ค่าที่มาจากข้อมูล (ชื่อลูกค้า, ชื่อสินค้า) ต้องผ่าน `TelegramAlertBotService::esc()` ก่อนประกอบเข้าข้อความ HTML
- "สำเร็จ" ของการยิง Telegram = HTTP ตอบ ok เท่านั้น — การไม่มี `message_id` ใน response ห้ามถูกตีความว่าล้มเหลว
- ห้าม log ค่า credential (`detail`) เด็ดขาด
- รันเทสต์จาก `backend/` เสมอ
- คอมเมนต์และข้อความผู้ใช้เป็นภาษาไทย ตามสไตล์ไฟล์เดิม

## File Structure

| ไฟล์ | หน้าที่ | Task |
|------|---------|------|
| `backend/app/Services/Payment/TelegramAlertBotService.php` | wrapper Telegram API — คืน `result` ให้ผู้เรียก + รองรับ reply | 1 |
| `backend/tests/Feature/TelegramAlertBotServiceTest.php` | เทสต์ wrapper | 1 |
| `backend/database/migrations/2026_08_12_000000_add_card_message_id_to_account_deliveries_table.php` | คอลัมน์ `card_message_id` | 2 |
| `backend/app/Models/AccountDelivery.php` | เพิ่ม `card_message_id` ใน `$fillable` | 2 |
| `backend/app/Services/Delivery/AccountDeliveryService.php` | `sendCard()` จด message_id + เมธอดใหม่ `sendReminder()` | 2, 3 |
| `backend/tests/Feature/AccountDeliverySendCardTest.php` | เทสต์การจด message_id | 2 |
| `backend/app/Console/Commands/RemindPendingDeliveries.php` | เรียก `sendReminder()` แทน `sendCard()` | 3 |
| `backend/tests/Feature/RemindPendingDeliveriesTest.php` | เทสต์ใบเตือนไม่มีปุ่ม + fallback | 3 |

---

### Task 1: `sendMessage()` คืน result และรองรับ reply

**Files:**
- Modify: `backend/app/Services/Payment/TelegramAlertBotService.php:34-49`, `:74-95`
- Test: `backend/tests/Feature/TelegramAlertBotServiceTest.php:38-64`

**Interfaces:**
- Consumes: ไม่มี (task แรก)
- Produces:
  - `TelegramAlertBotService::sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null, ?int $replyToMessageId = null): ?array`
    คืน `null` เมื่อยิงไม่สำเร็จ; คืน array ของ `result` จาก Telegram เมื่อสำเร็จ (อาจเป็น `[]` ถ้า response ไม่มี `result`) — ผู้เรียกต้องเทียบด้วย `!== null` ห้ามใช้ truthiness เพราะ `[]` เป็น falsy
  - `TelegramAlertBotService::editMessageText(...)` และ `answerCallbackQuery(...)` ลายเซ็นเดิมทุกตัวอักษร

**เหตุผลของชนิดค่าคืน:** ถ้าคืน `?int` (message_id) จะแยกไม่ออกระหว่าง "ส่งไม่สำเร็จ" กับ "ส่งสำเร็จแต่ response ไม่มี message_id" — เทสต์เดิม 15 ไฟล์ fake ด้วย `['ok' => true]` เปล่าๆ ทั้งหมด จะกลายเป็นล้มเหลวหมด

- [ ] **Step 1: อัปเดตเทสต์เดิม 3 ตัวให้เข้ากับชนิดค่าคืนใหม่**

แก้ `backend/tests/Feature/TelegramAlertBotServiceTest.php` แทนที่ 3 เมธอด (บรรทัด 38-64) ด้วย:

```php
    public function test_send_message_returns_the_telegram_result_when_accepted(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 4321],
        ])]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertSame(4321, $result['message_id'] ?? null);
    }

    public function test_send_message_returns_empty_array_when_telegram_omits_result(): void
    {
        // "สำเร็จ" คือ HTTP ตอบ ok — response ที่ไม่มี result ห้ามถูกตีความว่าล้มเหลว
        // (เทสต์ทั้งระบบ fake ด้วย ['ok' => true] เปล่าๆ ถ้าตีความผิดจะพังยกแผง)
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertSame([], $result);
    }

    public function test_send_message_returns_null_when_telegram_rejects_the_payload(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertNull($result);
    }

    public function test_send_message_returns_null_when_the_connection_times_out(): void
    {
        // เคสจริง 1 ส.ค. 2026: api.telegram.org ค้าง การ์ดปุ่มส่งของหายเงียบ
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertNull($result);
    }

    public function test_send_message_attaches_reply_target_that_survives_a_deleted_card(): void
    {
        // allow_sending_without_reply: ถ้าการ์ดใบแรกถูกลบไปแล้ว Telegram ต้องยังส่งใบเตือนออก
        // ไม่ใช่ปฏิเสธทั้งข้อความ (ใบเตือนตายเงียบอันตรายกว่าปุ่มค้าง)
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

        app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi', null, 777);

        Http::assertSent(fn ($r) => ($r['reply_to_message_id'] ?? null) === 777
            && ($r['allow_sending_without_reply'] ?? null) === true
            && ! isset($r['reply_markup']));
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าพัง**

```bash
cd backend && php artisan test --filter=TelegramAlertBotServiceTest
```
Expected: FAIL — `assertSame(4321, ...)` ได้ `null` (ตอนนี้ `sendMessage` คืน bool) และเทสต์ reply ไม่เจอ `reply_to_message_id`

- [ ] **Step 3: แก้ `call()` ให้คืน result array**

ใน `backend/app/Services/Payment/TelegramAlertBotService.php` แทนที่เมธอด `call()` (บรรทัด 73-95) ด้วย:

```php
    /**
     * @return array<string, mixed>|null null = ยิงไม่สำเร็จ; array = Telegram รับแล้ว
     *
     * "สำเร็จ" ผูกกับ HTTP ok เท่านั้น — response ที่ไม่มี result คืน [] ไม่ใช่ null
     * ผู้เรียกต้องเทียบด้วย !== null ([] เป็น falsy จะทำให้ if (! $x) อ่านผิดเป็นล้มเหลว)
     */
    private function call(string $token, string $method, array $params): ?array
    {
        try {
            $res = Http::timeout(self::TIMEOUT_SECONDS)->retry(2, 500)->post(self::BASE.$token.'/'.$method, $params);

            if (! $res->successful() || $res->json('ok') === false) {
                Log::warning('Telegram alert API non-OK response', [
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);

                return null;
            }

            $result = $res->json('result');

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            Log::warning('Telegram alert API call failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
```

- [ ] **Step 4: แก้ `sendMessage()` / `setWebhook()` ให้เข้ากับ `call()` ใหม่**

แทนที่ `sendMessage()` (บรรทัด 34-42) ด้วย:

```php
    /**
     * @return array<string, mixed>|null null = ส่งไม่สำเร็จ; array = result จาก Telegram
     *                                   (มี message_id เมื่อ Telegram ส่งมา) — เทียบด้วย !== null
     *
     * $replyToMessageId: ผูกข้อความนี้เป็น reply ของข้อความเดิม พร้อม allow_sending_without_reply
     * เพื่อให้ยังส่งออกแม้ข้อความต้นทางถูกลบไปแล้ว
     */
    public function sendMessage(
        string $token,
        string $chatId,
        string $text,
        ?array $inlineKeyboard = null,
        ?int $replyToMessageId = null,
    ): ?array {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
            $params['allow_sending_without_reply'] = true;
        }

        return $this->call($token, 'sendMessage', $params);
    }
```

`editMessageText()` และ `answerCallbackQuery()` ไม่ต้องแก้ (คืน `void` อยู่แล้ว — เรียก `call()` แล้วทิ้งค่า)
`setWebhook()` ไม่ได้ใช้ `call()` — ไม่ต้องแก้

แก้ docblock ของคลาส (บรรทัด 8-13) บรรทัดที่เขียนว่า "ตัวที่คืน bool (sendMessage, setWebhook)" เป็น:

```php
 * แต่ตัวที่คืนค่า (sendMessage คืน ?array, setWebhook คืน bool) บอกผู้เรียกได้ว่าสำเร็จไหม
```

- [ ] **Step 5: รันเทสต์ไฟล์นี้ให้ผ่าน**

```bash
cd backend && php artisan test --filter=TelegramAlertBotServiceTest
```
Expected: PASS ทั้งไฟล์ (รวม `tests/Unit/Payment/TelegramAlertBotServiceTest.php` ที่ชื่อคลาสเดียวกัน — ทั้งคู่ไม่ได้ assert ค่าคืน)

- [ ] **Step 6: รันเทสต์ที่แตะ Telegram ทั้งหมด กันการตีความ `[]` ผิด**

```bash
cd backend && php artisan test --filter='Delivery|Telegram|Slip|ManualPaymentConfirm|OrderChecksum'
```
Expected: PASS ทั้งหมด — ถ้ามีเคสไหนพังเพราะ `if (! $result)` แปลว่ามีผู้เรียกที่ใช้ truthiness ให้แก้เป็น `=== null` / `!== null`

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Services/Payment/TelegramAlertBotService.php tests/Feature/TelegramAlertBotServiceTest.php
git commit -m "refactor(telegram): sendMessage คืน result array + รองรับ reply_to_message_id"
```

---

### Task 2: จด `card_message_id` ของการ์ดใบแรก

**Files:**
- Create: `backend/database/migrations/2026_08_12_000000_add_card_message_id_to_account_deliveries_table.php`
- Modify: `backend/app/Models/AccountDelivery.php:23-32`
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:230-249`
- Test: `backend/tests/Feature/AccountDeliverySendCardTest.php`

**Interfaces:**
- Consumes: `TelegramAlertBotService::sendMessage(...): ?array` จาก Task 1
- Produces:
  - คอลัมน์ `account_deliveries.card_message_id` (`unsignedBigInteger`, nullable)
  - `AccountDeliveryService::sendCard(AccountDelivery $delivery, string $prefix = ''): bool` — ลายเซ็นเดิม แต่บันทึก `card_message_id` เมื่อส่งสำเร็จและ response มี `message_id`

- [ ] **Step 1: เขียนเทสต์ที่ยังไม่ผ่าน**

เพิ่มท้ายคลาสใน `backend/tests/Feature/AccountDeliverySendCardTest.php`:

```php
    public function test_send_card_records_the_telegram_message_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 8899],
        ])]);
        $delivery = $this->makeDelivery();

        $sent = app(AccountDeliveryService::class)->sendCard($delivery);

        $this->assertTrue($sent);
        $this->assertSame(8899, $delivery->fresh()->card_message_id);
    }

    public function test_send_card_leaves_message_id_null_when_telegram_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $delivery = $this->makeDelivery();

        $sent = app(AccountDeliveryService::class)->sendCard($delivery);

        $this->assertFalse($sent);
        $this->assertNull($delivery->fresh()->card_message_id);
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าพัง**

```bash
cd backend && php artisan test --filter=AccountDeliverySendCardTest
```
Expected: FAIL — คอลัมน์ `card_message_id` ยังไม่มี (SQLite: "no such column")

- [ ] **Step 3: สร้าง migration**

สร้าง `backend/database/migrations/2026_08_12_000000_add_card_message_id_to_account_deliveries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            // message_id ของการ์ดปุ่มใบแรกใน Telegram — ใบเตือนซ้ำ reply มาที่ใบนี้แทน
            // การส่งการ์ดใบใหม่ เพื่อให้ 1 งานมีปุ่มกดอยู่ชุดเดียวเสมอ (กันกดส่งซ้ำ)
            // null = การ์ดใบแรกไม่เคยออก → รอบเตือนจะส่งการ์ดเต็มพร้อมปุ่มแทน
            $table->unsignedBigInteger('card_message_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->dropColumn('card_message_id');
        });
    }
};
```

- [ ] **Step 4: เพิ่มคอลัมน์ใน `$fillable`**

ใน `backend/app/Models/AccountDelivery.php` แก้ `$fillable` (บรรทัด 23-26) เป็น:

```php
    protected $fillable = [
        'bot_id', 'conversation_id', 'slip_verification_id', 'status',
        'amount', 'confirmed_by', 'delivered_at', 'last_reminded_at', 'card_message_id',
    ];
```

- [ ] **Step 5: ให้ `sendCard()` จด message_id**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` แทนที่ `sendCard()` (บรรทัด 230-249) ด้วย:

```php
    public function sendCard(AccountDelivery $delivery, string $prefix = ''): bool
    {
        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for card', ['delivery_id' => $delivery->id]);

            return false;
        }

        $keyboard = $delivery->status === AccountDelivery::STATUS_RESERVED
            ? $this->cardKeyboard($delivery)
            : null;

        $result = $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $prefix.$this->cardText($delivery),
            $keyboard,
        );

        if ($result === null) {
            return false;
        }

        // จดใบที่ถือปุ่มไว้ ให้รอบเตือนซ้ำ reply มาที่ใบนี้แทนการสร้างปุ่มชุดใหม่
        // เทียบ !== null ไม่ใช่ truthiness: result ที่ว่าง ([]) ยังแปลว่าส่งสำเร็จ
        if (isset($result['message_id'])) {
            $delivery->update(['card_message_id' => (int) $result['message_id']]);
        }

        return true;
    }
```

- [ ] **Step 6: รันเทสต์ให้ผ่าน**

```bash
cd backend && php artisan test --filter=AccountDeliverySendCardTest
```
Expected: PASS ทั้ง 5 เคส

- [ ] **Step 7: Commit**

```bash
cd backend && git add database/migrations/2026_08_12_000000_add_card_message_id_to_account_deliveries_table.php app/Models/AccountDelivery.php app/Services/Delivery/AccountDeliveryService.php tests/Feature/AccountDeliverySendCardTest.php
git commit -m "feat(delivery): จด message_id ของการ์ดปุ่มใบแรกไว้บนงานส่งของ"
```

---

### Task 3: ใบเตือนเป็นข้อความไม่มีปุ่มที่ quote การ์ดใบแรก

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (เพิ่มเมธอด `sendReminder()` ต่อจาก `sendCard()`)
- Modify: `backend/app/Console/Commands/RemindPendingDeliveries.php:44-54`
- Test: `backend/tests/Feature/RemindPendingDeliveriesTest.php`

**Interfaces:**
- Consumes:
  - `TelegramAlertBotService::sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null, ?int $replyToMessageId = null): ?array` (Task 1)
  - `AccountDelivery::$card_message_id` และ `AccountDeliveryService::sendCard(...): bool` (Task 2)
- Produces:
  - `AccountDeliveryService::sendReminder(AccountDelivery $delivery, int $ageMinutes): bool` — `false` = ใบเตือนไม่ออก ผู้เรียกต้องไม่ประทับ `last_reminded_at`

- [ ] **Step 1: เขียนเทสต์ที่ยังไม่ผ่าน**

เพิ่มท้ายคลาสใน `backend/tests/Feature/RemindPendingDeliveriesTest.php`:

```php
    public function test_reminder_carries_no_buttons_and_quotes_the_original_card(): void
    {
        // ต้นเหตุที่เจ้าของกดส่งซ้ำ: ใบเตือนเคยเป็นการ์ดเต็มที่แนบปุ่มชุดใหม่ทุกรอบ
        // เตือนกี่รอบ = ปุ่มค้างในกลุ่มเพิ่มอีกกี่ชุด
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 12345],
        ])]);
        $delivery = $this->makeDelivery([
            'created_at' => now()->subHour(),
            'card_message_id' => 4321,
        ]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && ! isset($r['reply_markup'])
            && ($r['reply_to_message_id'] ?? null) === 4321
            && str_contains($r['text'] ?? '', '⏰')
            && str_contains($r['text'] ?? '', "#{$delivery->id}"));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }

    public function test_reminder_falls_back_to_a_full_card_when_the_first_one_never_arrived(): void
    {
        // เคส 1 ส.ค. 2026: การ์ดใบแรกไม่เคยไปถึง Telegram → card_message_id ว่าง
        // รอบเตือนต้องส่งการ์ดพร้อมปุ่มแทน ไม่งั้นงานนี้จะไม่มีปุ่มให้กดเลยตลอดกาล
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 777],
        ])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(function ($r) {
            $markup = json_decode($r['reply_markup'] ?? '[]', true);

            return str_contains($r->url(), 'sendMessage')
                && str_contains(json_encode($markup), 'dv|')
                && str_contains(json_encode($markup), 'dx|');
        });
        // การ์ดใบนี้กลายเป็นใบที่ถือปุ่ม — รอบถัดไปต้อง reply มาที่ใบนี้ ไม่ส่งปุ่มซ้ำอีก
        $this->assertSame(777, $delivery->fresh()->card_message_id);
    }
```

แก้เมธอดเดิม `test_reminds_stale_reserved_delivery` (บรรทัด 61-71) ให้ fake คืน `result` ด้วย เพื่อสะท้อน response จริง:

```php
    public function test_reminds_stale_reserved_delivery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 111],
        ])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour(), 'card_message_id' => 42]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '⏰'));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าพัง**

```bash
cd backend && php artisan test --filter=RemindPendingDeliveriesTest
```
Expected: FAIL — `test_reminder_carries_no_buttons_and_quotes_the_original_card` ล้มเพราะใบเตือนยังแนบ `reply_markup` และไม่มี `reply_to_message_id`

- [ ] **Step 3: เพิ่มเมธอด `sendReminder()`**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` แทรกเมธอดนี้ **ต่อจาก `sendCard()`** (ก่อน `cardKeyboard()`):

```php
    /**
     * เตือนงานที่ค้างกดยืนยัน — ข้อความสั้นไม่มีปุ่ม ที่ reply กลับไปที่การ์ดใบแรก
     *
     * เจตนา: 1 งานต้องมีปุ่มกดอยู่ชุดเดียวเสมอ เดิมรอบเตือนส่งการ์ดเต็มพร้อมปุ่มชุดใหม่
     * ทุกรอบ พอกดส่งจากใบหนึ่ง ปุ่มบนใบอื่นยังค้าง เจ้าของเผลอกดซ้ำได้
     * (กดซ้ำไม่ทำให้ส่งของซ้ำ — deliver() กันไว้ — แต่ทำให้สับสนว่าตกลงส่งไปหรือยัง)
     *
     * @return bool false = ใบเตือนไม่ออก ผู้เรียกต้องไม่ประทับ last_reminded_at
     */
    public function sendReminder(AccountDelivery $delivery, int $ageMinutes): bool
    {
        // การ์ดใบแรกไม่เคยไปถึง Telegram (เหตุการณ์ 1 ส.ค. 2026) — งานนี้ยังไม่มีปุ่มให้กดเลย
        // ต้องส่งการ์ดเต็มพร้อมปุ่ม ไม่ใช่ข้อความชี้ไปที่การ์ดที่ไม่มีอยู่จริง
        if ($delivery->card_message_id === null) {
            return $this->sendCard($delivery, "⏰ <b>เตือน:</b> งานส่งของค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
        }

        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for reminder', ['delivery_id' => $delivery->id]);

            return false;
        }

        $conv = $delivery->conversation;
        $customer = TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';

        $text = "⏰ <b>เตือน:</b> งาน #{$delivery->id} ค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n"
            ."👤 <b>{$customer}</b> · 💵 <code>{$amount}</code> บาท\n"
            .'👆 กดปุ่มบนการ์ดที่ quote ไว้';

        return $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $text,
            null,
            $delivery->card_message_id,
        ) !== null;
    }
```

- [ ] **Step 4: ให้คำสั่ง `delivery:remind` เรียก `sendReminder()`**

ใน `backend/app/Console/Commands/RemindPendingDeliveries.php` แทนที่บรรทัด 44-54 ด้วย:

```php
            $ageMinutes = (int) $delivery->created_at->diffInMinutes(now());
            if (! $service->sendReminder($delivery, $ageMinutes)) {
                // ห้ามประทับเวลาเตือนเมื่อใบเตือนไม่ออก ไม่งั้นงานจะเสียสิทธิ์ทะลุช่วงเงียบครั้งเดียว
                // ไปฟรีๆ แล้วเงียบยาวถึง 08:00 แบบเคส #49 — ตาข่ายสุดท้ายดับตอนที่ต้องใช้พอดี
                // ยอมให้พยายามซ้ำทุกรอบดีกว่าปล่อยออเดอร์ที่ลูกค้าจ่ายเงินแล้วค้างข้ามคืน
                Log::error('Delivery: reminder never reached Telegram', [
                    'delivery_id' => $delivery->id,
                ]);

                continue;
            }
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

```bash
cd backend && php artisan test --filter=RemindPendingDeliveriesTest
```
Expected: PASS ทั้ง 8 เคส (6 เดิม + 2 ใหม่)

- [ ] **Step 6: รันเทสต์ทั้งชุดของระบบส่งของ**

```bash
cd backend && php artisan test --filter='Delivery|Telegram|Slip|ManualPaymentConfirm|OrderChecksum'
```
Expected: PASS ทั้งหมด

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Services/Delivery/AccountDeliveryService.php app/Console/Commands/RemindPendingDeliveries.php tests/Feature/RemindPendingDeliveriesTest.php
git commit -m "fix(delivery): ใบเตือนไม่แนบปุ่มอีก ให้ quote การ์ดใบแรกแทน กันกดส่งซ้ำ"
```

---

### Task 4: ตรวจทั้งชุดก่อนส่งขึ้น prod

**Files:** ไม่แก้ไฟล์ (เว้นแต่เจอของพัง)

- [ ] **Step 1: รันเทสต์ทั้ง suite**

```bash
cd backend && composer test
```
Expected: PASS ทั้งหมด — ถ้ามีไฟล์ที่ fake Telegram แล้วพึ่ง `sendMessage` คืน bool ให้แก้ที่จุดนั้น

- [ ] **Step 2: ตรวจ style**

```bash
cd backend && ./vendor/bin/pint --dirty
```
Expected: ไม่มี style issue ค้าง (ถ้า Pint แก้ไฟล์ ให้ `git add` แล้ว amend commit ล่าสุด)

- [ ] **Step 3: ตรวจ migration ขึ้น-ลงได้จริง**

```bash
cd backend && php artisan migrate --pretend | grep -i card_message_id
```
Expected: เห็น SQL `alter table ... add column card_message_id`

- [ ] **Step 4: ทวนว่าไม่ได้แตะไฟล์ต้องห้าม**

```bash
cd /Users/jaochai/Code/bot-fb && git diff --stat main -- backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php
```
Expected: ไม่มี output (ไฟล์นี้ต้องไม่ถูกแก้)

---

### Task 5: ปิดช่องที่รีวิวเจอ — dedupe format ชื่อ/ยอด + เทสต์กฎทองบน reply path

เพิ่มหลังรีวิว Task 3 (เจ้าของตัดสิน 2026-08-12): reviewer ชี้ว่า `sendReminder()` คัดลอกโค้ด
format ชื่อลูกค้า/ยอด มาจาก `cardText()` แบบ verbatim — ถ้าวันหน้าเปลี่ยนวิธี resolve ชื่อหรือ
format ยอด ต้องแก้ 2 ที่ ลืมที่หนึ่ง = ใบเตือนกับการ์ดแสดงคนละชื่อ/คนละยอด
และ Claude พบเพิ่มว่ากฎ "ส่งไม่ออก = ห้ามประทับ `last_reminded_at`" ยังไม่มีเทสต์บน reply path
ซึ่งจะเป็นทางเดินของเกือบทุกงานหลัง deploy

**ไม่อยู่ในขอบเขต:** guard plugin-null 5 บรรทัดที่ซ้ำกับ `sendCard()` — เจ้าของตัดสินให้ปล่อยไว้
(ต่างกันแค่ข้อความ log · extract แล้วต้องส่ง context string เข้าไปซึ่งอ่านยากกว่าเดิม)

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (`cardText()` และ `sendReminder()`)
- Test: `backend/tests/Feature/RemindPendingDeliveriesTest.php`

**Interfaces:**
- Consumes: `AccountDeliveryService::sendReminder(AccountDelivery, int): bool` (Task 3)
- Produces: helper ใหม่ 2 ตัว **ทั้งคู่เป็น `private`** ไม่มีผู้เรียกนอกคลาส:
  - `customerLabel(?Conversation $conv): string`
  - `amountLabel(AccountDelivery $delivery): string`

- [ ] **Step 1: เขียนเทสต์ที่ยังไม่ผ่าน**

เพิ่มท้ายคลาสใน `backend/tests/Feature/RemindPendingDeliveriesTest.php`:

```php
    public function test_does_not_stamp_reminder_when_the_reply_message_never_reached_telegram(): void
    {
        // reply path คือทางเดินหลักหลัง deploy — กฎ "ส่งไม่ออก = ห้ามประทับเวลา" ต้องคุมที่นี่ด้วย
        // ไม่ใช่แค่ fallback path ไม่งั้นตาข่ายเคส #49 ดับบนเส้นทางที่ใช้จริง
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $delivery = $this->makeDelivery([
            'created_at' => now()->subHours(3),
            'card_message_id' => 4321,
        ]);

        $this->artisan('delivery:remind')->assertSuccessful();

        $this->assertNull($delivery->fresh()->last_reminded_at);
    }

    public function test_reminder_shows_the_same_customer_label_as_the_card(): void
    {
        // ชื่อ/ยอดบนใบเตือนต้องมาจากทางเดียวกับการ์ด — คนละค่า = เจ้าของสับสนตอนกดยืนยัน
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 999],
        ])]);
        $delivery = $this->makeDelivery([
            'created_at' => now()->subHour(),
            'card_message_id' => 4321,
        ]);
        $expected = app(AccountDeliveryService::class)->cardTextForTesting($delivery);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(function ($r) use ($expected) {
            // ยอด 1,100 ที่ format แล้ว ปรากฏทั้งบนการ์ดและบนใบเตือน
            return str_contains($expected, '1,100') && str_contains($r['text'] ?? '', '1,100');
        });
    }
```

เพิ่ม import ที่หัวไฟล์ (ถ้ายังไม่มี): `use App\Services\Delivery\AccountDeliveryService;`

- [ ] **Step 2: รันเทสต์ให้เห็นว่าพัง**

```bash
cd backend && php artisan test --filter=RemindPendingDeliveriesTest
```
Expected: `test_does_not_stamp_reminder_when_the_reply_message_never_reached_telegram` FAIL
(เดิมยังไม่มีเทสต์นี้ · ถ้ามันผ่านตั้งแต่รอบแรกให้รายงานว่าผ่าน แล้วอธิบายว่าทำไม —
logic อาจถูกอยู่แล้วและเทสต์นี้เป็นการล็อกพฤติกรรมไว้ ไม่ใช่การแก้บั๊ก)

- [ ] **Step 3: extract helper แล้วเรียกใช้ทั้ง 2 ที่**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` แทรก 2 เมธอดนี้ **ก่อน** `cardText()`:

```php
    /** ชื่อลูกค้าที่ขึ้นบนการ์ดและใบเตือน — escape แล้ว · ไม่มีชื่อใช้ "แชท #id" แทน */
    private function customerLabel(?Conversation $conv): string
    {
        return TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
    }

    /** ยอดเงินที่ขึ้นบนการ์ดและใบเตือน — ไม่มียอดใช้ '-' */
    private function amountLabel(AccountDelivery $delivery): string
    {
        return $delivery->amount !== null ? number_format($delivery->amount) : '-';
    }
```

ใน `sendReminder()` แทนที่ 3 บรรทัดนี้:

```php
        $conv = $delivery->conversation;
        $customer = TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';
```
ด้วย:
```php
        $customer = $this->customerLabel($delivery->conversation);
        $amount = $this->amountLabel($delivery);
```

ใน `cardText()` แทนที่ 3 บรรทัดแรกของเมธอด:

```php
        $conv = $delivery->conversation;
        $customer = TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';
```
ด้วย:
```php
        $conv = $delivery->conversation;   // ยังใช้ต่อในบรรทัด "แชท #{$conv?->id}" ด้านล่าง — ห้ามลบ
        $customer = $this->customerLabel($conv);
        $amount = $this->amountLabel($delivery);
```

**ห้ามแตะส่วนอื่นของ `cardText()`** — ข้อความบนการ์ดต้องเหมือนเดิมทุกตัวอักษร

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

```bash
cd backend && php artisan test --filter=RemindPendingDeliveriesTest
```
Expected: PASS 10 เคส

- [ ] **Step 5: รันชุดกว้าง — การ์ดต้องไม่เปลี่ยนแม้แต่ตัวอักษรเดียว**

```bash
cd backend && php artisan test --filter='Delivery|Telegram|Slip|ManualPaymentConfirm|OrderChecksum'
```
Expected: PASS ทั้งหมด (เทสต์ที่ assert เนื้อการ์ดจะจับได้ทันทีถ้า extract ทำข้อความเพี้ยน)

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Services/Delivery/AccountDeliveryService.php tests/Feature/RemindPendingDeliveriesTest.php
git commit -m "refactor(delivery): ดึง format ชื่อ/ยอด มาใช้ทางเดียวกันทั้งการ์ดและใบเตือน"
```

---

## หลัง merge — สิ่งที่ต้องทำบน prod

1. deploy แล้ว **รัน migration บน prod** (`railway ssh` → `php artisan migrate --force`) — ดู `docs/` เรื่อง Railway SSH
2. งานที่สร้างก่อน deploy จะมี `card_message_id` เป็น null → รอบเตือนแรกจะส่งการ์ดเต็มพร้อมปุ่ม 1 ใบ แล้วจากนั้นเข้าโหมดใหม่ — เป็นพฤติกรรมที่ตั้งใจ
3. การ์ดที่ค้างอยู่ในกลุ่ม Telegram ตอนนี้จะยังค้างต่อไป (กดแล้วปลอดภัย ระบบขึ้น "งาน #N ถูกจัดการไปแล้ว")
4. เฝ้างานส่งของเคสจริงเคสแรกที่ค้างจนถึงรอบเตือน — ยืนยันว่าใบเตือน quote การ์ดใบแรกได้จริงและไม่มีปุ่ม
