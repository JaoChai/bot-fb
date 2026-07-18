# Telegram Delivery Card Delay — ให้การ์ดปุ่มมาหลังข้อความออเดอร์ใหม่

**วันที่:** 2026-07-18
**สถานะ:** อนุมัติ design แล้ว (เจ้าของเลือกแนวทาง A)

## ปัญหา

ใน Telegram ตอนสลิปผ่าน ข้อความมาสลับลำดับ:

1. **การ์ดปุ่ม** "🚚 พร้อมส่งสินค้า" (+ ปุ่ม ✅ ส่งให้ลูกค้าเลย / ↩️ ยกเลิก คืนเข้า stock) มาก่อน
2. **ข้อความ "🛒 ออเดอร์ใหม่!"** (รายละเอียดออเดอร์จาก Telegram plugin) ตามมาทีหลัง

เจ้าของต้องการให้การ์ดปุ่มอยู่ **ล่าง (หลัง)** ข้อความออเดอร์ใหม่ เพื่อให้ปุ่มอยู่ท้ายสุดของแชท กดง่าย

## สาเหตุ (ตรวจโค้ดแล้ว)

สองข้อความมาจากคนละเส้นทางที่วิ่งแข่งกัน:

- **การ์ดปุ่ม:** `ReserveAccountStock` ถูก dispatch เข้า queue ทันทีที่สลิปผ่าน
  (`LineWebhookResponseService::handleSlipImage` ~line 574) → job จองสต๊อก → `AccountDeliveryService::sendCard()`
  — queue worker หยิบไปทำภายในไม่กี่วินาที
- **ข้อความออเดอร์ใหม่:** ส่งทีหลังใน `FlowPluginService::executePlugins()` ซึ่งถูกเรียกหลังจาก
  ตอบลูกค้าเสร็จ (`LineWebhookOutputService`)

Queue เร็วกว่า → การ์ดปุ่มแซงหน้า

พฤติกรรมเดียวกันเกิดทั้ง 3 เส้นทางที่สร้างงานส่งของ เพราะทุกที่เรียกผ่าน
`ReserveAccountStock::dispatchSafely()` เหมือนกัน:

1. สลิปผ่านปกติ — `LineWebhookResponseService`
2. เจ้าของกดยืนยันรับเงินใน Telegram — `ManualPaymentConfirmService`
3. Auto-retry สลิป pending — `SlipRetryService`

## แนวทางที่เลือก (A — หน่วงทั้ง job)

แก้ที่ `ReserveAccountStock::dispatchSafely()` จุดเดียว: dispatch แบบ delay

```php
self::dispatch(...)->delay(now()->addSeconds((int) config('delivery.card_delay_seconds', 15)));
```

- เพิ่ม config `delivery.card_delay_seconds` ใน `backend/config/delivery.php`
  (default `15`, override ได้ทาง env `ACCOUNT_DELIVERY_CARD_DELAY_SECONDS`)
- 15 วินาทีนานพอให้ `executePlugins` ส่ง "ออเดอร์ใหม่!" ไปก่อนเสมอ
  (ข้อความนั้นส่ง synchronous ภายในไม่กี่วินาทีหลังตอบลูกค้า)

### แนวทางที่ตัดทิ้ง

- **B — จองสต๊อกทันที หน่วงเฉพาะการ์ด:** ต้องเพิ่ม job class ใหม่ + แก้ `createFromPayment`
  แลกกับปิดช่องแย่งสต๊อก 15 วิ ซึ่งความเสี่ยงจริงต่ำมาก (ปริมาณออเดอร์ไม่ถี่ +
  มี fail-safe "⚠️ ของหมด ต้องส่งเอง" อยู่แล้ว) — ไม่คุ้มความซับซ้อน
- **บังคับลำดับเชิงโครงสร้าง (ส่งการ์ดต่อท้าย executePlugins):** ต้องรื้อ flow ข้าม service
  3 เส้นทาง ซับซ้อนเกินโจทย์

## Trade-off ที่ยอมรับ

- การจองสต๊อกเลื่อนไป ~15 วิ → มีช่องแคบๆ ที่ออเดอร์อื่นแย่งของชิ้นเดียวกันได้
  ยอมรับเพราะ: ออเดอร์ไม่ถี่, เดิมก็ async อยู่แล้ว (ต่างกันไม่กี่วิ), และมี fail-safe ของหมด
- การ์ดปุ่มถึงมือเจ้าของช้าลง ~15 วิ — เจ้าของยืนยันว่ารับได้

## สิ่งที่ไม่กระทบ

- การ์ดเตือนซ้ำ (`RemindPendingDeliveries`) — เรียก `sendCard` ตรง ไม่ผ่าน job นี้
- `delivery:reconcile`, ปุ่ม callback (`TelegramAlertCallbackController`), เนื้อหาข้อความทั้งสองแบบ

## Testing

- Unit/Feature: `Queue::fake()` + assert ว่า `ReserveAccountStock` ถูก dispatch พร้อม delay
  ตรงกับ config (และ delay เปลี่ยนตาม config ที่ override)
- E2E หลัง deploy: ดูลำดับข้อความจริงใน Telegram — "ออเดอร์ใหม่!" ต้องมาก่อนการ์ดปุ่ม
