# Design: Auto-retry ตรวจสลิปเมื่อเจอ SLIP_PENDING

**วันที่:** 2026-07-16
**สถานะ:** อนุมัติ design แล้ว รอเขียน implementation plan

## ปัญหา

เมื่อลูกค้าส่งสลิปที่เพิ่งโอน (ไม่ถึง ~5 นาที) EasySlip ยังหาธุรกรรมในระบบธนาคารไม่เจอ → คืน HTTP 404 `SLIP_PENDING`
พฤติกรรมปัจจุบัน (`LineWebhookResponseService::trySlipVerification`, branch `pending`):

- บอทตอบลูกค้า: *"สลิปเพิ่งโอนมา ธนาคารกำลังประมวลผลครับ 🙏 รบกวนรอ 1-2 นาทีแล้วส่งสลิปเดิมมาอีกครั้ง"*
- **ไม่ตรวจซ้ำ ไม่แจ้ง Telegram แอดมิน ไม่จองของ** (โดยตั้งใจ — comment เดิมบอกว่า "ลูกค้าแก้เองได้")

**ช่องโหว่:** ถ้าลูกค้าไม่ส่งสลิปซ้ำ ออเดอร์หายเงียบ — ไม่มีใครรู้ ทั้งที่เงินอาจโอนเข้าจริงแล้ว (ธนาคารแค่ยังไม่ขึ้นธุรกรรมตอนที่ตรวจ)

**เคสจริง:** แชท #1367 (16 ก.ค. 2026), slip_verification id=52, raw_response = `SLIP_PENDING` จากธนาคารกรุงเทพ

## กุญแจที่ทำให้แก้ได้

`message.media_url` ของรูปสลิปเป็น **Cloudflare R2 URL ถาวร** (`https://pub-...r2.dev/line/...`) ไม่ใช่ LINE content URL ชั่วคราว
→ ระบบ re-verify รูปเดิมกับ EasySlip ซ้ำเองได้ โดยลูกค้าไม่ต้องส่งใหม่

## Flow ใหม่

```
ลูกค้าส่งสลิป → EasySlip = PENDING
  ├─ บอทตอบ: "ระบบกำลังตรวจให้อัตโนมัติ รอสักครู่" (ไม่ขอให้ส่งซ้ำ)
  └─ dispatch RetrySlipVerification (delayed) attempt=1
        ⤷ +90วิ  → verify() ซ้ำ (รอบ 1, ~t+1.5น)
        ⤷ +180วิ → verify() ซ้ำ (รอบ 2, ~t+4.5น)
        ⤷ +300วิ → verify() ซ้ำ (รอบ 3, ~t+9.5น)
     ผลลัพธ์แต่ละครั้ง:
        ✅ PASSED   → success side-effects (จองของ + ตอบ "เงินเข้าแล้ว" + สร้างออเดอร์ ผ่าน LINE push)
        ⏳ PENDING  → ถ้ายังไม่ครบ re-dispatch ครั้งถัดไป / ครบ 3 ครั้ง → แจ้ง Telegram แอดมิน + ปุ่มยืนยันมือ
        ❌ FAIL อื่น → ตอบลูกค้า (fail message) + แจ้งแอดมิน (เหมือน path เดิม)
```

## Components

### 1. จุด trigger — `LineWebhookResponseService::trySlipVerification`

branch `failReason === 'pending'` (บรรทัด ~541):

- เปลี่ยนข้อความ `SLIP_PENDING_TEMPLATE` → ไม่ขอให้ลูกค้าส่งซ้ำ เช่น:
  > "สลิปเพิ่งโอน ธนาคารกำลังประมวลผลครับ 🙏 ระบบจะตรวจให้อัตโนมัติใน 1-2 นาที รอสักครู่นะครับ"
- Dispatch `RetrySlipVerification::dispatch(botId, conversationId, messageId, imageUrl, attempt: 1)->delay(delays[0])`
  - gated ด้วย `config('delivery.pending_retry.enabled')`
  - `imageUrl` = `$imageUrl` ที่มีอยู่แล้วใน scope (R2 URL)

### 2. Job ใหม่ `App\Jobs\RetrySlipVerification` (ShouldQueue, tries=1)

`__construct(int $botId, int $conversationId, int $messageId, string $imageUrl, int $attempt)`

`handle()`:

1. Load bot + conversation + message; ถ้าหายให้ return เงียบ
2. **Dedup guard:** ถ้ามี `SlipVerification` ของ conversation นี้ status ∈ (`passed`,`manual_confirmed`) ที่ created หลังแถว pending เดิม → return (ลูกค้าส่งซ้ำผ่านเอง / แอดมินยืนยันมือไปแล้ว) กันออเดอร์ซ้ำ
3. Rebuild recent text history (ใช้ logic เดียวกับ `ManualPaymentConfirmService::recentTextHistory`)
4. เรียก `SlipVerificationService::verify(bot, conversation, message, imageUrl, history)` ซ้ำ
5. แตกผล:
   - `passed` → เรียก success emitter (ข้อ 3)
   - `pending` → `attempt < count(delays)` ? re-dispatch attempt+1 delay ถัดไป : `notifyAdmin` (backstop message "ตรวจซ้ำ N ครั้งยัง pending — รบกวนตรวจมือ") + ปุ่มยืนยัน
   - fail อื่น (`fake`/`amount_mismatch`/`wrong_account`/`duplicate`/`no_pending_order`) → push fail message ให้ลูกค้า + `notifyAdmin`
   - `api_error`/`config_error` → ถ้ายังไม่ครบ retry (ธนาคาร/EasySlip ล่มชั่วคราว) : re-dispatch ; ครบ → `notifyAdmin`

