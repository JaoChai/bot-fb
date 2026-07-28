# Slip Alert Noise + Cart Fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เลิกเด้งการ์ด "ยืนยันยอด" หาเจ้าของเมื่อลูกค้าส่งรูปทั่วไปตอน EasySlip ล่ม และให้สลิปที่ยอดตรงกับข้อความ "ตะกร้า/ยืนยัน" ผ่านอัตโนมัติแทนที่จะกวนเจ้าของ

**Architecture:** แก้ `SlipVerificationService::verify()` สองจุดที่แยกกันชัดเจน — (1) เมื่อ EasySlip ให้คำตอบไม่ได้เลย (token หาย/ล่ม/ตอบเพี้ยน) ให้ถาม vision ก่อนว่ารูปเป็นสลิปไหม แทนที่จะเดาว่าเป็นสลิปทุกครั้ง (2) เมื่อหาข้อความสรุปยอด+เลขบัญชีใน history ไม่เจอ ให้ลองข้อความ "ตะกร้า/ยืนยัน" ที่บอทพิมพ์เองเป็น fallback ก่อนยอมแพ้เป็น `no_pending_order`. ทั้งสองจุดใช้ของที่มีอยู่แล้วในระบบ (`classifySlipImage`, `findExpectedFromConfirmMessage`) ไม่สร้าง abstraction ใหม่

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit (ผ่าน `php artisan test`), Http::fake สำหรับ mock EasySlip/OpenRouter/Telegram

## Global Constraints

- ห้ามเปลี่ยน signature สาธารณะของ `SlipVerificationService::verify()` — `SlipRetryService.php:44` เรียกโดยไม่ส่ง `$isSlipCheck` (ได้ `null`) และต้องคง fail-safe เดิม
- fail-safe direction คงเดิมเสมอ: ไม่แน่ใจ = ถือเป็นสลิป = alert (เข้าข้างเงิน) มีแค่ "vision ตอบ `false` ชัดเจน" เท่านั้นที่ทำให้เงียบ
- ห้ามแตะ branch 400 (`unreadable`), 404 (`fake`/`pending`), เช็ค 1 (บัญชี), เช็ค 2 (สลิปซ้ำ), เช็ค 4 (ยอด) — อยู่นอกขอบเขต
- เทสเดิมทุกตัวใน `backend/tests/Feature/SlipVerificationServiceTest.php`, `SlipVerificationPipelineTest.php`, `SlipRetryServiceTest.php`, `Payment/ConfirmMessageFallbackTest.php` ต้องยังเขียว ห้ามแก้เทสเดิมให้ผ่าน
- คอมเมนต์ในโค้ดเขียนภาษาไทย ตามสไตล์ไฟล์เดิม
- รันเทสจาก `backend/` เสมอ

---

## File Structure

| ไฟล์ | หน้าที่ | Task |
|---|---|---|
| `backend/app/Services/Payment/SlipVerificationService.php` | เพิ่ม private helper `apiUnavailable()` + เปลี่ยน 4 call site ให้ผ่าน helper | 1 |
| `backend/tests/Feature/SlipVerificationServiceTest.php` | เทสหน่วยของ `verify()` ทั้ง Task 1 และ Task 2 | 1, 2 |
| `backend/tests/Feature/SlipVerificationPipelineTest.php` | เทส end-to-end ผ่าน `LineWebhookResponseService::generate()` ว่าไม่ยิง Telegram | 1 |
| `backend/app/Services/Payment/SlipVerificationService.php` | เช็ค 3 เพิ่ม fallback ไป `findExpectedFromConfirmMessage()` | 2 |

ไม่มีไฟล์ใหม่ — ทั้งสอง task แก้ service เดิมและเพิ่มเทสลงไฟล์เทสเดิม

---

## Background: ทำไมต้องแก้ (อ่านก่อนลงมือ)

