# ใบเตือนงานส่งของไม่มีปุ่ม — ให้มีปุ่มชุดเดียวต่อ 1 งาน

วันที่: 2026-08-12
สถานะ: spec (รออนุมัติก่อนทำแผน)

## ปัญหา

เจ้าของเจอว่าใน Telegram มีปุ่มส่งของค้างอยู่หลายชุดต่องานเดียว — กดส่งของจากใบหนึ่งแล้ว
อีกใบยังมีปุ่มอยู่ เสี่ยงกดซ้ำโดยไม่ตั้งใจ

### ต้นเหตุ (ตรวจแล้วจากโค้ด)

- การ์ดปุ่มส่งของถูกส่งเป็น **ข้อความใหม่ทุกครั้ง**
  - ใบแรกตอนสร้างงาน: `SendDeliveryCard` → `AccountDeliveryService::sendCard()`
  - ใบเตือนซ้ำทุกรอบ: `RemindPendingDeliveries.php:45` → `sendCard()` ตัวเดียวกัน
  - ทุกใบแนบ `cardKeyboard()` (`AccountDeliveryService.php:252`) = ปุ่ม `dv` / `dx` ชุดเดิม
- ตอนกดปุ่ม ระบบ `editMessageText` **เฉพาะใบที่ถูกกด** (`TelegramAlertCallbackController.php:209`)
  ใบอื่นจึงยังคงมีปุ่มค้างอยู่ในกลุ่ม
- เตือนกี่รอบ = ปุ่มค้างเพิ่มอีกกี่ชุด

### สิ่งที่ยังปลอดภัยอยู่ (อย่าเข้าใจผิดว่าข้อมูลเสียหาย)

การกดปุ่มบนใบที่ค้าง **ไม่ทำให้ส่งของซ้ำหรือคืน stock ผิด**:
`deliver()` และ `cancel()` ล็อกแถวด้วย `lockForUpdate()` แล้วเช็คสถานะ ถ้าไม่ใช่ `reserved`
จะโยน `DeliveryAlreadyHandledException` แล้วแก้การ์ดใบนั้นเป็น "งาน #N ถูกจัดการไปแล้ว"
(`AccountDeliveryService.php:332-340`, `TelegramAlertCallbackController.php:213-216`)

**ปัญหานี้จึงเป็นเรื่อง UX/ความเชื่อมั่นของผู้ใช้ ไม่ใช่ data integrity** — และการแก้ครั้งนี้
ต้องไม่ไปแตะ guard ชั้นนั้น

## ทางออกที่เลือก

**"อย่าสร้างปุ่มใบที่สอง" แทนการไล่ปิดปุ่มทีหลัง** — ใบเตือนซ้ำเปลี่ยนเป็นข้อความสั้นไม่มีปุ่ม
ที่ reply (quote) การ์ดใบแรก เจ้าของแตะ quote เพื่อกระโดดขึ้นไปกดที่การ์ดใบเดียวของงานนั้น

ทางเลือกที่พิจารณาแล้วไม่เอา:
- **เก็บ message_id ทุกใบแล้ววนปิดปุ่มตอนกด** — แก้อาการไม่แก้ต้นเหตุ, ยิง Telegram หลายครั้งต่อการกด 1 ที,
  ถ้า edit ใบไหนพลาดปุ่มใบนั้นก็ยังค้างเหมือนเดิม
- **การ์ดหมุนเวียน (ลบใบเก่าก่อนส่งใบใหม่)** — ประวัติการเตือนหายจากกลุ่ม และ Telegram ลบข้อความได้
  ในกรอบ 48 ชม. เท่านั้น

ข้อแลกเปลี่ยนที่ยอมรับ: เจ้าของกดจากใบเตือนตรงๆ ไม่ได้อีก ต้องแตะ quote ขึ้นไปกดที่การ์ด (+1 แตะ)

## การเปลี่ยนแปลง

### 1. เก็บเลขข้อความของการ์ดใบแรก

- migration: `account_deliveries.card_message_id` — `bigInteger`, nullable
- `TelegramAlertBotService::sendMessage()` เปลี่ยน return จาก `bool` → `?array`
  (ค่า `result` จาก Telegram ซึ่งมี `message_id`; `null` = ส่งไม่สำเร็จ)
  - **ทำไมไม่ใช่ `?int`**: จะแยกไม่ออกระหว่าง "ส่งไม่สำเร็จ" กับ "ส่งสำเร็จแต่ response ไม่มี
    message_id" — เทสต์ทั้งระบบ 15 ไฟล์ fake ด้วย `['ok' => true]` เปล่าๆ จะกลายเป็นล้มเหลวหมด
  - private `call()` เปลี่ยน return จาก `bool` → `?array` (`result` จาก response, `[]` ถ้าไม่มี;
    `null` = ล้มเหลว) · ผู้เรียกต้องเทียบด้วย `!== null` ห้ามใช้ truthiness เพราะ `[]` เป็น falsy
  - `editMessageText` / `answerCallbackQuery` ไม่สนใจค่าคืน — ไม่ต้องแก้ signature
  - `setWebhook` ไม่ได้ใช้ `call()` (มี `Http::` ของตัวเอง) — ไม่กระทบ
  - ผู้เรียก `sendMessage` อีก 2 จุด (`SlipVerificationService.php:543`, `ReconcileDeliveries.php:138`)
    ไม่ได้ใช้ค่าคืนอยู่แล้ว — ไม่ต้องแก้
