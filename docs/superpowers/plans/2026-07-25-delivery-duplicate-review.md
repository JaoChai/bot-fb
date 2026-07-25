# เปลี่ยนการกันออเดอร์ซ้ำจาก "บล็อกเงียบ" เป็น "เตือนให้เจ้าของตัดสิน" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เลิกให้ระบบตัดสินแทนคนว่ายอดซ้ำคือ "จ่ายก้อนเดิม" — สร้างงานส่งของเสมอแล้วติดคำเตือนบนการ์ด Telegram ให้เจ้าของกดตัดสิน เพื่อให้ออเดอร์จริงหายเงียบไม่ได้อีก

**Architecture:** ระบบนี้ให้เจ้าของกดปุ่มยืนยันทุกงานอยู่แล้ว (การ์ด Telegram + ปุ่ม `dv|`/`dx|`) จึงย้ายการตัดสิน "ซ้ำหรือไม่" จากโค้ดไปที่คน โดยใช้ช่องใส่คำเตือนบนการ์ดที่มีอยู่แล้ว (`sendCard($delivery, $prefix)` — ตัวเดียวกับที่ `delivery:remind` ใช้) เมื่อชั้นงานส่งของจัดการเคสซ้ำด้วยการถามได้แล้ว เช็คที่ชั้นตรวจสลิป (`recentManualConfirmExists`) ที่ปฏิเสธสลิปลูกค้าต่อหน้าจึงไม่จำเป็นและถูกลบทิ้ง

**Tech Stack:** Laravel 13 (PHP 8.3), PHPUnit 12/Pest 4, PostgreSQL (Neon), Telegram Bot API, Laravel Pint

## Global Constraints

- ภาษาไทยในคอมเมนต์อธิบาย "ทำไม" และในข้อความที่ส่งออก Telegram/ลูกค้า — ตามสไตล์ไฟล์เดิม
- ห้าม log ค่า `detail` (credential) เด็ดขาด (กติกาคลาส `AccountDeliveryService`)
- ค่าที่ประกอบเข้าข้อความ HTML ของ Telegram ต้อง escape ด้วย `TelegramAlertBotService::esc()` — ยกเว้นตัวเลขล้วนที่เราสร้างเอง
- โปรเจกต์ไม่ใช้ helper `filled()`/`blank()` — ใช้ `!== null && !== ''` ตามสไตล์เดิม
- รัน `./vendor/bin/pint --dirty` ก่อน commit ทุกครั้ง (จาก `backend/`)
- คำสั่งทดสอบทั้งหมดรันจาก `backend/`
- ห้ามแตะ `config('delivery.dedup_window_minutes')` — ค่า 30 นาทีคงไว้ เปลี่ยนบทบาทจาก "ตัวบล็อก" เป็น "ตัวจุดคำเตือน"

---

## File Structure

| ไฟล์ | หน้าที่หลังแก้ |
|---|---|
| `backend/app/Services/Delivery/AccountDeliveryService.php` | สร้างงานส่งของเสมอ; หาคู่ที่ยอดซ้ำในหน้าต่างเวลาแล้วส่งคำเตือนไปกับการ์ด แทนการ `return null` |
| `backend/app/Services/Payment/SlipVerificationService.php` | เลิกปฏิเสธสลิปลูกค้าเพราะเพิ่งมี manual confirm ยอดเดียวกัน (ลบเช็ค + method) |
| `backend/tests/Feature/AccountDeliveryCreateTest.php` | ครอบพฤติกรรมใหม่: ยอดซ้ำ → มีงาน + การ์ดมีคำเตือน; trans_ref ต่างกัน → ไม่มีคำเตือน |
| `backend/tests/Feature/SlipVerificationServiceTest.php` | กลับด้าน test ที่ล็อกการปฏิเสธสลิปหลัง manual confirm |

---

