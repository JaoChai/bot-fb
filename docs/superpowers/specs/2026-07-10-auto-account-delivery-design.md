# Auto Account Delivery — ระบบส่งบัญชีอัตโนมัติจาก stock `mhha_acc_db`

**วันที่:** 2026-07-10
**สถานะ:** อนุมัติ design แล้ว รอเขียน implementation plan
**ขอบเขต:** LINE bot 26 ตัวเดียว (ขยายภายหลังได้)

## 1. โจทย์

ลูกค้าซื้อบัญชีโฆษณา/เพจผ่านแชท LINE bot 26 ปัจจุบันหลังยืนยันเงิน (EasySlip ผ่าน หรือเจ้าของกดยืนยันรับเงินใน Telegram) การส่งสินค้าเป็น manual 100% — เจ้าของอ่าน alert แล้วไปหยิบบัญชีส่งเอง

ต้องการให้ระบบหยิบบัญชีจาก stock DB (`mhha_acc_db` — Neon project แยก, id `muddy-mountain-79902399`) มาส่งให้ลูกค้าอัตโนมัติ โดยมีเงื่อนไขสำคัญ:

- มี **บอทเบิก stock ผ่าน Telegram แยกต่างหาก** (ระบบภายนอก แก้ไม่ได้ ใช้ต่อเหมือนเดิม) อ่าน/ตัดของจาก `items_available` ตัวเดียวกัน
- การส่งสินค้า **ผิดพลาดไม่ได้** — บัญชีหนึ่งใบต้องไปถึงมือคนเดียวเท่านั้น

## 2. ข้อมูลจริงที่ตรวจแล้ว

### 2.1 `mhha_acc_db` (Postgres 17, Neon org "boom")

| ตาราง | บทบาท | หมายเหตุ |
|---|---|---|
| `items_available` | stock พร้อมขาย | คอลัมน์: `id, name, detail, type, viaId, bmId, adsId, cost, price, createdAt, updatedAt` |
| `items_claimed` | ของที่ถูกเบิกผ่านบอท Telegram | มี `first_name`, `username` ของคนเบิก |
| `items_sold` | ขายแล้ว (~4,000 แถว) | โครงเดียวกับ claimed |
| `ad_accounts` | ข้อมูล via/bm/ads id | ไม่เกี่ยวกับ flow นี้ |

- `detail` = credential เป็น string คั่นด้วย `|` (เช่น `uid|pass|email|2fa`) — ส่งให้ลูกค้าแบบดิบตามที่เก็บ
- รหัสสินค้า (`name`): `NLMP` (Nolimit ส่วนตัว), `NLMBM` (Share BM), `G3D` (เฟสไก่), `PAGE` (เพจ)

### 2.2 ฝั่ง bot-fb (สิ่งที่มีอยู่แล้ว)

- **จุดยืนยันเงิน 2 จุด** ที่รู้ order summary แล้ว: `LineWebhookResponseService::trySlipVerification()` (สลิปผ่าน) และ `ManualPaymentConfirmService::confirm()` (เจ้าของกดยืนยัน)
- **โครง Telegram ปุ่ม inline** ใช้ซ้ำได้: `TelegramAlertBotService` + `TelegramAlertCallbackController` (validate secret + chat_id, parse `callback_data`, มี pattern ยืนยัน 2 จังหวะ)
- **การ parse รายการสินค้า**: `PaymentMessageDetector::parseItems()` ได้ `{name, total, price?, qty?}` ต่อรายการ
- **`product_stocks`**: สวิตช์เปิด/ปิดขายต่อสินค้า + `aliases` สำหรับจับคู่ชื่อ — ไม่มีจำนวน ไม่มีการจอง
- **ไม่มี**การตัด stock, ไม่มี credential store, ไม่มี delivery step ใด ๆ (Order ถูก mark `completed` ตั้งแต่จ่ายเงิน)

## 3. การตัดสินใจหลัก (ตกลงกับเจ้าของแล้ว)

| เรื่อง | ตัดสินใจ |
|---|---|
| แนวทางจอง | **A: หยิบออกจากตะกร้าทันที** — ย้ายแถวออกจาก `items_available` แบบ atomic ตั้งแต่สลิปผ่าน บอทเบิกภายนอกจึงมองไม่เห็นของที่จองแล้ว (ตัด race ที่ราก) |
| ระดับ auto | สลิปผ่าน → จอง → เจ้าของกดปุ่มใน Telegram → ค่อยส่งให้ลูกค้า (ไม่ส่งเองโดยไม่มีคนกด) |
| สินค้าเพจ (PAGE) | ไม่ดึง stock — ส่งข้อความ template ลิงก์ Support ให้ลูกค้าไปเบิกกับทีม Support แทน |
| ออเดอร์ผสม (บัญชี + เพจ) | รองรับ — ส่งทั้ง credential และลิงก์ Support ในงานเดียว |
| ของค้าง (ไม่กดยืนยัน) | ไม่คืนเข้า stock อัตโนมัติ — เตือนซ้ำใน Telegram ทุก 30 นาทีจนกว่าจะกด (มีปุ่มยกเลิกคืน stock แบบ manual) |
| รูปแบบข้อความ credential | string ดิบตามที่เก็บ ไม่ผ่าน LLM ไม่ parse |
| จับคู่สินค้า | Nolimit ส่วนตัว → `NLMP`, Share BM → `NLMBM`, เฟสไก่ → `G3D`, เพจ → support link |

