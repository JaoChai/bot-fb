# Auto Account Delivery — Remediation Plan (post-review PR #215)

> ที่มา: หลัง PR #215 (`98cf672`) merge เข้า main ทีม Opus 4 มุม + Fable ตรวจซ้ำ พบ 3 blocker + 6 hardening
> สถานะฟีเจอร์: อยู่บน main แล้ว แต่ยัง **ไม่เปิดใช้** (`ACCOUNT_DELIVERY_ENABLED=false`) — แก้ทันก่อนตั้ง env บน bot 26
> Decisions จากเจ้าของ: Blocker #1 = ทำ **ทั้งสองชั้น** (delivery + payment) · #9 = บอทภายนอก **ใช้ items_reserved ร่วม** → ต้อง prefix order_ref

หลักการทำงาน: ทุก task เขียน **failing test ก่อน** (TDD ตาม CLAUDE.md) แล้วค่อยแก้ให้ผ่าน · surgical changes · `/simplify` ก่อน commit ทุกครั้ง

---

## Wave 1 — Blockers (ต้องเสร็จก่อนเปิดสวิตช์ bot 26)

### PR A — credential ไม่ไหลออก LLM/OpenRouter + ไม่เก็บ plaintext (Blocker #2 + HIGH #5)
**ไฟล์:** `app/Services/Delivery/AccountDeliveryService.php` (`recordConversationMessage`)

- เปลี่ยน `content` ที่ insert ลง `messages` จาก credential ดิบ → **placeholder ต่อชิ้น**
  เช่น `"✅ ส่งบัญชี {product_name} แล้ว (#{stock_item_id})"` (support_link ใช้ข้อความ template สั้น)
- credential ตัวจริงส่งเข้า LINE เท่านั้น (ผ่าน `pushTextsToLine` เดิม) — ไม่แตะ
- คง `metadata.account_delivery=true` ไว้ (หน้าเว็บ/รายงานยังรู้ว่าเป็นข้อความส่งบัญชี)

**ทำไมปิด 2 ช่องพร้อมกัน:** `getConversationHistory` (`AIService.php:154-178`) ดึง `content` ของ bot message เข้า context ทุกเทิร์นโดยไม่กรอง metadata → เดิม credential ขึ้น OpenRouter; และ `messages` surface บนหน้าแชท → เดิม plaintext-at-rest. placeholder ตัดทั้งคู่

**Verify:**
1. test: หลัง `deliver()` สำเร็จ → `messages` แถวล่าสุด `content` **ไม่มี** substring ของ credential (ประกอบ credential ปลอมที่มี marker แล้ว assert ไม่พบ)
2. test: `content` มี product_name + stock_item_id (ยัง traceable)
3. credential จริงยังไปถึง LINE (assert `replyWithFallback` ได้ text ที่มี credential)

---

### PR B — กันขายซ้ำจาก 2 dispatch path (Blocker #1) — ทำทั้งสองชั้น
**ชั้น delivery (`AccountDeliveryService::createFromPayment`):**
- ก่อน `AccountDelivery::create` เพิ่ม guard: มี AccountDelivery ของ `conversation_id` เดียวกัน สถานะ ∈ {reserving, reserved, delivering, delivered} และ `amount` เท่ากัน ภายในหน้าต่าง `delivery.dedup_window_minutes` (default 30, env) → `return null`
- ทำใน transaction + `lockForUpdate` บน conversation (กัน 2 job แข่งกันผ่าน guard พร้อมกัน) หรืออาศัย unique index เสริม (ดูด้านล่าง)
- ทางเลือกเสริมที่แข็งกว่า: partial unique index `(conversation_id, amount)` where status ยัง active — แต่ FALSE-block การซื้อซ้ำจริง จึงใช้ guard + window แทน index

**ชั้น payment (สมมาตร):**
- `SlipVerificationService::verify` ก่อน return `passed=true` (บรรทัด ~229): เพิ่มเช็ค recent `manual_confirmed` บน conversation เดียวกัน (ยอดใกล้กัน) ใน window → ถือเป็น duplicate ไม่ dispatch ซ้ำ (แนวเดียวกับ check 2 เดิมแต่ครอบ manual)
- `ManualPaymentConfirmService::guardAgainstDoubleConfirm`: ขยาย `$windowSeconds` ให้ครอบเคส manual-after-easyslip (เดิม 120s สั้นไป) → ยกเป็น config เดียวกับ dedup window

**Tradeoff ที่ยอมรับ:** ลูกค้าซื้อของ **ยอดเท่ากัน** ซ้ำในหน้าต่างเวลาสั้นๆ อาจโดนบล็อก → เจ้าของยืนยันมือได้ (การ์ด/หน้าเว็บ) window ปรับได้

