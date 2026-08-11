# ทยอยเบิกของ → เสนอให้ซื้อทีละตัว — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ลูกค้าที่จะจ่ายก้อนใหญ่แล้วขอทยอยรับของทีหลัง → บอทเสนอให้จ่ายเท่าที่จะรับตอนนี้ก่อน (ราคาต่อหน่วยเท่ากัน) และห้ามรับปากว่าจะเก็บของไว้ให้เบิกทีหลัง

**Architecture:** แก้ system prompt ของ flow 24 ใน Neon prod โดยตรง (ไม่แตะโค้ด ไม่มี migration ไม่มี PR) — เพิ่มบล็อก `<staggered_pickup>` 1 บล็อก + ต่อท้าย checklist ข้อ 4 ให้ชี้เข้าบล็อกใหม่ แล้วล้าง cache prompt บน container prod และทดสอบด้วยการเรียก `RAGService` จริงบน prod

**Tech Stack:** Neon PostgreSQL 17 (`bot-facebook` / `solitary-math-34010034`) · Laravel 13 บน Railway · `railway ssh` one-off · Neon MCP `run_sql`

**Spec:** `docs/superpowers/specs/2026-08-11-staggered-pickup-design.md`

## Global Constraints

- แก้ได้เฉพาะ `flows.system_prompt` แถว `id = 24` เท่านั้น — ห้ามแตะ flow อื่น ห้ามแตะโค้ด backend/frontend
- ทุก `UPDATE flows` ต้องมี `WHERE id = 24` เสมอ และต้อง backup ลง `flow_audit_logs` ก่อนเสมอ
- ห้ามใช้ `now()` เปล่าๆ เขียนคอลัมน์เวลา — DB เก็บเวลาไทย ส่วน Neon `now()` เป็น UTC → ต้องใช้ `now() + interval '7 hours'`
- แก้ prompt แล้ว **ต้อง** `php artisan cache:forget bot:26:default_flow` เสมอ (FlowCacheService แคช 30 นาที) ไม่ทำ = prompt เก่ายังวิ่งอยู่ 30 นาที
- ข้อความสคริปต์ในบล็อกใหม่ต้องตรงตาม spec ทุกตัวอักษร (สคริปต์ลูกค้าเห็นจริง) ห้ามแต่งเพิ่ม/ตัดคำเอง
- ห้ามใส่คำว่า "เก็บไว้เบิกทีหลัง" / "ฝากไว้ก่อน" ในความหมายว่าทำได้ ลงใน prompt เด็ดขาด
- `railway ssh` ตัด argument ยาว → สคริปต์ PHP ต้อง pipe เข้า container ทาง stdin เท่านั้น (ดู Task 4)

**ค่าคงที่ที่ใช้ซ้ำ — Railway prod:**
```
--project ba714504-2721-4535-9fc7-6b3d903c481a
--environment 40f44433-1f1e-40cb-8e0c-7b2e83ff14a4
--service 36066744-e919-4084-ab57-a31e76694a9a
```

---

### Task 1: Backup prompt เดิมลง flow_audit_logs

**Files:**
- Modify (DB): ตาราง `flow_audit_logs` — เพิ่ม 1 แถว action `backup_before_staggered_pickup`
- อ่านอย่างเดียว (DB): `flows` id=24

**Interfaces:**
- Consumes: ไม่มี (task แรก)
- Produces: แถว backup ที่ task ถัดไปใช้เป็นทางถอย — อ้างถึงด้วย `SELECT field_changes->>'system_prompt' FROM flow_audit_logs WHERE action = 'backup_before_staggered_pickup'`

- [ ] **Step 1: จดความยาว prompt ปัจจุบันไว้ก่อน**

รันด้วย Neon MCP `run_sql` (projectId `solitary-math-34010034`):

```sql
SELECT length(system_prompt) AS len_before,
       (length(system_prompt) - length(replace(system_prompt, '</multi_order>', ''))) / 14 AS anchor1_count,
       (length(system_prompt) - length(replace(system_prompt, ' 4. MULTI-ORDER? → ออเดอร์ยังไม่จ่าย', ''))) / 36 AS anchor2_count
FROM flows WHERE id = 24;
```

