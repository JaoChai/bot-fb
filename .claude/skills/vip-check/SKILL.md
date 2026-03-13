# VIP Check Skill

## Metadata
- **Trigger**: `/vip-check` หรือเมื่อ user ถามเรื่อง VIP, promote, ปรับ VIP, ดู VIP candidates
- **Platform**: Production DB (Neon)
- **Dependencies**: `mcp__neon__run_sql` (project: `solitary-math-34010034`)

## Purpose

ตรวจสอบลูกค้าที่พร้อม promote เป็น VIP โดยใช้ `vip_candidates` VIEW บน production DB
และสร้าง memory note VIP ให้อัตโนมัติเมื่อ user confirm

## Workflow

### Step 1: Query VIP Candidates

```sql
-- ใช้ mcp__neon__run_sql กับ projectId: solitary-math-34010034
SELECT * FROM vip_candidates;
```

### Step 2: แสดงผลเป็นตาราง

| # | ชื่อ | Channel | ออเดอร์ | ยอดรวม | สินค้าที่ซื้อ | ออเดอร์ล่าสุด |
|---|------|---------|---------|--------|--------------|--------------|
| 1 | display_name | channel_type | completed_orders | total_spent | products_bought | last_order_at |

- ถ้าไม่มี candidates → แจ้ง "ไม่พบลูกค้าที่พร้อม promote เป็น VIP ในตอนนี้"
- ถ้ามี → ถาม user ว่าจะ promote ทั้งหมด หรือเลือกบางคน

### Step 3: ดึงราคาจริงจาก order_items (ก่อน promote)

สำหรับแต่ละ candidate ให้ query ราคาจริง:

```sql
SELECT DISTINCT oi.product_name, oi.unit_price
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
WHERE o.conversation_id = <conversation_id>
  AND o.status IN ('confirmed', 'completed', 'delivered', 'paid')
  AND oi.unit_price IS NOT NULL
ORDER BY oi.product_name;
```

### Step 4: สร้าง Memory Note Content

**ต้องตรง pattern เดิมทุกประการ** — ดูตัวอย่างจริงจาก production:

```
ลูกค้า VIP ปิดขายให้เร็ว | สินค้าเดิม: Nolimit Level Up+ BM, Page | ราคา: ตาม KB ปกติ (1,100/199) | เฟส = Nolimit BM (ไม่ใช่ G3D)
```

**Product Name Mapping** (order_items → VIP note):

| order_items.product_name | ชื่อใน VIP note | ราคา KB ปกติ | เป็น Nolimit? |
|--------------------------|-----------------|-------------|--------------|
| Nolimit BM | Nolimit Level Up+ BM | 1,100 | ใช่ |
| Nolimit Personal | Nolimit Level Up+ Personal | 1,100 | ใช่ |
| Nolimit | Nolimit Level Up+ | 1,100 | ใช่ |
| Page | Page | 199 | ไม่ |
| G3D | G3D | (ดู KB) | ไม่ |

**Content Assembly Rules:**

1. **สินค้าเดิม**: map product names ตามตารางด้านบน คั่นด้วย `, `
2. **ราคา**: ใช้ราคาจาก unit_price ถ้ามี ไม่งั้นใช้ราคา KB ปกติ
   - สินค้าเดียว: `(1,100)`
   - หลายสินค้า: `(1,100/199)` — คั่นด้วย `/`
3. **เฟส**: ระบุ Nolimit product หลักตัวแรกที่เจอ + `(ไม่ใช่ G3D)`
   - ถ้าซื้อ Nolimit BM → `เฟส = Nolimit BM (ไม่ใช่ G3D)`
   - ถ้าซื้อ Nolimit Personal → `เฟส = Nolimit Personal (ไม่ใช่ G3D)`
   - ถ้าไม่มี Nolimit product → ไม่ต้องใส่ส่วน เฟส

### Step 5: Promote VIP (เมื่อ user confirm)

1. **ดึง memory_notes เดิม**:
```sql
SELECT id, memory_notes FROM conversations WHERE id = <conversation_id>;
```

2. **สร้าง note JSON** ตาม format:
```json
{
  "id": "<UUID v4>",
  "type": "memory",
  "content": "<content จาก Step 4>",
  "created_by": 14,
  "created_at": "<ISO timestamp>",
  "updated_at": "<ISO timestamp>"
}
```

3. **Append เข้า memory_notes** — ระวัง JSONB syntax:

ถ้า memory_notes เป็น NULL:
```sql
UPDATE conversations
SET memory_notes = '[{"id":"<uuid>","type":"memory","content":"<content>","created_by":14,"created_at":"<ts>","updated_at":"<ts>"}]'::jsonb
WHERE id = <conversation_id>;
```

ถ้า memory_notes มีอยู่แล้ว (เป็น array):
```sql
UPDATE conversations
SET memory_notes = memory_notes || '[{"id":"<uuid>","type":"memory","content":"<content>","created_by":14,"created_at":"<ts>","updated_at":"<ts>"}]'::jsonb
WHERE id = <conversation_id>;
```

> **สำคัญ**: note ใหม่ต้องครอบด้วย `[...]` เป็น array ก่อน `||` เพื่อ append เข้า array เดิม
> **สำคัญ**: `created_by: 14` คือ admin user ที่ใช้สำหรับ auto-generated VIP notes

### Step 6: Verify

```sql
SELECT id, memory_notes FROM conversations WHERE id IN (<promoted_ids>);
```

แสดงผลยืนยันว่า promote สำเร็จกี่คน พร้อม content ที่ใส่ไป

## Notes

- `vip_candidates` VIEW exclude ลูกค้าที่มี 'vip' ใน memory_notes แล้ว (ไม่ซ้ำ)
- Threshold: >= 3 completed orders (confirmed/completed/delivered/paid)
- VIEW ใช้ subquery aggregate orders ก่อน JOIN order_items → นับ orders ถูกต้องไม่ซ้ำ
- ถ้าต้องเปลี่ยน threshold → `CREATE OR REPLACE VIEW` แก้ HAVING clause
- ราคาอาจเปลี่ยนได้ → ใช้ unit_price จาก order_items ล่าสุดเป็น reference แล้ว cross-check กับราคา KB