เคสจริงบน prod 27 ก.ค. 2026 เวลา 19:53:47 — ลูกค้า (แชท #361) ส่ง screenshot หน้าเพจ Facebook มาถามเรื่องพาดหัวคลิป ระบบยิงรูปไป EasySlip แล้ว **timeout พอดี 15 วินาที** (`Http::timeout(15)`) → `ConnectionException` → `api_error` → บันทึก `slip_verifications` id=116 (`raw_response` = null) → เด้ง Telegram หาเจ้าของว่า "⚠️ ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ" พร้อมปุ่ม `✅ ยืนยัน (ใช้ยอดจากแชท)` ทั้งที่รูปไม่ใช่สลิปและแชทไม่มีออเดอร์ค้าง

จุดที่ผิด: branch 400 มี guard ฉลาดอยู่แล้ว (`SlipVerificationService.php:234`) แต่ branch `api_error` ข้าม guard ทั้งหมด

**ต้นทุน LLM ของ Task 1 (วัดจริงหลัง implement — แก้จากที่เคยเขียนไว้ว่า "เท่าเดิม"):**

| กรณี (เกิดเฉพาะตอน EasySlip ล่ม/ไม่มี token) | ก่อนแก้ | หลังแก้ |
|---|---|---|
| รูปทั่วไป (`is_slip=false`) | 1 | **1** — `classifySlipImage()` ฝาก draft ไว้ที่ `$ctx->metadata['slip_vision_draft']` แล้ว `generateImageResponse()` (`LineWebhookResponseService.php:252-255`) หยิบไปใช้ ไม่ยิงซ้ำ |
| รูปเป็นสลิปจริง (`is_slip=true`) หรือไม่แน่ใจ | 1 | **2** — draft ถูกเก็บเฉพาะตอน `is_slip === false` (`LineWebhookResponseService.php:671`) พอเป็นสลิปจึงต้องยิง vision ซ้ำเพื่อตอบลูกค้า |

ยอมรับต้นทุนนี้ ไม่แก้ เพราะการแก้ต้องไปเปลี่ยน prompt/schema ของ classifier ให้ร่างคำตอบทุกกรณี = นอกขอบเขต diff และเสี่ยงกับสัญญา LLM ที่ใช้อยู่ ส่วนความถี่จริง: `api_error` เกิด 1 ครั้งใน 117 records (3 สัปดาห์) มีเทส `test_api_error_with_slip_image_costs_two_vision_calls` pin ไว้แล้ว ถ้าวันหนึ่งอยากแก้จะเห็นทันทีว่าตัวเลขเปลี่ยน

---

## Task 1: ตอน EasySlip ให้คำตอบไม่ได้ ให้ถามสายตาก่อนกวนเจ้าของ

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:206-213` (config_error), `:219-225` (ConnectionException), `:254-262` (5xx), `:264-269` (JSON เพี้ยน)
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` — เพิ่ม private method ใหม่ก่อน `notifyAdmin()` (บรรทัด ~324)
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php`
- Test: `backend/tests/Feature/SlipVerificationPipelineTest.php`

**Interfaces:**
- Consumes: `$isSlipCheck: ?\Closure` — พารามิเตอร์ตัวที่ 6 ของ `verify()` ที่มีอยู่แล้ว คืน `?bool` (`true`=เป็นสลิป, `false`=ไม่ใช่, `null`=ไม่แน่ใจ)
- Produces: `private function apiUnavailable(Bot $bot, ?Conversation $conversation, ?Message $message, ?array $rawResponse, string $failReason, ?\Closure $isSlipCheck): SlipVerificationResult` — ใช้ภายในไฟล์เท่านั้น Task 2 ไม่ยุ่งด้วย

---

- [ ] **Step 1: เขียนเทสที่ต้องแดง — รูปทั่วไป + API ล่ม ต้องเงียบ**

เพิ่มท้ายไฟล์ `backend/tests/Feature/SlipVerificationServiceTest.php` (ก่อน `}` ปิดคลาส):

```php
    public function test_api_error_stays_silent_when_vision_says_not_a_slip(): void
    {
        // เคสจริง prod 27 ก.ค. (แชท #361, slip_verifications id=116): ลูกค้าส่ง screenshot
        // หน้าเพจ FB ตอน EasySlip timeout → เดิมเด้งการ์ด "ยืนยันยอด" หาเจ้าของทั้งที่ไม่ใช่สลิป
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/fb-page.jpg', $this->paymentHistory,
            fn (): ?bool => false,
        );

        $this->assertFalse($result->isSlip);
        $this->assertNull($result->failReason);
        $this->assertSame(0, SlipVerification::count());
    }
