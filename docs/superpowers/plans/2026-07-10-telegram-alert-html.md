# แจ้งเตือน Telegram แบบ HTML ทั้ง 6 แบบ — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** แจ้งเตือน Telegram ฝั่งเจ้าของทุกแบบอ่านง่ายขึ้นด้วย HTML formatting (หัวข้อหนา, ยอดเงิน `<code>`, รายการสินค้าใน `<blockquote>`) โดย escape ข้อมูล dynamic ทุกจุดกันข้อความพังทั้งใบ

**Architecture:** เปิด `parse_mode: HTML` ที่ `TelegramAlertBotService` จุดเดียว (sendMessage + editMessageText) พร้อม helper `esc()` แล้วไล่ปรับ message builder ทีละ domain: การ์ดส่งของ+เตือนซ้ำ → ปุ่ม callback → แจ้งเตือนสลิป → reconcile — ดีไซน์ per-message ตามที่เจ้าของอนุมัติ (mockup ในแต่ละ task)

**Tech Stack:** Laravel 13, Telegram Bot API (`parse_mode: HTML` — tags ที่ใช้: `<b>`, `<code>`, `<blockquote>`), PHPUnit 12

## Global Constraints

- **ทุกค่าที่มาจากภายนอก (ชื่อลูกค้า, ชื่อสินค้า, ชื่อคนกด, reason, status) ต้องผ่าน `TelegramAlertBotService::esc()` ก่อนประกอบเข้า HTML** — ค่าที่มี `<`/`&` ที่ไม่ escape จะทำให้ Telegram ปฏิเสธข้อความทั้งใบ (แจ้งเตือนหายเงียบ)
- `answerCallbackQuery` (toast) ไม่รองรับ formatting — ห้ามใส่ tag
- `FlowPluginService::sendTelegramNotification` ใช้ HTML + escape อยู่แล้ว — แตะเฉพาะ `appendItems` header ตาม Task 5, ห้ามแตะ template หลัก (อยู่ใน DB plugin config)
- ห้ามเปลี่ยน callback_data / โครงสร้างปุ่ม — เปลี่ยนเฉพาะ text ของข้อความ
- ห้าม log / เก็บ credential — งานนี้ไม่แตะ credential path
- Branch: `feat/telegram-alert-html` (จาก main `4d7acdb`) ใน worktree `.claude/worktrees/delivery-toggle`
- test: `cd backend && php artisan test --filter=<ชื่อ>` · `/simplify` ก่อน commit สุดท้าย
- อ้างอิงตำแหน่งโค้ดด้วย "เนื้อหา" ไม่ใช่เลขบรรทัด (เลขบรรทัดโดยประมาณ)

---

### Task 1: TelegramAlertBotService — parse_mode HTML + esc()

**Files:**
- Modify: `backend/app/Services/Payment/TelegramAlertBotService.php`
- Test: `backend/tests/Feature/TelegramAlertBotServiceTest.php` (ใหม่)

**Interfaces:**
- Produces: `sendMessage`/`editMessageText` ส่ง `parse_mode: 'HTML'` เสมอ; `TelegramAlertBotService::esc(?string $value): string` (static) — ทุก task ถัดไปเรียกใช้