- `AccountDeliveryService::sendCard()` ยังคืน `bool` เหมือนเดิม (ผู้เรียกพึ่งค่านี้อยู่)
  แต่เมื่อส่งสำเร็จให้บันทึก message_id ที่ได้ลง `card_message_id`

### 2. เมธอดใหม่ `AccountDeliveryService::sendReminder(AccountDelivery $delivery, int $ageMinutes): bool`

- **มี `card_message_id`** → ส่งข้อความสั้น **ไม่มี `reply_markup`** พร้อม `reply_to_message_id`
  ชี้ไปการ์ดใบแรก เนื้อความประมาณ:
  ```
  ⏰ เตือน: งาน #123 ค้างมา 40 นาที ยังไม่ได้กดส่ง
  👤 คุณสมชาย · 💵 1,100 บาท
  👆 กดปุ่มบนการ์ดที่ quote ไว้
  ```
  (ชื่อลูกค้าและยอดต้องผ่าน `TelegramAlertBotService::esc()` เหมือน `cardText()`)
- **ไม่มี `card_message_id`** (การ์ดใบแรกไม่เคยออก — เคส 1 ส.ค. 2026 ที่ api.telegram.org ค้าง)
  → เรียก `sendCard()` เต็มพร้อมปุ่มเหมือนเดิม แล้วจดเป็นใบหลัก
  **ตาข่ายสุดท้ายต้องไม่ดับ**: งานที่การ์ดไม่เคยไปถึงยังต้องได้ปุ่มจากรอบเตือน
- ส่งด้วย `allow_sending_without_reply=true` เสมอ — ถ้าการ์ดใบแรกถูกลบไปแล้ว Telegram จะยัง
  ส่งใบเตือนออก ไม่ใช่ error ทั้งข้อความ (ปล่อยไว้ = ใบเตือนตายเงียบ ซึ่งอันตรายกว่าปัญหาเดิม)
- คืน `bool` แบบเดียวกับ `sendCard()` เพื่อให้ผู้เรียกยังตัดสินใจเรื่อง `last_reminded_at` ได้เหมือนเดิม
- ต้องเพิ่ม optional param `?int $replyToMessageId` ใน `TelegramAlertBotService::sendMessage()`
  (ใส่ `reply_to_message_id` + `allow_sending_without_reply` เมื่อมีค่า)

### 3. `RemindPendingDeliveries` เรียก `sendReminder()` แทน `sendCard()`

logic เดิมคงไว้ทุกบรรทัด:
- เตือนรอบแรกทะลุช่วงเงียบได้, เตือนซ้ำเคารพ quiet hours
- ห้ามประทับ `last_reminded_at` เมื่อส่งไม่ออก
- `Log::error` เมื่อการ์ด/ใบเตือนไม่ถึง Telegram

### 4. ไม่แตะ `TelegramAlertCallbackController`

guard `DeliveryAlreadyHandledException` ยังเป็นชั้นสุดท้ายเหมือนเดิม

## เทสต์ (เขียนก่อนแก้ ตาม TDD)

1. เตือนตอนมี `card_message_id` → payload ที่ยิงไป Telegram **ไม่มี** `reply_markup`
   และมี `reply_to_message_id` ตรงกับการ์ดใบแรก + `allow_sending_without_reply=true`
2. เตือนตอน `card_message_id` เป็น null → payload มีปุ่มครบ 2 ปุ่ม (`dv`, `dx`)
   และคอลัมน์ถูกเซ็ตหลังส่งสำเร็จ
3. `sendCard()` ส่งสำเร็จ → บันทึก `card_message_id`; ส่งไม่สำเร็จ → คอลัมน์ยังว่าง + คืน `false`
4. เทสต์เดิมต้องผ่าน: `tests/Feature/RemindPendingDeliveriesTest.php`,
   `tests/Feature/AccountDeliverySendCardTest.php` (ปรับเฉพาะ assertion ที่ผูกกับปุ่มบนใบเตือน)

## ขอบเขต

- migration 1 ไฟล์
- `app/Services/Payment/TelegramAlertBotService.php`
- `app/Services/Delivery/AccountDeliveryService.php`
- `app/Console/Commands/RemindPendingDeliveries.php`
- `app/Models/AccountDelivery.php` (เพิ่ม `card_message_id` ใน `$fillable`)
- เทสต์
- ไม่มีงาน frontend

## ที่ไม่อยู่ในขอบเขต

- การ์ดที่ค้างอยู่ในกลุ่ม Telegram **ตอนนี้** จะยังค้างต่อไป (กดแล้วปลอดภัย ระบบขึ้น
  "งาน #N ถูกจัดการไปแล้ว") — ไม่ไล่เก็บย้อนหลัง
- ปุ่มยืนยันรับเงิน (`pa`/`pc`) และการ์ดเลือกรายการ (`po`) ไม่เกี่ยวและไม่แตะ
