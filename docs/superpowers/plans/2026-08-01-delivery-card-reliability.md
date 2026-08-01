# แผนแก้การ์ดปุ่มส่งของหายเงียบ (Delivery Card Reliability)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ทำให้การ์ด Telegram ที่มีปุ่ม "✅ ส่งให้ลูกค้าเลย" ไปถึงเจ้าของร้านเสมอ แม้ api.telegram.org จะค้างชั่วคราว และถ้าไปไม่ถึงจริงๆ ต้องมีร่องรอยให้ตามได้

**Architecture:** แยกการส่งการ์ดออกจาก job จองสต๊อกให้เป็น job ของตัวเองที่ retry ได้ (การจองยังต้องทำครั้งเดียวเหมือนเดิม), ทำให้ชั้นส่ง Telegram รายงานผลสำเร็จ/ล้มเหลวแทนที่จะกลืนทิ้ง, ขยาย timeout ให้เท่ากับตัวส่งข้อความอีกตัวที่รอดในเหตุการณ์จริง, และเปิดทางให้ log ระดับ warning มองเห็นได้บน production

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12 (เขียนแบบ class-based ผ่าน `Tests\TestCase`), Pest 4 (ติดตั้งไว้แต่เทสต์ delivery เดิมเป็น class-based ทั้งหมด — เขียนตามของเดิม), Railway (production), Neon PostgreSQL

---

## ที่มา — เหตุการณ์จริงที่แผนนี้แก้

เกิดขึ้น 2 ครั้ง ทั้งสองครั้งลูกค้าจ่ายเงินแล้วแต่ไม่ได้ของ:

| งาน | วันเวลา | ผลลัพธ์ |
|---|---|---|
| #49 | 20 ก.ค. 2026 22:49 | การ์ดไม่มา → ช่วงเงียบ (23:00–08:00) กลืนการเตือนทุกรอบ → เจ้าของเพิ่งรู้ 08:00 วันรุ่งขึ้น (ค้าง 9 ชม. 11 นาที) แล้วกดส่งภายใน 42 วินาที |
| #99 | 1 ส.ค. 2026 18:19 | การ์ดไม่มา → เจ้าของต้องเบิกบัญชีส่งเอง บัญชีที่บอทจองไว้ค้างใน `items_reserved` |

**ไทม์ไลน์เคส #99 (จาก Railway log จริง):**

| เวลา | เหตุการณ์ |
|---|---|
| 18:19:11 | EasySlip ตรวจสลิปผ่าน (เร็วปกติ ~2 วิ) |
| 18:19:14 | push ข้อความตอบลูกค้าเข้า LINE (เร็วปกติ) |
| ~18:19:17 | LLM ตัดสินใจแจ้งเตือนเสร็จ (เร็วปกติ) → plugin POST "ออเดอร์ใหม่!" ไป Telegram → **ค้าง** |
| 18:19:27 | job จองสต๊อกตัดบัญชี #4691 สำเร็จทันที → POST การ์ดปุ่ม ครั้งที่ 1 → ค้าง → ตายที่ 5 วิ |
| 18:19:32.5 | POST การ์ดปุ่ม ครั้งที่ 2 → ค้าง → ตายที่ 5 วิ → เลิก |
| 18:19:37 | **job รายงานว่า "DONE" ทั้งที่การ์ดไม่เคยออก** |
| ~18:19:47 | POST ของ plugin ตายที่ 30 วิ → ลองใหม่ |
| 18:19:48 | ครั้งที่ 2 ผ่าน → "ออเดอร์ใหม่!" ขึ้นกลุ่ม |

**หลักฐานสนับสนุน:** job ใช้เวลา 10 วินาที เท่ากับเพดาน `timeout(5) × 2 ครั้ง + หน่วง 0.5 วิ = 10.5 วิ` พอดี (ปกติ job นี้ใช้ 0.9–1 วิ) / CPU สูงสุด 0.04 จาก 32 core, RAM 0.3 จาก 32 GB → เครื่องว่างสนิท / EasySlip, LINE, OpenRouter ในนาทีเดียวกันเร็วปกติหมด → ช้าเฉพาะทาง Telegram

**Root cause 4 ชั้น:**

