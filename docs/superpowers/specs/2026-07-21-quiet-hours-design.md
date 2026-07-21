# ช่วงเวลาเงียบแจ้งเตือน (Quiet Hours) — Design

**วันที่:** 2026-07-21
**ปัญหา:** ร้านเปิด 8:00–23:00 แต่แจ้งเตือน Telegram เด้งทั้งคืน — ตัวการหลักคือตัวเตือนซ้ำ `delivery:remind` (ทุก 30 นาที) และ `delivery:reconcile` (ทุก 1 ชม.) ที่รันตลอด 24 ชม.

## ขอบเขต (ตามที่เจ้าของเลือก)

- **เงียบเฉพาะตัวเตือนซ้ำ:** `delivery:remind` + `delivery:reconcile`
- **"ออเดอร์ใหม่!" + การ์ดปุ่มยืนยันส่งของ ยังเด้งปกติ** แม้กลางคืน (เรื่องเงินเข้า ไม่ควรพลาด)
- **ตั้งเวลาได้จากหน้าเว็บ** (SettingsPage) ไม่ hardcode
- ค่า default: เงียบ 23:00–08:00 (Asia/Bangkok) เปิดใช้ตั้งแต่แรก

## แนวทางที่เลือก: Send-time gate

Command ยังรันตาม schedule เดิมทุกรอบ แต่**เช็คก่อนส่ง Telegram** ว่าตอนนี้อยู่ในช่วงเงียบไหม — ถ้าใช่ ข้ามการส่งและ**ไม่อัพเดต** `last_reminded_at`

เหตุผลที่ไม่ทำระบบ "เก็บไว้ส่งตอนเช้า": ตัวเตือนซ้ำวนหางานค้างเองอยู่แล้วทุกรอบ พอพ้นช่วงเงียบ (รอบแรกหลัง 8:00) งานที่ค้างข้ามคืนจะถูกเตือนทันทีโดยธรรมชาติ ไม่ต้องมี queue เพิ่ม (YAGNI)

แนวทางที่ตัดทิ้ง:
- `->between()` ที่ระดับ schedule — ง่ายแต่แก้เวลาจากเว็บไม่ได้
- Hold & flush ตอน 8:00 — ต้องมีตารางเก็บข้อความค้าง ซับซ้อนเกินจำเป็น

## Design

### Backend

1. **Migration** เพิ่มคอลัมน์ในตาราง `user_settings`:
   - `quiet_hours_enabled` boolean, default `true`
   - `quiet_hours_start` time, default `23:00`
   - `quiet_hours_end` time, default `08:00`

2. **`UserSetting::isInQuietHours(): bool`**
   - คืน `false` ถ้า `quiet_hours_enabled` เป็น false
   - เทียบเวลาปัจจุบัน (app timezone = Asia/Bangkok) กับช่วง start–end
   - รองรับช่วงข้ามเที่ยงคืน: start > end (เช่น 23:00→08:00) หมายถึง `now >= start || now < end`
   - ช่วงปกติ start < end หมายถึง `start <= now < end`

3. **`RemindPendingDeliveries`** — ก่อนส่งการ์ดของแต่ละ delivery เช็ค quiet hours ของเจ้าของบอท (`delivery->bot->user->settings`) — อยู่ในช่วงเงียบ → skip ทั้ง `sendCard` และการอัพเดต `last_reminded_at` พร้อม log info บรรทัดเดียวต่อรอบ

4. **`ReconcileDeliveries`** — gate การ `sendMessage` แจ้ง limbo ด้วยเงื่อนไขเดียวกัน (ตรวจต่อได้ แค่ไม่ส่งข้อความ) — reconcile ไม่ผูกกับ delivery เดียวเสมอ จึงใช้ settings ของ **เจ้าของบอท** ที่เป็นเจ้าของ Telegram plugin ที่จะใช้ส่ง (`bot->user->settings`)

5. **API** — `PUT /settings/quiet-hours` ใน `UserSettingController` (validate: enabled boolean, start/end รูปแบบ `HH:MM`) และเพิ่มฟิลด์ทั้ง 3 ใน response ของ `GET /settings`

### Frontend

6. **`SettingsPage.tsx`** เพิ่มการ์ด "ช่วงเวลาเงียบแจ้งเตือน":
   - สวิตช์เปิด/ปิด
   - ช่องเวลาเริ่ม / สิ้นสุด (`<input type="time">`)
   - คำอธิบาย: "ช่วงเวลานี้จะเงียบเฉพาะแจ้งเตือนซ้ำ (งานค้างกดยืนยัน/ของค้างสต๊อก) — ออเดอร์ใหม่ยังแจ้งเตือนปกติ"
   - ยึด pattern การ์ด settings เดิมในหน้า (เช่นการ์ด EasySlip)

## พฤติกรรมที่ได้

| เวลา | เหตุการณ์ | ผล |
|------|-----------|-----|
| 02:00 | มีงานค้างยังไม่กดยืนยัน | ไม่เตือน (เงียบ) |
| 02:00 | ลูกค้าโอนเงิน | "ออเดอร์ใหม่!" + การ์ดปุ่มเด้งปกติ |
| 08:00–08:30 | รอบ remind แรกของเช้า | เตือนงานที่ค้างข้ามคืนทั้งหมดทันที |
| ปิดสวิตช์ quiet hours | ทุกอย่าง | กลับไปเตือน 24 ชม. เหมือนเดิม |

## Error handling

- ไม่มี `UserSetting` row (user ยังไม่เคยตั้งค่าอะไร) → ถือว่าใช้ default (เงียบ 23:00–08:00)
- ค่าเวลาผิดรูปแบบจาก API → 422 จาก validation ก่อนถึง DB

## Testing

- Unit: `isInQuietHours` — ช่วงข้ามเที่ยงคืน, ช่วงปกติ, ขอบเขตพอดี 23:00/08:00, สวิตช์ปิด
- Feature: `delivery:remind` ในช่วงเงียบไม่ส่งและไม่แตะ `last_reminded_at`; นอกช่วงเงียบส่งปกติ
- Feature: endpoint quiet-hours validate + บันทึกค่า