```

- [ ] **Step 2: รันเทสให้เห็นว่าแดง**

```bash
cd backend && php artisan test --filter=test_api_error_stays_silent_when_vision_says_not_a_slip
```

Expected: FAIL — `Failed asserting that 'api_error' is null` (โค้ดเดิม record + ตั้ง failReason เสมอ)

- [ ] **Step 3: เพิ่ม private helper `apiUnavailable()`**

ใน `backend/app/Services/Payment/SlipVerificationService.php` แทรกก่อน docblock ของ `notifyAdmin()` (บรรทัด ~321):

```php
    /**
     * EasySlip ให้คำตอบเรื่องรูปนี้ไม่ได้เลย (token หาย / API ล่ม / ตอบเพี้ยน)
     * → ระบบไม่รู้ด้วยซ้ำว่ารูปเป็นสลิปไหม จึงถามสายตา ($isSlipCheck) ก่อนกวนเจ้าของ
     *   false ชัดเจน = รูปทั่วไป (เช่น screenshot หน้าเพจ) → เงียบ ไม่ record ไม่ alert ปล่อยไป vision
     *   true / ไม่แน่ใจ / ถามไม่ได้ = ถือเป็นสลิป → record + ให้ปลายทาง alert (fail-safe เข้าข้างเงิน)
     */
    private function apiUnavailable(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        ?array $rawResponse,
        string $failReason,
        ?\Closure $isSlipCheck,
    ): SlipVerificationResult {
        if ($isSlipCheck !== null && $isSlipCheck() === false) {
            return new SlipVerificationResult(isSlip: false, passed: false);
        }

        return $this->record($bot, $conversation, $message, $rawResponse, new SlipVerificationResult(
            isSlip: false, passed: false, failReason: $failReason,
        ));
    }

```

- [ ] **Step 4: เปลี่ยน call site ที่ 1 — config_error (token หาย)**

แทนที่บล็อกเดิมที่บรรทัด ~206-213:

```php
        $token = $bot->user?->settings?->getEasySlipApiToken();
        if (! $token) {
            Log::warning('Slip verification enabled but EasySlip token missing', ['bot_id' => $bot->id]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'config_error',
            ));
        }
```

ด้วย:

```php
        $token = $bot->user?->settings?->getEasySlipApiToken();
        if (! $token) {
            Log::warning('Slip verification enabled but EasySlip token missing', ['bot_id' => $bot->id]);

            return $this->apiUnavailable($bot, $conversation, $message, null, 'config_error', $isSlipCheck);
        }