1. **ตัวจุดชนวน (นอกเหนือการควบคุม):** api.telegram.org ค้าง ~30 วินาที
2. **ทำไมการ์ดตายทั้งที่ข้อความอื่นรอด:** `TelegramAlertBotService` ตั้ง `Http::timeout(5)` ส่วน `FlowPluginService` ใช้ค่าปริยาย 30 วินาที — ยิงเซิร์ฟเวอร์เดียวกัน โทเคนเดียวกัน กลุ่มเดียวกัน แต่ตัวที่สำคัญที่สุดตั้งเวลารอสั้นที่สุด และ retry 2 ครั้งห่างกัน 0.5 วิ = อยู่ในหน้าต่างช้าเดียวกันทั้งคู่
3. **ทำไมยิงซ้ำไม่ได้เลย:** การส่งการ์ดอยู่ใน `ReserveAccountStock` ซึ่งตั้ง `tries = 1` โดยตั้งใจ (ถูกต้อง — กันจองซ้ำ) งานที่ต้อง "ทำครั้งเดียว" กับงานที่ต้อง "ทำจนสำเร็จ" ถูกมัดรวมกัน
4. **ทำไมเงียบ 2 รอบโดยไม่มีร่องรอย:** `catch (\Throwable)` → `Log::warning` เท่านั้น แต่ prod ตั้ง `LOG_LEVEL=error` และไม่ได้ตั้ง `LOG_STACK` (default `single` = เขียนไฟล์ที่หายทุก deploy) → warning ไม่ถูกเก็บที่ไหนเลย Sentry ก็ไม่เห็นเพราะ exception ถูก catch ไปแล้ว

---

## Global Constraints

- Laravel 13 / PHP 8.4 — `laravel/framework: ^13.0`, `"php": "^8.4"` ใน `backend/composer.json`
- เทสต์ delivery ทั้งหมดเป็น **class-based PHPUnit** สืบทอด `Tests\TestCase` ใช้ `RefreshDatabase` — เขียนตามรูปแบบเดิม ห้ามเปลี่ยนเป็น Pest function style
- `QUEUE_CONNECTION=sync` ใน `backend/phpunit.xml` → job ที่ dispatch ในเทสต์จะรัน**ทันทีแบบ inline** และ exception จะเด้งกลับหาผู้ dispatch
- `DB_CONNECTION=sqlite` ใน test — ระวังพฤติกรรมต่างจาก PostgreSQL บน prod
- **ห้าม log ค่า `detail` ของ stock item เด็ดขาด** (เป็น credential) — กฎเดิมของ `AccountDeliveryService`
- คำ commit ใช้ conventional commits ภาษาไทย ตามของเดิมใน repo (เช่น `fix(delivery): ...`)
- รัน Pint ก่อน commit ทุกครั้ง: `cd backend && ./vendor/bin/pint`
- ค่าคงที่จากเอกสาร Laravel 13 ที่ยืนยันแล้ว (ใช้อ้างอิงได้): `Http::retry($maxAttempts, $msBetween)` — พารามิเตอร์แรกคือ**จำนวนครั้งรวม** / `Http::timeout()` ค่าปริยาย **30 วินาที** / ยิงครบทุกครั้งแล้วยังพังจะโยน `RequestException` (ถ้าเป็นปัญหาการเชื่อมต่อจะโยน `ConnectionException` เสมอ)

---

## File Structure

| ไฟล์ | หน้าที่ | Task |
|---|---|---|
| `backend/app/Services/Payment/TelegramAlertBotService.php` | แก้: ให้ `sendMessage`/`call` คืนผลสำเร็จ + ขยาย timeout | 1 |
| `backend/tests/Feature/TelegramAlertBotServiceTest.php` | แก้: เพิ่มเทสต์ค่าคืนและ timeout | 1 |
| `backend/app/Services/Delivery/AccountDeliveryService.php` | แก้: `sendCard` คืน bool + เปลี่ยนไป dispatch job | 2, 3 |
| `backend/tests/Feature/AccountDeliverySendCardTest.php` | สร้าง: เทสต์ค่าคืนของ `sendCard` | 2 |
| `backend/app/Jobs/SendDeliveryCard.php` | สร้าง: job ส่งการ์ดที่ retry ได้ | 3 |
| `backend/tests/Feature/SendDeliveryCardTest.php` | สร้าง: เทสต์ job | 3 |
| `backend/app/Console/Commands/RemindPendingDeliveries.php` | แก้: เตือนรอบแรกเสมอแม้อยู่ในช่วงเงียบ | 4 |
| `backend/tests/Feature/RemindPendingDeliveriesTest.php` | แก้: เพิ่มเทสต์ช่วงเงียบ | 4 |
| Railway production env vars | ops: `LOG_STACK`, `LOG_LEVEL` | 5 |

---

### Task 1: ให้ชั้นส่ง Telegram รายงานผล และรอให้นานเท่าตัวที่รอด

แก้ชั้นที่ 2 และครึ่งหนึ่งของชั้นที่ 4 ของ root cause — ตอนนี้ `sendMessage` คืน `void` ผู้เรียกจึงไม่มีทางรู้ว่าส่งสำเร็จไหม

**Files:**
- Modify: `backend/app/Services/Payment/TelegramAlertBotService.php`
- Test: `backend/tests/Feature/TelegramAlertBotServiceTest.php`

