# Telegram Inline Confirm Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ให้แอดมินกด "ยืนยันรับเงิน" ได้จบในข้อความแจ้งเตือน Telegram โดยไม่ต้องเปิดเว็บ

**Architecture:** แนบ inline button ท้ายข้อความ alert ตรวจสลิปไม่ผ่าน; เปิด webhook "ขารับ" ให้ bot แจ้งเตือน (endpoint ใหม่แยกจาก webhook ของ bot ลูกค้า); เมื่อกดปุ่ม → verify chat_id → เรียก `ManualPaymentConfirmService` ตัวเดิม (reuse) → แก้ข้อความ Telegram เป็นสถานะยืนยันแล้ว

**Tech Stack:** Laravel 12 (PHP), PHPUnit/Pest, Telegram Bot API (raw HTTP ผ่าน `Http` facade), PostgreSQL (JSON column query)

## Global Constraints

- Reuse `ManualPaymentConfirmService::confirm(Bot $bot, Conversation $conversation, ?float $amountOverride, int $confirmedBy)` — **ห้ามเขียน confirm logic ใหม่** และห้ามแก้ไฟล์นี้
- alert bot ระบุด้วย `token` + `chat_id` จาก `flow_plugins` (type=`telegram`, enabled=true, `config.access_token`, `config.chat_id`) — ไม่ใช่ Bot model
- callback_data ต้อง ≤ 64 bytes (ข้อจำกัด Telegram)
- webhook route ต้องอยู่ในกลุ่ม `Route::prefix('webhook')->middleware('throttle.webhook')->withoutMiddleware(['auth:sanctum'])` ใน `routes/api.php`
- `confirmed_by` = `bot->user_id` (Telegram ไม่มี Laravel user)
- ต้องตอบ Telegram HTTP 200 เสมอ (แม้ chat_id ผิด) เพื่อกัน Telegram retry ถล่ม
- FRAUD_REASONS = `['fake','duplicate','amount_mismatch','wrong_account']` (เคส 🚨 กด 2 ชั้น); ที่เหลือเป็นเคส ⚠️ กดชั้นเดียว
- secret token เก็บที่ `config('services.telegram_alert.secret')` (env `TELEGRAM_ALERT_WEBHOOK_SECRET`)

## File Structure

| ไฟล์ | ความรับผิดชอบ |
|------|----------------|
| `app/Services/Payment/TelegramAlertBotService.php` (ใหม่) | wrapper Telegram API ด้วย raw token ของ alert bot: `sendMessage`, `editMessageText`, `answerCallbackQuery`, `setWebhook` |
| `app/Services/Payment/SlipVerificationService.php` (แก้) | `notifyAdmin()` สร้าง inline_keyboard ตามยอด/เคส แล้วส่งผ่าน `TelegramAlertBotService` |
| `app/Http/Controllers/Webhook/TelegramAlertCallbackController.php` (ใหม่) | รับ callback_query, verify secret+chat_id, 2-step fraud, เรียก confirm, edit ข้อความ |
| `routes/api.php` (แก้) | route `POST /webhook/telegram-alert/{token}` |
| `config/services.php` (แก้) | เพิ่ม key `telegram_alert.secret` |
| `app/Console/Commands/SetTelegramAlertWebhook.php` (ใหม่) | artisan `telegram:alert-webhook` ตั้ง setWebhook ให้ทุก plugin telegram ที่ enabled |

## callback_data format

ใช้ `|` คั่น, ≤ 64 bytes:
- Confirm: `pc|{conversationId}|{amount}` — amount เป็นตัวเลข (เช่น `590` หรือ `590.50`) หรือ `x` = ให้ service resolve จากแชท
- Arm (เคส fraud กดครั้งแรก): `pa|{conversationId}|{amount}` — controller ตอบด้วยการแก้ปุ่มเป็น `pc|...` แล้วรอกดซ้ำ

หมายเหตุความปลอดภัย: การฝัง amount ใน callback_data ปลอดภัยเพราะ controller รับเฉพาะ callback
ที่มาจาก `chat_id` ที่ตั้งไว้ (คนนอกยิงปลอมมาถูกปฏิเสธ)

