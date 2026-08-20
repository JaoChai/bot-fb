# Off-topic Guardrail Enforcement Layer — Design

**วันที่:** 2026-08-20
**เป้าหมาย:** เพิ่มชั้นป้องกันแบบ deterministic (โค้ดล้วน ไม่พึ่ง LLM เพิ่ม) ต่อยอดจาก off-topic guardrail ที่เพิ่งเพิ่มลง prompt วันนี้ (flow 24, backup `flow_audit_logs` id=27) เพื่อรับมือ 3 สัญญาณที่เจ้าของกังวลไว้ล่วงหน้า

## บริบท

**Off-topic guardrail ที่มีอยู่ตอนนี้ (prompt-only, LIVE บน prod):**
`<security>` block ใน flow 24 มีบล็อก `OFF-TOPIC GUARDRAIL` ที่ตอบปฏิเสธ+redirect เมื่อลูกค้าขอเรื่องนอกสินค้า (แปลภาษา/เขียนโค้ด/ข่าว ฯลฯ) — ทดสอบผ่าน 5/5 บน prod แล้ว แต่เป็น**เกราะชั้นเดียว** (probabilistic — พึ่ง LLM ทำตาม prompt) ไม่มีตัวกันสำรองที่เป็นโค้ด

**3 สัญญาณที่ต้องรับมือ (ยังไม่เกิดจริง แต่เป็นความเสี่ยงที่ระบุไว้ล่วงหน้า):**

| สัญญาณ | ปัญหาจริง |
|---|---|
| คนใช้บอทฟรีซ้ำๆ | ทุกข้อความ (แม้แต่ข้อความที่โดน guardrail ปฏิเสธ) เสีย LLM cost จริง เพราะ intent classification + chat completion เรียกทุกครั้งไม่ว่าจะตอบอะไร |
| โดนแคปแชร์ | หลุดแค่ครั้งเดียวก็เสียหน้าร้านได้ — prompt ทำงานถูก 100% ไม่ได้การันตี |
| แชทโตจนอัตราหลุดเล็กๆ กลายเป็นตัวเลขจริง | ไม่มีตัวเลข/log ให้ดูว่าถึงจุดที่ต้องอัพเกรดหรือยัง |

**ของเดิมที่มีอยู่แล้วและตรวจแล้วว่า "พอไม่หมด":** `RateLimitService` จำกัด 30 ข้อความ/user/วัน, 500/บอท/วัน (bot 26) เป็น gate ก่อนเรียก LLM จริง — แต่เป็นตัวนับรวมแบบไม่แยกแยะ ไม่รู้ว่าข้อความไหนเป็น off-topic ตัดช้าเกินไป (30 ครั้ง/วัน) และเสี่ยงบล็อกลูกค้าจริงที่คุยเยอะ

## การตัดสินใจ (ยืนยันจากเจ้าของแล้ว 2026-08-20 ผ่าน AskUserQuestion)

1. **Threshold circuit breaker: 3 ครั้ง** off-topic guardrail trigger ในบทสนทนาเดียว → ตัดวงจร
2. **Reset ทุก 24 ชม.** (rolling ต่อ conversation)
3. **หลังตัดวงจรแล้ว ตอบข้อความสำเร็จรูปทุกครั้ง** (ไม่ใช่เงียบ, ไม่ใช่ตอบครั้งเดียวแล้วเงียบ)
4. **ไม่ต้องแจ้งเตือนเจ้าของร้านตอนนี้** — เก็บ log ไว้ดูย้อนหลังพอ (เผื่อ signal 3 ในอนาคต)
5. **Output sanitizer เช็คเฉพาะ 2 อย่าง:** code block/markdown จริง + วลี "เป็น AI" — **ไม่เช็ค**ภาษาอังกฤษล้วน (เสี่ยง false positive กับศัพท์ธุรกิจจริง เช่น BM/CAPI/Pixel)
6. **เจอ output ต้องห้าม → สลับเป็นข้อความสำเร็จรูป + เก็บ log** (ไม่แจ้งเจ้าของทันที)
7. **ใช้กับทุกบอทอัตโนมัติ** ไม่ทำ per-bot toggle ใน v1
8. **ไม่มี UI/dashboard ใหม่** — เกณฑ์ทั้งหมดเป็นค่าคงที่ในโค้ด