```

- [ ] **Step 5: เปลี่ยน call site ที่ 2 — ConnectionException (timeout)**

แทนที่บล็อกเดิมที่บรรทัด ~219-225:

```php
        } catch (ConnectionException $e) {
            Log::warning('EasySlip connection failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }
```

ด้วย:

```php
        } catch (ConnectionException $e) {
            Log::warning('EasySlip connection failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return $this->apiUnavailable($bot, $conversation, $message, null, 'api_error', $isSlipCheck);
        }
```

- [ ] **Step 6: เปลี่ยน call site ที่ 3 และ 4 — 5xx และ JSON เพี้ยน**

แทนที่บล็อกเดิมที่บรรทัด ~254-269:

```php
        if (! $response->successful()) {
            Log::warning('EasySlip API error', [
                'bot_id' => $bot->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500),
            ]);

            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $data = $response->json('data');
        if (! is_array($data) || empty($data['rawSlip']['transRef'])) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }
```

ด้วย:

```php
        if (! $response->successful()) {
            Log::warning('EasySlip API error', [
                'bot_id' => $bot->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500),
            ]);

            return $this->apiUnavailable($bot, $conversation, $message, $response->json(), 'api_error', $isSlipCheck);
        }

        $data = $response->json('data');
        if (! is_array($data) || empty($data['rawSlip']['transRef'])) {
            return $this->apiUnavailable($bot, $conversation, $message, $response->json(), 'api_error', $isSlipCheck);
        }
```

- [ ] **Step 7: รันเทสให้เห็นว่าเขียว**

```bash
cd backend && php artisan test --filter=test_api_error_stays_silent_when_vision_says_not_a_slip
```

Expected: PASS

- [ ] **Step 8: เขียนเทสฝั่งตรงข้าม — เป็นสลิปจริง / ไม่แน่ใจ ต้องยัง alert**

เพิ่มต่อท้ายเทสจาก Step 1 ในไฟล์เดียวกัน:

```php
    public function test_api_error_still_records_when_vision_says_slip(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/slip.jpg', $this->paymentHistory,
            fn (): ?bool => true,
        );

        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_api_error_records_when_vision_is_unsure(): void
    {
        // fail-safe: vision parse ไม่ได้/เรียกไม่ได้ (null) ต้องเข้าข้างเงิน = alert ไว้ก่อน
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/slip.jpg', $this->paymentHistory,
            fn (): ?bool => null,
        );

        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_missing_token_stays_silent_when_vision_says_not_a_slip(): void
    {
        // token หาย + รูปทั่วไป → ไม่ควรเด้ง config_error ทุกรูปที่ลูกค้าส่งมา
        $this->bot->user->settings->update(['easyslip_api_token' => null]);
        Http::fake();

        $result = app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/cat.jpg', $this->paymentHistory,
            fn (): ?bool => false,
        );

        $this->assertFalse($result->isSlip);
        $this->assertSame(0, SlipVerification::count());
        Http::assertNothingSent();
    }
```

- [ ] **Step 9: รันเทสทั้งไฟล์ — เทสใหม่เขียว เทสเดิมต้องไม่พัง**

```bash
cd backend && php artisan test --filter=SlipVerificationServiceTest
```

Expected: PASS ทั้งหมด รวมถึงเทสเดิม `test_server_error_is_api_error`, `test_connection_exception_is_api_error`, `test_missing_token_is_config_error_without_http_call` (ทั้งสามเรียก `verify()` โดยไม่ส่ง `$isSlipCheck` → ได้ `null` → เข้า fail-safe → พฤติกรรมเดิมทุกประการ)

- [ ] **Step 10: เขียนเทส end-to-end ว่าไม่ยิง Telegram**

เพิ่มใน `backend/tests/Feature/SlipVerificationPipelineTest.php` ต่อจาก `test_easyslip_api_error_falls_back_to_vision_and_alerts_admin()` (บรรทัด ~200):

```php
    public function test_api_error_with_non_slip_image_does_not_alert_admin(): void
    {
        // เคสจริง prod 27 ก.ค. แชท #361: screenshot หน้าเพจ FB + EasySlip timeout
        // → ต้องตอบลูกค้าตามบริบท และห้ามเด้งการ์ดหาเจ้าของ
        $this->enableTelegramAlert();
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['message' => 'server error'], 500),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_slip": false, "reply": "เรื่องนี้ต้องให้ทีม Support ดูครับ"}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ทีม Support', $ctx->response->payload);
        $this->assertDatabaseCount('slip_verifications', 0);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        // ตัดสิน+ตอบจบใน LLM call เดียว (draft ถูกใช้ต่อ) — ต้นทุน vision เท่าเดิมกับก่อนแก้
        $this->assertCount(1, Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai')));
    }
