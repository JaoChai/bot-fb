# EasySlip Slip Verification — Design

**วันที่:** 2026-07-08
**สถานะ:** อนุมัติดีไซน์แล้ว (brainstorming session)
**เป้าหมายแรก:** เปิดใช้บน bot 26 (Line - Adsvance)

## ปัญหา

ปัจจุบันการ "ตรวจสลิป" คือ LLM vision อ่านตัวเลขจากรูปเท่านั้น (prompt hardcode ใน
`LineWebhookResponseService::buildVisionSystemPrompt()` และ legacy
`ProcessLINEWebhook::buildVisionSystemPrompt()`) — ไม่มีการยืนยันกับธนาคารจริง
ดังนั้น **สลิปปลอม สลิปเก่า สลิปซ้ำ หรือรูปแต่งยอด ระบบตรวจจับไม่ได้** แต่บอทตอบ
"เงินเข้าแล้ว ✅" และสร้าง order ทันที

ข้อเท็จจริงประกอบ (ตรวจจาก production DB 2026-07-08):

- bot 26 ทำ vision analysis ~237 ครั้ง/เดือน (รวมรูปที่ไม่ใช่สลิป), orders 1,583 รายการ
- bot 28 ไม่เคยตรวจสลิป (auto_handover=true ทุก conversation → image branch ถูก skip)
- คอลัมน์ `bot_settings.image_analysis_prompt` ที่โค้ดอ้างถึง **ไม่มีอยู่จริงใน DB** (dead code)
- มี stub `bot_hitl_settings.easy_slip_enabled` จาก migration 2026-01-09 — ไม่มีโค้ดอ่าน
  **การตัดสินใจ: ไม่ใช้และไม่ลบ stub นี้**

## สิ่งที่จะทำ