- [ ] **Step 1: เขียน failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertBotServiceTest extends TestCase
{
    public function test_send_message_uses_html_parse_mode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->sendMessage('TOK', '999', '<b>hi</b>');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && ($r['parse_mode'] ?? null) === 'HTML');
    }

    public function test_edit_message_uses_html_parse_mode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->editMessageText('TOK', '999', 5, '<b>hi</b>');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && ($r['parse_mode'] ?? null) === 'HTML');
    }

    public function test_esc_escapes_html_special_chars_and_null(): void
    {
        $this->assertSame('a&lt;b&gt; &amp; &quot;c&quot;', TelegramAlertBotService::esc('a<b> & "c"'));
        $this->assertSame('', TelegramAlertBotService::esc(null));
    }
}
```

- [ ] **Step 2: รันให้ fail** — `cd backend && php artisan test --filter=TelegramAlertBotServiceTest` → FAIL (parse_mode ไม่มี / method esc ไม่มี)

- [ ] **Step 3: แก้ service** — ใน `sendMessage` เปลี่ยนบรรทัด params เป็น:

```php
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
```

ใน `editMessageText` เปลี่ยนเป็น:

```php
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
```

เพิ่ม method ใหม่ (วางเหนือ `sendMessage` พร้อม docblock):

```php
    /** escape ค่า dynamic ก่อนประกอบเข้า HTML message — ค่าที่ไม่ escape ทำให้ Telegram ปฏิเสธทั้งข้อความ */
    public static function esc(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
```

- [ ] **Step 4: รันให้ผ่าน** — filter เดิม → PASS 3 tests

- [ ] **Step 5: รัน test เดิมที่แตะ Telegram ทั้งหมด กันพัง** — `php artisan test --filter="AccountDelivery|TelegramAlertCallback|SlipVerification|Reconcile|RemindPending"` → ต้อง PASS (ข้อความยังเป็น text เดิม แค่มี parse_mode เพิ่ม — plain text เป็น HTML ที่ valid อยู่แล้ว ยกเว้นมี `<` ซึ่งไม่มีใน fixture)

- [ ] **Step 6: Commit** — `git add backend/app/Services/Payment/TelegramAlertBotService.php backend/tests/Feature/TelegramAlertBotServiceTest.php && git commit -m "feat(telegram): parse_mode HTML + esc() ที่ TelegramAlertBotService"`

---

### Task 2: การ์ดส่งของ + เตือนซ้ำ

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (`cardText`, `pendingManualNote`)
- Modify: `backend/app/Console/Commands/RemindPendingDeliveries.php` (prefix)
- Test: `backend/tests/Feature/AccountDeliveryCreateTest.php`, `backend/tests/Feature/RemindPendingDeliveriesTest.php` (มีอยู่แล้ว — ถ้าชื่อไฟล์ต่างให้ grep `delivery:remind` ใน backend/tests)

**Interfaces:**
- Consumes: `TelegramAlertBotService::esc()` จาก Task 1
- Produces: การ์ดรูปแบบใหม่ที่ Task 3 (callback edit) ต้องไม่ทำพัง

**ดีไซน์การ์ด (ตกลงกับเจ้าของแล้ว):**

```
🚚 <b>พร้อมส่งสินค้า</b> · งาน #58
👤 <b>{ชื่อลูกค้า}</b> · แชท #1234
💵 ยอด <code>3,000</code> บาท
<blockquote>📦 Nolimit Level Up+ BM — จองแล้ว (#7721)
📦 Page ×1 — ส่งลิงก์ Support ให้ลูกค้า
⚠️ G3D — ของหมด ต้องส่งเอง</blockquote>
```
(เคส FAILED: บรรทัด `❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ` ต่อท้ายนอก blockquote เหมือนเดิม)

- [ ] **Step 1: เพิ่ม failing test ใน `AccountDeliveryCreateTest`**

```php
    public function test_card_uses_html_formatting_and_escapes_names(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->conversation->customerProfile()?->delete();
        \App\Models\CustomerProfile::factory()->create([
            'conversation_id' => $this->conversation->id, 'display_name' => 'ลูกค้า <x&y>',
        ]);

        $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1,299 บาท']]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }
            $text = $request['text'] ?? '';

            return str_contains($text, '<b>พร้อมส่งสินค้า</b>')
                && str_contains($text, 'ลูกค้า &lt;x&amp;y&gt;')       // escape แล้ว
                && str_contains($text, '<code>1,299</code>')
                && str_contains($text, '<blockquote>')
                && str_contains($text, '</blockquote>');
        });
    }
```

หมายเหตุ: ถ้า `CustomerProfile` ไม่มี factory หรือ relation ชื่อไม่ตรง ให้ดูวิธีสร้าง customer profile จาก test อื่น (grep `display_name` ใน backend/tests/Feature) แล้วปรับ setup — assertion คงเดิม

- [ ] **Step 2: รันให้ fail** — `php artisan test --filter=test_card_uses_html`

- [ ] **Step 3: เขียน `cardText` ใหม่** (แทนทั้ง method เดิม; `use App\Services\Payment\TelegramAlertBotService;` มีอยู่แล้วในไฟล์):

```php
    private function cardText(AccountDelivery $delivery): string
    {
        $conv = $delivery->conversation;
        $customer = TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';

        $items = [];
        foreach ($delivery->items as $item) {
            $name = TelegramAlertBotService::esc($item->product_name);
            $items[] = match ($item->status) {
                AccountDeliveryItem::ST_RESERVED => $item->kind === AccountDeliveryItem::KIND_SUPPORT_LINK
                    ? "📦 {$name} ×{$item->qty} — ส่งลิงก์ Support ให้ลูกค้า"
                    : "📦 {$name} — จองแล้ว (#{$item->stock_item_id})",
                AccountDeliveryItem::ST_SHORTAGE => "⚠️ {$name} — ของหมด ต้องส่งเอง",
                AccountDeliveryItem::ST_UNMAPPED => "⚠️ {$name} — ไม่รู้จักสินค้า ต้องส่งเอง",
                default => "• {$name} — ".TelegramAlertBotService::esc($item->status),
            };
        }

        $lines = [
            "🚚 <b>พร้อมส่งสินค้า</b> · งาน #{$delivery->id}",
            "👤 <b>{$customer}</b> · แชท #{$conv?->id}",
            "💵 ยอด <code>{$amount}</code> บาท",
            '<blockquote>'.implode("\n", $items).'</blockquote>',
        ];
        if ($delivery->status === AccountDelivery::STATUS_FAILED) {
            $lines[] = '❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ';
        }

        return implode("\n", $lines);
    }
