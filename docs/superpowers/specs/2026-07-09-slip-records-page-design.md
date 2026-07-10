# Slip Records Page — Design Spec

วันที่: 2026-07-09
สถานะ: อนุมัติ design แล้ว รอ review spec

## ปัญหา / เป้าหมาย

บอทตรวจสลิปผ่าน EasySlip และบันทึกผลทุกครั้งลงตาราง `slip_verifications` อยู่แล้ว
แต่**ไม่มีทางดูข้อมูลนี้จากหน้าเว็บ** — ยังไม่มี API และไม่มีหน้าแสดงผล

เจ้าของต้องการหน้าเดียวที่ครอบคลุม 4 มุม:
1. **Audit เงินเข้า/ยอดขาย** — ดูว่ามีสลิปโอนเข้ามาเท่าไร รวมวันนี้/ช่วงเวลา
2. **จับสลิปปลอม/ผิดปกติ** — เห็นรายการ fake / duplicate / amount_mismatch / wrong_account เพื่อไล่เช็คลูกค้า
3. **ตรวจย้อนหลังรายลูกค้า** — เวลามีปัญหากับลูกค้าคนหนึ่ง ดูว่าเขาส่งสลิปอะไรมาบ้าง เชื่อมโยงกับแชท
4. **เฝ้าระวัง error ระบบ** — เห็นเคสที่ตรวจไม่ได้ (unreadable / api_error / image_download_failed)

## ขอบเขต (Scope)

**อยู่ในเฟสนี้:**
- API `GET /api/slips` (list + filter + pagination + summary), owner-only
- หน้าเว็บ `SlipsPage` ใหม่ในเมนู sidebar "สลิป / การชำระเงิน"
- แสดงข้อมูลสลิปที่ตรวจได้ (ยอด/ref/ธนาคาร/ผู้โอน/สถานะ/ลูกค้า/เวลา) + ลิงก์ไปแชท