ฟีเจอร์ตรวจสลิปจริงด้วย [EasySlip API](https://easyslip.com/api-products/)
(แพ็กเกจ Start 99 บาท/250 สลิป/เดือน) แบบ **EasySlip-first**: รูปทุกใบที่เข้ามา
(เฉพาะบอทที่เปิดฟีเจอร์) ส่งตรวจกับ EasySlip ก่อน ไม่ใช่สลิปค่อยตกไป vision flow เดิม

### Flow การทำงาน (image branch, บอทที่เปิดฟีเจอร์)

1. ลูกค้าส่งรูปเข้า LINE → ส่งรูปให้ EasySlip verify ทันที
2. **ไม่ใช่สลิป** → เข้ากระบวนการ vision เดิมทุกอย่าง (ไม่มีอะไรเปลี่ยน)
3. **เป็นสลิป + ผ่านทุกเช็ค** → บอทตอบข้อความยืนยัน (template ตั้งค่าได้) ที่ลงท้ายด้วย
   แท็ก `[ยืนยันชำระเงิน]` → ระบบเดิม (Telegram plugin → OrderService) ทำงานต่อโดยไม่แก้
4. **เป็นสลิป + ไม่ผ่าน** → บอทตอบข้อความสุภาพ (template ตั้งค่าได้ ไม่กล่าวหาว่าปลอม)
   + แจ้ง Telegram พร้อมเหตุผล + **ไม่สร้าง order ไม่คอนเฟิร์ม**
5. **EasySlip ล่ม/timeout** → fallback ไป vision flow เดิม (ไม่บล็อกลูกค้า) + log warning
   + แจ้ง Telegram ว่าระบบตรวจสลิปใช้ไม่ได้ชั่วคราว

### เกณฑ์ "ผ่าน" (ต้องผ่านครบทุกข้อ)

| เช็ค | วิธี |
|------|------|
| สลิปจริง | EasySlip ยืนยันธุรกรรมกับระบบธนาคาร |
| เข้าบัญชีร้าน | เลขบัญชีปลายทางจากผล EasySlip ตรงกับ `receiver_account` ที่ตั้งค่าไว้ — EasySlip คืนเลขแบบ mask (เช่น `xxx-x-x4880-x`) กติกา: ตัดทุกอักขระที่ไม่ใช่ตัวเลขทั้งสองฝั่ง แล้วเทียบเฉพาะตำแหน่งที่ฝั่ง EasySlip เป็นตัวเลข (นับจากท้าย) ต้องตรงทุกตัว |
| ยอดตรงออเดอร์ | ยอดจาก EasySlip = ยอดออเดอร์ค้างชำระใน conversation history (parse ด้วย `PaymentMessageDetector` ที่มีอยู่) ± tolerance ที่ตั้งค่า (default 0 = ตรงเป๊ะ) |
| ไม่ซ้ำ | `trans_ref` ไม่เคยมีในตาราง `slip_verifications` (unique constraint) |

หมายเหตุ: ถ้าไม่พบยอดออเดอร์ใน history (ลูกค้าส่งสลิปลอยๆ) → ถือว่า "ไม่ผ่าน"
เหตุผล `no_pending_order` → แจ้งแอดมินตรวจเอง

## การตั้งค่า (ฟีเจอร์เต็ม ตั้งได้จากหน้าเว็บ)

### ระดับบอท — แท็บใหม่ "ตรวจสลิป" ใน BotSettingsPage (แท็บที่ 5)

ตามแพทเทิร์น `StickerReplyTab` เป๊ะ (component ใหม่ `SlipVerificationTab.tsx`):

- `slip_verification_enabled` (boolean, default false) — สวิตช์เปิด/ปิด
- `slip_receiver_account` (string) — เลขบัญชีร้าน (ย้ายจาก hardcode `223-3-24880-3`
  ใน `PaymentMessageDetector`; ค่าใน detector เดิมคงไว้ ใช้เฉพาะ parse ข้อความ)
- `slip_amount_tolerance` (decimal, default 0) — ยอมให้ยอดคลาดเคลื่อนได้กี่บาท
- `slip_success_message` (text, มี default) — รองรับ placeholder `{amount}`, `{order_summary}`
- `slip_fail_message` (text, มี default)

เก็บเป็นคอลัมน์ใหม่ในตาราง `bot_settings` (แพทเทิร์นเดียวกับ `reply_sticker_*`)
ผ่าน API เดิม GET/PUT `/api/bots/{bot}/settings` (เพิ่ม validation ใน `BotSettingController`)

### ระดับบัญชีผู้ใช้ — หน้า Settings รวม

- EasySlip API token เก็บแบบเดียวกับ OpenRouter key (`UserSettingController` pattern,
  encrypted) + ปุ่ม test connection
- ทุกบอทของ user ใช้ token เดียวกัน

## สถาปัตยกรรม

### ใหม่

- `app/Services/Payment/SlipVerificationService.php` — EasySlip HTTP client + เกณฑ์ตัดสิน
  คืน result object: `{is_slip, passed, fail_reason, amount, receiver_account, trans_ref, raw}`
- ตาราง `slip_verifications`: `id, bot_id, conversation_id, message_id, trans_ref (unique),
  amount, receiver_account, status (passed|fake|duplicate|amount_mismatch|wrong_account|no_pending_order|api_error),
  raw_response (json), created_at` — ประวัติตรวจทุกครั้ง + กันสลิปซ้ำ
- Migration เพิ่มคอลัมน์ `bot_settings` (5 คอลัมน์ข้างบน) — ผ่าน safe-migration workflow
- `SlipVerificationTab.tsx` + wiring ใน `BotSettingsPage.tsx`
- ช่อง EasySlip token ในหน้า Settings + endpoint update/test/clear

### แก้ของเดิม (surgical)

- `LineWebhookResponseService::generateImageResponse()` — เพิ่มขั้น EasySlip ก่อน vision
  (เฉพาะเมื่อ `slip_verification_enabled` = true; บอทอื่นทำงานเดิม 100%)
- `BotSettingController` — เพิ่ม validation fields ใหม่
- **ไม่แก้** legacy `ProcessLINEWebhook` image path (bot 26 ใช้ pipeline ใหม่อยู่แล้ว;
  บอทอื่นยังไม่เปิดฟีเจอร์)

### Telegram แจ้งเตือนเมื่อไม่ผ่าน

ใช้ Telegram bot config เดิมจาก flow plugin ของบอทนั้น (ช่องทางเดียวกับแจ้งออเดอร์)
ข้อความระบุ: ลูกค้า, เหตุผลไม่ผ่าน, ยอดในสลิป vs ยอดออเดอร์, ลิงก์ conversation

## Error handling

- EasySlip timeout/5xx → นับเป็น `api_error`, fallback vision, ไม่ retry (สลิปยังอยู่ใน
  แชท แอดมินตรวจได้)
- ไม่มี token/token หมดอายุ → เหมือน api_error + log ชัดเจน
- Race สลิปใบเดียวส่งซ้ำเร็วๆ → unique constraint บน `trans_ref` เป็นตัวกันชั้นสุดท้าย

## Testing

- Pest feature tests (mock EasySlip client): ผ่าน / ปลอม / ซ้ำ / ยอดไม่ตรง / ผิดบัญชี /
  ไม่มีออเดอร์ค้าง / API ล่ม fallback / บอทไม่เปิดฟีเจอร์ต้องไม่เรียก EasySlip
- Frontend: Vitest ตาม pattern แท็บเดิม
- Manual: สลิปจริงบน bot 26 ก่อนถือว่าเสร็จ

## นอกขอบเขต (YAGNI)

- bot 28 (เปิด auto_handover อยู่ — บอทไม่ตอบอะไรเลยโดย design)
- Facebook/ช่องทางอื่น (เฉพาะ LINE pipeline)
- Dashboard รายงานสถิติการตรวจสลิป (ดูจาก Telegram + ตาราง DB ไปก่อน)
- ลบ/ฟื้น stub `bot_hitl_settings.easy_slip_enabled`

## สิ่งที่ user ต้องเตรียม

1. สมัคร EasySlip แพ็กเกจ Start (99 บาท/เดือน 250 สลิป) → เอา API token มา
2. ใส่ token ในหน้า Settings (หลังฟีเจอร์เสร็จ) — ไม่ต้องตั้ง env บน Railway
