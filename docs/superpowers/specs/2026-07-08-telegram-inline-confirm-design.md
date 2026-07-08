# Telegram Inline Confirm — ยืนยันรับเงินจากปุ่มใน Telegram

วันที่: 2026-07-08
สถานะ: Design (รออนุมัติ)

## ปัญหา / เป้าหมาย

ทุกวันนี้เมื่อระบบตรวจสลิปไม่ผ่าน บอทจะส่ง alert ไปหาแอดมินใน Telegram แต่แอดมิน
ต้อง **เปิดเว็บ dashboard** แล้วกดปุ่ม "ยืนยันรับเงิน ✅" ในหน้าแชทเพื่อยืนยันจริง
(สร้างออเดอร์ + push ข้อความยืนยันหาลูกค้า) — ขั้นตอนเปิดเว็บนี้ยุ่งยาก

**เป้าหมาย:** ให้แอดมินกด "ยืนยันรับเงิน" ได้จบในข้อความ Telegram โดยไม่ต้องเปิดเว็บ

## ขอบเขต (Scope)

**ทำ:**
- แนบปุ่ม inline button ท้ายข้อความ alert ตรวจสลิปไม่ผ่าน
- ตั้ง webhook ให้ bot แจ้งเตือน (ขารับ callback) — bot ตัวนี้ใช้แจ้งเตือนอย่างเดียว
  (ยืนยันจาก owner แล้วว่าไม่ได้ใช้คุยลูกค้า จึง webhook ไม่ชน)
- รับการกดปุ่ม → เรียก `ManualPaymentConfirmService` ตัวเดิม → แก้ข้อความเดิมเป็นสถานะยืนยันแล้ว

**ไม่ทำ (YAGNI):**
- ไม่แตะ flow ยืนยันในเว็บ (ยังใช้ได้เหมือนเดิม ทำงานคู่กันผ่าน guard เดิม)
- ไม่รองรับกรอกยอดอิสระใน Telegram (ปุ่มเลือกจากยอดที่ระบบรู้เท่านั้น)
- ไม่ทำปุ่ม "ปฏิเสธ/reject" (นอกขอบเขตรอบนี้)

## Requirement ที่เคาะแล้ว (จาก brainstorming)

1. **ปุ่มโผล่เคสไหน:** เคส ⚠️ (ระบบตรวจไม่ได้) กดยืนยันได้เลย; เคส 🚨 (โกง) ต้องกด 2 ชั้น
2. **ยอดเงิน:** แสดงหลายปุ่มให้เลือกเมื่อยอดออเดอร์กับยอดในสลิปต่างกัน; ปุ่มเดียวเมื่อรู้ยอดเดียว;
   ถ้าไม่รู้ยอดเลย → ปุ่ม "ใช้ยอดจากแชท" (ให้ service resolve เอง)
3. **ใครกดได้:** รับเฉพาะ callback ที่มาจาก chat_id เดิมที่ตั้งไว้ (ห้องแจ้งเตือนของ bot 26)

## สถาปัตยกรรม

### จุดสำคัญ: alert bot เป็น "ส่งออกอย่างเดียว" ในปัจจุบัน

- alert ส่งจาก `SlipVerificationService::notifyAdmin()` โดยยิง Telegram API ตรง ๆ ด้วย
  `token` + `chat_id` ที่เก็บใน **flow telegram plugin config** (ไม่ใช่ Bot model)
- Telegram จะส่ง callback (การกดปุ่ม) กลับมาได้ก็ต่อเมื่อ bot นั้นมี **webhook** ชี้มาที่ server
- ตัวรับ webhook Telegram ที่มีอยู่ (`TelegramWebhookController`) ผูกกับ **Bot model**
  (`channel_type=telegram`, key ด้วย `webhook_url`) — ใช้กับ alert bot ไม่ได้ตรง ๆ

### Approach ที่เลือก: endpoint แยกเฉพาะ alert callback (Approach A)

เทียบทางเลือก:
- **A (เลือก):** endpoint ใหม่ `/api/webhook/telegram-alert/{token}` รับเฉพาะ `callback_query`
  → แยกชัดจาก webhook ของ bot ลูกค้า, reuse `ManualPaymentConfirmService`. เรียบง่าย ปลอดภัย
- B: ทำ alert bot เป็น Bot model แล้วใช้ controller เดิม → ปน channel ลูกค้ากับ notif ยุ่ง
- C: polling `getUpdates` ด้วย scheduled job → latency สูง, ซับซ้อน, ชนกับ webhook

### Flow เต็ม

```
ตรวจสลิปไม่ผ่าน
  → notifyAdmin() ส่งข้อความ + inline_keyboard (ปุ่มยืนยัน) พร้อม callback_data
     ที่ encode: action + conversation_id + amount source
  → แอดมินกดปุ่มใน Telegram
  → Telegram POST callback_query มาที่ /api/webhook/telegram-alert/{token}
  → Controller: หา flow telegram plugin ที่ config.access_token == {token} (map token→plugin→chat_id),
     verify chat_id ที่ตั้งไว้ตรงกับ callback_query.message.chat.id
  → เคส 🚨: กดครั้งแรก = แก้ปุ่มเป็น "กดอีกครั้งเพื่อยืนยันจริง" แล้วจบ (ยังไม่ทำงาน)
  → เคส ⚠️ หรือ 🚨 กดครั้งที่สอง:
     resolve Conversation จาก conversation_id → Bot → confirmedBy = bot owner (user_id)
     → ManualPaymentConfirmService::confirm($bot, $conversation, $amount, $ownerId)
     → editMessageText: "✅ ยืนยันแล้วโดย {ชื่อแอดมิน} เมื่อ {เวลา}" (ลบปุ่มทิ้ง)
     → answerCallbackQuery: toast "ยืนยันแล้ว" ใน Telegram
```