**Interfaces:**
- Consumes: ไม่มี (task แรก)
- Produces:
  - `TelegramAlertBotService::sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): bool` — คืน `true` เมื่อ Telegram ตอบ `ok: true`, คืน `false` ทุกกรณีอื่น (HTTP ไม่สำเร็จ / `ok: false` / timeout / เชื่อมต่อไม่ได้) และไม่โยน exception ออกมา
  - `private const TelegramAlertBotService::TIMEOUT_SECONDS = 30`

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดงก่อน**

เพิ่ม 4 เทสต์นี้ต่อท้ายคลาสใน `backend/tests/Feature/TelegramAlertBotServiceTest.php` และเพิ่ม import ที่หัวไฟล์:

```php
use Illuminate\Http\Client\ConnectionException;
```

```php
    public function test_send_message_returns_true_when_telegram_accepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertTrue($sent);
    }

    public function test_send_message_returns_false_when_telegram_rejects_the_payload(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertFalse($sent);
    }

    public function test_send_message_returns_false_when_the_connection_times_out(): void
    {
        // เคสจริง 1 ส.ค. 2026: api.telegram.org ค้าง การ์ดปุ่มส่งของหายเงียบ
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertFalse($sent);
    }

    public function test_alert_timeout_is_not_shorter_than_the_plugin_notifier(): void
    {
        // กันตั้ง timeout สั้นกว่า FlowPluginService (ใช้ค่าปริยาย Laravel = 30 วิ) อีก
        // ต้นเหตุที่การ์ดตายแต่ข้อความ "ออเดอร์ใหม่!" รอดในเหตุการณ์เดียวกัน
        $timeout = (new \ReflectionClassConstant(TelegramAlertBotService::class, 'TIMEOUT_SECONDS'))->getValue();

        $this->assertGreaterThanOrEqual(30, $timeout);
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `cd backend && ./vendor/bin/phpunit --filter TelegramAlertBotServiceTest`

Expected: FAIL — 3 เทสต์แรกฟ้องว่า `assertTrue()`/`assertFalse()` ได้ `null` (เพราะ `sendMessage` คืน `void`) และเทสต์ที่ 4 ฟ้อง `ReflectionException: Constant ... does not exist`

- [ ] **Step 3: แก้ `TelegramAlertBotService`**

ในไฟล์ `backend/app/Services/Payment/TelegramAlertBotService.php` เพิ่มค่าคงที่ใต้ `private const BASE`:

```php
    /**
     * เวลารอ Telegram ตอบ ต้องไม่สั้นกว่าตัวส่งข้อความอีกทางคือ FlowPluginService
     * ซึ่งใช้ค่าปริยายของ Laravel (30 วิ)
     *
     * เหตุการณ์ 1 ส.ค. 2026: ค่านี้เคยเป็น 5 วิ พอ api.telegram.org ค้าง ~30 วิ
     * การ์ดปุ่มส่งของตายทั้ง 2 ครั้งใน 10 วินาที ส่วนข้อความ "ออเดอร์ใหม่!" รอด
     * ผลคือเจ้าของเห็นว่ามีออเดอร์ แต่ไม่มีปุ่มให้กดส่ง ออเดอร์ค้างเงียบ
     */
    private const TIMEOUT_SECONDS = 30;
```

เปลี่ยนลายเซ็นของ `sendMessage` ให้คืน `bool`:

```php
    public function sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): bool
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        return $this->call($token, 'sendMessage', $params);
    }