---

### Task 1: TelegramAlertBotService — wrapper Telegram API

**Files:**
- Create: `backend/app/Services/Payment/TelegramAlertBotService.php`
- Test: `backend/tests/Unit/Payment/TelegramAlertBotServiceTest.php`

**Interfaces:**
- Produces:
  - `sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): void`
  - `editMessageText(string $token, string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void`
  - `answerCallbackQuery(string $token, string $callbackQueryId, string $text): void`
  - `setWebhook(string $token, string $url, string $secret): bool`
  - ทุก method best-effort: จับ `\Throwable` แล้ว `Log::warning` (ยกเว้น `setWebhook` คืน bool ตามผล)

- [ ] **Step 1: เขียน test ที่ fail**

```php
<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertBotServiceTest extends TestCase
{
    public function test_send_message_posts_text_and_inline_keyboard(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->sendMessage(
            'TOK', '123', "hi",
            [[['text' => 'A', 'callback_data' => 'pc|1|590']]],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botTOK/sendMessage')
                && $request['chat_id'] === '123'
                && $request['text'] === 'hi'
                && str_contains($request['reply_markup'], 'callback_data');
        });
    }

    public function test_answer_callback_query_posts_id_and_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->answerCallbackQuery('TOK', 'cb99', 'ยืนยันแล้ว');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/botTOK/answerCallbackQuery')
            && $r['callback_query_id'] === 'cb99' && $r['text'] === 'ยืนยันแล้ว');
    }

    public function test_send_message_swallows_errors(): void
    {
        Http::fake(fn () => throw new \RuntimeException('down'));

        // ต้องไม่ throw
        app(TelegramAlertBotService::class)->sendMessage('TOK', '123', 'hi');
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

Run: `cd backend && php artisan test tests/Unit/Payment/TelegramAlertBotServiceTest.php`
Expected: FAIL — class `TelegramAlertBotService` not found

- [ ] **Step 3: เขียน implementation**

```php
<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper Telegram Bot API สำหรับ "bot แจ้งเตือน" (ใช้ raw token จาก flow telegram plugin,
 * ไม่ใช่ Bot model). ทุก method นอกจาก setWebhook เป็น best-effort — ล้มแล้วแค่ log.
 */
class TelegramAlertBotService
{
    private const BASE = 'https://api.telegram.org/bot';