### callback_data (จำกัด 64 bytes ของ Telegram)

รูปแบบสั้น: `cfmpay:{conversationId}:{amountSource}` โดย amountSource เป็นหนึ่งใน
`order` (ยอดออเดอร์) / `slip` (ยอดในสลิป) / `chat` (ให้ service resolve). ยอดจริงไม่ฝัง
ใน callback_data — resolve ฝั่ง server จาก slip_verification/แชทตอนกด เพื่อความถูกต้อง
และกันปลอมแปลง. เคส 🚨 เพิ่ม prefix ยืนยันชั้นสอง เช่น `cfmpay2:...`

## Components ที่แตะ / เพิ่ม

| ไฟล์ | เปลี่ยนอะไร |
|------|-----------|
| `SlipVerificationService::notifyAdmin()` | เพิ่ม `reply_markup` (inline_keyboard) ตามยอดที่รู้ + เคส fraud/non-fraud |
| `routes/api.php` | route ใหม่ `POST /webhook/telegram-alert/{token}` |
| `Http/Controllers/Webhook/TelegramAlertCallbackController.php` (ใหม่) | รับ callback_query, verify, 2-step, เรียก service, edit ข้อความ |
| `Services/Payment/TelegramAlertBotService.php` (ใหม่) | ยิง Telegram API ด้วย token จาก plugin (raw HTTP เหมือน notifyAdmin ทำอยู่): `sendMessage(+markup)`, `editMessageText`, `answerCallbackQuery`, `setWebhook`. รวม logic ยิง alert ที่กระจายอยู่ให้มาอยู่ที่เดียว |
| `ManualPaymentConfirmService` | **ไม่แก้** — reuse `confirm()` ตรง ๆ |
| สคริปต์/command ตั้ง webhook | `php artisan telegram:alert-webhook:set` (ตั้ง setWebhook ให้ token ของ plugin ที่ enabled) |

## ความปลอดภัย

- **ยืนยันแหล่งที่มา:** รับ callback เฉพาะเมื่อ `callback_query.message.chat.id` ตรงกับ
  `chat_id` ที่ตั้งใน plugin config (คนนอกยิง callback ปลอมมาถูกปฏิเสธ)
- **secret token:** ตั้ง `X-Telegram-Bot-Api-Secret-Token` ตอน setWebhook แล้ว verify header
  (แบบเดียวกับ controller เดิมทำ)
- **กันยืนยันซ้ำ:** ใช้ `guardAgainstDoubleConfirm` เดิมใน service — กดเว็บแล้วกด Telegram ซ้ำ
  (หรือกลับกัน) ภายใน window จะโดน 409 → แสดง toast "ยืนยันไปแล้ว" ไม่สร้างออเดอร์ซ้ำ
- **attribution:** `confirmed_by` = bot owner (`bot->user_id`) เพราะ Telegram ไม่มี Laravel user;
  ชื่อ Telegram คนกด (`callback_query.from`) เก็บใน log/ข้อความที่แก้

## Error handling

- token ไม่ตรง plugin ใด → 404, log warning (แบบ controller เดิม)
- chat_id ไม่ตรง → 200 ok (กัน Telegram retry) แต่ไม่ทำงาน + log warning
- `NoPendingPaymentException` (หายอดไม่ได้) → answerCallbackQuery แจ้ง "หายอดออเดอร์ไม่พบ กรุณายืนยันในเว็บ"
- `RecentManualConfirmException` → answerCallbackQuery "ยืนยันไปแล้ว" + edit ข้อความเป็นสถานะยืนยันแล้ว
- service throw อื่น → answerCallbackQuery แจ้ง error ทั่วไป + ไม่แก้ปุ่ม (ให้กดใหม่ได้)
- ต้องตอบ Telegram 200 เร็วเสมอ (ประมวลผลใน request สั้น ๆ; ยิง edit/answer หลัง confirm)

## Testing

- **Unit/Feature (PHPUnit):**
  - `notifyAdmin` แนบปุ่มถูกต้อง: เคส non-fraud 1 ปุ่ม / ยอดต่าง 2 ปุ่ม / ไม่มียอด → ปุ่ม chat / fraud → ปุ่ม 2 ชั้น
  - callback endpoint: chat_id ตรง → เรียก confirm; chat_id ผิด → ปฏิเสธ
  - เคส fraud กดครั้งแรกไม่ confirm (แค่แก้ปุ่ม); กดครั้งสอง → confirm
  - double-confirm: กด Telegram หลัง auto-pass/manual → 409 → ไม่สร้างออเดอร์ซ้ำ (มี test เดิมครอบ service อยู่แล้ว)
- **Manual (หลัง deploy):** ส่งสลิปทดสอบเข้า bot 26 → กดปุ่มใน Telegram จริง → เช็คออเดอร์ + ข้อความลูกค้า + ข้อความ Telegram ถูกแก้เป็นยืนยันแล้ว

## งาน Ops (ทำครั้งเดียว)

- รัน command ตั้ง webhook ให้ token ของ alert bot (bot 26) หลัง deploy
- เก็บ secret token ไว้ verify header