## 4. สถาปัตยกรรม

```
ลูกค้าส่งสลิป (LINE bot 26)
   │
   ▼
EasySlip ผ่าน ───────────────── สลิปไม่ผ่าน → เจ้าของกดยืนยันรับเงิน (ปุ่มเดิม)
   │                                  │
   ▼                                  ▼
【ReserveAccountsJob】 จองของ: items_available → items_reserved (atomic)
   │
   ▼
การ์ด Telegram: รายการ + เลขอ้างอิงของที่จอง + [✅ ส่งให้ลูกค้า] [↩️ คืน stock]
   │
   ▼ เจ้าของกดส่ง
ส่ง credential ดิบ + ลิงก์ Support (ถ้ามีเพจ) ให้ลูกค้าใน LINE
   │
   ▼ LINE ตอบสำเร็จเท่านั้น
items_reserved → items_sold + การ์ดเปลี่ยนเป็น "✅ ส่งแล้ว"
```

หลักการ: **ของหนึ่งชิ้นอยู่ได้ที่เดียวเสมอ** (`available` → `reserved` → `sold`) ทุกการย้ายเป็น atomic ระดับ DB

## 5. Database changes

### 5.1 ใน `mhha_acc_db` (additive เท่านั้น — ไม่แตะตาราง/บอทเดิม)

ตารางใหม่ `items_reserved`:
- โครงเหมือน `items_available` ทุกคอลัมน์
- เพิ่ม `order_ref` (text — id ของ `account_deliveries` ฝั่ง bot-fb) และ `reservedAt` (timestamp)

### 5.2 ใน DB bot-fb (Neon `bot-facebook`)

**`account_deliveries`** — 1 งานส่งของต่อ 1 การชำระเงิน:
- `id`, `bot_id`, `conversation_id`, `slip_verification_id` (**unique** — กันงานซ้ำ), `order_id` (nullable)
- `status`: `reserving` → `reserved` → `delivered` | `canceled` | `failed`
- `amount`, `telegram_message_id`, `confirmed_by` (nullable), `delivered_at`, `last_reminded_at`, timestamps

**`account_delivery_items`** — ของแต่ละชิ้น/รายการในงาน:
- `id`, `delivery_id` (FK), `product_name` (ชื่อจากออเดอร์), `stock_code` (nullable), `kind`: `stock` | `support_link` | `manual`
- `stock_item_id` (id แถวใน mhha DB, nullable), `status`: `reserved` | `delivered` | `shortage` | `unmapped` | `returned`
- รายการที่จองไม่ได้ (ของขาด / จับคู่ชื่อไม่ได้) บันทึกเป็นแถว `shortage` / `unmapped` เพื่อโชว์ในการ์ดว่า "ต้องส่งเอง"

**`product_stocks`** (ตารางเดิม) เพิ่มคอลัมน์:
- `stock_code` (text nullable — `NLMP`/`NLMBM`/`G3D`)
- `delivery_method` (text: `stock` | `support_link` | `none`, default `none`)

### 5.3 Connection ใหม่

- เพิ่ม connection `mhha_acc` (pgsql) ใน `config/database.php` + env `MHHA_ACC_DATABASE_URL`
- ใช้ query builder ตรง (`DB::connection('mhha_acc')`) ไม่สร้าง Eloquent model ผูก schema ภายนอก

### 5.4 นโยบาย credential

- credential ตัวจริงอยู่ใน `mhha_acc_db` ที่เดียว — bot-fb เก็บเฉพาะ `stock_item_id`
- **ห้าม log ค่า `detail`** ทุกกรณี (log ได้แค่ id/รหัสสินค้า)
- การ์ด Telegram โชว์เลขอ้างอิง ไม่โชว์ password

## 6. การจอง (ReserveAccountsJob)

Trigger 2 จุด (converge เป็น job เดียว, queue เดิมของระบบ):
1. `trySlipVerification()` — branch `passed` (หลังส่งข้อความ "เงินเข้าแล้ว" ให้ลูกค้า)
2. `ManualPaymentConfirmService::confirm()` — post-commit hook