Expected: `len_before` = 29248 · `anchor1_count` = 1 · `anchor2_count` = 1

⛔ ถ้า anchor ตัวใดได้ 0 หรือ >1 → **หยุด** อย่า UPDATE ต่อ (แปลว่า prompt ถูกแก้ไประหว่างทาง) ให้รายงานเจ้าของก่อน

- [ ] **Step 2: เขียนแถว backup**

```sql
INSERT INTO flow_audit_logs (flow_id, user_id, action, field_changes, created_at, updated_at)
SELECT 24, NULL, 'backup_before_staggered_pickup',
       json_build_object(
         'reason', 'ทยอยเบิกของ: บอทรับปาก "เก็บไว้เบิกภายหลัง" ทั้งที่ระบบส่งของครบ qty ทันที (conv #1451 2026-08-11) — spec docs/superpowers/specs/2026-08-11-staggered-pickup-design.md',
         'system_prompt', system_prompt
       ),
       now() + interval '7 hours', now() + interval '7 hours'
FROM flows WHERE id = 24;
```

- [ ] **Step 3: ตรวจว่า backup อ่านกลับได้จริงและครบ**

```sql
SELECT id,
       length(field_changes->>'system_prompt') AS backup_len,
       (SELECT length(system_prompt) FROM flows WHERE id = 24) AS live_len
FROM flow_audit_logs
WHERE action = 'backup_before_staggered_pickup';
```

Expected: ได้ 1 แถว (id = 23) · `backup_len` = `live_len` = 29248

⛔ ถ้า `backup_len` ≠ `live_len` → หยุด อย่าไปต่อ

---

### Task 2: แทรกบล็อก `<staggered_pickup>` เข้า prompt

**Files:**
- Modify (DB): `flows.system_prompt` แถว id=24 — แทรกบล็อกใหม่ต่อจาก `</multi_order>`

**Interfaces:**
- Consumes: แถว backup จาก Task 1 (ทางถอย)
- Produces: แท็ก `<staggered_pickup>` … `</staggered_pickup>` ใน prompt ที่ Task 3 จะอ้างถึงจาก checklist

- [ ] **Step 1: แทรกบล็อก**

ใช้ dollar-quoting (`$blk$`) ห้ามใช้ single quote ครอบ เพราะข้อความมีอักขระพิเศษ:

```sql
UPDATE flows
SET system_prompt = replace(system_prompt, '</multi_order>', $blk$</multi_order>

<staggered_pickup>
TRIGGER: ลูกค้าจะจ่ายจำนวนหนึ่ง แต่ขอรับของน้อยกว่านั้นตอนนี้ แล้วเก็บส่วนที่เหลือไว้รับทีหลัง
คำสัญญาณ: "เบิกก่อน X", "เบิกทีละ", "เอาก่อน X", "รับก่อน X", "ที่เหลือค่อย...", "ค่อยเอา/ค่อยรับ", "เก็บไว้ก่อน", "ยังไม่เอาตอนนี้", "จ่าย X แต่เอา Y ก่อน"
⛔ ไม่ใช่ trigger: "ทยอยส่งก็ได้" / "ตัวไหนส่งก่อนได้ส่งมาเลย" = ลูกค้ายอมให้ร้านทยอยส่ง (เร่งของ) ไม่ใช่ขอฝากของ → ตอบปกติ ห้ามเสนอแยกออเดอร์

⛔ กฎเหล็ก: ร้านไม่มีระบบฝากของ — เงินเข้า = ระบบตัดสต็อกและส่งของครบตามจำนวนทันที
ห้ามพูดเด็ดขาด: "เก็บไว้เบิกทีหลัง", "ฝากไว้ก่อน", "ค้างไว้ได้", "ทยอยเบิกได้"

A. ยังไม่จ่าย (ยังไม่ส่งสลิป) → เสนอแยกออเดอร์ทันที (1 ข้อความ):
"อ๋อ ได้ครับพี่ 👍 แต่บอกไว้ก่อนนะครับ ของเราซื้อกี่ตัวราคาก็เท่ากันครับ ตัวละ [ราคา] พี่ไม่ต้องจ่ายก้อนใหญ่ค้างไว้เลยครับ
แนะนำจ่ายเท่าที่จะรับตอนนี้ก่อนครับ — รอบนี้ [จำนวนที่จะรับ] ตัว [ยอด] บาท พอจะเอาเพิ่มเมื่อไหร่ ทักมาสั่งได้เลยครับ ส่งให้ใน 5-10 นาทีเหมือนเดิม
เอาแบบนี้ไหมครับ? หรือจะรับครบ [จำนวนเต็ม] ตัวเลยก็ได้ครับ"
→ ตอบรับ ("เอา 1 ก่อน"/"ทีละตัว") = แก้ตะกร้าเป็นจำนวนที่จะรับจริง (Page ปรับตามสัดส่วน: รับ 1 ชุด = Page 1 ตัว) แล้วไป Step 2 ปกติ
→ เลือกรับครบ = ขายจำนวนเต็มตามปกติ ห้ามทวงซ้ำ
→ ยืนกรานจะจ่ายเต็มแต่ขอรับทีหลัง = ใช้ B

B. ยืนกราน / จ่ายมาแล้ว → ปิดประตู (1 ข้อความ):
"ต้องขออภัยจริงๆ ครับพี่ ทางร้านไม่มีระบบฝากของไว้เบิกทีหลังนะครับ พอเงินเข้าปุ๊บ ระบบจะตัดสต็อกและส่งของครบตามจำนวนทันทีเลยครับ
ถ้าพี่ยังไม่อยากรับครบตอนนี้ แนะนำจ่ายเฉพาะจำนวนที่จะใช้ก่อนครับ ราคาเท่าเดิมทุกตัว สั่งเพิ่มทีหลังได้ตลอดครับ"
(จ่ายมาแล้ว = ส่งของครบตามยอดที่จ่ายเสมอ ห้ามหน่วงของไว้)

C. ถามเรื่องเวลาเคลม/ประกัน ("ยังไม่เบิกยังไม่นับเวลาเคลมใช่ไหม") → ⛔ ห้ามยืนยันวันเริ่มนับเด็ดขาด
"เรื่องเงื่อนไขรับประกัน ขอให้ดูรายละเอียดที่ https://mhhacoursecontent.my.canva.site/ads-vance นะครับ ถ้าอยากได้คำตอบชัดๆ เดี๋ยวผมให้ทีมงานยืนยันให้อีกทีครับ"

D. STOCK GUARD: ห้ามการันตี "สั่งเพิ่มได้ตลอด" ถ้า STOCK STATUS เหลือน้อยกว่าจำนวนที่ลูกค้าจะทยอยซื้อ → ใช้ "ตอนนี้เหลือ [X] ตัวครับ" แทน
</staggered_pickup>$blk$),
    updated_at = now() + interval '7 hours'
WHERE id = 24;
```

- [ ] **Step 2: ตรวจผล**

```sql
SELECT length(system_prompt) AS len_after,
       position('<staggered_pickup>' in system_prompt) AS p_open,
       position('</staggered_pickup>' in system_prompt) AS p_close,
       position('<context_intelligence>' in system_prompt) AS p_next,
       (length(system_prompt) - length(replace(system_prompt, '<staggered_pickup>', ''))) / 18 AS block_count
FROM flows WHERE id = 24;
```

Expected: `len_after` อยู่ระหว่าง 30500-31200 · `p_open` > 0 · `p_close` > `p_open` · `p_next` > `p_close` (บล็อกใหม่อยู่ก่อน `<context_intelligence>` จริง) · `block_count` = 1

⛔ ถ้า `block_count` = 2 แปลว่ารัน UPDATE ซ้ำ → ถอยด้วย Rollback ท้ายแผน แล้วทำ Task 2 ใหม่รอบเดียว

- [ ] **Step 3: ตรวจว่าไม่มีคำสัญญาลอยหลงเหลือ**