> หมายเหตุ: `verify()` จะ `record()` แถว slip_verification ใหม่ทุกครั้งที่เรียก (เป็นพฤติกรรมเดิม) — แถว pending เก่าคงอยู่ตามเดิม แถวใหม่คือผลรอบ retry

### 3. Success side-effects (ตอน retry ผ่าน)

ทำงานนอก webhook → ต้อง **push** (ไม่มี reply token) ไม่ใช่ reply
**เขียนแยกใน job/service ใหม่ ไม่แตะ `ManualPaymentConfirmService`** (surgical, เลี่ยงความเสี่ยงกับ payment path ที่ทำงานอยู่) โดย reuse building blocks เดิม:

- สร้าง bot message (`slip_status = passed`, ผูก slipVerificationId)
- `PaymentFlexService::tryConvertToFlex` + `LINEService::replyWithFallback` (push) — mirror `ManualPaymentConfirmService::pushToLine`
- `FlowPluginService::executePlugins` (Telegram + OrderService สร้างออเดอร์) — mirror `runPlugins`
- `ReserveAccountStock::dispatchSafely(botId, convId, slipVerificationId, amount, orderItems)`
- broadcast `MessageSent` + `ConversationUpdated`

ยอมรับ duplication ~30 บรรทัดจาก `ManualPaymentConfirmService` โดยตั้งใจ เพื่อไม่แตะโค้ด payment ที่ทำงานอยู่

### 4. Config — เพิ่มใน `config/delivery.php`

```php
'pending_retry' => [
    'enabled' => (bool) env('SLIP_PENDING_RETRY_ENABLED', true),
    // วินาที: ระยะ "รอก่อนตรวจแต่ละรอบ" (incremental จากจุด dispatch ครั้งนั้น) ไม่ใช่ offset สะสม
    // จำนวน element = จำนวนรอบ retry; ตรวจครบทุกรอบยัง pending → แจ้งแอดมิน
    'delays'  => [90, 180, 300],  // verify ที่ ~t+1.5น, +4.5น, +9.5น จากรับสลิป
],
```

**ความหมาย `delays` (กันกำกวม):** `delays[i]` = จำนวนวินาทีที่ job หน่วงก่อน verify รอบที่ `i+1` นับจากตอน dispatch job นั้น
`attempt` เริ่มที่ 1 → dispatch แรกใช้ `delays[0]`; ใน `handle()` ถ้ายัง pending และ `attempt < count(delays)` → re-dispatch `attempt+1` ด้วย `delays[attempt]`; ถ้า `attempt === count(delays)` → แจ้งแอดมิน

## Idempotency / Race handling (ใช้ guard เดิมที่มีอยู่)

| Race | กันด้วย |
|---|---|
| ลูกค้าส่งสลิปซ้ำ + retry ผ่านพร้อมกัน | `verify()` เช็ค `duplicate` (trans_ref เดียวกัน + มี status=passed อยู่แล้ว) |
| แอดมินกดยืนยันมือระหว่างรอ retry | dedup guard ใน job (เห็น `manual_confirmed`/`passed` หลัง pending → หยุด) |
| จองของซ้ำข้าม path | `config('delivery.dedup_window_minutes')` เดิม (block ยอดเดียวกัน conversation เดิมในหน้าต่างเวลา) |

## Test plan

- **pending → passed:** verify() รอบแรก pending (dispatch job) → รอบ retry passed → assert `ReserveAccountStock` dispatched + success message ถูก push + bot message `slip_status=passed`
- **pending ครบ 3 ครั้ง:** ทุกรอบ pending → assert `notifyAdmin` ถูกเรียกหลังครบ + **ไม่มี** reservation
- **customer resent & passed ก่อน retry:** มี slip `passed` ใหม่ก่อน job รัน → assert job no-op (dedup) ไม่จองซ้ำ
- **retry ได้ fail อื่น (fake):** assert fail message push + notifyAdmin + ไม่จองของ
- **feature-flag off:** `pending_retry.enabled=false` → พฤติกรรมเดิม (ไม่ dispatch, ข้อความ pending เดิม) — คง fallback ปลอดภัย

## Out of scope

- ไม่แตะ path fail อื่นของ EasySlip (fake/amount_mismatch/ฯลฯ) นอกจากเมื่อเจอตอน retry
- ไม่ refactor `ManualPaymentConfirmService`
- ไม่เพิ่มหน้า UI — เป็น backend job ล้วน
