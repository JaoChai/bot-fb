# Slip Verification Hardening Plan (Round 2)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Follows Opus review of `scratchpad/prompt-redesign-proposal.md` (verdict: เห็นด้วยแบบมีเงื่อนไข — R1/R2 must close first).

**Goal:** ปิดช่องโหว่ที่ Opus review พบ ก่อนบังคับหลักการ "เงินเข้าแล้ว ✅ = EasySlip เท่านั้น"

**Tech Stack:** Laravel 13 (backend/), React 19 + TS (frontend/), เทสต์สไตล์ PHPUnit class + RefreshDatabase

## Global Constraints

- Branch `feat/slip-hardening`; backend commands จาก `backend/`, frontend จาก `frontend/`
- ห้ามแก้ legacy `ProcessLINEWebhook.php`
- ห้ามเปลี่ยนรูปแบบ success template (`เงินเข้าแล้ว {amount} บาท ✅ ... [ยืนยันชำระเงิน]`) — order pipeline พึ่งพา
- ห้ามแตะรูปแบบข้อความสรุปยอดที่ `PaymentMessageDetector` parse
- Commit ลงท้าย `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`; ห้าม `--no-verify`
- Task H4 (prompt ใน DB) ทำหลัง code deploy เท่านั้น — ไม่อยู่ใน branch นี้ (เป็น ops step)

---

### Task H1: unreadable-slip alert + pending message + config_error

**Files:** Modify `backend/app/Services/Payment/SlipVerificationService.php`, `backend/app/Services/LineWebhook/LineWebhookResponseService.php`; Test: ขยาย `SlipVerificationServiceTest` + `SlipVerificationPipelineTest`

**Behavioral spec:**

1. **`config_error` (token หาย แต่เปิดฟีเจอร์):** ใน `verify()` เคส token ว่าง เปลี่ยน `failReason` จาก `'api_error'` → `'config_error'`; เพิ่ม label ใน `FAIL_REASON_LABELS`: `'config_error' => 'ตั้งค่าไม่ครบ — EasySlip token หายไป กรุณาใส่ที่หน้า Settings (ระบบจะไม่ตรวจสลิปจนกว่าจะแก้)'`; pipeline ปฏิบัติกับ `config_error` เหมือน `api_error` (notifyAdmin + fallback vision) — แก้เงื่อนไขใน `trySlipVerification` ให้ครอบทั้งสอง reason
2. **`pending` (SLIP_PENDING จาก BBL):** ใน `trySlipVerification` — เมื่อ `failReason === 'pending'` ตอบลูกค้าด้วย template ใหม่ (constant ใน LineWebhookResponseService): `SLIP_PENDING_TEMPLATE = 'สลิปเพิ่งโอนมา ธนาคารกำลังประมวลผลครับ 🙏 รบกวนรอ 1-2 นาทีแล้วส่งสลิปเดิมมาอีกครั้งนะครับ ระบบจะตรวจให้อัตโนมัติ'` — **ไม่ notifyAdmin** (ลูกค้าแก้เองได้), ยัง record DB ตามเดิม, ยัง return true (ไม่เข้า vision)
3. **`unreadable` (R2):** ใน `verify()` เคส HTTP 400 — เดิมคืน not-a-slip เฉยๆ ตอนนี้: เช็ค `findExpectedPayment($conversationHistory, $configured)` ก่อน ถ้า**มีออเดอร์ค้าง** → ถือว่ารูปนี้น่าจะเป็นสลิปที่อ่านไม่ได้ → คืน `isSlip: true, passed: false, failReason: 'unreadable'` + record DB; ถ้า**ไม่มีออเดอร์ค้าง** → พฤติกรรมเดิม (not-a-slip → vision) เพิ่ม label: `'unreadable' => 'รูปสลิปอ่านไม่ได้/ไม่ชัด — ระบบตรวจอัตโนมัติไม่ได้ กรุณาตรวจมือ'` → pipeline ตอบ fail template เดิม + notifyAdmin (เหมือน fail อื่น)
   *เหตุผล design:* ช่วงมีออเดอร์ค้าง รูปที่ลูกค้าส่งเกือบทั้งหมดคือสลิป; false alarm (รูปอื่นตอนมีออเดอร์ค้าง) ราคาถูกกว่าสลิปหลุดไปให้ LLM เดา

**Tests ต้องครอบ:** config_error label+alert, pending → pending template + ไม่มี telegram call + record, 400+pending-order → fail reply + alert + record 'unreadable', 400+ไม่มี order → vision เดิม (ของเดิมมีอยู่แล้ว ต้องยังผ่าน)

---

### Task H2: Admin manual payment confirm (R1)

**Files:** Create `backend/app/Http/Controllers/Api/ManualPaymentConfirmController.php` (หรือ action ใน controller conversation ที่มีอยู่ — ดู pattern เดิมก่อน), route ใน `backend/routes/api.php`; Frontend: ปุ่มในหน้าแชท (`frontend/src/components/chat/` หรือ conversation header — ดูโครงสร้างจริง); Tests: feature test backend + component test frontend

**Behavioral spec:**

