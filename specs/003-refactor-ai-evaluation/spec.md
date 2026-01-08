# Feature Specification: Refactor AI Evaluation System - Phase 1

**Feature Branch**: `003-refactor-ai-evaluation`
**Created**: 2026-01-08
**Status**: Draft
**Input**: Refactor AI Evaluation System - Phase 1 (Priority 1 Items) - ปรับปรุงระบบ AI Evaluation เพื่อลด cost และ latency โดยเน้น 3 ด้าน: (1) Second AI Unified LLM Call, (2) Evaluation Cheaper Judge Model, (3) Knowledge Base Warning UI

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Second AI Performance Improvement (Priority: P1)

Bot owner และ operations team ต้องการลดค่าใช้จ่ายและเวลาตอบสนองของ Second AI checks เมื่อ bot ตอบลูกค้า โดยปัจจุบันเมื่อเปิด Second AI ทั้ง 3 options (Fact Check, Policy, Personality) response time เพิ่มขึ้น 3-6 วินาที และ cost เพิ่ม 2-3 เท่า ซึ่งส่งผลกระทบต่อประสบการณ์ของลูกค้าและงบประมาณ

**Why this priority**: เป็น optimization ที่ส่งผลกระทบโดยตรงต่อทุก bot ที่เปิดใช้ Second AI - ลด cost 60-70% และ latency 50%+ จะช่วยให้ feature นี้ใช้งานได้จริงใน production scale

**Independent Test**: เปรียบเทียบ response time และ cost ระหว่าง implementation เดิม กับ unified call โดยส่ง request ที่เปิด Second AI ทั้ง 3 options จำนวน 100 requests แล้ววัด average latency และ total cost - ควรเห็นผลลัพธ์ที่ latency ≤1.5s และ cost ลด ≥60%

**Acceptance Scenarios**:

1. **Given** flow มี Second AI enabled พร้อม Fact Check, Policy, และ Personality options ทั้งหมด, **When** user ส่งข้อความถาม bot, **Then** response time จาก Primary AI ถึงได้รับ final response ≤1.5 วินาที (ลดจาก 3-6 วินาที)

2. **Given** flow มี Second AI enabled พร้อม multiple options, **When** ระบบประมวลผล 100 requests, **Then** total cost ของ Second AI checks ลดลง ≥60% เมื่อเทียบกับ sequential implementation

3. **Given** unified check ใช้เวลาเกิน timeout หรือ error, **When** system ตรวจพบ failure, **Then** system fallback ไปใช้ sequential checks อัตโนมัติและ log error พร้อมส่ง response กลับไปได้

4. **Given** flow เปิดเฉพาะ Fact Check option อย่างเดียว, **When** user ส่งข้อความ, **Then** system ใช้ unified call format แต่ skip Policy และ Personality checks และยังคงได้ผลลัพธ์ถูกต้อง

5. **Given** unified check response เป็น JSON format, **When** system parse ผลลัพธ์, **Then** system แยกแยะ modifications จาก Fact/Policy/Personality ได้ถูกต้องและ apply ตามลำดับ

---

### User Story 2 - Evaluation Cost Reduction (Priority: P2)

Bot owner ที่ใช้ Evaluation feature เพื่อทดสอบคุณภาพ bot ต้องการลดค่าใช้จ่ายในการรัน evaluation โดยปัจจุบันการประเมิน 40 test cases ใช้ Claude 3.5 Sonnet สำหรับทุก metric (240+ API calls) ทำให้ cost สูงมากและจำกัดการใช้งานจริง

**Why this priority**: Evaluation เป็น feature ที่มีค่าใช้จ่ายสูง แต่จำเป็นสำหรับ quality assurance - การลด cost 50-70% จะทำให้ bot owners รัน evaluation บ่อยขึ้นและได้ feedback ดีขึ้น

**Independent Test**: สร้าง evaluation ใหม่ด้วย 20 test cases โดยใช้ model tier system แล้วเปรียบเทียบ cost กับการใช้ premium model ทั้งหมด - ควรเห็นว่า cost ลด ≥50% ในขณะที่ overall scores แตกต่างไม่เกิน 10%

**Acceptance Scenarios**:

1. **Given** evaluation กำลังจะประเมิน simple metric (answer_relevancy), **When** system เลือก model, **Then** system ใช้ budget model (gpt-4o-mini หรือ gemini-flash-free) แทน premium model

2. **Given** evaluation กำลังจะประเมิน complex metric (faithfulness, context_precision), **When** system เลือก model, **Then** system ใช้ premium model (claude-3.5-sonnet) เพื่อความแม่นยำ

3. **Given** evaluation รัน 40 test cases ด้วย model tier system, **When** evaluation เสร็จสิ้น, **Then** total cost ลดลง ≥50% เมื่อเทียบกับการใช้ premium model ทั้งหมด

4. **Given** evaluation ใช้ budget model สำหรับ simple metrics, **When** เปรียบเทียบ scores กับ premium model, **Then** ความแตกต่างของ scores ≤10% สำหรับ metrics เหล่านั้น

5. **Given** user สร้าง evaluation ใหม่, **When** user ดู model configuration, **Then** system แสดง default model tiers ที่ใช้สำหรับแต่ละ metric type

---

### User Story 3 - Knowledge Base Warning (Priority: P3)

Bot owner ที่เปิด Fact Check option ใน Second AI แต่ไม่มี Knowledge Base attached กับ flow ต้องการได้รับ warning ที่ชัดเจนว่า Fact Check จะไม่ทำงาน และควรเพิ่ม Knowledge Base เพื่อใช้ feature นี้ให้เต็มประสิทธิภาพ

**Why this priority**: เป็น UX improvement ที่ช่วยป้องกัน confusion - มีผลกระทบต่อ user experience แต่ไม่ blocking feature อื่นๆ

**Independent Test**: สร้าง flow ใหม่โดยไม่ attach Knowledge Base → เปิด Second AI พร้อม Fact Check option → ตรวจสอบว่ามี warning แสดงขึ้นพร้อมปุ่มไปเพิ่ม KB

**Acceptance Scenarios**:

1. **Given** user กำลัง edit flow ที่ไม่มี Knowledge Base, **When** user เปิด Fact Check toggle, **Then** system แสดง warning message ชัดเจนว่า "Fact Check ต้องการ Knowledge Base เพื่อตรวจสอบข้อเท็จจริง"

2. **Given** warning แสดงอยู่, **When** user คลิกปุ่ม "เพิ่ม Knowledge Base", **Then** system navigate ไปหน้า Knowledge Base management

3. **Given** flow มี Knowledge Base attached อยู่แล้ว, **When** user เปิด Fact Check toggle, **Then** ไม่แสดง warning และ toggle ทำงานปกติ

4. **Given** user เปิด Fact Check โดยไม่มี KB และได้เห็น warning, **When** user บันทึก flow settings, **Then** system บันทึก settings ได้โดยไม่ block แต่ Fact Check จะไม่ทำงานจริง (graceful degradation)

---

### Edge Cases

- What happens when unified Second AI check returns malformed JSON?
  - System logs error และ fallback ไปใช้ sequential checks อัตโนมัติ

- What happens when budget model มี rate limit หรือ unavailable?
  - System fallback ไปใช้ standard model แล้วถึง premium model ตามลำดับ และ log model used

- What happens when user มี flow หลายตัวที่ใช้ Second AI พร้อมกัน?
  - Rate limiting และ timeout ต้องทำงานแยก per-request ไม่ได้ share state

- What happens when evaluation timeout (30 นาที) แต่ยังไม่เสร็จ?
  - System mark evaluation as failed พร้อม error message และ partial results ที่ทำเสร็จแล้ว

- What happens when user attach KB ภายหลังจากเห็น warning?
  - Warning หายไปทันทีเมื่อ KB ถูก attach และ Fact Check พร้อมใช้งาน

## Requirements *(mandatory)*

### Functional Requirements

**Second AI Unified Call:**