```

เปลี่ยน `call` ให้คืน `bool` และใช้ค่า timeout ใหม่:

```php
    /** @return bool true เมื่อ Telegram รับข้อความแล้วจริง — ผู้เรียกที่แคร์ต้องเช็คค่านี้ */
    private function call(string $token, string $method, array $params): bool
    {
        try {
            $res = Http::timeout(self::TIMEOUT_SECONDS)->retry(2, 500)->post(self::BASE.$token.'/'.$method, $params);

            if (! $res->successful() || $res->json('ok') === false) {
                Log::warning('Telegram alert API non-OK response', [
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Telegram alert API call failed', ['method' => $method, 'error' => $e->getMessage()]);

            return false;
        }
    }
```

`editMessageText` และ `answerCallbackQuery` ยังคงลายเซ็น `void` เหมือนเดิม — ปล่อยให้เรียก `$this->call(...)` แล้วทิ้งค่าคืนไป (PHP อนุญาต) ไม่ต้องแก้

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && ./vendor/bin/phpunit --filter TelegramAlertBotServiceTest`

Expected: PASS ทุกเทสต์ (รวมของเดิม 3 ตัว)

- [ ] **Step 5: รันเทสต์ที่เกี่ยวข้องทั้งหมดกันของเดิมพัง**

Run: `cd backend && ./vendor/bin/phpunit --filter "Delivery|Telegram|Slip"`

Expected: PASS ทั้งหมด — ถ้าแดง แปลว่ามีที่เรียก `sendMessage` แล้วประกาศ return type ขัดกัน ให้แก้ที่นั่น

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint
git add backend/app/Services/Payment/TelegramAlertBotService.php backend/tests/Feature/TelegramAlertBotServiceTest.php
git commit -m "fix(telegram): บอกผู้เรียกว่าส่งสำเร็จไหม + รอ Telegram นานเท่าตัวที่รอด"
```

---

### Task 2: ให้ `sendCard` บอกได้ว่าการ์ดออกจริงไหม

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (เมธอด `sendCard`)
- Test: `backend/tests/Feature/AccountDeliverySendCardTest.php` (สร้างใหม่)

**Interfaces:**
- Consumes: `TelegramAlertBotService::sendMessage(...): bool` จาก Task 1
- Produces: `AccountDeliveryService::sendCard(AccountDelivery $delivery, string $prefix = ''): bool` — `false` เมื่อไม่มี telegram plugin หรือส่งไม่สำเร็จ

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดงก่อน**

สร้าง `backend/tests/Feature/AccountDeliverySendCardTest.php`:

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountDeliverySendCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(bool $withPlugin = true): AccountDelivery
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        if ($withPlugin) {
            FlowPlugin::create([
                'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
                'enabled' => true, 'trigger_condition' => 'always',
                'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
            ]);
        }

        $conv = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1100, 'status' => 'passed',
        ]);

        return AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1100,
        ]);
    }

    public function test_send_card_returns_true_when_telegram_accepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery());

        $this->assertTrue($sent);
    }

    public function test_send_card_returns_false_when_telegram_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery());

        $this->assertFalse($sent);
    }

    public function test_send_card_returns_false_when_flow_has_no_telegram_plugin(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(AccountDeliveryService::class)->sendCard($this->makeDelivery(withPlugin: false));

        $this->assertFalse($sent);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `cd backend && ./vendor/bin/phpunit --filter AccountDeliverySendCardTest`

Expected: FAIL — `assertTrue()`/`assertFalse()` ได้ `null` เพราะ `sendCard` ยังคืน `void`

- [ ] **Step 3: แก้ `sendCard`**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` แทนที่เมธอด `sendCard` ทั้งตัวด้วย:

```php
    /**
     * ส่งการ์ดสรุป + ปุ่มเข้า Telegram (ใช้ตอนสร้างงาน และตอนเตือนซ้ำ)
     *
     * @return bool false = การ์ดไม่ได้ออก ผู้เรียกต้องจัดการต่อ (ยิงซ้ำ / บันทึกไว้ตาม)
     */
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

        return $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $prefix.$this->cardText($delivery),
            $keyboard,
        );
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

Run: `cd backend && ./vendor/bin/phpunit --filter AccountDeliverySendCardTest`

Expected: PASS ทั้ง 3 เทสต์

- [ ] **Step 5: รันเทสต์ delivery เดิมทั้งหมด**

Run: `cd backend && ./vendor/bin/phpunit --filter "Delivery|Remind|Reconcile"`

Expected: PASS ทั้งหมด (ผู้เรียกเดิมทิ้งค่าคืนได้ ไม่กระทบ)

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Feature/AccountDeliverySendCardTest.php
git commit -m "fix(delivery): ให้ sendCard บอกได้ว่าการ์ดออกจริงไหม"
```

---

### Task 3: แยกการส่งการ์ดออกเป็น job ที่ยิงซ้ำได้

หัวใจของแผน — แก้ชั้นที่ 3 ของ root cause การจองสต๊อกต้องทำครั้งเดียว (`tries = 1`) แต่การ์ดต้องส่งจนสำเร็จ สองอย่างนี้ต้องแยก job กัน

**Files:**
- Create: `backend/app/Jobs/SendDeliveryCard.php`
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:157` (บรรทัดที่เรียก `sendCard` ใน `createFromPayment`)
- Test: `backend/tests/Feature/SendDeliveryCardTest.php` (สร้างใหม่)

**Interfaces:**
- Consumes: `AccountDeliveryService::sendCard(AccountDelivery, string): bool` จาก Task 2
- Produces:
  - `SendDeliveryCard::__construct(int $deliveryId, string $prefix = '')`
  - `SendDeliveryCard::dispatchSafely(int $deliveryId, string $prefix = ''): void` — dispatch โดยไม่ปล่อย exception ออกมาทำลาย flow การจอง
  - `SendDeliveryCard::handle(AccountDeliveryService $service): void` — โยน `\RuntimeException` เมื่อส่งไม่สำเร็จ เพื่อให้ queue retry

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดงก่อน**

สร้าง `backend/tests/Feature/SendDeliveryCardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendDeliveryCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(): AccountDelivery
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

        return AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1100,
        ]);
    }

    public function test_job_sends_the_card(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery();

        (new SendDeliveryCard($delivery->id))->handle(app(AccountDeliveryService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', "งาน #{$delivery->id}"));
    }

    public function test_job_throws_so_the_queue_retries_when_telegram_is_unreachable(): void
    {
        // ถ้าไม่โยน queue จะถือว่าสำเร็จแล้วเลิก = การ์ดหายเงียบแบบเคส #99
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $delivery = $this->makeDelivery();

        $this->expectException(\RuntimeException::class);

        (new SendDeliveryCard($delivery->id))->handle(app(AccountDeliveryService::class));
    }

    public function test_job_gives_up_quietly_when_the_delivery_is_gone(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new SendDeliveryCard(999999))->handle(app(AccountDeliveryService::class));

        Http::assertNothingSent();
    }

    public function test_job_retries_at_least_three_times(): void
    {
        // เคสจริง: Telegram ค้างประมาณ 30 วิ retry 2 ครั้งใน 10 วิ ไม่พอ
        $job = new SendDeliveryCard(1);

        $this->assertGreaterThanOrEqual(3, $job->tries);
    }
}
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดง**

