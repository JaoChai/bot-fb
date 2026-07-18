# Design: Confirm-Message Fallback สำหรับ Manual Payment Confirm

วันที่: 2026-07-18
สถานะ: อนุมัติแล้ว (เจ้าของ approve ในแชท)

## ปัญหา (root cause จากเคสจริง delivery #38, แชท #92)

ลูกค้าเก่ารู้เลขบัญชีอยู่แล้ว โอนเงิน+ส่งสลิป **ก่อน**ที่บอทจะส่งข้อความสรุปยอด+เลขบัญชี
(ลูกค้าไม่พิมพ์ "ยืนยัน" หลังข้อความยืนยันขั้น 2) ทำให้:

1. `SlipVerificationService::findExpectedPayment()` หาข้อความบอทที่มีเลขบัญชี+คำว่า
   รวมยอดโอนไม่เจอ (ข้อความรอบซื้อก่อนหน้าถูก `context_cleared_at` ตัดออกจาก window แล้ว)
2. EasySlip ตก `no_pending_order` → แจ้ง Telegram ให้ตรวจมือ (ถูกต้องตาม design)
3. เจ้าของกดปุ่มยืนยัน → `ManualPaymentConfirmService::confirm()` parse หา items อีกรอบ
   ก็ไม่เจอ → `expected = null` → summary = '-', items = []
4. `ReserveAccountStock` dispatch ด้วย items ว่าง → delivery สร้างแบบ 0 items →
   สถานะ `failed` → การ์ด "❌ ไม่มีรายการที่ส่งอัตโนมัติได้"

จุดเจ็บ: ข้อความยืนยันขั้น 2 ของบอท ("Nolimit Level Up+ Personal แบบผูกบัตร 2 ตัว
รวม 2,200 บาท ถูกต้องไหมครับ?") **มีข้อมูลออเดอร์ครบ** แต่ parser ไม่พิจารณาข้อความนี้

## ขอบเขต (ตัดสินใจโดยเจ้าของ)

- Fallback ทำงาน **เฉพาะ path เจ้าของกดยืนยันรับเงิน** (ปุ่ม Telegram / หน้าเว็บ)
- **ไม่แตะ** EasySlip auto-verify — เคสลูกค้าโอนข้ามขั้นตอนยังต้องมีคนกดยืนยันเสมอ
- แนวทาง A (parse ข้อความยืนยันขั้น 2) — ไม่ทำ state ลง DB (แนวทาง B) และไม่แก้ prompt
  อย่างเดียว (แนวทาง C)

## การเปลี่ยนแปลง

### 1. `SlipVerificationService` — เพิ่ม method ใหม่

```
findExpectedFromConfirmMessage(
    array $conversationHistory,   // shape เดียวกับ findExpectedPayment
    ?Bot $bot,
    float $confirmedAmount,       // ยอดที่เจ้าของกดยืนยัน
): ?array                          // {total, summary, items} shape เดิม หรือ null
```

พฤติกรรม:

- สแกน history จากใหม่ไปเก่า เอาเฉพาะข้อความ `sender = bot`
- เข้าเกณฑ์เมื่อ `PaymentMessageDetector::isConfirmMessage()` เป็นจริง (detector มีอยู่แล้ว)
- ดึงยอด+รายการด้วย `parseConfirmData()` (มีอยู่แล้ว)
- **Amount guard:** ยอดจากข้อความต้องตรงกับ `$confirmedAmount` ภายใน
  `slip_amount_tolerance` ของบอท (default 0) — ไม่ตรง → ข้ามข้อความนั้น หาต่อ
- ถ้า regex ดึง items ไม่ออก (prose) → เรียก `LLMOrderItemExtractor::extract()` ตัวเดิม
  ภายใต้ config flag `delivery.llm_item_fallback_enabled` เดิม (cost guard เดิม)
- ไม่เจอข้อความเข้าเกณฑ์เลย หรือได้ items ว่าง → คืน null

### 2. `ManualPaymentConfirmService::confirm()` — ต่อท่อ fallback

- หลัง `findExpectedPayment()` คืน null และ resolve `$amount` ได้ (จาก override):
  เรียก `findExpectedFromConfirmMessage($history, $bot, $amount)`
- ได้ผลลัพธ์ → ใช้ `summary` ในข้อความ "เงินเข้าแล้ว {amount} ออเดอร์: {summary}"
  และใช้ `items` ส่งเข้า `ReserveAccountStock::dispatchSafely()`
- fallback คืน null → พฤติกรรมเดิมทุกอย่าง (summary '-', items [] → การ์ดบอกส่งเอง)
- กรณี `findExpectedPayment()` เจอปกติ → ไม่เรียก fallback เลย (พฤติกรรมเดิม 100%)
- กรณีไม่มี `$amountOverride` และ `findExpectedPayment()` คืน null → ยังคง throw
  `NoPendingPaymentException` เหมือนเดิม (fallback ต้องมียอดยืนยันไว้ทำ amount guard
  เสมอ — ไม่เดายอดจากข้อความแชทเอง)

### สิ่งที่ไม่แตะ

- `SlipVerificationService::verify()` (EasySlip check 1–4)
- `SlipRetryService`, `AccountDeliveryService`, `ReserveAccountStock`
- การ์ด/ปุ่ม Telegram (`TelegramAlertCallbackController`, `notifyAdmin`)
- DB schema — ไม่มี migration

## Error handling

- fallback ล้มเหลวทุกกรณี → เท่ากับพฤติกรรมปัจจุบัน (ไม่มีทางแย่กว่าเดิม)
- LLM extractor พัง/timeout → ปฏิบัติเหมือน items ว่าง (คืน null) — ห้าม throw
  ออกไปทำให้ confirm ล้ม
- Log ระดับ info เมื่อ fallback ถูกใช้และผลลัพธ์ (เจอ/ไม่เจอ/ยอดไม่ตรง) เพื่อตามเคสจริงได้

## การทดสอบ

Unit (`SlipVerificationServiceTest` หรือไฟล์ใหม่):
1. เจอข้อความยืนยันขั้น 2 ยอดตรง + regex ดึง items ได้ → คืน items
2. ยอดไม่ตรง (ต่าง > tolerance) → ข้าม/คืน null
3. prose ดึงไม่ออก → เรียก LLM extractor (mock) → ได้ items
4. LLM คืนว่าง/throw → คืน null ไม่ throw
5. ไม่มีข้อความเข้าเกณฑ์ → คืน null

Feature (`ManualPaymentConfirmTest`):
6. จำลองเคส #38: history แบบแชท #92 (มีข้อความยืนยันขั้น 2 แต่ไม่มีสรุปยอด+เลขบัญชี)
   + confirm ด้วย override 2200 → dispatch `ReserveAccountStock` พร้อม items ไม่ว่าง
   และข้อความลูกค้าแสดง "ออเดอร์:" ไม่ใช่ '-'
7. Regression: ไม่มีข้อความยืนยันเลย → พฤติกรรมเดิม (items ว่าง)

Test เดิมทั้งหมดที่เกี่ยวต้องผ่าน (ManualPaymentConfirm, SlipVerification*, AccountDelivery*)

## เกณฑ์สำเร็จ

เคสแบบ #38 เกิดซ้ำ → หลังเจ้าของกดยืนยัน การ์ด Telegram ต้องแสดงรายการสินค้า
พร้อมปุ่ม "✅ ส่งให้ลูกค้าเลย" (จอง stock สำเร็จ) แทนข้อความ "ไม่มีรายการที่ส่งอัตโนมัติได้"