```

**หมายเหตุ:** ข้อความ reply ในเทสนี้ต้องไม่มีคำว่า `ได้รับสลิป` เพราะมี safety net อีกชั้นที่ `LineWebhookResponseService.php:310-316` ที่จะ alert เมื่อ vision ตอบแบบรับสลิป — ชั้นนั้นต้องยังทำงานอยู่ ห้ามแตะ

- [ ] **Step 11: รันเทส pipeline ทั้งไฟล์**

```bash
cd backend && php artisan test --filter=SlipVerificationPipelineTest
```

Expected: PASS ทั้งหมด — เทสเดิม `test_easyslip_api_error_falls_back_to_vision_and_alerts_admin` ยังเขียวเพราะ history ของมันมีข้อความสรุปยอด และ mock openrouter คืนข้อความธรรมดาที่ `decodeSlipCheck()` parse ไม่ได้ → `null` → fail-safe → ยัง alert

หากเทสเดิมตัวนั้นแดง ให้อ่าน assertion ที่แดงก่อน อย่าเพิ่งแก้เทส — รายงานกลับมา

- [ ] **Step 12: รันเทสกลุ่มสลิปทั้งหมดกันพลาด**

```bash
cd backend && php artisan test --filter='Slip|Payment|Delivery'
```

Expected: PASS ทั้งหมด

- [ ] **Step 13: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationServiceTest.php backend/tests/Feature/SlipVerificationPipelineTest.php
git commit -m "fix(payment): EasySlip ล่มแล้วอย่าเดาว่าทุกรูปคือสลิป ถามสายตาก่อนกวนเจ้าของ"
```

---

## Task 2: ยอดสลิปตรงกับข้อความ "ตะกร้า/ยืนยัน" ให้ผ่านอัตโนมัติ

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php:296-303` (เช็ค 3)
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php`

**Interfaces:**
- Consumes: `public function findExpectedFromConfirmMessage(array $conversationHistory, ?Bot $bot, float $confirmedAmount): ?array` (`SlipVerificationService.php:153`) — มีอยู่แล้ว ใช้อยู่ในเส้น manual confirm คืน `array{total: float, summary: string, items: array}` หรือ `null`
- Produces: ไม่มี API ใหม่ — เปลี่ยนเฉพาะพฤติกรรมภายใน `verify()`