ขั้นตอน:
1. กันซ้ำ: insert `account_deliveries` (unique `slip_verification_id`) — ถ้ามีอยู่แล้ว จบเงียบ
2. จับคู่รายการออเดอร์ (จาก `PaymentMessageDetector::parseItems()`) กับ `product_stocks` ด้วย name/aliases → ได้ `stock_code` + `delivery_method` ต่อรายการ
3. รายการ `kind=stock`: จองทีละชิ้น (qty กี่ชิ้นทำกี่รอบ) ด้วย SQL atomic บน connection `mhha_acc`:

```sql
DELETE FROM items_available
WHERE id = (SELECT id FROM items_available WHERE name = :code
            ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)
RETURNING *;
```

   `DELETE ... RETURNING` + insert เข้า `items_reserved` (พร้อม `order_ref`) ต้องอยู่ใน **transaction เดียวกัน** บน connection `mhha_acc` — สำเร็จแล้วจึง insert `account_delivery_items` ฝั่ง bot-fb ทันทีต่อชิ้น
   ไม่ได้แถว (หมด) → บันทึก `shortage`
4. รายการ `kind=support_link` → บันทึกแถว `support_link` (ไม่แตะ stock)
5. จับคู่ไม่ได้ → บันทึก `unmapped`
6. อัพเดต delivery เป็น `reserved` แล้วส่งการ์ด Telegram

ความทนทาน:
- mhha DB ล่ม → job retry ตาม backoff ของ queue; retry หมดแล้วยังพัง → delivery = `failed` + แจ้ง Telegram "จองไม่สำเร็จ ส่งเองนะ"
- ล่มกลางคัน (ย้ายแถวแล้วแต่ยังไม่บันทึกฝั่ง bot-fb) → console command `delivery:reconcile` สแกน `items_reserved` ที่ `order_ref` ไม่มีคู่ / delivery ค้าง `reserving` เกิน 10 นาที แล้วแจ้งเตือนใน Telegram (ไม่แก้เองอัตโนมัติ)

## 7. การ์ด Telegram + ปุ่ม

ส่งผ่านบอท alert ตัวเดิม (`TelegramAlertBotService` + FlowPlugin config เดิม):

```
🚚 พร้อมส่งสินค้า — {ชื่อลูกค้า} (ยอด {amount} บาท)
📦 NLMP ×1 — จองแล้ว (#4512)
📦 PAGE ×2 — จะส่งลิงก์ Support ให้ลูกค้า
⚠️ NLMBM ×1 — ของหมด ต้องส่งเอง        ← แสดงเมื่อมี shortage/unmapped
[✅ ส่งให้ลูกค้าเลย]  [↩️ ยกเลิก คืนเข้า stock]
```

Callback actions ใหม่ใน `TelegramAlertCallbackController` (ต่อจาก `pa`/`pc` เดิม):
- `dv|{deliveryId}` — ส่งให้ลูกค้า (กดครั้งเดียว ไม่ต้อง 2 จังหวะ เพราะเงินยืนยันแล้วและการ์ดคือหน้ารีวิว)
- `dx|{deliveryId}` — ยกเลิก: ยืนยัน 2 จังหวะ (pattern เดิมของปุ่มสลิปน่าสงสัย) → ย้าย `items_reserved` → `items_available` กลับ, delivery = `canceled`, items = `returned`
- กดซ้ำ / สถานะไม่ใช่ `reserved` → `answerCallbackQuery` "ส่งไปแล้ว/ยกเลิกไปแล้ว" ไม่ทำอะไรเพิ่ม

การเตือนซ้ำ: scheduled command ทุก 30 นาที หา delivery `status=reserved` ที่ค้างเกิน 30 นาที (เช็ค `last_reminded_at`) → ส่งข้อความเตือนใน Telegram ซ้ำจนกว่าจะกด

## 8. การส่งให้ลูกค้า (AccountDeliveryService::deliver)

1. Lock แถว delivery (`lockForUpdate`) — ผ่านเฉพาะ `status=reserved` (กันกดพร้อมกัน/กดซ้ำ)
2. อ่าน `detail` จาก `items_reserved` ตาม `stock_item_id`
3. ประกอบข้อความ:
   - ต่อบัญชี: หัวข้อสั้น (`✅ บัญชีของคุณ (1/2) — Nolimit ส่วนตัว`) + `detail` ดิบ
   - มีเพจ: ข้อความ template ลิงก์ Support (เก็บเป็น template แก้ได้ ไม่ hardcode — ค่าเริ่มต้นตามข้อความที่เจ้าของให้: LINK LINE `https://lin.ee/sTD5TQL`, ID `@743ddeqy`)
   - แบ่งหลายข้อความถ้ายาวเกิน limit ของ LINE