```sql
SELECT position('เก็บไว้เบิกภายหลัง' in system_prompt) AS bad1,
       (length(system_prompt) - length(replace(system_prompt, 'ฝากของไว้', ''))) / 9 AS occ_total,
       (length(system_prompt) - length(replace(system_prompt, 'ไม่มีระบบฝากของไว้', ''))) / 18 AS occ_negated
FROM flows WHERE id = 24;
```

Expected: `bad1` = 0 · `occ_total` = `occ_negated` = 1

เหตุผลที่ต้องเทียบสองค่า ไม่ใช่เช็ค `position('ฝากของไว้') = 0` เฉยๆ: สคริปต์ B มีประโยค "ทางร้าน**ไม่มีระบบฝากของไว้**เบิกทีหลังนะครับ" ซึ่งเป็นการ**ปฏิเสธ** แต่มีสตริงย่อย `ฝากของไว้` อยู่ด้วย — เกณฑ์ที่ถูกคือทุกครั้งที่เจอต้องอยู่ในรูปปฏิเสธเท่านั้น

---

### Task 3: ต่อท้าย `<safety_checklist>` ข้อ 4 ให้ชี้เข้าบล็อกใหม่

**Files:**
- Modify (DB): `flows.system_prompt` แถว id=24 — แก้บรรทัด checklist ข้อ 4

**Interfaces:**
- Consumes: แท็ก `<staggered_pickup>` ที่ Task 2 สร้าง
- Produces: เส้นทางบังคับใน PRE-RESPONSE CHECK ที่ทำให้ LLM เข้าบล็อกใหม่จริง (บล็อกเฉยๆ ไม่มี checklist ชี้ = มักถูกข้าม)

- [ ] **Step 1: แก้บรรทัด checklist**

```sql
UPDATE flows
SET system_prompt = replace(system_prompt,
      ' 4. MULTI-ORDER? → ออเดอร์ยังไม่จ่าย + เพิ่มของ = รวมใบเดียว / ยอดสลิปตรงออเดอร์ไหน ห้ามสับสน',
      ' 4. MULTI-ORDER? → ออเดอร์ยังไม่จ่าย + เพิ่มของ = รวมใบเดียว / ยอดสลิปตรงออเดอร์ไหน ห้ามสับสน / ขอทยอยเบิก = ไม่มีระบบฝากของ → <staggered_pickup>'),
    updated_at = now() + interval '7 hours'
WHERE id = 24;
```

- [ ] **Step 2: ตรวจผล**

```sql
SELECT substring(system_prompt from position(' 4. MULTI-ORDER?' in system_prompt) for 190) AS checklist_line_4,
       length(system_prompt) AS len_final
FROM flows WHERE id = 24;
```

Expected: `checklist_line_4` ลงท้ายด้วย `/ ขอทยอยเบิก = ไม่มีระบบฝากของ → <staggered_pickup>` และไม่มีคำว่า `<staggered_pickup>` ซ้ำสองรอบในบรรทัดเดียว

---

### Task 4: ล้าง cache prod แล้วยืนยันว่า container เห็น prompt ใหม่

**Files:**
- Modify: ไม่มีไฟล์ (คำสั่งบน container prod)
- Create: `/private/tmp/claude-501/-Users-jaochai-Code-bot-fb/4f9b336d-b81b-4724-a92e-0ee1f523b66a/scratchpad/check_prompt.php` (สคริปต์ตรวจ ใช้แล้วทิ้ง)

**Interfaces:**
- Consumes: prompt ที่แก้เสร็จจาก Task 2-3
- Produces: prompt ใหม่กำลังวิ่งจริงบน prod — เงื่อนไขจำเป็นของ Task 5 (เทสต์ที่รันก่อนล้าง cache จะได้ผลของ prompt เก่า)

- [ ] **Step 1: ล้าง cache**

```bash
railway ssh --project ba714504-2721-4535-9fc7-6b3d903c481a \
  --environment 40f44433-1f1e-40cb-8e0c-7b2e83ff14a4 \
  --service 36066744-e919-4084-ab57-a31e76694a9a \
  -- php artisan cache:forget bot:26:default_flow
```