## สถาปัตยกรรม

**จุดเชื่อมเดียวที่ทุกช่องทางผ่านหมด (ตรวจโค้ดจริงแล้ว):**
- `AIService::generateResponse()` (`backend/app/Services/AIService.php`) — คอมเมนต์ในโค้ดยืนยันตรงๆ ว่า "ทั้ง webhook pipeline และ ProcessAggregatedMessages ผ่านเมธอดนี้เสมอ... ทางออกอื่นทั้งหมด — LINE push, Flex, bubbles, หน้าเว็บ — อ่านจาก content ที่ผ่านตรงนี้แล้ว"
- ผู้เรียกจากภายนอกมีจุดเดียว: `ProcessAggregatedMessages::generateAndDeliver()` (บรรทัด ~347)

ระบบมี pattern เดียวกันนี้ใช้งานอยู่แล้วสำหรับบล็อก `[[ORDER]]...[[/ORDER]]` — LLM แนบท้ายคำตอบ, `OrderPayloadExtractor` (`App\Services\Payment\`) ดึงออกมาเป็น structured data แล้วตัดออกจาก content ก่อนถึงลูกค้า, ทำเป็นจุดเดียวใน `AIService::generateResponse()` (บรรทัด ~62-70) — **จะใช้ pattern เดียวกันนี้ทุกจุดที่สร้างใหม่**

### ส่วนที่ 1: Off-topic signal marker

- ต่อท้าย script ทั้ง 3 แบบใน `OFF-TOPIC GUARDRAIL` block ของ prompt ด้วย marker `[[OFFTOPIC]]` (ระบบตัดออกก่อนส่ง ลูกค้าไม่เห็น — เหมือน `[[ORDER]]`)
- คลาสใหม่ `App\Services\Guardrail\OffTopicSignalExtractor` (mirror `OrderPayloadExtractor`) — `extract(string $content): array` คืน `['clean' => string, 'triggered' => bool]`
- เหตุผลที่ไม่ใช้ string-match กับข้อความ script ตรงๆ: LLM พาราเฟรสคำพูดได้ (เห็นแล้วจากเทสต์วันนี้ เช่น "เรื่องข่าววันนี้ผมช่วยไม่ได้ตรงนี้ครับ" ต่างจาก script ต้นฉบับ) — marker ที่โค้ด parse แน่นอนกว่า ไม่มีวันตีความผิด

### ส่วนที่ 2: Repeat-offender circuit breaker

- Redis key: `off_topic_count:{bot_id}:{conversation_id}` TTL 24 ชม. (`Cache` facade เหมือน `RateLimitService`)
- ใน `ProcessAggregatedMessages::generateAndDeliver()` **ก่อน**เรียก `$aiService->generateResponse(...)`: เช็ค counter ≥ 3 → ข้าม LLM ไปสร้าง+ส่งข้อความสำเร็จรูปทันที (mirror pattern ของ `OpenRouterException` fallback ที่มีอยู่แล้วในเมธอดเดียวกัน บรรทัด ~353-369)
- หลัง `generateResponse()` คืนค่า: ถ้า `$result['off_topic_triggered']` เป็น true → `Cache::increment()` counter
- Redis ล่ม → fail open (เช็คไม่ได้ = ปล่อยผ่านปกติ ไม่บล็อกลูกค้า) ใช้แพทเทิร์นเดียวกับ `RedisFallbackSwitch` ที่มีอยู่แล้ว

### ส่วนที่ 3: Output sanitizer

- อยู่จุดเดียวกับส่วนที่ 1 ใน `AIService::generateResponse()` รันบน `$result['content']` สุดท้าย (หลังตัด `[[ORDER]]` และ `[[OFFTOPIC]]` ออกแล้ว) — ใช้กับ**ทุกคำตอบ** ไม่จำกัดเฉพาะที่ถูกตีว่า off-topic
- Regex ล้วน ไม่มี LLM call เพิ่ม เช็ค:
  - Code fence (```` ``` ````)
  - Markdown จริง (`**`, `#`) — prompt ห้ามอยู่แล้วทุกกรณี ดังนั้นเจอ = enforce กฎเดิมที่มีอยู่แล้ว ไม่ใช่กฎใหม่
  - วลี "เป็น AI" (ไทย+อังกฤษ: "as an AI", "I cannot", "ในฐานะ AI", "ผมเป็น AI")