4. Push เข้า LINE (pattern เดียวกับ `pushToLine` ใน `ManualPaymentConfirmService`)
5. **สำเร็จเท่านั้น** → transaction: ย้ายแถว `items_reserved` → `items_sold` (ใส่ `first_name`/`username` = ชื่อคนกด + "bot-fb"), delivery = `delivered`, items = `delivered`, แก้การ์ดเป็น "✅ ส่งแล้ว โดย {name} {เวลา}"
6. บันทึกข้อความที่ส่งเข้าประวัติแชท (bot `Message` + metadata `account_delivery`) — บอทและหน้าเว็บแชทเห็นว่าส่งอะไรไปแล้ว
7. Push ล้มเหลว → ของคงอยู่ `reserved`, การ์ดเปลี่ยนเป็น "❌ ส่งไม่สำเร็จ กดลองใหม่" (ปุ่มเดิมกดซ้ำได้)

## 9. ตารางกรณีผิดพลาด

| เหตุการณ์ | พฤติกรรมระบบ |
|---|---|
| ของหมด/ขาดบางชิ้นตอนจอง | จองเท่าที่มี การ์ดระบุจำนวนที่ขาดชัดเจน เจ้าของส่งเองส่วนที่ขาด |
| จับคู่ชื่อสินค้าไม่ได้ | รายการขึ้น "ต้องส่งเอง" ไม่เดา |
| `mhha_acc_db` ล่มตอนจอง | queue retry → พังถาวรค่อยแจ้ง Telegram ให้ส่งเอง |
| webhook ยิงซ้ำ / job รันซ้ำ | unique `slip_verification_id` — 1 สลิป = 1 งานเสมอ |
| กดปุ่มพร้อมกัน / กดซ้ำ | row lock + status guard — ส่งครั้งเดียวเสมอ |
| LINE push ล้มเหลว | ของไม่เข้า `sold`, กดลองใหม่ได้ |
| ระบบล่มกลางคัน | `delivery:reconcile` ตรวจ + แจ้งเตือน ไม่แก้เอง |
| คนเบิก Telegram แย่งชิ้นสุดท้ายพร้อมกัน | `DELETE ... SKIP LOCKED` — ฝ่ายเดียวได้ไป อีกฝ่ายเห็น shortage ไม่มีทางได้ของซ้ำ |
| เจ้าของไม่กดยืนยัน | เตือนซ้ำทุก 30 นาที ของไม่หลุดกลับ stock เอง |

## 10. Stock sync อัตโนมัติ (รวมใน v1)

Scheduled command ทุก 5 นาที: นับ `items_available` ต่อ `stock_code` →
- เหลือ 0 → ปิด `product_stocks.in_stock` อัตโนมัติ (บอทหยุดเชียร์ขายทันที ผ่านกลไก stock injection เดิม)
- กลับมามีของ → เปิดสวิตช์คืน
- ทุกครั้งที่สลับ ให้ล้าง cache เดิม (`product_stocks:all` + RagCache) ตาม pattern ของ `ProductStockController::update()`

## 11. สิ่งที่ไม่ทำใน v1 (ตัดสินใจแล้ว)

- ช่องทางอื่นนอกจาก LINE bot 26 (Facebook / bot อื่น) — โครงรองรับอยู่แล้ว ค่อยเปิดทีหลัง
- หน้า UI ดูรายการ delivery ในเว็บ — Telegram คือหน้าจอหลักของ v1 (ดูข้อมูลดิบได้จาก DB)
- แก้ไข/ทดแทนบอทเบิก Telegram เดิม — ใช้ต่อเหมือนเดิม
- ส่งอัตโนมัติแบบไม่มีคนกด — อาจพิจารณาหลังระบบพิสูจน์ตัวเองแล้ว

## 12. การทดสอบ

1. **Unit**: จับคู่ชื่อสินค้า (รวม aliases), นับ qty, ประกอบข้อความ (บัญชีเดี่ยว/หลายใบ/ผสมเพจ), template Support
2. **Concurrency**: จองพร้อมกัน 2 process แย่งชิ้นสุดท้าย → ต้องได้ฝ่ายเดียว (ทดสอบกับ Postgres จริงบน Neon branch)
3. **Feature**: ครอบทุกแถวในตารางกรณีผิดพลาด (ข้อ 9) + idempotency ทุกจุด
4. **Manual E2E บน bot 26**: สินค้าทดสอบราคา 1 บาท — สลิปจริง → จอง → การ์ด → กดส่ง → ตรวจข้อความใน LINE → ตรวจแถวย้ายเข้า `items_sold` ถูกต้อง แล้วค่อยเปิดใช้กับสินค้าจริง