### Task 1: การ์ดเตือนแทนการบล็อกเงียบ

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php:53-82` (createFromPayment — ส่วนสร้างงาน), `:161` (จุดเรียก sendCard), `:166-197` (hasRecentActiveDelivery)
- Test: `backend/tests/Feature/AccountDeliveryCreateTest.php`

**Interfaces:**
- Consumes: `AccountDeliveryService::sendCard(AccountDelivery $delivery, string $prefix = '')` (มีอยู่แล้ว, public), `TelegramAlertBotService::esc(?string $value): string`
- Produces: `private function recentDuplicateDelivery(int $conversationId, ?float $amount, int $slipVerificationId): ?AccountDelivery` — แทนที่ `hasRecentActiveDelivery(...): bool` เดิม; ไม่มี consumer นอกคลาส

- [ ] **Step 1: เขียน test ที่ล้มก่อน — ยอดซ้ำต้องได้งาน + การ์ดมีคำเตือน**

แทนที่ test เดิมทั้งตัว `test_second_delivery_for_same_conversation_and_amount_is_blocked` ใน `backend/tests/Feature/AccountDeliveryCreateTest.php` (ราวบรรทัด 194-215) ด้วย:

```php
    public function test_duplicate_amount_creates_job_with_warning_instead_of_blocking(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->seedAvailable(11, 'NLMP');

        // path แรก (เช่น manual confirm) — slip ใบที่ 1 ไม่มี trans_ref
        $first = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1299']]);
        $this->assertNotNull($first);

        // path ที่สอง — slip คนละใบ ยอดเดียวกัน conversation เดิม, เทียบ trans_ref ไม่ได้
        // ระบบไม่รู้ว่าเป็นเงินก้อนเดิมหรือลูกค้าซื้อซ้ำ → ต้องสร้างงานแล้วให้เจ้าของตัดสิน
        $slip2 = SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $this->conversation->id,
            'amount' => 1299, 'status' => 'manual_confirmed',
        ]);
        $second = app(AccountDeliveryService::class)->createFromPayment(
            $this->bot, $this->conversation, $slip2->id, 1299.0,
            [['name' => 'Nolimit ส่วนตัว', 'total' => '1299']],
        );

        $this->assertNotNull($second);
        $this->assertSame(AccountDelivery::STATUS_RESERVED, $second->status);
        $this->assertSame(2, DB::connection('mhha_acc')->table('items_reserved')->count());

        // การ์ดของงานที่ 2 ต้องบอกว่าซ้ำกับงานไหน + มีปุ่มให้ยกเลิกตามเดิม
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'] ?? '', "ซ้ำกับงาน #{$first->id}")
            && str_contains($request['reply_markup'] ?? '', "dx|{$second->id}|x"));
    }

    public function test_first_delivery_card_has_no_duplicate_warning(): void
    {
        $this->seedAvailable(10, 'NLMP');

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1299']]);

        $this->assertNotNull($delivery);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ! str_contains($request['text'] ?? '', 'ซ้ำกับงาน'));
    }