- เจอเข้าเงื่อนไข → สลับ `$result['content']` เป็นข้อความสำเร็จรูป + `Log::warning('Guardrail output sanitizer triggered', [...])`

## Data flow ต่อ 1 ข้อความ

1. `ProcessAggregatedMessages::generateAndDeliver()` เช็ค circuit-breaker counter
2. ≥3 ครั้งใน 24 ชม. → ตอบข้อความสำเร็จรูปทันที จบ (0 LLM cost)
3. ไม่ถึง → เรียก `AIService::generateResponse()`
4. ภายใน: RAG/chat flow ปกติ → ตัด `[[ORDER]]` (เดิม) → ตัด `[[OFFTOPIC]]` (ใหม่) → เช็ค output sanitizer (ใหม่) → `$result` สะอาด
5. กลับมาที่ job: ถ้า `off_topic_triggered` → เพิ่ม counter สำหรับครั้งถัดไป
6. บันทึกข้อความ + ส่งเข้าช่องทาง (เดิม ไม่แตะ)

## สิ่งที่ไม่ทำ (ตัดออกโดยตั้งใจ)

- ❌ Harmlessness screen แบบเรียก LLM แยก (utility_model) — เก็บไว้เป็นตัวเลือกขั้นถัดไป ใช้เมื่อ log จาก circuit breaker บอกว่าจำเป็นจริง (signal 3)
- ❌ แจ้งเตือนเจ้าของร้านแบบ real-time — v1 เก็บแค่ log
- ❌ Dashboard/UI ตั้งค่า threshold — เกณฑ์คงที่ในโค้ดก่อน
- ❌ เช็คภาษาอังกฤษล้วนใน output sanitizer — เสี่ยง false positive สูงกับศัพท์ธุรกิจจริง
- ❌ Per-bot toggle — ใช้กับทุกบอทอัตโนมัติผ่านจุดเชื่อมเดียว

## แผนตรวจสอบ (ก่อน deploy จริง)

1. Backup prompt เดิม (`flow_audit_logs`) → เพิ่ม `[[OFFTOPIC]]` ท้าย 3 script → update DB → `cache:forget`
2. เทสต์บน prod ผ่าน tinker (pattern เดียวกับที่ใช้วันนี้ — `AIService::generateResponse()` ตรงๆ ไม่ผ่านลูกค้าจริง):
   - ยิง off-topic 4 ครั้งติดในบทสนทนาเดียว → ครั้งที่ 4 ต้อง**ไม่มี LLM call เกิดขึ้นจริง** (เช็คจาก log/usage=0)
   - เช็คว่า `[[OFFTOPIC]]` ไม่หลุดไปให้ลูกค้าเห็นในคำตอบจริง
   - ยิงคำถามสินค้าปกติสลับกับ off-topic → ต้องไม่ถูกนับเป็น off-topic (counter ไม่ขยับ)
   - จำลอง response ที่มี code fence/วลี "เป็น AI" ผ่าน sanitizer โดยตรง → ต้องถูกสลับเป็นข้อความสำเร็จรูป
3. **เก็บ script ทดสอบนี้ไว้ในโค้ดจริง** (ไม่ใช่ scratch) ให้รันซ้ำได้ทุกครั้งที่แก้ prompt/guardrail ต่อไป — แก้จุดอ่อนที่เจอวันนี้ว่าไม่มี regression suite