    public function sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        $this->call($token, 'sendMessage', $params);
    }

    public function editMessageText(string $token, string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard ?? []]);
        $this->call($token, 'editMessageText', $params);
    }

    public function answerCallbackQuery(string $token, string $callbackQueryId, string $text): void
    {
        $this->call($token, 'answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text]);
    }

    public function setWebhook(string $token, string $url, string $secret): bool
    {
        try {
            $res = Http::timeout(10)->post(self::BASE.$token.'/setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['callback_query'],
            ]);

            return $res->successful() && ($res->json('ok') === true);
        } catch (\Throwable $e) {
            Log::warning('Telegram alert setWebhook failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function call(string $token, string $method, array $params): void
    {
        try {
            Http::timeout(5)->retry(2, 500)->post(self::BASE.$token.'/'.$method, $params);
        } catch (\Throwable $e) {
            Log::warning('Telegram alert API call failed', ['method' => $method, 'error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test tests/Unit/Payment/TelegramAlertBotServiceTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Payment/TelegramAlertBotService.php backend/tests/Unit/Payment/TelegramAlertBotServiceTest.php
git commit -m "feat(slip): TelegramAlertBotService — Telegram API wrapper for alert bot"
```

---

### Task 2: notifyAdmin แนบ inline button

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` (method `notifyAdmin`, ~บรรทัด 221-274; inject `TelegramAlertBotService` ใน constructor ~บรรทัด 31)
- Test: `backend/tests/Feature/SlipVerificationAlertTest.php` (เพิ่ม test method)

**Interfaces:**
- Consumes: `TelegramAlertBotService::sendMessage(token, chatId, text, inlineKeyboard)` (Task 1)
- Produces: ปุ่ม callback_data รูปแบบ `pc|{convId}|{amt}` (non-fraud) / `pa|{convId}|{amt}` (fraud) — Task 3 อ่านต่อ

**Logic การสร้างปุ่ม** (ทำใน private helper `buildConfirmKeyboard`):
- `$action = in_array($result->failReason, self::FRAUD_REASONS, true) ? 'pa' : 'pc'`
- ถ้าไม่มี `$conversation` → ไม่มีปุ่ม (คืน `null`) เพราะ resolve conversation ไม่ได้
- `$orderAmt = $result->expectedAmount; $slipAmt = $result->amount;`
- ถ้า `$orderAmt !== null && $slipAmt !== null && $orderAmt != $slipAmt` → 2 ปุ่ม:
  `"✅ ยอดออเดอร์ ".self::formatBaht($orderAmt)` → `{action}|{convId}|{orderAmt}` และ
  `"✅ ยอดในสลิป ".self::formatBaht($slipAmt)` → `{action}|{convId}|{slipAmt}`
- elif `$orderAmt !== null` → 1 ปุ่ม `"✅ ยืนยันรับเงิน ".self::formatBaht($orderAmt)." บาท"` → `{action}|{convId}|{orderAmt}`
- elif `$slipAmt !== null` → 1 ปุ่ม `"✅ ยืนยันรับเงิน ".self::formatBaht($slipAmt)." บาท"` → `{action}|{convId}|{slipAmt}`
- else → 1 ปุ่ม `"✅ ยืนยัน (ใช้ยอดจากแชท)"` → `{action}|{convId}|x`
- แต่ละปุ่มเป็น row เดี่ยว: `[[btn1],[btn2]]`

- [ ] **Step 1: เขียน test ที่ fail**

```php
public function test_alert_attaches_confirm_button_for_nonfraud(): void
{
    // เตรียม bot + flow + telegram plugin (enabled) + conversation ตาม pattern ที่ไฟล์นี้ใช้อยู่
    // (คัดลอกการ setup จาก test เดิมในไฟล์นี้)
    [$bot, $conversation, $plugin] = $this->makeBotWithTelegramPlugin();

    $captured = null;
    $this->mock(\App\Services\Payment\TelegramAlertBotService::class, function ($m) use (&$captured) {
        $m->shouldReceive('sendMessage')->once()
          ->andReturnUsing(function ($token, $chatId, $text, $keyboard) use (&$captured) {
              $captured = $keyboard;
          });
    });

    $result = new \App\Services\Payment\SlipVerificationResult(
        isSlip: true, passed: false, failReason: 'unreadable',
        amount: null, expectedAmount: 590.0,
    );

    app(\App\Services\Payment\SlipVerificationService::class)
        ->notifyAdmin($bot, $conversation, $result);

    $this->assertNotNull($captured);
    $this->assertSame('pc|'.$conversation->id.'|590', $captured[0][0]['callback_data']);
    $this->assertStringContainsString('ยืนยันรับเงิน', $captured[0][0]['text']);
}

public function test_alert_shows_two_buttons_when_amounts_differ_and_fraud_prefix(): void
{
    [$bot, $conversation, $plugin] = $this->makeBotWithTelegramPlugin();

    $captured = null;
    $this->mock(\App\Services\Payment\TelegramAlertBotService::class, function ($m) use (&$captured) {
        $m->shouldReceive('sendMessage')->once()
          ->andReturnUsing(fn ($t, $c, $tx, $kb) => $captured = $kb);
    });

    $result = new \App\Services\Payment\SlipVerificationResult(
        isSlip: true, passed: false, failReason: 'amount_mismatch',
        amount: 600.0, expectedAmount: 590.0,
    );

    app(\App\Services\Payment\SlipVerificationService::class)
        ->notifyAdmin($bot, $conversation, $result);

    $this->assertCount(2, $captured);
    $this->assertSame('pa|'.$conversation->id.'|590', $captured[0][0]['callback_data']);
    $this->assertSame('pa|'.$conversation->id.'|600', $captured[1][0]['callback_data']);
}
```

> หมายเหตุ: ถ้าไฟล์ test เดิมยังไม่มี helper `makeBotWithTelegramPlugin()` ให้สร้างขึ้นจากโค้ด setup
> ที่ test เดิมในไฟล์นี้ใช้อยู่ (bot + flow + FlowPlugin type=telegram enabled พร้อม config
> `['access_token' => 'TOK', 'chat_id' => '999']` + conversation ผูก bot) แล้วคืน `[$bot, $conversation, $plugin]`

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

Run: `cd backend && php artisan test tests/Feature/SlipVerificationAlertTest.php`
Expected: FAIL — sendMessage ถูกเรียกโดยไม่มี argument keyboard / signature ไม่ตรง

- [ ] **Step 3: แก้ constructor ให้ inject service**

ใน `SlipVerificationService.php` แก้ constructor (เดิม ~บรรทัด 31):

```php
    public function __construct(
        private readonly PaymentMessageDetector $detector,
        private readonly TelegramAlertBotService $alertBot,
    ) {}
```

เพิ่ม `use App\Services\Payment\TelegramAlertBotService;` ถ้ายังไม่มี (namespace เดียวกันจึงไม่ต้อง use — อยู่ `App\Services\Payment`)

- [ ] **Step 4: แทนที่การส่งใน notifyAdmin ด้วย service + keyboard**

แทนบล็อกส่ง (เดิม ~บรรทัด 266-273) ด้วย:

```php
        $keyboard = $this->buildConfirmKeyboard($conversation, $result);
        $this->alertBot->sendMessage($token, $chatId, implode("\n", $lines), $keyboard);
```

เพิ่ม private method (วางใกล้ `formatBaht`):

```php
    /**
     * สร้าง inline_keyboard ปุ่มยืนยันตามยอดที่รู้และประเภทเคส (fraud → prefix pa).
     * คืน null เมื่อไม่มี conversation (resolve ตอน callback ไม่ได้).
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    private function buildConfirmKeyboard(?Conversation $conversation, SlipVerificationResult $result): ?array
    {
        if ($conversation === null) {
            return null;
        }

        $action = in_array($result->failReason, self::FRAUD_REASONS, true) ? 'pa' : 'pc';
        $id = $conversation->id;
        $orderAmt = $result->expectedAmount;
        $slipAmt = $result->amount;

        $btn = fn (string $text, string $amt) => [['text' => $text, 'callback_data' => "{$action}|{$id}|{$amt}"]];

        if ($orderAmt !== null && $slipAmt !== null && $orderAmt != $slipAmt) {
            return [
                $btn('✅ ยอดออเดอร์ '.self::formatBaht($orderAmt), (string) $orderAmt),
                $btn('✅ ยอดในสลิป '.self::formatBaht($slipAmt), (string) $slipAmt),
            ];
        }
        if ($orderAmt !== null) {
            return [$btn('✅ ยืนยันรับเงิน '.self::formatBaht($orderAmt).' บาท', (string) $orderAmt)];
        }
        if ($slipAmt !== null) {
            return [$btn('✅ ยืนยันรับเงิน '.self::formatBaht($slipAmt).' บาท', (string) $slipAmt)];
        }

        return [$btn('✅ ยืนยัน (ใช้ยอดจากแชท)', 'x')];
    }
```

> หมายเหตุ callback_data: `(string) 590.0` ใน PHP ได้ `"590"` และ `(string) 590.5` ได้ `"590.5"` — ตรงกับที่ test คาด

- [ ] **Step 5: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test tests/Feature/SlipVerificationAlertTest.php`
Expected: PASS (รวม test เดิมในไฟล์ที่ยังต้องผ่านด้วย)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationAlertTest.php
git commit -m "feat(slip): attach confirm buttons to Telegram admin alert"
```

---

### Task 3: Callback endpoint — รับการกดปุ่ม + ยืนยัน

**Files:**
- Create: `backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php`
- Modify: `backend/routes/api.php` (เพิ่ม route ในกลุ่ม `webhook`; เพิ่ม `use` controller)
- Modify: `backend/config/services.php` (เพิ่ม `telegram_alert.secret`)
- Test: `backend/tests/Feature/TelegramAlertCallbackTest.php`

**Interfaces:**
- Consumes: `TelegramAlertBotService` (Task 1), `ManualPaymentConfirmService::confirm()` (มีอยู่), callback_data จาก Task 2
- Produces: HTTP 200 เสมอ

**พฤติกรรม `handle(Request, string $token)`:**
1. verify header `X-Telegram-Bot-Api-Secret-Token` == `config('services.telegram_alert.secret')` → ไม่ตรง: 401
2. หา plugin: `FlowPlugin::where('type','telegram')->where('enabled',true)->where('config->access_token',$token)->first()` → ไม่เจอ: 404
3. `$cb = $request->input('callback_query')` → null: `return response()->json(['ok'=>true])`
4. เทียบ chat: `(string)($cb['message']['chat']['id'] ?? '') !== (string)($plugin->config['chat_id'] ?? '')` → log warning + `return ok` (ไม่ทำงาน)
5. parse `$cb['data']`: `[$act,$convId,$amt] = explode('|', $data)`; ถ้า format เพี้ยน → ok
6. หา conversation: `Conversation::find($convId)` → ไม่เจอ: answerCallbackQuery "ไม่พบแชท" + ok
7. **เคส arm (`pa`):** edit ปุ่มเป็นปุ่มเดียว `pc|{convId}|{amt}` label `"❗ กดอีกครั้งเพื่อยืนยันจริง"` + answerCallbackQuery "กดอีกครั้งเพื่อยืนยัน" → ok (ยังไม่ confirm)
8. **เคส confirm (`pc`):**
   - `$amount = $amt === 'x' ? null : (float) $amt`
   - `$bot = $conversation->bot`
   - try `confirm($bot,$conversation,$amount,$bot->user_id)`:
     - สำเร็จ: editMessageText `"✅ ยืนยันแล้ว ".self::formatBaht(...)."\nโดย {fromName}"` (ลบปุ่ม) + answerCallbackQuery "ยืนยันรับเงินแล้ว"
     - `RecentManualConfirmException`: editMessageText "✅ ยืนยันไปแล้ว" + answerCallbackQuery "ยืนยันไปแล้ว"
     - `NoPendingPaymentException`: answerCallbackQuery "หายอดออเดอร์ไม่พบ กรุณายืนยันในเว็บ" (ไม่ลบปุ่ม)
     - `\Throwable` อื่น: answerCallbackQuery "เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ" + log
   - return ok
9. `$fromName = $cb['from']['first_name'] ?? 'admin'`; message_id = `$cb['message']['message_id']`

- [ ] **Step 1: เพิ่ม config key**

ใน `backend/config/services.php` เพิ่ม (ใกล้ config บริการอื่น):

```php
    'telegram_alert' => [
        'secret' => env('TELEGRAM_ALERT_WEBHOOK_SECRET'),
    ],
```

- [ ] **Step 2: เขียน test ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Exceptions\RecentManualConfirmException;
use App\Models\Conversation;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAlertCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlugin(): array
    {
        // สร้าง bot(user_id) + flow + FlowPlugin(type=telegram,enabled,config access_token=TOK,chat_id=999)
        //  + conversation ผูก bot channel_type=line. คืน [$bot,$conversation]
        // (ใช้ factory/สร้างตรงตาม pattern ในโปรเจกต์)
    }

    private function post(string $token, array $callback): \Illuminate\Testing\TestResponse
    {
        config(['services.telegram_alert.secret' => 'SEC']);

        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'SEC'])
            ->postJson("/api/webhook/telegram-alert/{$token}", ['callback_query' => $callback]);
    }

    public function test_rejects_wrong_secret(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        config(['services.telegram_alert.secret' => 'SEC']);

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'WRONG'])
            ->postJson('/api/webhook/telegram-alert/TOK', ['callback_query' => []])
            ->assertStatus(401);
    }

    public function test_wrong_chat_id_does_not_confirm(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class);

        $this->post('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'X'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 111]], // ผิด (คาด 999)
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_confirm_press_calls_service_and_edits_message(): void
    {
        [$bot, $conv] = $this->seedPlugin();

        $this->mock(ManualPaymentConfirmService::class, function ($m) use ($bot, $conv) {
            $m->shouldReceive('confirm')->once()
              ->with(\Mockery::any(), \Mockery::any(), 590.0, $bot->user_id)
              ->andReturn(['message' => new \App\Models\Message(), 'order_created' => true]);
        });
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once();
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->post('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_fraud_arm_press_only_edits_keyboard(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) => $m->shouldNotReceive('confirm'));
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once(); // แก้ปุ่มเป็น "กดอีกครั้ง"
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->post('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pa|'.$conv->id.'|590',
        ])->assertOk();
    }

    public function test_recent_confirm_shows_already_confirmed(): void
    {
        [$bot, $conv] = $this->seedPlugin();
        $this->mock(ManualPaymentConfirmService::class, fn ($m) =>
            $m->shouldReceive('confirm')->andThrow(new RecentManualConfirmException));
        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('editMessageText')->once();
            $m->shouldReceive('answerCallbackQuery')->once();
        });

        $this->post('TOK', [
            'id' => 'cb1', 'from' => ['first_name' => 'Admin'],
            'message' => ['message_id' => 5, 'chat' => ['id' => 999]],
            'data' => 'pc|'.$conv->id.'|590',
        ])->assertOk();
    }
}
```

- [ ] **Step 3: รัน test ให้เห็นว่า fail**

Run: `cd backend && php artisan test tests/Feature/TelegramAlertCallbackTest.php`
Expected: FAIL — route/controller ยังไม่มี (404)

- [ ] **Step 4: เขียน controller**

```php
<?php

namespace App\Http\Controllers\Webhook;

use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramAlertCallbackController extends Controller
{
    public function __construct(
        private readonly ManualPaymentConfirmService $confirmService,
        private readonly TelegramAlertBotService $alertBot,
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        $secret = config('services.telegram_alert.secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => false], 401);
        }

        $plugin = FlowPlugin::where('type', 'telegram')
            ->where('enabled', true)
            ->where('config->access_token', $token)
            ->first();
        if (! $plugin) {
            return response()->json(['ok' => false], 404);
        }

        $cb = $request->input('callback_query');
        if (! is_array($cb)) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        if ($chatId !== (string) ($plugin->config['chat_id'] ?? '')) {
            Log::warning('Telegram alert callback: chat_id mismatch', ['got' => $chatId]);

            return response()->json(['ok' => true]);
        }

        $parts = explode('|', (string) ($cb['data'] ?? ''));
        if (count($parts) !== 3) {
            return response()->json(['ok' => true]);
        }
        [$act, $convId, $amt] = $parts;

        $conversation = Conversation::find($convId);
        if (! $conversation) {
            $this->alertBot->answerCallbackQuery($token, $cb['id'] ?? '', 'ไม่พบแชท');

            return response()->json(['ok' => true]);
        }

        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $fromName = $cb['from']['first_name'] ?? 'admin';
        $cbId = $cb['id'] ?? '';

        // เคส fraud กดครั้งแรก: แค่แก้ปุ่มให้ยืนยันชั้นสอง ยังไม่ทำงาน
        if ($act === 'pa') {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "⚠️ ยืนยันทั้งที่สลิปน่าสงสัย?\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง",
                [[['text' => '❗ กดอีกครั้งเพื่อยืนยันจริง', 'callback_data' => "pc|{$convId}|{$amt}"]]],
            );
            $this->alertBot->answerCallbackQuery($token, $cbId, 'กดอีกครั้งเพื่อยืนยัน');

            return response()->json(['ok' => true]);
        }

        if ($act !== 'pc') {
            return response()->json(['ok' => true]);
        }

        $amount = $amt === 'x' ? null : (float) $amt;
        $bot = $conversation->bot;

        try {
            $this->confirmService->confirm($bot, $conversation, $amount, $bot->user_id);
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "✅ ยืนยันรับเงินแล้ว โดย {$fromName}");
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันรับเงินแล้ว');
        } catch (RecentManualConfirmException $e) {
            $this->alertBot->editMessageText($token, $chatId, $messageId,
                "✅ ยืนยันไปแล้ว (โดยคนอื่นหรือทางเว็บ)");
            $this->alertBot->answerCallbackQuery($token, $cbId, 'ยืนยันไปแล้ว');
        } catch (NoPendingPaymentException $e) {
            $this->alertBot->answerCallbackQuery($token, $cbId, 'หายอดออเดอร์ไม่พบ กรุณายืนยันในเว็บ');
        } catch (\Throwable $e) {
            Log::error('Telegram alert confirm failed', ['conversation_id' => $convId, 'error' => $e->getMessage()]);
            $this->alertBot->answerCallbackQuery($token, $cbId, 'เกิดข้อผิดพลาด ลองใหม่หรือยืนยันในเว็บ');
        }

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 5: เพิ่ม route**

ใน `backend/routes/api.php` เพิ่มบรรทัด `use` ด้านบน:

```php
use App\Http\Controllers\Webhook\TelegramAlertCallbackController;
```

แล้วในกลุ่ม `Route::prefix('webhook')...->group(...)` เพิ่มถัดจาก route `webhook.telegram`:

```php
    // Telegram alert callback (ปุ่มยืนยันรับเงิน) - POST /api/webhook/telegram-alert/{token}
    Route::post('/telegram-alert/{token}', [TelegramAlertCallbackController::class, 'handle'])
        ->name('webhook.telegram-alert');
```

- [ ] **Step 6: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test tests/Feature/TelegramAlertCallbackTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php backend/routes/api.php backend/config/services.php backend/tests/Feature/TelegramAlertCallbackTest.php
git commit -m "feat(slip): Telegram callback endpoint to confirm payment from alert"
```

---

### Task 4: Artisan command ตั้ง webhook ให้ alert bot

**Files:**
- Create: `backend/app/Console/Commands/SetTelegramAlertWebhook.php`
- Test: `backend/tests/Feature/SetTelegramAlertWebhookTest.php`

**Interfaces:**
- Consumes: `TelegramAlertBotService::setWebhook()` (Task 1), `config('app.url')`, `config('services.telegram_alert.secret')`
- Produces: signature `telegram:alert-webhook`

**พฤติกรรม:** วนทุก `FlowPlugin` type=telegram enabled ที่มี `config.access_token` → เรียก
`setWebhook(token, config('app.url')."/api/webhook/telegram-alert/{token}", secret)` → รายงานผลต่อ plugin

- [ ] **Step 1: เขียน test ที่ fail**

```php
<?php

namespace Tests\Feature;

use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetTelegramAlertWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_webhook_for_each_enabled_telegram_plugin(): void
    {
        config(['app.url' => 'https://ex.com', 'services.telegram_alert.secret' => 'SEC']);
        // seed: flow + FlowPlugin type=telegram enabled config access_token=TOK
        $this->seedTelegramPlugin('TOK');

        $this->mock(TelegramAlertBotService::class, function ($m) {
            $m->shouldReceive('setWebhook')->once()
              ->with('TOK', 'https://ex.com/api/webhook/telegram-alert/TOK', 'SEC')
              ->andReturn(true);
        });

        $this->artisan('telegram:alert-webhook')->assertExitCode(0);
    }
}
```

> `seedTelegramPlugin('TOK')`: สร้าง flow + FlowPlugin(type=telegram, enabled=true, config=['access_token'=>'TOK','chat_id'=>'999'])

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

Run: `cd backend && php artisan test tests/Feature/SetTelegramAlertWebhookTest.php`
Expected: FAIL — command `telegram:alert-webhook` not found

- [ ] **Step 3: เขียน command**

```php
<?php

