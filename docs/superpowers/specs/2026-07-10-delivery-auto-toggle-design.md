# สวิตช์เปิด/ปิดส่งของ Auto รายบอท (Delivery Auto Toggle)

> สถานะ: design อนุมัติแล้ว 2026-07-10 · ต่อยอดจากระบบ Auto Account Delivery (PR #215)
> เงื่อนไขเวลา: **เริ่มเขียนโค้ดหลัง PR #216–218 (remediation blockers) merge เข้า main** — แตะ `createFromPayment` เมธอดเดียวกัน

## เป้าหมาย

เจ้าของเปิด/ปิดการส่งบัญชีอัตโนมัติได้เองจากหน้าเว็บ ทันที ไม่ต้อง redeploy
เมื่อปิด: ระบบกลับไปเหมือนก่อนมีฟีเจอร์ส่งของ — การ์ดแจ้งรับเงินใน Telegram เด้งตามปกติ แล้วเจ้าของส่งของเอง

## การตัดสินใจหลัก (ถามเจ้าของแล้ว)

1. **ที่วางสวิตช์:** หน้าเว็บ Connection Settings (`EditConnectionPage`) — ไม่ทำคำสั่ง Telegram
2. **พฤติกรรมตอนปิด:** ปิดสนิท — ไม่จอง stock ไม่ส่งการ์ดส่งของ (ไม่ใช่แบบ "จองแต่ไม่ส่ง")
3. **ขอบเขต:** สวิตช์แยกรายบอท (คอลัมน์บนตาราง `bots`) ตาม pattern `auto_handover`/`kb_enabled`

## พฤติกรรม

- **เปิด:** จ่ายเงินผ่าน → จองของ → การ์ดส่งของ Telegram พร้อมปุ่ม ✅/↩️ (ตาม PR #215)
- **ปิด:** `createFromPayment` คืน `null` ตั้งแต่ gate — ไม่แตะ stock ไม่มีการ์ดส่งของ; การ์ดแจ้งรับเงินเดิม (PR #200/#201) ไม่เกี่ยวกับสวิตช์นี้ ยังเด้งเสมอ
- **ปิดกลางคัน:** มีผลเฉพาะออเดอร์ใหม่ — งานที่จองไว้แล้ว การ์ดเดิม/ปุ่ม/ตัวเตือน 30 นาที ทำงานต่อจนเคลียร์ (ส่งหรือคืน stock)
- **ลำดับ gate:** `config('delivery.enabled')` (env, master kill switch ฝั่ง dev) **AND** `$bot->auto_delivery_enabled` (สวิตช์รายวันของเจ้าของ)
- **ตัด `ACCOUNT_DELIVERY_BOT_IDS`:** คอลัมน์รายบอทแทนหน้าที่เลือกบอทแล้ว — ลบออกจาก `config/delivery.php` + จุดอ่านทั้งหมด

## จุดที่แก้ (ตาม pattern `auto_handover` ทุกจุด)

| ไฟล์ | งาน |
|---|---|
| migration ใหม่ | `bots.auto_delivery_enabled` boolean NOT NULL default `false` |
| `app/Models/Bot.php` | fillable + casts `'boolean'` |
| `app/Services/Delivery/AccountDeliveryService.php` (gate ใน `createFromPayment`) | `config('delivery.enabled') && $bot->auto_delivery_enabled` |
| `app/Services/Payment/SlipVerificationService.php` (gate dedup จาก PR #217, ~บรรทัด 252) | เปลี่ยนเงื่อนไข `bot_ids` → `$bot->auto_delivery_enabled` แบบเดียวกัน |
| `app/Console/Commands/ReconcileDeliveries.php` (fallback หา telegram plugin, ~บรรทัด 106) | เปลี่ยนจากวนตาม `config('delivery.bot_ids')` → วน `Bot::where('auto_delivery_enabled', true)` |
| `app/Http/Requests/Bot/StoreBotRequest.php` | `'auto_delivery_enabled' => ['nullable', 'boolean']` |
| `app/Http/Requests/Bot/UpdateBotRequest.php` | `'auto_delivery_enabled' => ['sometimes', 'boolean']` |
| `app/Http/Resources/BotResource.php` | expose ค่า (default `false`) |
| `frontend/src/pages/EditConnectionPage.tsx` | สวิตช์ "ส่งของอัตโนมัติ" ใกล้สวิตช์ auto handover เดิม + ส่งค่าใน form |
| `config/delivery.php` | ลบ `bot_ids` |

ไม่มี error handling พิเศษ — gate เป็น boolean check ธรรมดา ค่า default ฝั่ง DB = ปิด (ปลอดภัยโดยธรรมชาติ: บอทใหม่/บอทเก่าไม่ส่งของจนกว่าจะเปิดเอง)

## การทดสอบ

1. บอทเปิดสวิตช์ + env เปิด → เกิด `AccountDelivery` + จองของ
2. บอทปิดสวิตช์ → `createFromPayment` คืน `null`, ไม่มีแถว delivery, ไม่แตะ stock pool
3. env `ACCOUNT_DELIVERY_ENABLED=false` → ปิดหมดแม้สวิตช์บอทเปิด
4. Test เดิมของ delivery ทั้งชุดผ่าน — แก้ setup ทุกไฟล์ที่ใช้ `config(['delivery.bot_ids' => ...])` (AccountDeliveryCreateTest, SlipVerificationServiceTest, ReserveAccountStockDispatchTest, ReconcileDeliveriesTest, StockPoolConnectionTest) เป็นเซ็ตคอลัมน์บอทแทน
5. Reconcile fallback: ไม่มีงานค้างแต่มีบอทเปิดสวิตช์ → ยังหา telegram plugin เจอ
6. API: update bot ด้วย `auto_delivery_enabled` แล้วค่าถูกบันทึก + BotResource ส่งค่ากลับ

## นอกขอบเขต (YAGNI)

- คำสั่ง/ปุ่มเปิดปิดใน Telegram
- สวิตช์ราย "ประเภทสินค้า" (ตัวเลือกอยู่ที่ `product_stocks.delivery_method` อยู่แล้ว)
- ประวัติการสลับสวิตช์ (audit log)