- **FR-001**: System MUST combine Fact Check, Policy Check, และ Personality Check เป็น single LLM call เมื่อมีหลาย options ถูกเปิดใช้งาน
- **FR-002**: System MUST parse unified JSON response ที่มี structure: `{"passed": bool, "modifications": {"fact": {...}, "policy": {...}, "personality": {...}}, "rewritten": "..."}`
- **FR-006**: System MUST maintain backward compatibility โดย existing flows ที่ใช้ Second AI ยังคงทำงานได้โดยไม่ต้องเปลี่ยนแปลง settings
- **FR-007**: System MUST gracefully fallback ไปใช้ sequential checks หาก unified check ล้มเหลว timeout หรือ return invalid response
- **FR-008**: System MUST log performance metrics (latency, cost, model used) สำหรับทั้ง unified และ sequential implementations เพื่อใช้เปรียบเทียบ

**Evaluation Model Tiers:**

- **FR-003**: System MUST support model tier selection โดยมี 3 tiers: premium (claude-3.5-sonnet), standard (gpt-4o-mini), budget (gemini-flash-free)
- **FR-004**: System MUST ใช้ model tier ที่เหมาะสมสำหรับแต่ละ metric type:
  - Simple metrics (answer_relevancy, task_completion): budget หรือ standard model
  - Complex metrics (faithfulness, context_precision, role_adherence): premium model

**Knowledge Base Warning:**

- **FR-005**: System MUST แสดง warning message เมื่อ user เปิด Fact Check option แต่ flow ไม่มี Knowledge Base attached
- **FR-009**: Warning MUST มีปุ่มที่ navigate ไปหน้า Knowledge Base management เพื่อให้ user เพิ่ม KB ได้ทันที
- **FR-010**: System MUST ซ่อน warning เมื่อ flow มี Knowledge Base attached อยู่แล้ว

### Key Entities

**SecondAICheckResult**:
- Represents unified check result จาก single LLM call
- Contains: passed (boolean), modifications (object with fact/policy/personality keys), rewritten content (string)
- Used for parsing และ applying sequential modifications

**ModelTierConfig**:
- Represents model tier mapping สำหรับ evaluation metrics
- Contains: metric name, assigned tier (premium/standard/budget), actual model used
- Used for tracking cost และ ensuring correct model selection

## Success Criteria *(mandatory)*

### Measurable Outcomes

**Second AI Performance:**

- **SC-001**: Bot response time (from user message to final response) ลดลง ≥50% เมื่อใช้ Second AI พร้อม multiple options (จาก 3-6s → ≤1.5s)
- **SC-002**: Second AI cost per request ลดลง ≥60% เมื่อเทียบกับ sequential implementation (จาก 6-9 API calls → 1 call)
- **SC-006**: Response quality (measured by user feedback หรือ A/B test metrics) ไม่ลดลงเกิน 5% เมื่อใช้ unified approach

**Evaluation Cost Reduction:**

- **SC-003**: Evaluation cost per test case ลดลง ≥50% เมื่อใช้ model tier system (เฉลี่ย 40 test cases × 5 metrics)
- **SC-007**: Evaluation accuracy (overall scores) แตกต่างจาก premium-only approach ไม่เกิน 10%

**User Experience:**

- **SC-004**: User ที่เปิด Fact Check โดยไม่มี Knowledge Base ได้รับ warning message ภายใน 1 วินาทีหลังจาก toggle
- **SC-005**: Existing tests ทั้งหมด (backend + frontend) ผ่านโดยไม่ต้องแก้ไข (zero breaking changes)

## Assumptions

- OpenRouter API มี rate limit เพียงพอสำหรับ unified calls ที่ใช้ token มากกว่า sequential calls
- Budget models (gpt-4o-mini, gemini-flash-free) มี accuracy ≥90% เมื่อเทียบกับ premium model สำหรับ simple metrics
- User ยอมรับ trade-off ระหว่าง cost savings และ slight accuracy difference (≤10%)
- Unified prompt design ไม่ทำให้ context length เกิน model limits (สำหรับ claude-3.5-sonnet: 200K tokens)
- Existing Second AI settings (second_ai_enabled, second_ai_options) ใน database ไม่ต้องเปลี่ยนแปลง schema

## Out of Scope

**Phase 2 Features** (ไม่รวมใน Phase 1 นี้):
- Parallel processing of individual checks
- Batch processing สำหรับ evaluations
- Progressive evaluation with early stopping
- Caching layer สำหรับ Second AI results
- General fact checking without Knowledge Base (fallback mechanism)
- UI สำหรับเลือก model tier manually (ใช้ automatic assignment ตาม metric type)