**บริบท:** เคสจริง `slip_verifications` id=63 (แชท #92, 2,200 บาท) และ id=67 (แชท #1072, 1,100 บาท) — ลูกค้าขาประจำที่รู้เลขบัญชีอยู่แล้ว โอนทันทีหลังบอทพิมพ์ข้อความตะกร้า โดยไม่รอข้อความสรุปยอด+เลขบัญชี ทำให้ `findExpectedPayment()` (ซึ่งบังคับว่าต้องมีเลขบัญชีในข้อความ ดู `PaymentMessageDetector.php:18`) หาไม่เจอ → `no_pending_order` → กวนเจ้าของทั้งที่ข้อมูลครบอยู่แล้ว

ข้อความจริงของ id=67 คือ `"เพิ่ม Nolimit Level Up+ Personal แบบเติมเงิน 1 ตัวลงตะกร้าแล้วครับ\n\nรวม: 1,100 บาท\n\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"` ซึ่ง `isConfirmMessage()` (`PaymentMessageDetector.php:182-212`) match

**หมายเหตุความปลอดภัย:** `findExpectedFromConfirmMessage()` เช็คยอดกับ tolerance ให้แล้วภายใน (`SlipVerificationService.php:171`) และใช้ `requireItems: true` → ถ้าดึงรายการสินค้าไม่ได้จะคืน `null` แล้วตกกลับไปเป็น `no_pending_order` + alert แบบเดิม (ไม่แย่ลงกว่าวันนี้) ส่วนเช็ค 1 (บัญชีร้าน) และเช็ค 2 (สลิปซ้ำ) ยังทำงานก่อนหน้านี้ทุกครั้ง

---

- [ ] **Step 1: เขียนเทสที่ต้องแดง — ยอดตรงกับข้อความตะกร้า ต้องผ่าน**

เพิ่มท้ายไฟล์ `backend/tests/Feature/SlipVerificationServiceTest.php`:

```php
    public function test_slip_passes_against_cart_confirm_message_when_no_payment_summary(): void
    {
        // เคสจริง slip_verifications id=67 (แชท #1072): ลูกค้าขาประจำโอนทันทีหลังบอทพิมพ์
        // ข้อความตะกร้า โดยไม่รอข้อความสรุปยอด+เลขบัญชี → เดิมได้ no_pending_order + กวนเจ้าของ
        $this->paymentHistory = [
            ['sender' => 'user', 'content' => 'เอา Nolimit Personal 1 เฟสครับ'],
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit BM = 1,500 บาท\nรวม: 1,500 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'user', 'content' => 'ยืนยัน'],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertSame('Nolimit BM', $result->orderSummary);
        $this->assertDatabaseHas('slip_verifications', ['trans_ref' => 'TR100', 'status' => 'passed']);
    }
```

**ทำไมใช้รูปแบบ `1. ชื่อ = ราคา บาท` ไม่ใช่ prose แบบเคสจริง:** เทสไฟล์นี้ resolve service จาก container จริง → `LLMOrderItemExtractor` จะยิง OpenRouter จริงถ้า regex ดึง items ไม่ได้ ทำให้เทสไม่ deterministic รูปแบบนี้พิสูจน์แล้วว่า regex ดึงได้ (ดูเทสเดิม `test_valid_slip_passes_all_checks` ที่ได้ summary `'Nolimit BM'`) ส่วนเคส prose มี `Payment/ConfirmMessageFallbackTest::test_prose_confirm_uses_llm_extractor` คุมอยู่แล้วด้วย mock

- [ ] **Step 2: รันเทสให้เห็นว่าแดง**

```bash
cd backend && php artisan test --filter=test_slip_passes_against_cart_confirm_message_when_no_payment_summary
```

Expected: FAIL — `Failed asserting that false is true` (ได้ `no_pending_order` เพราะ `findExpectedPayment()` หาข้อความที่มีเลขบัญชีไม่เจอ)

- [ ] **Step 3: เพิ่ม fallback ที่เช็ค 3**

ใน `backend/app/Services/Payment/SlipVerificationService.php` แทนที่บล็อกเดิมที่บรรทัด ~296-303:

```php
        // เช็ค 3: ต้องมีออเดอร์ค้างชำระใน history
        $expected = $this->findExpectedPayment($conversationHistory, $configured, $bot);
        if ($expected === null) {
```

ด้วย:

```php
        // เช็ค 3: ต้องมีออเดอร์ค้างชำระใน history
        // fallback: ลูกค้าขาประจำที่รู้เลขบัญชีอยู่แล้วมักโอนทันทีหลังข้อความตะกร้า ไม่รอข้อความ
        // สรุปยอด+เลขบัญชี — อ่านยอดจากข้อความ "ตะกร้า/ยืนยัน" ที่บอทพิมพ์เองแทน (ยอดต้องตรง)
        $expected = $this->findExpectedPayment($conversationHistory, $configured, $bot)
            ?? $this->findExpectedFromConfirmMessage($conversationHistory, $bot, $slipAmount);
        if ($expected === null) {
```

- [ ] **Step 4: รันเทสให้เห็นว่าเขียว**

```bash
cd backend && php artisan test --filter=test_slip_passes_against_cart_confirm_message_when_no_payment_summary
```

Expected: PASS

- [ ] **Step 5: เขียนเทสขอบเขต — ยอดไม่ตรง / ไม่มีตะกร้า ต้องยังเป็น no_pending_order**

เพิ่มต่อท้ายในไฟล์เดียวกัน:

```php
    public function test_cart_confirm_with_different_amount_still_no_pending_order(): void
    {
        // ตะกร้า 900 แต่สลิป 1,500 → ห้ามผ่าน ต้องให้เจ้าของตัดสิน
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit BM = 900 บาท\nรวม: 900 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'no_pending_order']);
    }

    public function test_payment_summary_still_wins_over_cart_message(): void
    {
        // มีทั้งข้อความตะกร้า (900) และข้อความสรุปยอด+เลขบัญชี (1,500)
        // → ต้องใช้ข้อความสรุปยอดเป็นหลัก fallback ห้ามแย่งงาน
        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "เพิ่มลงตะกร้าแล้วครับ\n1. Nolimit Personal = 900 บาท\nรวม: 900 บาท\nถูกต้องไหมครับ? พิมพ์ “ยืนยัน” ได้เลย"],
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
        ];
        Http::fake(['api.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->passed);
        $this->assertSame('Nolimit BM', $result->orderSummary);
    }
```

- [ ] **Step 6: รันเทสทั้งไฟล์ — เทสเดิมต้องไม่พัง**

```bash
cd backend && php artisan test --filter=SlipVerificationServiceTest
```

Expected: PASS ทั้งหมด — เทสเดิม `test_no_pending_order_fails` ยังเขียวเพราะ history ของมันคือ `[['sender' => 'user', 'content' => 'สวัสดี']]` ซึ่งไม่มีข้อความ bot เลย → `findExpectedFromConfirmMessage()` คืน `null`

- [ ] **Step 7: รันเทสกลุ่มสลิป/ส่งของทั้งหมด**

```bash
cd backend && php artisan test --filter='Slip|Payment|Delivery'
```

Expected: PASS ทั้งหมด — โดยเฉพาะ `ConfirmMessageFallbackTest` (ทดสอบ `findExpectedFromConfirmMessage()` โดยตรง) และ `ReserveAccountStockDispatchTest` (เส้นทาง auto-ส่งของที่ตอนนี้เคส cart จะไหลเข้าไปด้วย)

- [ ] **Step 8: รันเทสทั้ง suite**

```bash
cd backend && composer test
```

Expected: PASS ทั้งหมด ถ้ามีตัวแดงที่ไม่เกี่ยวกับสลิป ให้รายงานกลับมาก่อนแก้

- [ ] **Step 9: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationServiceTest.php
git commit -m "feat(payment): ยอดสลิปตรงกับข้อความตะกร้าให้ผ่านเอง ไม่ต้องรอเจ้าของกดยืนยัน"
```

---

## หลัง execute เสร็จ (ผู้ตรวจทำ ไม่ใช่ worker)

- [ ] รัน `/simplify` ก่อน push ตามกติกาโปรเจกต์
- [ ] Deploy แล้วเฝ้า 2 อย่าง:
  - `slip_verifications` ที่ `status='api_error'` ควรเกิดน้อยลงหรือเท่าเดิม แต่ต้องไม่มีการ์ด Telegram ตามมาสำหรับรูปที่ไม่ใช่สลิป
  - `status='no_pending_order'` ควรลดลง และเคสที่หายไปต้องกลายเป็น `passed` พร้อม `order_items` ครบ (ไม่ใช่ `ออเดอร์: -`)
- [ ] Query ตรวจหลัง deploy 3-7 วัน:
  ```sql
  SELECT status, COUNT(*) FROM slip_verifications
  WHERE created_at >= '<วันที่ deploy>' GROUP BY status ORDER BY 2 DESC;
  ```
