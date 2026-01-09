# Feature Specification: Second AI for Improvement

**Feature Branch**: `002-second-ai-improvement`
**Created**: 2026-01-07
**Status**: Draft
**Input**: Second AI for Improvement - ใช้ AI ตัวที่สองเพื่อตรวจสอบและปรับปรุง response ก่อนส่งกลับ user โดยมี 3 options: Fact Check (ตรวจข้อเท็จจริงเทียบกับ KB), Policy (ตรวจว่าไม่ละเมิดนโยบาย), Personality (ตรวจ tone/บุคลิกภาพตาม brand). UI มีอยู่แล้วใน FlowEditorPage.tsx แต่ยังไม่ได้ save ไป backend และยังไม่มี service ทำงานจริง

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enable Second AI Check on Flow (Priority: P1)

Bot owner ต้องการเปิดใช้งาน Second AI เพื่อตรวจสอบคำตอบก่อนส่งให้ลูกค้า โดยสามารถเลือก options ที่ต้องการตรวจสอบได้

**Why this priority**: เป็น core functionality ที่ต้องทำงานได้ก่อน - ถ้า user เปิด toggle แล้วไม่ save ลง database ฟีเจอร์อื่นๆ จะไม่มีข้อมูลใช้งาน

**Independent Test**: สามารถทดสอบได้โดยเปิด Flow Editor → toggle Second AI → เลือก options → บันทึก → reload หน้า → ตรวจสอบว่า settings ยังอยู่

**Acceptance Scenarios**:

1. **Given** user อยู่ในหน้า Flow Editor, **When** user เปิด toggle "Second AI for Improvement", **Then** ระบบแสดง checkboxes สำหรับ Fact Check, Policy, Personality
2. **Given** user เปิด Second AI และเลือก options แล้ว, **When** user กดบันทึก, **Then** settings ถูก save ลง database และแสดง toast success
3. **Given** user เปิด Second AI ไว้แล้วบันทึก, **When** user reload หน้าหรือกลับมาภายหลัง, **Then** settings แสดงตาม state ที่บันทึกไว้

---

### User Story 2 - Fact Check Response Against Knowledge Base (Priority: P2)

เมื่อเปิด Fact Check option ระบบจะตรวจสอบว่าคำตอบที่ Primary AI สร้างขึ้นมีข้อมูลตรงกับ Knowledge Base หรือไม่ ถ้าพบข้อมูลที่ไม่มีใน KB จะแก้ไขให้ถูกต้อง

**Why this priority**: Fact Check เป็น option ที่มีผลกระทบสูงสุดต่อคุณภาพ chatbot - ป้องกัน hallucination โดยตรง

**Independent Test**: สามารถทดสอบได้โดยถาม chatbot คำถามที่ไม่มีใน KB → ตรวจสอบว่า response ไม่มีข้อมูลที่ AI สร้างขึ้นเอง

**Acceptance Scenarios**:

1. **Given** Fact Check เปิดอยู่และ Primary AI ตอบด้วยข้อมูลที่มีใน KB, **When** Second AI ตรวจสอบ, **Then** response ถูกส่งกลับโดยไม่มีการแก้ไข
2. **Given** Fact Check เปิดอยู่และ Primary AI ตอบด้วยข้อมูลที่ไม่มีใน KB, **When** Second AI ตรวจสอบ, **Then** response ถูกแก้ไขโดยลบ/แก้ข้อมูลที่ไม่มีใน KB และแทนที่ด้วยข้อความที่เหมาะสม (เช่น "กรุณาสอบถามทีมงาน")
3. **Given** Fact Check เปิดอยู่และ Primary AI ตอบราคาสินค้าที่ไม่ตรงกับ KB, **When** Second AI ตรวจสอบ, **Then** response ถูกแก้ไขให้แสดงราคาที่ถูกต้องจาก KB หรือแนะนำให้สอบถามทีมขาย

---

### User Story 3 - Policy Compliance Check (Priority: P2)

เมื่อเปิด Policy option ระบบจะตรวจสอบว่าคำตอบไม่ละเมิดนโยบายของธุรกิจ เช่น ไม่พูดถึงคู่แข่ง ไม่ให้ส่วนลดที่ไม่มีจริง ไม่เปิดเผยข้อมูลภายใน

**Why this priority**: Policy compliance มีความสำคัญเท่ากับ Fact Check แต่ตรวจสอบในมุมที่ต่างกัน - ป้องกันความเสียหายทางธุรกิจ

**Independent Test**: สามารถทดสอบได้โดยถาม chatbot เกี่ยวกับคู่แข่งหรือขอส่วนลดพิเศษ → ตรวจสอบว่า response ไม่มีเนื้อหาที่ละเมิดนโยบาย

**Acceptance Scenarios**:

1. **Given** Policy check เปิดอยู่และ Primary AI ตอบโดยไม่พูดถึงคู่แข่ง, **When** Second AI ตรวจสอบ, **Then** response ถูกส่งกลับโดยไม่มีการแก้ไข
2. **Given** Policy check เปิดอยู่และ Primary AI พูดถึงคู่แข่งในคำตอบ, **When** Second AI ตรวจสอบ, **Then** response ถูกแก้ไขโดยลบส่วนที่เกี่ยวกับคู่แข่งออก
3. **Given** Policy check เปิดอยู่และ Primary AI เสนอส่วนลดที่ไม่มีในระบบ, **When** Second AI ตรวจสอบ, **Then** response ถูกแก้ไขโดยลบส่วนลดที่ไม่มีจริงและแนะนำโปรโมชั่นที่มีอยู่จริง (ถ้ามี)