**Verify:**
1. test เคส A: manual confirm → delivery #1; แล้ว EasySlip pass ยอดเดียวกัน conversation เดิม → **ไม่เกิด delivery #2** (createFromPayment คืน null หรือ verify มองเป็น duplicate)
2. test เคส B: EasySlip pass → delivery #1; manual confirm ยอดเดียวกันหลัง 121s → **ไม่เกิด #2** (guard window ครอบ)
3. test regression: 2 conversation คนละคน / ยอดต่างกัน → เกิด delivery แยกได้ปกติ (ไม่ false-block)

---

### PR C — reconcile แยกแยะ "ส่งแล้ว" vs "คืนได้" + markSold retry (Blocker #3)
**ไฟล์:** `AccountDeliveryService::deliver`, `app/Console/Commands/ReconcileDeliveries.php`, job ใหม่ `MarkStockSold`

- **markSold retry job:** เมื่อ `markSold` throw หลัง push สำเร็จ → แทน swallow เฉยๆ ให้ `dispatch(new MarkStockSold($stockItemIds, $confirmedBy))` (backoff, tries=หลายครั้ง) เพื่อปิด limbo อัตโนมัติ + ยัง log ไว้ (idempotent: markSold ย้ายเฉพาะแถวที่ยังใน items_reserved)
- **reconcile disambiguation:** ใน `ReconcileDeliveries` เวลาเจอ orphaned reserved row ให้ join `order_ref` → `account_deliveries`:
  - delivery = `delivered` → ข้อความ `"⚠️ ส่งลูกค้าแล้วแต่ยังไม่ย้าย sold #{id} — ต้องย้ายเข้า items_sold, ห้ามขายซ้ำ"`
  - delivery = `canceled`/ไม่มี → ข้อความ `"ของจองค้าง คืน stock ได้ #{id}"`
  - เพิ่ม `delivered` เข้า activeRefs? **ไม่** — ต้องการให้ reconcile เห็นแถว delivered-ค้าง เพื่อ retry/แจ้ง; แต่ต้องแยกข้อความไม่ให้กำกวม

**Verify:**
1. test: markSold throw หลัง push → job MarkStockSold ถูก dispatch; รัน job → แถวย้ายเข้า items_sold
2. test: orphan row ที่ order_ref ชี้ delivery `delivered` → ข้อความ reconcile มีคำว่า "ห้ามขายซ้ำ" (แยกจากเคส canceled)
3. test regression: orphan ของ delivery canceled → ข้อความ "คืน stock ได้" เหมือนเดิม

---

## Wave 2 — Hardening (หลัง blocker, ก่อน/ระหว่างเปิดใช้จริง)

### PR D — Cross-DB limbo anchor + order_ref prefix + reconcile age filter (HIGH #4 + #9 + LOW)
**#4 anchor item ก่อน reserve** (`createFromPayment`):
- สร้าง item row (status ชั่วคราว เช่น `reserving`) **ก่อน** เรียก `reserveOne` แล้วค่อย `update(['stock_item_id'=>..., 'status'=>reserved])` หลัง reserve สำเร็จ → มี anchor เสมอแม้ crash
- reconcile เพิ่มเคส: delivery active ที่มี item `reserving` ค้าง / reserved row ใน mhha ที่ item ยังไม่มี stock_item_id → แจ้ง "ต้อง link มือ"

**#9 order_ref prefix (บอทภายนอกใช้ตารางร่วม — ยืนยันแล้ว):**
- `StockPoolService::reserveOne` เขียน `order_ref = "bfb:{delivery_id}"` (แทน `(string)$id` เดิม)
- `AccountDeliveryService::cancel`, `deliver` (getReserved by stock_item_id ไม่กระทบ) — จุดที่ map order_ref→delivery ต้องถอด prefix
- `ReconcileDeliveries` activeRefs → `"bfb:{id}"`; `orphanedReservedRows` กรอง **เฉพาะ** `order_ref LIKE 'bfb:%'` ก่อนเทียบ → ไม่แตะแถวของบอทภายนอกอีกต่อไป
- **migration/backfill:** ยังไม่มีข้อมูล prod (ยังไม่เปิดใช้) → ไม่ต้อง backfill; ถ้ามี row ค้างจากเทสต์ให้ล้าง

**LOW age filter:** `orphanedReservedRows` เพิ่มเงื่อนไข `reservedAt <= now()-10min` → ล้าง TOCTOU false alarm (W3) + ลด noise

**Verify:**
1. test: reserveOne เขียน order_ref ขึ้นต้น `bfb:`
2. test: items_reserved มีแถว order_ref ที่ไม่ใช่ `bfb:` (จำลองบอทภายนอก) → `orphanedReservedRows` **ไม่คืน**แถวนั้น
3. test: reserved row เพิ่งเกิด (<10 นาที) → ไม่ถูก flag orphan
4. test: crash หลัง reserveOne ก่อน update stock_item_id → มี item anchor + reconcile ตรวจเจอ