```

- [ ] **Step 2: รัน test ให้เห็นว่าล้ม**

Run: `php artisan test --filter="test_duplicate_amount_creates_job_with_warning_instead_of_blocking"`
Expected: FAIL — `Failed asserting that null is not null` (โค้ดปัจจุบันยัง `return null` ตอนเจอยอดซ้ำ)

- [ ] **Step 3: เปลี่ยน `hasRecentActiveDelivery` ให้คืนงานที่ซ้ำแทน bool**

ใน `backend/app/Services/Delivery/AccountDeliveryService.php` แทนที่ method เดิมทั้งตัว (`:166-197`) ด้วย:

```php
    /**
     * งานส่งยอดเดียวกันบน conversation นี้ที่ยัง active/ส่งแล้ว ในหน้าต่างเวลา — คืน null ถ้าไม่มี
     *
     * ระบบแยกไม่ออกว่า "จ่ายก้อนเดิมเข้า 2 path" หรือ "ลูกค้าซื้อชุดเดิมซ้ำ" จึงไม่บล็อก
     * แต่เอางานที่ซ้ำไปติดคำเตือนบนการ์ดให้เจ้าของตัดสิน (บล็อกเองทำให้ออเดอร์จริงหายเงียบ)
     */
    private function recentDuplicateDelivery(int $conversationId, ?float $amount, int $slipVerificationId): ?AccountDelivery
    {
        $window = now()->subMinutes(config_int('delivery.dedup_window_minutes', 30));

        $query = AccountDelivery::where('conversation_id', $conversationId)
            ->whereIn('status', [
                AccountDelivery::STATUS_RESERVING,
                AccountDelivery::STATUS_RESERVED,
                AccountDelivery::STATUS_DELIVERING,
                AccountDelivery::STATUS_DELIVERED,
            ])
            ->where('created_at', '>=', $window)
            ->where(fn ($q) => $amount === null ? $q->whereNull('amount') : $q->where('amount', $amount));

        // trans_ref คนละค่า = ยืนยันได้ว่าคนละการโอน ไม่ใช่เรื่องน่าสงสัย → ไม่ต้องเตือนให้รำคาญ
        // trans_ref ว่าง = manual confirm ที่ไม่มีสลิป เทียบไม่ได้ → ยังนับเป็นคู่ที่ต้องเตือน
        $transRef = SlipVerification::whereKey($slipVerificationId)->value('trans_ref');
        if ($transRef !== null && $transRef !== '') {
            $otherTransfers = SlipVerification::where('conversation_id', $conversationId)
                ->whereNotNull('trans_ref')
                ->where('trans_ref', '!=', $transRef)
                ->pluck('id');
            $query->whereNotIn('slip_verification_id', $otherTransfers);
        }

        return $query->latest('id')->first();
    }