**ไม่อยู่ในเฟสนี้ (follow-up ทีหลัง):**
- แสดง**รูปสลิปจริง** — เพราะ URL รูปจาก LINE เป็นของชั่วคราวหมดอายุ (บั๊ก PR #209) และตาราง `slip_verifications` ไม่ได้เก็บ media_url ตรงๆ ต้อง join ผ่าน message ซึ่งซับซ้อนและไม่การันตีว่ารูปยังอยู่
- Export CSV
- ไม่แตะ DB schema เดิม

## ข้อมูลที่มีอยู่แล้ว (ยืนยันจากโค้ดจริง)

ตาราง `slip_verifications` (migration `2026_07_08_100002_create_slip_verifications_table.php`):

| column | ใช้ทำอะไรในหน้านี้ |
|--------|-------------------|
| `id` | key |
| `bot_id` (FK) | filter ตามบอท |
| `conversation_id` (FK, nullable) | ลิงก์ไปแชท + ดึงชื่อลูกค้า (`customerProfile.display_name`) |
| `message_id` (FK, nullable) | (ไม่ใช้เฟสนี้ — เผื่อรูปสลิปทีหลัง) |
| `trans_ref` (nullable) | เลขอ้างอิงธุรกรรม |
| `amount` (decimal) | ยอดเงิน |
| `receiver_account` (nullable) | บัญชีปลายทาง |
| `status` (string 32) | สถานะ (ดูรายการด้านล่าง) |
| `raw_response` (jsonb, nullable) | ดึงชื่อผู้โอน/ธนาคาร/วันเวลาในสลิป |
| `created_at` | เวลาที่ตรวจ |

Model: `app/Models/SlipVerification.php` (มี relations `bot()`, `conversation()` แล้ว)

ค่า `status` ที่เป็นไปได้: `passed`, `fake`, `duplicate`, `amount_mismatch`, `wrong_account`, `no_pending_order`, `pending`, `unreadable`, `api_error`, `config_error`, `image_download_failed`

จัดกลุ่มสถานะสำหรับ UI:
- **ผ่าน (เขียว):** `passed`
- **ผิดปกติ/ต้องตรวจ (แดง):** `fake`, `wrong_account`
- **เตือน (ส้ม):** `duplicate`, `amount_mismatch`, `no_pending_order`
- **error ระบบ (เทา):** `unreadable`, `api_error`, `config_error`, `image_download_failed`, `pending`

## Backend Design

### Endpoint
`GET /api/slips` — owner-only

Query params:
- `bot_id` (optional — ถ้าไม่ส่งรวมทุกบอทของ user)
- `status` (optional — ค่าเดียวหรือกลุ่ม)
- `date_from`, `date_to` (optional)
- `search` (optional — match `trans_ref` หรือชื่อลูกค้า)
- `page`, `per_page` (pagination server-side)

### Authorization (owner-only 2 ชั้น)
- Backend: middleware/gate เช็ค `user.role === 'owner'` → non-owner ได้ 403
- Query scope: จำกัดเฉพาะ bot ที่เป็นของ user นั้น (กัน tenant leak)

### Response
- `data[]`: แต่ละสลิป — id, created_at, สถานะ, amount, trans_ref, receiver_account, customer_name (จาก conversation), sender_name + bank + slip_datetime (ดึงจาก `raw_response`, null-safe), conversation_id (สำหรับลิงก์)
- `meta`: pagination + summary
  - summary: `total_amount_passed` (ผลรวม amount เฉพาะ passed ในช่วง filter), `count_today`, `count_abnormal`, `count_system_error`

### ไฟล์ที่แตะ (backend)
- `routes/api.php` — เพิ่ม route (ในกลุ่ม auth + owner)
- `app/Http/Controllers/Api/SlipVerificationController.php` — ใหม่ (`index`)
- `app/Http/Resources/SlipResource.php` — ใหม่ (แปลง raw_response → sender/bank/datetime, null-safe)
- อาจเพิ่ม relation `customerProfile` access ผ่าน conversation (มีอยู่แล้ว ใช้ eager load กัน N+1)

## Frontend Design

หน้า `SlipsPage.tsx` — ลอกแม่แบบ `pages/VipManagementPage.tsx`

### Layout
```
PageHeader: "สลิป / การชำระเงิน"
├─ Metric row (4 cards): เงินเข้าวันนี้ | สลิปวันนี้ | ผิดปกติ | error ระบบ
├─ Toolbar: [ค้นหา ref/ชื่อ] [BotPicker] [filter สถานะ] [ช่วงวันที่]
├─ Table (shadcn Table ใน Card):
│    เวลา | ลูกค้า | ยอด | ธนาคาร/ผู้โอน | ref | สถานะ(badge) | ปุ่มไปแชท
│    → แถวสถานะผิดปกติ highlight พื้นอ่อน
├─ Pagination (‹ หน้า x/y ›)
└─ คลิกแถว → รายละเอียด (raw slip fields) + ปุ่มเด้งไปแชท (ใช้ conversation_id)
```

### Status badge สี
- passed → เขียว
- fake / wrong_account → แดง
- duplicate / amount_mismatch / no_pending_order → ส้ม
- system error group → เทา

### ไฟล์ที่แตะ (frontend)
- `pages/SlipsPage.tsx` — ใหม่ (named export `SlipsPage`)
- `hooks/useSlips.ts` — ใหม่ (React Query v5, ลอก pattern `useOrders` เพราะมี filter+pagination server-side)
- `router.tsx` — เพิ่ม lazy import + route child `slips` ใน RootLayout block
- `components/layout/Sidebar.tsx` — เพิ่มเมนู (owner-only guard เหมือน Quick Replies) + icon `Receipt` จาก lucide-react
- `components/layout/MobileNav.tsx` — เพิ่มเมนูให้ขึ้นบนมือถือด้วย
- `types/api.ts` — เพิ่ม type ของ slip list item + summary

### UI polish
ตอน implement เรียก skill `ui-ux-pro-max:ui-styling` เพื่อ polish สี badge / metric card / layout ให้เข้ากับ design system เดิม

## จุดต้องระวัง (จากโค้ด/ประวัติจริง)

1. **Timezone** — DB บน Neon เก็บ UTC แต่ร้านอยู่ Bangkok (+7h) ห้ามใช้ `NOW()` ใน raw SQL. filter "วันนี้" และ metric `count_today` / การแสดงเวลา ต้องคิด offset +7 ให้ถูก (บั๊กเดิมตอน dashboard redesign)
2. **owner-only 2 ชั้น** — ซ่อนเมนู frontend อย่างเดียวไม่พอ backend ต้อง block 403 + scope bot ตาม user
3. **raw_response null** — เคส error ระบบ (`api_error`, `config_error`, `image_download_failed`) จะไม่มี raw_response → Resource ต้อง null-safe ทุก field ที่ดึงจาก raw
4. **N+1** — eager load `conversation.customerProfile` และ `bot` กันยิง query ต่อแถว

## Success Criteria (การพิสูจน์ว่าใช้ได้)

Backend (Feature test):
- owner เรียก `GET /api/slips` ได้ 200 พร้อม data + meta
- non-owner (admin/member) ได้ 403
- filter `status` คืนเฉพาะสถานะนั้น
- filter ช่วงวันที่คืนเฉพาะในช่วง (คิด +7h ถูก)
- summary: `total_amount_passed` = ผลรวม amount ของ passed จริง, `count_abnormal` นับกลุ่มผิดปกติถูก
- user เห็นเฉพาะสลิปของ bot ตัวเอง (ไม่หลุด tenant)

Frontend:
- หน้า render ได้, empty state เมื่อไม่มีสลิป, error state เมื่อ API พัง
- filter สถานะ/ค้นหาเปลี่ยนผลตาราง
- คลิกแถวเปิดรายละเอียด, ปุ่มไปแชทพาไปถูก conversation
- non-owner ไม่เห็นเมนู