---

### User Story 4 - Personality/Tone Check (Priority: P3)

เมื่อเปิด Personality option ระบบจะตรวจสอบว่าคำตอบมี tone และบุคลิกภาพตรงตาม brand guidelines ที่กำหนดไว้ใน system prompt

**Why this priority**: Personality check มีความสำคัญน้อยกว่า Fact Check และ Policy เพราะผลกระทบต่อความถูกต้องของข้อมูลต่ำกว่า แต่ยังมีผลต่อ brand consistency

**Independent Test**: สามารถทดสอบได้โดยดู response หลายๆ ครั้ง → ตรวจสอบว่า tone สม่ำเสมอตาม brand

**Acceptance Scenarios**:

1. **Given** Personality check เปิดอยู่และ Primary AI ตอบด้วย tone ที่ตรงกับ brand, **When** Second AI ตรวจสอบ, **Then** response ถูกส่งกลับโดยไม่มีการแก้ไข
2. **Given** Personality check เปิดอยู่และ Primary AI ตอบด้วย tone ที่แข็งกระด้าง, **When** Second AI ตรวจสอบ, **Then** response ถูกปรับให้ friendly และเป็นมิตรมากขึ้นตาม brand guidelines
3. **Given** Personality check เปิดอยู่และ brand guidelines กำหนดให้ใช้ภาษาทางการ, **When** Primary AI ใช้ภาษาพูด, **Then** Second AI ปรับให้เป็นภาษาทางการตาม guidelines

---

### Edge Cases

- What happens when Second AI service ไม่สามารถเข้าถึงได้ (timeout/error)?
  - ระบบส่ง response จาก Primary AI โดยตรง พร้อม log error
- What happens when user เปิดทั้ง 3 options พร้อมกัน?
  - ระบบ run checks ทั้งหมดตามลำดับ: Fact Check → Policy → Personality
- What happens when Second AI แก้ไข response แต่ทำให้ความหมายเปลี่ยนไปมาก?
  - ระบบรักษาเจตนาเดิมของ response และแก้ไขเฉพาะส่วนที่ผิด
- What happens when Knowledge Base ว่างเปล่า?
  - Fact Check skip การตรวจสอบและส่ง response ต่อไป

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST persist Second AI settings (enabled/disabled, selected options) when user saves a Flow
- **FR-002**: System MUST load and display saved Second AI settings when user opens Flow Editor
- **FR-003**: System MUST intercept Primary AI response before sending to user when Second AI is enabled
- **FR-004**: System MUST perform Fact Check by comparing response content against Knowledge Base when Fact Check option is enabled
- **FR-005**: System MUST perform Policy Check by validating response against business rules defined in system prompt when Policy option is enabled
- **FR-006**: System MUST perform Personality Check by validating response tone against brand guidelines in system prompt when Personality option is enabled
- **FR-007**: System MUST send original Primary AI response if Second AI service fails or times out (graceful fallback)
- **FR-008**: System MUST log all Second AI operations including checks performed, modifications made, and any errors
- **FR-009**: System MUST execute checks in order: Fact Check → Policy → Personality when multiple options are enabled
- **FR-010**: System MUST preserve original response intent when making corrections

### Key Entities

- **Flow Settings**: Extended to include second_ai_enabled (boolean), second_ai_options (JSON object with factCheck, policy, personality booleans)
- **Second AI Check Log**: Records check type, original response, modified response, modifications made, timestamp, flow_id, conversation_id

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Bot owners can enable/disable Second AI and select options within 10 seconds
- **SC-002**: Settings persist correctly after page refresh with 100% reliability
- **SC-003**: Fact Check reduces hallucinated content (information not in KB) by 80%
- **SC-004**: Policy violations (competitor mentions, fake discounts) reduced by 90%
- **SC-005**: Response latency increases by no more than 3 seconds when Second AI is enabled
- **SC-006**: Second AI availability is 99.5% (fallback to Primary AI response on failure)
- **SC-007**: Bot owners can understand what each option does through clear UI descriptions

## Assumptions

- Primary AI และ Second AI ใช้ model เดียวกันหรือคนละ model ก็ได้ ขึ้นอยู่กับ configuration
- Policy rules (เช่น list of competitors, prohibited topics) จะถูกกำหนดผ่าน system prompt ของ Flow
- Brand guidelines สำหรับ Personality check จะถูกระบุใน system prompt
- ระบบ Knowledge Base ที่มีอยู่รองรับการ query เพื่อ verify facts
- User ยอมรับ trade-off ระหว่าง response quality กับ latency และ cost ที่เพิ่มขึ้น

## Out of Scope

- UI สำหรับกำหนด policy rules แบบ visual (ใช้ system prompt แทน)
- Metrics dashboard แสดงสถิติการแก้ไขของ Second AI
- การ train/fine-tune model เฉพาะสำหรับ Second AI
- A/B testing ระหว่าง enabled/disabled Second AI