Expected: ขึ้นข้อความยืนยันว่า forget สำเร็จ

- [ ] **Step 2: เขียนสคริปต์ตรวจว่า container โหลด prompt ใหม่จริง**

เขียนไฟล์ `check_prompt.php` ในโฟลเดอร์ scratchpad:

```php
<?php
require '/var/www/html/vendor/autoload.php';
$a = require '/var/www/html/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bot = App\Models\Bot::find(26);
$flow = app(App\Services\FlowCacheService::class)->getDefaultFlow(26);
$p = $flow->system_prompt;

echo 'flow_id=' . $flow->id . "\n";
echo 'len=' . mb_strlen($p) . "\n";
echo 'has_block=' . (str_contains($p, '<staggered_pickup>') ? 'YES' : 'NO') . "\n";
echo 'has_checklist=' . (str_contains($p, 'ขอทยอยเบิก = ไม่มีระบบฝากของ') ? 'YES' : 'NO') . "\n";
```

- [ ] **Step 3: ส่งสคริปต์เข้า container แล้วรัน**

`railway ssh` ตัด argument ยาว → ต้อง pipe ทาง stdin เท่านั้น:

```bash
SCRATCH=/private/tmp/claude-501/-Users-jaochai-Code-bot-fb/4f9b336d-b81b-4724-a92e-0ee1f523b66a/scratchpad
RSSH="railway ssh --project ba714504-2721-4535-9fc7-6b3d903c481a --environment 40f44433-1f1e-40cb-8e0c-7b2e83ff14a4 --service 36066744-e919-4084-ab57-a31e76694a9a"
cat $SCRATCH/check_prompt.php | $RSSH -- sh -c "cat > /tmp/check_prompt.php"
$RSSH -- php /tmp/check_prompt.php
```

Expected:
```
flow_id=24
len=<ตัวเลขเท่ากับ len_final จาก Task 3>
has_block=YES
has_checklist=YES
```

⛔ ถ้า `has_block=NO` → cache ยังไม่ถูกล้าง ให้รัน Step 1 ซ้ำแล้วรัน Step 3 ใหม่ ห้ามไป Task 5

---

### Task 5: ทดสอบ prompt ใหม่กับ RAGService จริงบน prod (7 เคส)

**Files:**
- Create: `/private/tmp/claude-501/-Users-jaochai-Code-bot-fb/4f9b336d-b81b-4724-a92e-0ee1f523b66a/scratchpad/test_staggered.php`

**Interfaces:**
- Consumes: prompt ใหม่ที่ยืนยันแล้วจาก Task 4
- Produces: ผลเทสต์ 7 เคสที่ใช้ตัดสินว่าจบงานหรือกลับไปแก้ถ้อยคำในบล็อก

วิธีนี้เรียก service จริงบน prod โดยไม่เขียนข้อความลง DB และไม่ต้องรอลูกค้าจริง (`conversation: null`)

- [ ] **Step 1: เขียนสคริปต์เทสต์**

เขียนไฟล์ `test_staggered.php` ในโฟลเดอร์ scratchpad:

```php
<?php
require '/var/www/html/vendor/autoload.php';
$a = require '/var/www/html/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bot = App\Models\Bot::find(26);
$flow = $bot->defaultFlow;
$rag = app(App\Services\RAGService::class);

$cartHistory = [
    ['sender' => 'user', 'content' => 'ต้องการ Nolimit Level Up+ Personal'],
    ['sender' => 'bot',  'content' => 'รับทราบครับพี่ Nolimit Level Up+ Personal 1 ตัว ราคา 1,100 บาทครับ พี่จะใช้แบบ "ผูกบัตร" หรือ "เติมเงิน" ดีครับ?'],
    ['sender' => 'user', 'content' => 'เติมเงินครับ'],
    ['sender' => 'user', 'content' => 'nolimit 3 ตัวครับ'],
    ['sender' => 'bot',  'content' => 'รับทราบครับพี่ สรุปเป็น Nolimit Level Up+ Personal (เติมเงิน) 3 ตัวครับ รวมยอด 3,300 บาทครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
];

$cases = [
    ['C1 ขอเบิกทีหลัง (เคสจริง conv #1451)', 'ผมจ่ายไป 3 แต่จะขอเบิกแค่ 1 ก่อนนะครับ', $cartHistory],
    ['C2 ตอบรับข้อเสนอแยกออเดอร์', 'เอา 1 ก่อนก็ได้ครับ', array_merge($cartHistory, [
        ['sender' => 'user', 'content' => 'ผมจ่ายไป 3 แต่จะขอเบิกแค่ 1 ก่อนนะครับ'],
        ['sender' => 'bot',  'content' => 'อ๋อ ได้ครับพี่ 👍 แต่บอกไว้ก่อนนะครับ ของเราซื้อกี่ตัวราคาก็เท่ากันครับ ตัวละ 1,100 พี่ไม่ต้องจ่ายก้อนใหญ่ค้างไว้เลยครับ แนะนำจ่ายเท่าที่จะรับตอนนี้ก่อนครับ เอาแบบนี้ไหมครับ? หรือจะรับครบ 3 ตัวเลยก็ได้ครับ'],
    ])],
    ['C3 ยืนกรานจ่ายเต็มแล้วเบิกทีหลัง', 'ไม่ครับ ผมจะจ่าย 3 ตัวเลย แต่ขอเบิกทีหลัง', array_merge($cartHistory, [
        ['sender' => 'user', 'content' => 'ผมจ่ายไป 3 แต่จะขอเบิกแค่ 1 ก่อนนะครับ'],
        ['sender' => 'bot',  'content' => 'อ๋อ ได้ครับพี่ 👍 ของเราซื้อกี่ตัวราคาก็เท่ากันครับ แนะนำจ่ายเท่าที่จะรับตอนนี้ก่อนครับ'],
    ])],
    ['C4 ถามเวลาเคลม', 'ถ้ายังไม่เบิกยังไม่นับเวลาการเคลมใช่ไหมครับ', $cartHistory],
    ['C5 regression: ทยอยส่งก็ได้ (conv #191)', 'ตัวไหนส่งก่อนได้ ทยอยส่งมาก็ได้นะครับ', $cartHistory],
    ['C6 regression: สั่ง 3 ตัวปกติ', 'เอา Nolimit Personal 3 ตัวครับ', []],
    ['C7 regression: เบิกเพจ (ลูกค้าเก่ามารับของ)', 'เบิกเพจครับ', []],
];

foreach ($cases as [$name, $msg, $history]) {
    $r = $rag->generateResponse(
        bot: $bot,
        userMessage: $msg,
        conversationHistory: $history,
        conversation: null,
        flow: $flow
    );
    echo "=== {$name} ===\n";
    echo "USER: {$msg}\n";
    echo 'BOT : ' . ($r['response'] ?? json_encode($r, JSON_UNESCAPED_UNICODE)) . "\n\n";
}
```

- [ ] **Step 2: ส่งเข้า container แล้วรัน**

```bash
SCRATCH=/private/tmp/claude-501/-Users-jaochai-Code-bot-fb/4f9b336d-b81b-4724-a92e-0ee1f523b66a/scratchpad
RSSH="railway ssh --project ba714504-2721-4535-9fc7-6b3d903c481a --environment 40f44433-1f1e-40cb-8e0c-7b2e83ff14a4 --service 36066744-e919-4084-ab57-a31e76694a9a"
cat $SCRATCH/test_staggered.php | $RSSH -- sh -c "cat > /tmp/test_staggered.php"
$RSSH -- php /tmp/test_staggered.php
```

หมายเหตุ: ถ้า key `response` ไม่มีในผลลัพธ์ สคริปต์จะพิมพ์ array ทั้งก้อนเป็น JSON ให้อ่านหาข้อความเอง — ไม่ต้องแก้สคริปต์