- `POST /api/conversations/{conversation}/confirm-payment` body `{amount?: number}` — auth: policy เดียวกับการตอบแชท (owner/admin ที่ได้รับมอบหมาย)
- ทำงาน: (1) หา expected payment จาก history ผ่าน `SlipVerificationService::findExpectedPayment` (window 15 ข้อความ text ล่าสุด แบบเดียวกับ pipeline) — ถ้า request ส่ง `amount` มา ใช้ค่านั้น override; ถ้าไม่มีทั้งคู่ → 422 "ไม่พบยอดออเดอร์ กรุณาระบุยอด"
- (2) สร้างข้อความ bot จาก `slip_success_message` template ของบอท (fallback `SLIP_SUCCESS_TEMPLATE` — ใช้ constant ที่ export ได้จากที่เดียว อย่า copy string) แทน `{amount}`/`{order_summary}`
- (3) บันทึก `Message` sender `'bot'` + metadata `{slip_verification: true, slip_status: 'manual_confirmed', confirmed_by: <user id>}`
- (4) ส่งเข้า LINE + Flex + plugins **เส้นทางเดียวกับบอทตอบเอง**: ศึกษา `LineWebhookOutputService::dispatchImage` ส่วน push/flex/executePlugins แล้ว reuse ให้มากที่สุด (ถ้า method เป็น private ให้ extract helper public เล็กๆ หรือเรียก `LINEService` + `PaymentFlexService` + `FlowPluginService::executePlugins` ตรงๆ ตาม pattern เดิม) — **เป้าหมายคือ order ต้องถูกสร้างเหมือน happy path ทุกประการ**
- (5) บันทึกแถว `slip_verifications` status `'manual_confirmed'` (trans_ref null)
- (6) คืน `{message, order_created: bool}` — order_created จากผล executePlugins (best effort, ไม่ block)
- **Frontend:** ปุ่ม "ยืนยันรับเงิน ✅" ในหน้าแชท (แสดงเฉพาะ conversation LINE ของบอทที่ `slip_verification_enabled`) → dialog ยืนยัน แสดงยอดที่ระบบเดาได้ (จาก endpoint GET หรือใส่ใน dialog ให้แก้ได้) → เรียก endpoint → toast ผลลัพธ์ ตาม pattern UI เดิมของหน้าแชท

**Tests:** endpoint สร้าง bot message + tag + LINE push (Http::fake) + plugins ยิง (Http fake telegram → assertSent) ; ไม่มียอด+ไม่ส่ง amount → 422; สิทธิ์: user อื่นที่ไม่ใช่ owner/assigned → 403

---

### Task H3: Conditional vision instruction

**Files:** Modify `backend/app/Services/LineWebhook/LineWebhookResponseService.php::buildVisionSystemPrompt`; Test: ขยาย `SlipVerificationPipelineTest` หรือ unit test ใหม่

**Behavioral spec:** เมื่อ `$ctx->bot->settings?->slip_verification_enabled === true` ให้เปลี่ยนบล็อก vision instruction ส่วนสลิปเป็น:

```
## การวิเคราะห์รูปภาพ
เมื่อได้รับรูปภาพ ให้ตรวจสอบก่อนว่าเป็นสลิปโอนเงิน/หลักฐานการชำระเงินหรือไม่

**ถ้าเป็นสลิปโอนเงิน:**
- ระบบตรวจสลิปอัตโนมัติของร้านไม่สามารถอ่านรูปนี้ได้ คุณห้ามยืนยันการรับเงินเองเด็ดขาด
- ห้ามตอบว่า "เงินเข้าแล้ว" และห้ามใส่แท็ก [ยืนยันชำระเงิน]
- ตอบเพียง: "ได้รับสลิปแล้วครับ รอทีมงานตรวจสอบยอดเข้าสักครู่นะครับ ปกติไม่เกิน 5 นาที ขอบคุณที่รอครับ"

**ถ้าไม่ใช่สลิป:**
- อธิบายรูปภาพและช่วยตอบคำถามตามบริบทของการสนทนา
```

บอทที่ไม่เปิดฟีเจอร์ → instruction เดิมทุกตัวอักษร (backward compatible)

**Tests:** บอทเปิดฟีเจอร์ + EasySlip 400 + ไม่มีออเดอร์ค้าง → vision ถูกเรียกด้วย system prompt ที่มีคำว่า "ห้ามยืนยันการรับเงิน" (assert ผ่าน Http::fake inspection ของ request body ไป openrouter); บอทปิดฟีเจอร์ → prompt มีข้อความเดิม "เงินเข้าแล้ว [จำนวนเงิน] บาท ✅"

---

### Task H4 (ops — หลัง merge+deploy code เท่านั้น): แก้ prompt flow 24 STEP 5

ขั้นตอน (controller ทำเอง ไม่ใช่ implementer subagent):
1. อ่าน block STEP 5 + HARD RESET เต็มๆ จาก DB ตรวจว่า HARD RESET key จาก history ไม่ใช่จากที่ LLM พิมพ์เอง (R4)
2. Backup ทั้ง prompt ลง `flow_audit_logs` (ดู schema + pattern id=8,9 เดิม)
3. UPDATE prompt: แทน PATH A/B ด้วยเวอร์ชัน "ห้ามยืนยันเอง" ตาม proposal (แก้ที่ 1) + ตัดตัวอย่างขัดแย้ง (ตัวอย่าง 3 สลิปผิดธนาคาร, ตัวอย่างที่ตอบ "เงินเข้าแล้ว 199")
4. Verify: query prompt กลับมาเช็ค + ทดสอบจริง