Run: `cd backend && ./vendor/bin/phpunit --filter SendDeliveryCardTest`

Expected: FAIL — `Error: Class "App\Jobs\SendDeliveryCard" not found`

- [ ] **Step 3: สร้าง job**

สร้าง `backend/app/Jobs/SendDeliveryCard.php`:

```php
<?php

namespace App\Jobs;

use App\Models\AccountDelivery;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ส่งการ์ดปุ่มส่งของเข้า Telegram — แยกจาก ReserveAccountStock โดยตั้งใจ
 *
 * การจองสต๊อกต้องทำครั้งเดียว (tries=1) แต่การ์ดต้องส่งจนกว่าจะสำเร็จ
 * เหตุการณ์ 1 ส.ค. 2026: สองอย่างนี้เคยอยู่ใน job เดียวกัน พอ api.telegram.org
 * ค้าง ~30 วิ การ์ดหายแล้วยิงซ้ำไม่ได้เลยเพราะ retry จะจองสต๊อกซ้ำ
 * ออเดอร์เลยค้างเงียบจนเจ้าของต้องเบิกบัญชีส่งเอง
 *
 * backoff กระจายยาวกว่าหน้าต่างที่ Telegram เคยค้าง (30 วิ) โดยตั้งใจ
 */
#[Backoff([10, 30, 60, 300])]
class SendDeliveryCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $prefix = '',
    ) {}

    public function handle(AccountDeliveryService $service): void
    {
        $delivery = AccountDelivery::with('items')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if (! $service->sendCard($delivery, $this->prefix)) {
            // โยนเพื่อให้ queue retry ตาม backoff — ทางเดียวที่การ์ดจะได้ไปต่อ
            throw new \RuntimeException("delivery card send failed (delivery {$this->deliveryId})");
        }
    }

    /**
     * ยิงครบทุกรอบแล้วการ์ดยังไม่ออก — ใช้ Log::error เพราะ production
     * กรอง log ที่ระดับ error ข้อความระดับ warning จะไม่ถูกบันทึกที่ไหนเลย
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Delivery: card never reached Telegram', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * dispatch แบบไม่ให้พังลาม flow จองสต๊อก — บน queue แบบ sync (เช่นในเทสต์)
     * exception จาก handle() จะเด้งกลับมาหาผู้ dispatch ทันที
     */
    public static function dispatchSafely(int $deliveryId, string $prefix = ''): void
    {
        try {
            self::dispatch($deliveryId, $prefix);
        } catch (\Throwable $e) {
            Log::error('Delivery: card dispatch failed', [
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: รันเทสต์ job ให้ผ่าน**

Run: `cd backend && ./vendor/bin/phpunit --filter SendDeliveryCardTest`

Expected: PASS ทั้ง 4 เทสต์

- [ ] **Step 5: เปลี่ยน `createFromPayment` ให้ใช้ job**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` เพิ่ม import ที่หัวไฟล์:

```php
use App\Jobs\SendDeliveryCard;
```

แล้วแทนบรรทัดที่เรียก `sendCard` ตรงท้าย `createFromPayment` (บรรทัด 157 ของเดิม):

```php
        $this->sendCard($delivery->fresh('items'), $this->duplicateWarning($duplicateOf));
```

ด้วย:

```php
        // ส่งผ่าน job เพื่อให้ยิงซ้ำได้เอง — ห้ามเรียก sendCard ตรงๆ ที่นี่
        // job นี้ tries=1 (กันจองซ้ำ) การ์ดที่ยิงพลาดจะไม่มีทางได้ไปต่อ
        SendDeliveryCard::dispatchSafely($delivery->id, $this->duplicateWarning($duplicateOf));
```

- [ ] **Step 6: รันเทสต์ delivery ทั้งหมด**