namespace App\Console\Commands;

use App\Models\FlowPlugin;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Console\Command;

class SetTelegramAlertWebhook extends Command
{
    protected $signature = 'telegram:alert-webhook';

    protected $description = 'ตั้ง Telegram webhook ให้ bot แจ้งเตือนสลิป (รับปุ่มยืนยันรับเงิน)';

    public function handle(TelegramAlertBotService $alertBot): int
    {
        $secret = (string) config('services.telegram_alert.secret');
        if ($secret === '') {
            $this->error('ยังไม่ได้ตั้ง TELEGRAM_ALERT_WEBHOOK_SECRET');

            return self::FAILURE;
        }

        $plugins = FlowPlugin::where('type', 'telegram')->where('enabled', true)->get();
        $seen = [];
        foreach ($plugins as $plugin) {
            $token = $plugin->config['access_token'] ?? '';
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;

            $url = rtrim((string) config('app.url'), '/').'/api/webhook/telegram-alert/'.$token;
            $ok = $alertBot->setWebhook($token, $url, $secret);
            $this->line(($ok ? '✅' : '❌').' plugin #'.$plugin->id.' token '.substr($token, 0, 8).'…');
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test tests/Feature/SetTelegramAlertWebhookTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/app/Console/Commands/SetTelegramAlertWebhook.php backend/tests/Feature/SetTelegramAlertWebhookTest.php
git commit -m "feat(slip): artisan command to register Telegram alert webhook"
```

---

### Task 5: รัน suite เต็ม + ตั้งค่า deploy

**Files:** ไม่มีไฟล์โค้ดใหม่ (ขั้นตอน verify + ops)

- [ ] **Step 1: รัน test ที่เกี่ยวข้องทั้งหมด**

Run: `cd backend && php artisan test tests/Feature/SlipVerificationAlertTest.php tests/Feature/TelegramAlertCallbackTest.php tests/Feature/SetTelegramAlertWebhookTest.php tests/Unit/Payment/TelegramAlertBotServiceTest.php`
Expected: PASS ทั้งหมด

- [ ] **Step 2: ตั้ง env production**

เพิ่ม `TELEGRAM_ALERT_WEBHOOK_SECRET=<สุ่มขึ้นมา>` ใน Railway (backend service) — ค่าลับ ไม่ต้อง commit

- [ ] **Step 3: หลัง deploy รัน command ตั้ง webhook**

Run (บน production shell): `php artisan telegram:alert-webhook`
Expected: `✅ plugin #... token ...` สำหรับ bot 26

- [ ] **Step 4: Manual test ของจริง**

ส่งสลิปทดสอบเข้า bot 26 → รอ alert Telegram → กดปุ่มยืนยัน →
เช็ค: (ก) ออเดอร์ถูกสร้าง (ข) ลูกค้าได้ข้อความยืนยันใน LINE (ค) ข้อความ Telegram ถูกแก้เป็น "✅ ยืนยันรับเงินแล้ว โดย ..." (ง) กดปุ่มซ้ำ → ขึ้น "ยืนยันไปแล้ว" ไม่สร้างออเดอร์ซ้ำ

---

## Notes สำหรับ implementer
- อย่าแก้ `ManualPaymentConfirmService` — reuse `confirm()` เท่านั้น
- `formatBaht` เป็น `private static` ใน `SlipVerificationService`; ใน controller (Task 3) ไม่มีสิทธิ์เรียก — ข้อความ success ใช้ `number_format` ตรง ๆ หรือไม่ต้องแสดงยอดก็ได้ (Task 3 code ปัจจุบันไม่ได้เรียก formatBaht)
- Postgres JSON query `where('config->access_token', $token)` ใช้ได้กับ column ที่ cast เป็น array
- ทุก Telegram API call เป็น best-effort (Task 1 จับ error) — controller จึงไม่ต้อง try/catch รอบ alertBot