---

### PR E — Delivery UX safety: shortage visible + qty cap (MEDIUM #6 + LOW qty)
- **#6 shortage:** ตอน `deliver()` สำเร็จ ถ้ามี item `ST_SHORTAGE`/`ST_UNMAPPED` → ต่อท้ายข้อความสำเร็จ (`handleDeliveryAction` dv branch) ด้วย `"⚠️ ยังต้องส่งเอง: {names}"` — ไม่ให้คำเตือนหายตอน editMessageText แทนที่ทั้งข้อความ
- **qty cap:** `createFromPayment` `$qty = min(config('delivery.max_qty', 20), max(1, (int)$qty))`; ถ้าถูก cap ใส่ note ในการ์ด

**Verify:**
1. test: order มี shortage 1 ชิ้น + reserved 1 ชิ้น → กด dv → ข้อความสำเร็จมี "ยังต้องส่งเอง"
2. test: qty=999 → จองไม่เกิน cap + การ์ดมี note

---

### PR F — ProductMapper transparency + alias guard (MEDIUM #7)
**ไฟล์:** `app/Services/Delivery/ProductMapper.php`, `AccountDeliveryService` (การ์ด)
- ProductMapper: ข้าม term/alias ที่สั้นเกิน (เช่น < 3 ตัวอักษร) กัน `"bm"`/`"nl"` match กว้าง; ถ้ามีหลาย candidate ยาวเท่ากัน → คืน null (ให้ส่งเอง) แทนเดา
- การ์ด (`cardText`): เก็บชื่อรายการ **ต้นฉบับที่ parse** ควบคู่ แล้วโชว์ `"{raw} → {mapped}"` เมื่อไม่ตรงกัน เพื่อให้เจ้าของเห็น mismatch ก่อนกดยืนยัน

**Verify:**
1. test: item name ที่ match ได้เฉพาะผ่าน alias สั้น → ตอนนี้คืน null (unmapped)
2. test: การ์ดโชว์ทั้งชื่อต้นฉบับและชื่อที่ map เมื่อต่างกัน

---

### PR G — Per-user authorization ใน Telegram (MEDIUM #8)
**ไฟล์:** `app/Http/Controllers/Webhook/TelegramAlertCallbackController.php`
- เพิ่ม config ต่อ telegram plugin: `authorized_user_ids` (array ของ Telegram `from.id`)
- ถ้า set แล้ว → เช็ค `$cb['from']['id']` ∈ allowlist ก่อนทำ **ทุก action ที่มีผล** (pc/pa, dv/dx/dz); ไม่ set = พฤติกรรมเดิม (backward-compat)
- ครอบทั้ง delivery **และ** payment confirm (คนในกลุ่มสั่งรับเงิน/ส่งของไม่ได้ถ้าไม่ใช่ staff)

**Verify:**
1. test: allowlist set + from.id ไม่อยู่ในนั้น → action ไม่ถูก execute (delivery/confirm ไม่เกิด)
2. test: allowlist ว่าง → พฤติกรรมเดิม

---

## นอกสโคป code (ops/design ที่ต้องคุยแยก)
- I2: bot token ใน URL path (pre-existing pattern ทั้งระบบ ไม่ใช่ regression PR นี้)
- I3: 2 plugin token ชนกัน → พิจารณา unique constraint บน config->access_token
- ถ้าธุรกิจต้องการเก็บ credential ที่ส่งไปแล้วจริง (audit) → ทำ encrypted column แยก ไม่ใช่ messages.content

## ลำดับแนะนำ
Wave 1 (A→B→C) ขนานได้เพราะแตะคนละไฟล์เป็นหลัก (A=recordConversationMessage, B=createFromPayment+verify, C=deliver+reconcile+job) — ระวัง B กับ C แตะ `createFromPayment`/`deliver` ให้ rebase ตามกัน
Wave 2 (D→E→F→G) หลัง Wave 1 merge; D แตะ StockPoolService/reconcile กว้างสุดทำก่อน

## Definition of Done (ก่อนตั้ง ACCOUNT_DELIVERY_ENABLED=true บน bot 26)
- [ ] PR A, B, C merged + test เขียว
- [ ] รันชุด delivery tests เดิมทั้งหมดผ่าน (regression)
- [ ] ยืนยัน `MHHA_ACC_DATABASE_URL` + DDL mhha + mapping product_stocks พร้อม (ops เดิม)
- [ ] E2E บน bot 26 ครบ flow: จ่าย → การ์ด → กดส่ง → ลูกค้าได้ credential → markSold → คุยต่อแล้ว **credential ไม่โผล่ใน LLM/หน้าเว็บ**