Run: `cd backend && ./vendor/bin/phpunit --filter "Delivery|Remind|Reconcile|Slip"`

Expected: PASS ทั้งหมด — `QUEUE_CONNECTION=sync` ทำให้ job รัน inline เทสต์เดิมที่ assert เนื้อการ์ดผ่าน `Http::assertSent` จึงยังผ่าน

หาก `AccountDeliveryCreateTest` แดงเพราะ assert จำนวน HTTP request ให้ดูว่าเทสต์นั้นคาดหวังการ์ด 1 ใบเท่าเดิมหรือไม่ — จำนวนไม่ควรเปลี่ยน ถ้าเปลี่ยนแปลว่า dispatch ซ้ำ ให้ตรวจว่ามีการเรียก `sendCard` ค้างอยู่ที่เดิมด้วย

- [ ] **Step 7: รันเทสต์ทั้ง suite**

Run: `cd backend && ./vendor/bin/phpunit`

Expected: PASS ทั้งหมด

- [ ] **Step 8: Commit**

```bash
cd backend && ./vendor/bin/pint
git add backend/app/Jobs/SendDeliveryCard.php backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Feature/SendDeliveryCardTest.php
git commit -m "fix(delivery): แยกการ์ดปุ่มส่งของเป็น job ที่ยิงซ้ำได้เอง"
```

---

### Task 4: อย่าให้ช่วงเงียบกลืนการเตือนรอบแรก

แก้เคส #49 ที่ค้าง 9 ชั่วโมง — งานที่ยังไม่เคยเตือนเลยแปลว่าการ์ดอาจไม่เคยไปถึง ต้องเตือนอย่างน้อย 1 ครั้งเสมอ ส่วนการเตือน**ซ้ำ** ยังเคารพช่วงเงียบเหมือนเดิม

**Files:**
- Modify: `backend/app/Console/Commands/RemindPendingDeliveries.php`
- Test: `backend/tests/Feature/RemindPendingDeliveriesTest.php`

**Interfaces:**
- Consumes: `UserSetting::quietNow(?UserSetting $settings): bool` (มีอยู่แล้ว), `AccountDeliveryService::sendCard(...): bool` จาก Task 2
- Produces: ไม่มีของใหม่ให้ task อื่นใช้

- [ ] **Step 1: แก้เทสต์เดิมที่ล็อกพฤติกรรมเก่าไว้**

`backend/tests/Feature/RemindPendingDeliveriesTest.php:82` มีเทสต์ `test_skips_reminder_during_quiet_hours` ที่สร้างงานซึ่ง **ยังไม่เคยเตือน** (`last_reminded_at` เป็น null) แล้วยืนยันว่าต้องเงียบ — นี่คือพฤติกรรมที่ task นี้ตั้งใจเปลี่ยน ต้องแก้เทสต์นี้ให้เป็นเคส "เตือนซ้ำ" แทน ไม่ใช่ปล่อยให้แดงแล้วไปแก้โค้ดให้เข้าทางเทสต์เก่า

แทนที่เมธอดเดิมทั้งตัว:

```php
    public function test_skips_reminder_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->last_reminded_at); // รอบเช้าต้องเตือนต่อได้
    }
```

ด้วยเวอร์ชันที่เจาะจงว่าเป็นการเตือน**ซ้ำ**:

```php
    public function test_skips_repeat_reminder_during_quiet_hours(): void
    {
        // ช่วงเงียบยังกันการเตือนซ้ำเหมือนเดิม — เปลี่ยนเฉพาะการเตือนรอบแรก
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $remindedAt = now()->subHours(2);
        $delivery = $this->makeDelivery([
            'created_at' => now()->subHours(3),
            'last_reminded_at' => $remindedAt,
        ]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
        // ไม่ถูกแตะ = รอบเช้าเตือนต่อได้
        $this->assertSame($remindedAt->toDateTimeString(), $delivery->fresh()->last_reminded_at->toDateTimeString());
    }
```

- [ ] **Step 2: เขียนเทสต์ใหม่ที่ต้องแดง**

เพิ่มเทสต์นี้ต่อท้ายคลาสในไฟล์เดียวกัน:

```php
    public function test_first_reminder_fires_even_during_quiet_hours(): void
    {
        // เคส #49 (20 ก.ค. 2026): การ์ดหายตอน 22:49 แล้วช่วงเงียบกลืนการเตือนไป 9 ชั่วโมง
        // ลูกค้าจ่ายเงินแล้วรอข้ามคืนเพราะไม่มีใครรู้ว่าการ์ดไม่เคยไปถึง
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $delivery = $this->makeDelivery(['created_at' => now()->subHours(3)]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }
```