```

- [ ] **Step 4: ให้ createFromPayment สร้างงานเสมอ แล้วจำคู่ที่ซ้ำไว้**

แทนที่บล็อก `:53-82` (ตั้งแต่คอมเมนต์ "สร้างงานใน transaction..." จนจบ `if ($delivery === null) {...}`) ด้วย:

```php
        // lock conversation ระหว่างเช็คคู่ซ้ำ+สร้างงาน: กัน 2 dispatch path (EasySlip vs manual)
        // ยิงพร้อมกันแล้วต่างฝ่ายต่างมองไม่เห็นกัน จนได้การ์ด 2 ใบที่ไม่มีคำเตือนสักใบ
        try {
            [$delivery, $duplicateOf] = DB::transaction(function () use ($bot, $conversation, $slipVerificationId, $amount) {
                Conversation::whereKey($conversation->id)->lockForUpdate()->first();

                $duplicateOf = $this->recentDuplicateDelivery($conversation->id, $amount, $slipVerificationId);

                return [AccountDelivery::create([
                    'bot_id' => $bot->id,
                    'conversation_id' => $conversation->id,
                    'slip_verification_id' => $slipVerificationId,
                    'status' => AccountDelivery::STATUS_RESERVING,
                    'amount' => $amount,
                ]), $duplicateOf];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // webhook ซ้ำ/job รันซ้ำ (slip เดียวกัน) — unique(slip) กันไว้แล้ว
        }

        if ($duplicateOf !== null) {
            Log::info('Delivery: created despite recent same-amount delivery — card carries a warning', [
                'conversation_id' => $conversation->id, 'amount' => $amount,
                'duplicate_of' => $duplicateOf->id,
            ]);
        }
```

- [ ] **Step 5: ส่งคำเตือนไปกับการ์ด**

แทนที่บรรทัด `$this->sendCard($delivery->fresh('items'));` (`:161`) ด้วย:

```php
        $this->sendCard($delivery->fresh('items'), $this->duplicateWarning($duplicateOf));
```

แล้วเพิ่ม method นี้ต่อจาก `recentDuplicateDelivery`:

```php
    /** คำเตือนหัวการ์ดเมื่อมีงานยอดเดียวกันเพิ่งเกิดไป — คืน '' ถ้าไม่มีคู่ซ้ำ */
    private function duplicateWarning(?AccountDelivery $duplicateOf): string
    {
        if ($duplicateOf === null) {
            return '';
        }

        $minutes = (int) $duplicateOf->created_at->diffInMinutes(now());

        return "⚠️ <b>ยอดนี้ซ้ำกับงาน #{$duplicateOf->id}</b> เมื่อ {$minutes} นาทีที่แล้ว\n"
            ."ถ้าเป็นเงินก้อนเดิม กด \"ยกเลิก คืนเข้า stock\"\n\n";
    }
```

- [ ] **Step 6: รัน test ทั้งไฟล์ให้ผ่าน**

Run: `php artisan test --filter="AccountDeliveryCreateTest"`
Expected: PASS ทุกตัว รวม `test_repeat_purchase_with_different_trans_ref_is_allowed` เดิม (trans_ref ต่างกัน → ไม่เข้าเงื่อนไขคู่ซ้ำ → ไม่มีคำเตือน)

ถ้า `test_duplicate_slip_verification_returns_null_without_reserving` ล้ม ให้หยุดและอ่านสาเหตุ — เคสนั้นคือ slip ใบเดียวกันซ้ำ ต้องถูก `UniqueConstraintViolationException` กันไว้เหมือนเดิม ห้ามแก้ test นั้นให้ผ่านโดยไม่เข้าใจ

- [ ] **Step 7: รัน suite เต็ม + format**

Run: `php artisan test` แล้ว `./vendor/bin/pint --dirty`
Expected: ไม่มี test ล้ม (ก่อนแก้มี 957 passed / 13 skipped), Pint `"result":"passed"`

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Feature/AccountDeliveryCreateTest.php
git commit -m "$(cat <<'EOF'
fix(delivery): ยอดซ้ำให้เตือนบนการ์ด แทนบล็อกงานส่งของเงียบๆ

ระบบแยกไม่ออกว่า "จ่ายก้อนเดิมเข้า 2 path" หรือ "ลูกค้าซื้อชุดเดิมซ้ำ"
การเดาแล้วบล็อกทำให้ออเดอร์จริงหายเงียบ (ออเดอร์ #1672) ส่วนการเดาผิด
อีกทางทำให้ขายซ้ำ — ไม่มีค่าหน้าต่างเวลาไหนที่ถูกทั้งสองทาง

เปลี่ยนเป็นสร้างงานเสมอแล้วติดคำเตือนบอกเลขงานที่ซ้ำบนการ์ด ให้เจ้าของ
ที่ต้องกดปุ่มยืนยันทุกงานอยู่แล้วเป็นคนตัดสิน — ออเดอร์หายเงียบเกิดไม่ได้
และการ์ดซ้ำแยกออกจากกันได้ ต่างจากเดิมที่การ์ด 2 ใบหน้าตาเหมือนกันเป๊ะ

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: เลิกปฏิเสธสลิปลูกค้าเพราะเพิ่งมี manual confirm

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:296-303` (ลบเช็ค 2.5), `:332-350` (ลบ method `recentManualConfirmExists`)
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php:109-128`

**Interfaces:**
- Consumes: พฤติกรรมจาก Task 1 — ชั้นงานส่งของจัดการยอดซ้ำด้วยการเตือนแล้ว จึงไม่ต้องกันที่ชั้นสลิปอีก
- Produces: ไม่มี API ใหม่ — `verify()` คืน `SlipVerificationResult` แบบเดิม แต่เลิกคืน `failReason: 'duplicate'` จากสาเหตุ manual-confirm

- [ ] **Step 1: กลับด้าน test ที่ล็อกการปฏิเสธไว้**

แทนที่ `test_easyslip_pass_blocked_when_recent_manual_confirm_same_amount` (`:109-128`) ด้วย:

```php
    public function test_easyslip_passes_after_manual_confirm_and_lets_owner_decide(): void
    {
        // เจ้าของกดยืนยันเงินเองไปแล้ว แล้วสลิปเข้ามาทีหลัง — ระบบแยกไม่ออกว่าเป็นเงินก้อนเดิม
        // หรือลูกค้าซื้อซ้ำ. ห้ามปฏิเสธสลิปต่อหน้าลูกค้า: ปล่อยผ่านแล้วให้การ์ดงานส่งของ
        // เตือนเจ้าของว่ายอดซ้ำ (ดู AccountDeliveryService::duplicateWarning)
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        config(['delivery.enabled' => true]);
        $this->bot->update(['auto_delivery_enabled' => true]);
        SlipVerification::create([
            'bot_id' => $this->bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1500, 'status' => 'manual_confirmed',
        ]);
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, $conversation, null, 'https://example.com/slip.jpg', $this->paymentHistory
        );

        $this->assertTrue($result->passed);
    }
```

- [ ] **Step 2: รัน test ให้เห็นว่าล้ม**

Run: `php artisan test --filter="test_easyslip_passes_after_manual_confirm_and_lets_owner_decide"`
Expected: FAIL — `Failed asserting that false is true` (เช็ค 2.5 ยังปฏิเสธอยู่)

- [ ] **Step 3: ลบเช็ค 2.5**

ใน `backend/app/Services/Payment/SlipVerificationService.php` ลบบล็อกนี้ทั้งก้อน (`:296-303`):

```php
        // เช็ค 2.5: เจ้าของเพิ่งกดยืนยันเงินเอง (manual_confirmed) ยอดเดียวกันบน conversation นี้
        // = การจ่ายก้อนเดียวกัน — ห้ามผ่านซ้ำจนเกิดออเดอร์/งานส่งของซ้ำ (เฉพาะบอทที่เปิด delivery)
        if ($conversation !== null && $this->recentManualConfirmExists($conversation, $bot, $slipAmount)) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'duplicate',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

```

- [ ] **Step 4: ลบ method ที่ไม่มีใครเรียกแล้ว**

ลบทั้งก้อนนี้ (`:332-350`) — docblock + method:

```php
    /**
     * เพิ่งมี manual_confirmed ยอดเดียวกันบน conversation นี้ในหน้าต่างกันซ้ำไหม
     * (กันขายซ้ำเมื่อ EasySlip ผ่านหลังเจ้าของยืนยันมือ — เฉพาะบอทที่เปิด Auto Account Delivery)
     */
    private function recentManualConfirmExists(Conversation $conversation, Bot $bot, float $amount): bool
    {
        if (! config('delivery.enabled') || ! $bot->auto_delivery_enabled) {
            return false;
        }

        $window = now()->subMinutes((int) config('delivery.dedup_window_minutes', 30));

        return SlipVerification::where('conversation_id', $conversation->id)
            ->where('status', 'manual_confirmed')
            ->where('amount', $amount)
            ->where('created_at', '>=', $window)
            ->exists();
    }
```

ห้ามลบ `use` statement ใดๆ — `Bot`, `Conversation`, `SlipVerification` ยังถูกใช้ที่ `verify()` และ `record()`

- [ ] **Step 5: รัน test ที่เกี่ยวข้องให้ผ่าน**

Run: `php artisan test --filter="SlipVerificationServiceTest"`
Expected: PASS ทุกตัว — โดยเฉพาะ `test_duplicate_trans_ref_fails` ต้องยังผ่าน (สลิปใบเดิมที่เคย passed ยังถูกกันด้วยเช็ค 2 + unique index ตามเดิม)

- [ ] **Step 6: รัน suite เต็ม + format**

Run: `php artisan test` แล้ว `./vendor/bin/pint --dirty`
Expected: ไม่มี test ล้ม, Pint `"result":"passed"`

ถ้ามี test อื่นล้มเพราะคาด `failReason === 'duplicate'` จากเส้นทาง manual confirm ให้หยุดแล้วรายงาน — อย่าแก้ให้ผ่านโดยไม่ตรวจว่า test นั้นล็อกพฤติกรรมที่เราตั้งใจเปลี่ยนหรือเป็นเคสอื่นจริง

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationServiceTest.php
git commit -m "$(cat <<'EOF'
fix(payment): เลิกปฏิเสธสลิปลูกค้าเพราะเพิ่งมี manual confirm ยอดเดียวกัน

เช็คนี้ใช้หน้าต่าง 30 นาทีเดาว่าเป็นเงินก้อนเดิม แล้วตอบลูกค้าว่าสลิป
ไม่ผ่านต่อหน้า ทั้งที่อาจเป็นออเดอร์ใหม่จริง (ลูกค้าซื้อซ้ำยอดเดิม)

ชั้นงานส่งของจัดการเคสยอดซ้ำด้วยการเตือนบนการ์ดให้เจ้าของตัดสินแล้ว
เช็คนี้จึงไม่จำเป็น. สลิปใบเดิมซ้ำยังถูกกันด้วยเช็ค trans_ref + unique
index (bot_id, trans_ref) where passed ตามเดิม

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## หลัง Task 2: ตรวจก่อนขึ้น prod

- [ ] **Step 1: ตรวจว่าไม่มีที่อื่นพึ่งพฤติกรรมเดิม**

Run: `grep -rn "recentManualConfirmExists\|hasRecentActiveDelivery" backend/`
Expected: ไม่มีผลลัพธ์เลย

- [ ] **Step 2: push แล้วเฝ้า deploy**

```bash
git push
```

Railway deploy อัตโนมัติจาก `main` (~8 นาที/deploy) — ตรวจสถานะให้เป็น SUCCESS ก่อนบอกว่าเสร็จ

- [ ] **Step 3: สิ่งที่ต้องเฝ้าหลัง deploy**

- ออเดอร์ที่ลูกค้าซื้อซ้ำยอดเดิมภายใน 30 นาที → ต้องได้การ์ด 2 ใบ ใบที่ 2 มีคำเตือน `⚠️ ยอดนี้ซ้ำกับงาน #N`
- ต้องไม่มีลูกค้าได้ข้อความ "สลิปไม่ผ่าน" จากเหตุ manual confirm อีก
- `delivery:reconcile` (รัน hourly) ไม่ควรมีเตือน "เงินเข้าแล้วแต่ยังไม่มีงานส่งของ" เพิ่มขึ้น — ถ้ามี แปลว่ายังมีเส้นทางที่เงียบอยู่ ให้สืบต่อ

---

## หมายเหตุการตัดสินใจ (ไว้ให้คนอ่านทีหลัง)

**ทำไมไม่ลดหน้าต่างเวลาแทน:** เคยพิจารณาลด `ACCOUNT_DELIVERY_DEDUP_MINUTES` จาก 30 เหลือ 5 นาที แต่การชนกันของ 2 path ห่างกันได้เกิน 5 นาทีจริง (เจ้าของกดยืนยันมือช้าหลังระบบตรวจผ่านไปแล้ว / ลูกค้าส่งรูปสลิปตามมาทีหลัง) — ไม่ว่าตั้งเลขไหนก็ผิดทางใดทางหนึ่ง สั้นไปขายซ้ำ ยาวไปออเดอร์หาย จึงเลิกเดาแล้วส่งให้คนตัดสินแทน

**ทำไมจองของก่อนถาม:** ถ้าเป็นเงินก้อนเดิมจริง ของจะถูกกันไว้จนเจ้าของกดยกเลิก (คืนเข้า `items_available` ครบ) มี `delivery:remind` เตือนทุก 30 นาทีและ `delivery:reconcile` จับงานค้าง — ไม่มีของหาย และหลีกเลี่ยงการเก็บ items ค้างเป็น JSON รอการตัดสิน

**สิ่งที่ยังไม่ทำ:** `guardAgainstDoubleConfirm` (120 วินาที) ใน `ManualPaymentConfirmService` คงไว้ — กันเจ้าของกดยืนยันรัวจากสองแท็บ เป็นคนละปัญหากับการเดาว่าเงินก้อนเดิม