```

และใน `pendingManualNote` เปลี่ยนบรรทัด return เป็น (escape ชื่อสินค้า):

```php
        return $names === [] ? '' : "\n⚠️ ยังต้องส่งเอง: ".TelegramAlertBotService::esc(implode(', ', $names));
```

- [ ] **Step 4: ปรับ prefix ใน `RemindPendingDeliveries`** — เปลี่ยนบรรทัด sendCard เป็น:

```php
            $service->sendCard($delivery, "⏰ <b>เตือน:</b> งานส่งของค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
```

- [ ] **Step 5: รันให้ผ่านทั้ง domain** — `php artisan test --filter="AccountDelivery|RemindPending"` → PASS ทั้งหมด (test เดิมที่ assert ข้อความการ์ดตรงตัว ให้แก้ assertion ตามรูปแบบใหม่ — เปลี่ยนเฉพาะ string ที่ assert, ห้ามลบ test)

- [ ] **Step 6: Commit** — `git commit -m "feat(telegram): การ์ดส่งของ+เตือนซ้ำแบบ HTML อ่านง่าย"` (add เฉพาะ 4 ไฟล์ที่แตะ)

---

### Task 3: ข้อความหลังกดปุ่ม (TelegramAlertCallbackController)

**Files:**
- Modify: `backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php`
- Test: ไฟล์ test เดิมของ controller นี้ (grep `TelegramAlertCallback` ใน backend/tests/Feature)

**Interfaces:**
- Consumes: `TelegramAlertBotService::esc()`; ห้ามแตะ `answerCallbackQuery` texts (toast — ไม่รองรับ tag)

**ดีไซน์ (editMessageText ทั้ง 6 จุด — ตำแหน่งหาจากข้อความเดิม):**

| ข้อความเดิม | ใหม่ |
|---|---|
| `⚠️ ยืนยันทั้งที่สลิปน่าสงสัย?\nกดปุ่ม...` | `⚠️ <b>ยืนยันทั้งที่สลิปน่าสงสัย?</b>\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง` |
| `✅ ยืนยันรับเงินแล้ว โดย {fromName}` | `✅ <b>ยืนยันรับเงินแล้ว</b> โดย {esc(fromName)}` |
| `✅ ยืนยันไปแล้ว (โดยคนอื่นหรือทางเว็บ)` | `✅ <b>ยืนยันไปแล้ว</b> (โดยคนอื่นหรือทางเว็บ)` |
| `⚠️ ยืนยันยกเลิก คืนของเข้า stock? (งาน #id)\nกด...` | `⚠️ <b>ยืนยันยกเลิก คืนของเข้า stock?</b> · งาน #id\nกดปุ่มด้านล่างอีกครั้งเพื่อยืนยันจริง` |
| `↩️ คืนของเข้า stock แล้ว โดย {fromName} (งาน #id)` | `↩️ <b>คืนของเข้า stock แล้ว</b> โดย {esc(fromName)} · งาน #id` |
| `✅ ส่งให้ลูกค้าแล้ว โดย {fromName} (งาน #id)` + note | `✅ <b>ส่งให้ลูกค้าแล้ว</b> โดย {esc(fromName)} · งาน #id` + note เดิม |
| `✅ งาน #id ถูกจัดการไปแล้ว (สถานะ: {status})` | `✅ งาน #id ถูกจัดการไปแล้ว (สถานะ: {esc(status)})` |

- [ ] **Step 1: เพิ่ม failing test** — ในไฟล์ test เดิมของ controller เพิ่ม (ปรับ setup ตาม pattern test เดิมในไฟล์นั้น เช่น การยิง webhook + secret):

```php
    public function test_deliver_edit_message_escapes_from_name_and_uses_bold(): void
    {
        // setup delivery reserved + กดปุ่ม dv ครั้งแรก+dz ยืนยัน ตาม pattern test เดิมในไฟล์
        // โดยตั้ง from.first_name = 'Boss <1>'
        // แล้ว assert:
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && str_contains($r['text'] ?? '', '<b>ส่งให้ลูกค้าแล้ว</b>')
            && str_contains($r['text'] ?? '', 'Boss &lt;1&gt;'));
    }
```

(โครง setup จริงลอกจาก test กด dv ที่มีอยู่ — เปลี่ยนเฉพาะ first_name + assertions; ถ้า flow กดจริงเป็น dv→dv ครั้งเดียวก็ตามนั้น ดูจาก test เดิม)

- [ ] **Step 2: รันให้ fail** — filter ชื่อ test ใหม่

- [ ] **Step 3: แก้ทั้ง 7 จุดตามตารางดีไซน์** — ใช้ `TelegramAlertBotService::esc($fromName)` (เพิ่ม use ถ้ายังไม่มี) — เปลี่ยนเฉพาะ string ใน `editMessageText`, ห้ามแตะ `answerCallbackQuery`/`callback_data`

- [ ] **Step 4: รันให้ผ่าน** — `php artisan test --filter=TelegramAlertCallback` → PASS ทั้งไฟล์ (แก้ assertion เดิมที่เทียบ string ตรงตัวให้ตรงรูปแบบใหม่)

- [ ] **Step 5: Commit** — `git commit -m "feat(telegram): ข้อความหลังกดปุ่มแบบ HTML + escape ชื่อคนกด"`

---

### Task 4: แจ้งเตือนสลิปมีปัญหา (notifyAdmin)

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` (`notifyAdmin` — ส่วนประกอบ `$lines`)
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php` (หรือไฟล์ที่ test notifyAdmin — grep `อย่าเพิ่งส่งของ` ใน backend/tests)

**Interfaces:**
- Consumes: `TelegramAlertBotService::esc()`

**ดีไซน์:**

```
🚨 <b>สลิปมีปัญหา — อย่าเพิ่งส่งของ</b> ({bot name})
👤 {ชื่อลูกค้า} · แชท #1234
เหตุผล: <b>{reason}</b>
ยอดในสลิป: <code>1,500</code> บาท
ยอดออเดอร์: <code>1,800</code> บาท
กรุณาเช็คในแชทก่อนยืนยัน
```

- [ ] **Step 1: เพิ่ม failing test** (ในไฟล์ที่มี test notifyAdmin เดิม — ตาม setup เดิม เปลี่ยน assertion):

```php
    public function test_notify_admin_uses_html_and_escapes_dynamic_values(): void
    {
        // ใช้ setup เดิมของ test notifyAdmin ที่มีอยู่ (bot+plugin+result fail)
        // ตั้งชื่อ bot หรือ display_name ให้มี '<' แล้ว assert:
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '<b>')
            && str_contains($r['text'] ?? '', '&lt;')
            && ! preg_match('/เหตุผล: [^<]*</u', '')); // แค่สอง assertion แรกพอถ้าเขียนยาก
    }
```

- [ ] **Step 2: รันให้ fail**

- [ ] **Step 3: แก้ `notifyAdmin`** — เปลี่ยนส่วนประกอบข้อความเป็น:

```php
        $reason = self::FAIL_REASON_LABELS[$result->failReason] ?? ($result->failReason ?? 'unknown');
        $botName = TelegramAlertBotService::esc($bot->name);
        $header = in_array($result->failReason, self::FRAUD_REASONS, true)
            ? "🚨 <b>สลิปมีปัญหา — อย่าเพิ่งส่งของ</b> ({$botName})"
            : "⚠️ <b>ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ</b> ({$botName})";
        $lines = [$header];
        if ($conversation !== null) {
            $displayName = $conversation->customerProfile?->display_name;
            $lines[] = $displayName !== null
                ? '👤 '.TelegramAlertBotService::esc($displayName)." · แชท #{$conversation->id}"
                : "👤 แชท #{$conversation->id}";
        }
        $lines[] = 'เหตุผล: <b>'.TelegramAlertBotService::esc($reason).'</b>';
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: <code>'.self::formatBaht($result->amount).'</code> บาท';
        }
        if ($result->expectedAmount !== null) {
            $lines[] = 'ยอดออเดอร์: <code>'.self::formatBaht($result->expectedAmount).'</code> บาท';
        }
        $lines[] = 'กรุณาเช็คในแชทก่อนยืนยัน';
```

(เพิ่ม `use App\Services\Payment\TelegramAlertBotService;` ไม่ต้อง — ไฟล์อยู่ namespace เดียวกัน เรียกสั้นได้เลย: `TelegramAlertBotService::esc()`)

- [ ] **Step 4: รันให้ผ่าน** — `php artisan test --filter=SlipVerification` → PASS (แก้ assertion เดิมตามรูปแบบใหม่)

- [ ] **Step 5: Commit** — `git commit -m "feat(telegram): แจ้งเตือนสลิปมีปัญหาแบบ HTML"`

---

### Task 5: Reconcile alert + header รายการสินค้าในการ์ดรับเงิน

**Files:**
- Modify: `backend/app/Console/Commands/ReconcileDeliveries.php` (`notifyTelegram`)
- Modify: `backend/app/Services/FlowPluginService.php` (`appendItems` — บรรทัด header)
- Test: `backend/tests/Feature/ReconcileDeliveriesTest.php` + ไฟล์ test ของ FlowPluginService items (grep `รายการสินค้า` ใน backend/tests)

**Interfaces:**
- Consumes: `TelegramAlertBotService::esc()`

**ดีไซน์ reconcile:**

```
🧯 <b>ตรวจพบของค้างในระบบส่งบัญชี</b>
<blockquote>{problem บรรทัดละรายการ — escape แต่ละบรรทัด}</blockquote>
รบกวนเช็คใน DB/แจ้งทีม dev
```

- [ ] **Step 1: เพิ่ม failing test ใน ReconcileDeliveriesTest** — ใช้ setup เดิมของ test ที่ assert `#88` แล้วเพิ่ม assertion `str_contains($r['text'], '<blockquote>')` และ `str_contains($r['text'], '<b>ตรวจพบของค้างในระบบส่งบัญชี</b>')`

- [ ] **Step 2: รันให้ fail**

- [ ] **Step 3: แก้ `notifyTelegram`** — เปลี่ยนบรรทัดส่งข้อความเป็น:

```php
        $escaped = array_map(fn ($p) => TelegramAlertBotService::esc($p), $problems);
        $alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            "🧯 <b>ตรวจพบของค้างในระบบส่งบัญชี</b>\n<blockquote>".implode("\n", $escaped)."</blockquote>\nรบกวนเช็คใน DB/แจ้งทีม dev",
        );
```

(เพิ่ม `use App\Services\Payment\TelegramAlertBotService;` ที่หัวไฟล์)

- [ ] **Step 4: แก้ `appendItems` ใน FlowPluginService** — เปลี่ยน `$lines = ['', '📦 รายการสินค้า'];` เป็น `$lines = ['', '<b>📦 รายการสินค้า</b>'];` (ช่องทางนี้ใช้ HTML อยู่แล้ว item ถูก escape อยู่แล้ว — แตะแค่ header)

- [ ] **Step 5: รันให้ผ่าน** — `php artisan test --filter="Reconcile|FlowPlugin"` → PASS (แก้ assertion เดิมตามใหม่)

- [ ] **Step 6: Commit** — `git commit -m "feat(telegram): reconcile alert + header รายการสินค้าแบบ HTML"`

---

### Task 6: Full suite + simplify + PR

- [ ] **Step 1:** `cd backend && php artisan test` → PASS ทั้งหมด (เทียบ baseline main ถ้ามีตัวพังที่ไม่เกี่ยว)
- [ ] **Step 2:** `/simplify` diff ทั้ง branch แล้ว commit fix ถ้ามี
- [ ] **Step 3:** push + `gh pr create` — title `feat(telegram): แจ้งเตือน Telegram แบบ HTML อ่านง่ายทั้ง 6 แบบ`; body สรุปดีไซน์ per-message + ตาราง before/after + หมายเหตุ: template การ์ดรับเงินหลักอยู่ใน DB plugin config ไม่ได้แตะ (แตะเฉพาะ block รายการสินค้าที่ generate จากโค้ด)

## Self-Review (ทำแล้ว)

- ครอบทั้ง 6 แบบ: การ์ดส่งของ (T2), เตือนซ้ำ (T2), หลังกดปุ่ม (T3), สลิป (T4), reconcile (T5), การ์ดรับเงิน-ส่วน items (T5) ✓
- esc() นิยามใน T1 ใช้ชื่อเดียวกันทุก task ✓
- ไม่มี placeholder — โค้ด/ดีไซน์จริงทุก step (T3 Step 1 ระบุให้ลอก setup จาก test เดิมพร้อม assertion จริง) ✓