หมายเหตุ: `makeDelivery` ที่มีอยู่รับ `created_at` ผ่าน `forceFill` อยู่แล้ว ส่วน `last_reminded_at` อยู่ใน `$fillable` ของ `AccountDelivery` จึงส่งผ่าน `create()` ได้ตรงๆ / เทสต์นี้ไม่ได้สร้างแถว `user_settings` เลย ซึ่งถูกต้องแล้ว เพราะ `UserSetting::quietNow(null)` ถือว่าเปิดช่วงเงียบด้วยค่าปริยาย 23:00–08:00 เวลา 02:00 จึงอยู่ในช่วงเงียบจริง

- [ ] **Step 3: รันเทสต์ให้เห็นว่าแดง**

Run: `cd backend && ./vendor/bin/phpunit --filter RemindPendingDeliveriesTest`

Expected: FAIL เฉพาะ `test_first_reminder_fires_even_during_quiet_hours` — `Http::assertSent` ฟ้องว่าไม่มี request ถูกส่ง ส่วน `test_skips_repeat_reminder_during_quiet_hours` ต้องผ่านอยู่แล้ว (ถ้าแดงด้วย แปลว่า `makeDelivery` ไม่ได้บันทึก `last_reminded_at` ให้ตรวจ `$fillable` ก่อน)

- [ ] **Step 4: แก้เงื่อนไขในคำสั่ง**

ใน `backend/app/Console/Commands/RemindPendingDeliveries.php` แทนบล็อกเช็คช่วงเงียบใน loop:

```php
            if (UserSetting::quietNow($delivery->bot?->user?->settings)) {
                $skipped++;

                continue;
            }
```

ด้วย:

```php
            // เตือนรอบแรกเสมอ แม้อยู่ในช่วงเงียบ — งานที่ยังไม่เคยเตือนแปลว่าการ์ดตอนสร้างงาน
            // อาจไม่เคยไปถึงเลย ปล่อยเงียบ = ลูกค้าที่จ่ายเงินแล้วรอข้ามคืน (เคส #49 ค้าง 9 ชม.)
            // ส่วนการเตือนซ้ำยังเคารพช่วงเงียบเหมือนเดิม
            if ($delivery->last_reminded_at !== null
                && UserSetting::quietNow($delivery->bot?->user?->settings)) {
                $skipped++;

                continue;
            }
```

- [ ] **Step 5: รันเทสต์ให้ผ่าน**

Run: `cd backend && ./vendor/bin/phpunit --filter RemindPendingDeliveriesTest`

Expected: PASS ทั้ง 5 เทสต์ในไฟล์นี้ (`test_reminds_stale_reserved_delivery`, `test_skips_fresh_and_recently_reminded`, `test_skips_repeat_reminder_during_quiet_hours`, `test_reminds_at_night_when_quiet_hours_disabled`, `test_first_reminder_fires_even_during_quiet_hours`)

- [ ] **Step 6: Commit**

```bash
cd backend && ./vendor/bin/pint
git add backend/app/Console/Commands/RemindPendingDeliveries.php backend/tests/Feature/RemindPendingDeliveriesTest.php
git commit -m "fix(delivery): เตือนงานค้างรอบแรกเสมอ ไม่ให้ช่วงเงียบกลืนออเดอร์ข้ามคืน"
```

---

### Task 5 (ops — ไม่มี commit): เปิดให้ log ระดับ warning มองเห็นได้บน production

แก้ชั้นที่ 4 ของ root cause งานนี้ไม่แตะโค้ด แต่**เป็นเหตุผลที่บั๊กนี้ซ่อนตัวได้ 2 รอบ** — ปัจจุบัน `Log::warning` ทั้งระบบไม่ถูกบันทึกที่ไหนเลย

**สถานะปัจจุบันบน Railway (ตรวจแล้วด้วย `railway ssh 'printenv'`):**

| ตัวแปร | ค่าปัจจุบัน | ผล |
|---|---|---|
| `LOG_CHANNEL` | `stack` | ใช้ channel stack |
| `LOG_STACK` | **ไม่ได้ตั้ง** | default `single` → เขียนลง `storage/logs/laravel.log` ที่หายทุก deploy ไม่ไป Railway |
| `LOG_LEVEL` | `error` | `warning` และ `info` ถูกทิ้งทั้งหมด |

- [ ] **Step 1: ยืนยันสถานะเดิมก่อนแก้**

```bash
railway ssh 'printenv LOG_CHANNEL LOG_STACK LOG_LEVEL'
```

Expected: `stack` / บรรทัดว่างหรือไม่มีค่า / `error`

- [ ] **Step 2: ตั้งค่าใหม่**

```bash
railway variables --service backend --set "LOG_STACK=stderr" --set "LOG_LEVEL=warning"
```

หมายเหตุ: การเปลี่ยน environment variable บน Railway จะ **trigger redeploy** ให้เลือกเวลาที่ไม่มีออเดอร์เข้า

- [ ] **Step 3: รอ deploy เสร็จแล้วยืนยันค่าใหม่**