- [ ] **Step 3: ตรวจผลตามเกณฑ์ (ทั้ง 7 ข้อต้องผ่าน)**

| เคส | ต้องได้ | ต้องไม่มี |
|-----|---------|-----------|
| C1 | เสนอให้จ่ายเท่าที่จะรับตอนนี้ + บอกว่าราคาต่อตัวเท่ากัน | คำว่า "เก็บไว้เบิก" / "ฝากไว้" ในความหมายว่าทำได้ |
| C2 | ตะกร้า/ยอดเหลือ Nolimit 1 ตัว = 1,100 บาท | ยอด 3,300 หรือ 3,897 |
| C3 | ปฏิเสธการฝากของชัดเจน + บอกว่าเงินเข้าแล้วส่งครบทันที | การรับปากว่าจะเก็บของไว้ให้ |
| C4 | โยนไปที่ลิงก์ Terms / ให้ทีมงานยืนยัน | ตัวเลขวัน/ชั่วโมง หรือประโยคว่าเริ่มนับตอนเบิก |
| C5 | ตอบเรื่องการจัดส่งตามปกติ | ข้อเสนอแยกออเดอร์/ให้ซื้อทีละตัว |
| C6 | ขาย 3 ตัว 3,300 บาทตามปกติ | ข้อเสนอให้ซื้อทีละตัว |
| C7 | ตอบเรื่องรับเพจตามปกติ (ทีม Support จัดการให้) | ข้อเสนอแยกออเดอร์ |

- [ ] **Step 4: ถ้ามีเคสตก → แก้ถ้อยคำแล้วเทสต์ซ้ำ**

แก้เฉพาะข้อความในบล็อก `<staggered_pickup>` ด้วย `UPDATE flows ... replace(...)` เจาะจงบรรทัดที่จะแก้ (ห้ามเขียนทับ prompt ทั้งก้อน) → `cache:forget bot:26:default_flow` → รัน Step 2 ซ้ำ

บทเรียนจากเคส BM5 (2026-08-11) ที่ต้องระวัง: **กฎที่เขียนเป็น "ห้าม…" อย่างเดียวทำให้ over-trigger** — ถ้า C5/C6 ตก (บอทเสนอแยกออเดอร์ทั้งที่ไม่ควร) ให้แก้เป็น decision tree เช็คก่อนว่า "ลูกค้าขอ**รับของช้าลง**" หรือ "ลูกค้ายอมให้**ร้านส่งช้าลง**" แล้วเลือกทางเดียว พร้อมใส่ตัวอย่างประโยคเต็มฝั่งที่ต้องปล่อยผ่าน

- [ ] **Step 5: commit บันทึกผลเทสต์**

เพิ่มหัวข้อ `## ผลเทสต์ (วันที่รันจริง)` ต่อท้ายไฟล์ spec โดยเขียนผลจริงของทั้ง 7 เคส (คำตอบบอทย่อ 1-2 บรรทัดต่อเคส + ผ่าน/ตก) แล้ว:

```bash
git add docs/superpowers/specs/2026-08-11-staggered-pickup-design.md
git commit -m "docs: ผลเทสต์ prompt ทยอยเบิก 7 เคสบน prod"
```

---

## Rollback (ถ้าเจอปัญหาบน prod)

```sql
UPDATE flows
SET system_prompt = (
      SELECT field_changes->>'system_prompt'
      FROM flow_audit_logs
      WHERE action = 'backup_before_staggered_pickup'
      ORDER BY id DESC LIMIT 1
    ),
    updated_at = now() + interval '7 hours'
WHERE id = 24;
```

แล้วล้าง cache ทันที:

```bash
railway ssh --project ba714504-2721-4535-9fc7-6b3d903c481a \
  --environment 40f44433-1f1e-40cb-8e0c-7b2e83ff14a4 \
  --service 36066744-e919-4084-ab57-a31e76694a9a \
  -- php artisan cache:forget bot:26:default_flow
```

ตรวจว่ากลับมาแล้ว: `SELECT length(system_prompt) FROM flows WHERE id = 24;` → ต้องได้ 29248