```bash
railway status
railway ssh 'printenv LOG_CHANNEL LOG_STACK LOG_LEVEL'
```

Expected: `stack` / `stderr` / `warning`

- [ ] **Step 4: ยืนยันว่า log ระดับ warning ไหลเข้า Railway จริง**

```bash
railway ssh 'php artisan tinker --execute="Log::warning(\"log-visibility-smoke-test\");"'
```

จากนั้นดู deploy log ของ service `backend` บน Railway ในช่วง 2 นาทีที่ผ่านมา

Expected: เห็นบรรทัดที่มีข้อความ `log-visibility-smoke-test` — ถ้าไม่เห็น แปลว่า `LOG_STACK` ยังไม่มีผล ให้ตรวจว่า config cache ถูกล้างตอน deploy หรือไม่ (`php artisan config:clear`)

- [ ] **Step 5: เฝ้าปริมาณ log 24 ชั่วโมง**

`KeywordSearchService: Search failed` ออกบ่อย (ระดับ error อยู่แล้ว) เมื่อเปิด warning อาจมีข้อความเพิ่มขึ้นมาก ถ้าปริมาณล้นจนหาอะไรไม่เจอ ให้พิจารณาลดกลับเป็น `error` แล้วเปลี่ยนจุดที่สำคัญให้เป็น `Log::error` แทน (Task 3 ทำไปแล้วสำหรับการ์ดที่ส่งไม่สำเร็จ)

---

## ไม่รวมในแผนนี้ (ตั้งใจ)

**ลำดับข้อความ: การ์ดมาก่อน "ออเดอร์ใหม่!"** — `config('delivery.card_delay_seconds') = 15` เป็นการ**เดาเวลา** ว่า webhook จะเสร็จภายใน 15 วินาที เคส #99 webhook ใช้ 39 วินาที ทำให้การ์ด (18:19:37) มาก่อนข้อความออเดอร์ใหม่ (18:19:48) ผิดจากที่ออกแบบไว้

ไม่รวมเพราะ:
1. เป็นปัญหาความสวยงามของลำดับข้อความ ไม่ทำให้ออเดอร์หาย — คนละเรื่องกับบั๊กที่ทำให้ลูกค้าไม่ได้ของ
2. การแก้ให้ถูกต้องต้องผูกลำดับจริง (รู้ว่า plugin ส่งข้อความไปแล้วหรือยัง) ซึ่งแตะ 4 จุดที่เรียก `dispatchSafely` (`LineWebhookResponseService`, `ManualPaymentConfirmService`, `SlipRetryService`) และต้องตัดสินใจเรื่อง design ก่อน — ควรเป็นแผนแยก
3. หลังทำ Task 3 แล้วผลกระทบยิ่งน้อยลง เพราะการ์ดที่ต้อง retry จะไปตกท้ายสุดของแชทอยู่ดี

**เพิ่มคอลัมน์ `card_sent_at`** — เคยพิจารณาเพื่อให้ `delivery:remind` แยกออกว่า "การ์ดไม่เคยออก" กับ "ออกแล้วแต่ยังไม่กด" แต่ Task 3 (retry ถึง ~6.5 นาที) กับ Task 4 (เตือนรอบแรกเสมอ) ครอบคลุมอาการแล้ว — ไม่เพิ่ม migration โดยไม่จำเป็น

---

## หลังลงมือเสร็จ — ต้องเฝ้าอะไร

1. **ออเดอร์จริงเคสแรกหลัง deploy** — ยืนยันว่าการ์ดยังออกปกติ และมาหลังข้อความ "ออเดอร์ใหม่!" เหมือนเดิม
2. **`failed_jobs`** — ถ้ามีแถว `SendDeliveryCard` โผล่ แปลว่ายิงครบ 5 รอบแล้วยังไม่ออก ต้องดูด้วยตาทันที
3. **ค้นหา log `Delivery: card never reached Telegram`** ใน Railway — ถ้าเจอ แปลว่ากลไก retry ทำงานแต่ Telegram ล่มยาวกว่า ~6.5 นาที
4. **ปริมาณ log หลังเปิด warning** ตาม Task 5 Step 5

---

## งานค้างที่ต้องเคลียร์แยก (ไม่ใช่ส่วนของแผนนี้)

งานส่งของ **#99** ยังสถานะ `reserved` และบัญชี NLMBM id **4691** ยังค้างใน `items_reserved` (order_ref `bfb:99`) ของฐาน `mhha_acc_db` — เจ้าของเบิกบัญชี id 5279 ส่งลูกค้าไปเองแล้ว ต้องกดปุ่ม **"↩️ ยกเลิก คืนเข้า stock"** บนการ์ดเตือนเพื่อคืนบัญชี 4691 เข้าคลัง **ห้ามกดปุ่ม ✅** เพราะจะส่งบัญชีให้ลูกค้าซ้ำอีกใบ
