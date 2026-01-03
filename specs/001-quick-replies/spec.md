# Feature Specification: Quick Replies (Canned Responses)

**Feature Branch**: `001-quick-replies`
**Created**: 2026-01-03
**Status**: Draft
**Input**: User description: "ฟีเจอร์คำตอบที่ใช้บ่อย สำหรับหน้าแชท - Global level, Basic selection + Shortcut autocomplete, Owner-only management"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Agent Uses Quick Reply in Chat (Priority: P1)

เมื่อ Agent กำลังตอบลูกค้าในหน้าแชท ต้องการส่งคำตอบที่ใช้บ่อย (เช่น ทักทาย, ขอบคุณ, นโยบายคืนสินค้า) ได้อย่างรวดเร็ว โดยไม่ต้องพิมพ์ใหม่ทุกครั้ง

**Why this priority**: เป็น core value ของฟีเจอร์ - ช่วยลดเวลาตอบลูกค้าและเพิ่มความสม่ำเสมอในการตอบ

**Independent Test**: Agent สามารถเลือก Quick Reply จาก list แล้วส่งไปยังลูกค้าได้ทันที - ทดสอบได้โดยเปิดหน้าแชท เลือก quick reply และส่ง

**Acceptance Scenarios**:

1. **Given** Agent อยู่ในหน้าแชทและเลือกสนทนากับลูกค้า, **When** คลิกปุ่ม Quick Reply ใกล้ช่อง input, **Then** แสดง list ของ Quick Replies ทั้งหมดที่ active
2. **Given** Quick Reply list เปิดอยู่, **When** Agent คลิกเลือก Quick Reply, **Then** เนื้อหาถูกส่งไปยังลูกค้าทันที
3. **Given** Agent พิมพ์ `/` ในช่อง input, **When** พิมพ์ตัวอักษรต่อ (เช่น `/hello`), **Then** แสดง autocomplete dropdown แสดง Quick Replies ที่ตรงกับ shortcut

---

### User Story 2 - Owner Manages Quick Replies (Priority: P2)

Owner ต้องการจัดการ Quick Replies ของทีม (สร้าง, แก้ไข, ลบ, เรียงลำดับ) เพื่อให้ทุกคนในทีมใช้คำตอบมาตรฐานเดียวกัน

**Why this priority**: จำเป็นสำหรับการตั้งค่าระบบก่อนใช้งาน แต่ไม่ใช่ task ที่ทำบ่อย

**Independent Test**: Owner สามารถเข้าหน้าจัดการ สร้าง Quick Reply ใหม่ และเห็น Quick Reply นั้นปรากฏใน list - ทดสอบได้โดยเข้าหน้า Settings > Quick Replies

**Acceptance Scenarios**:

1. **Given** Owner อยู่ในหน้า Settings, **When** เข้าเมนู Quick Replies, **Then** แสดง list ของ Quick Replies ทั้งหมดพร้อมปุ่มจัดการ
2. **Given** Owner อยู่ในหน้าจัดการ Quick Replies, **When** กดปุ่ม "เพิ่ม" และกรอก shortcut, ชื่อ, เนื้อหา, **Then** Quick Reply ใหม่ถูกบันทึกและปรากฏใน list
3. **Given** Owner มี Quick Reply อยู่แล้ว, **When** แก้ไขเนื้อหา, **Then** การเปลี่ยนแปลงถูกบันทึกและ Agent เห็นเนื้อหาใหม่ทันที
4. **Given** Owner มี Quick Reply อยู่แล้ว, **When** ลบ Quick Reply, **Then** Quick Reply หายไปจาก list และ Agent ไม่เห็นอีกต่อไป

---

### User Story 3 - Agent Searches Quick Replies (Priority: P3)

เมื่อมี Quick Replies จำนวนมาก Agent ต้องการค้นหาด้วยคำสำคัญเพื่อหา Quick Reply ที่ต้องการได้เร็วขึ้น

**Why this priority**: เป็น enhancement สำหรับ usability เมื่อมี Quick Replies มาก

**Independent Test**: Agent สามารถพิมพ์คำค้นหาใน Quick Reply list และเห็นเฉพาะ Quick Replies ที่ตรงกับคำค้น

**Acceptance Scenarios**:

1. **Given** Quick Reply list เปิดอยู่และมีหลายรายการ, **When** Agent พิมพ์คำค้นหาในช่อง search, **Then** แสดงเฉพาะ Quick Replies ที่ชื่อหรือเนื้อหาตรงกับคำค้น
2. **Given** ไม่มี Quick Reply ที่ตรงกับคำค้น, **When** Agent ค้นหา, **Then** แสดงข้อความ "ไม่พบ Quick Reply"

---

### Edge Cases

- What happens when Agent พิมพ์ shortcut ที่ไม่มีอยู่ในระบบ? → แสดง "ไม่พบ shortcut นี้" หรือไม่แสดง autocomplete
- What happens when Owner ลบ Quick Reply ที่ Agent กำลังใช้อยู่? → หายไปจาก list ทันที (real-time update)
- What happens when shortcut มีอักขระพิเศษ? → ใช้ได้เฉพาะ a-z, 0-9, - และ _ เท่านั้น
- What happens when เนื้อหา Quick Reply ยาวเกินขีดจำกัดของช่องทาง (เช่น LINE 5000 bytes)? → แสดง warning ตอนสร้าง/แก้ไข

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: ระบบต้องแสดงปุ่ม Quick Reply ใกล้ช่อง input ในหน้าแชท
- **FR-002**: ระบบต้องแสดง list ของ Quick Replies ที่ active เมื่อกดปุ่ม Quick Reply
- **FR-003**: ระบบต้องส่งเนื้อหา Quick Reply ไปยังลูกค้าเมื่อ Agent เลือก
- **FR-004**: ระบบต้องแสดง autocomplete dropdown เมื่อ Agent พิมพ์ `/` ตามด้วย shortcut
- **FR-005**: ระบบต้องให้เฉพาะ Owner สร้าง, แก้ไข, ลบ Quick Replies ได้
- **FR-006**: ระบบต้องเก็บ Quick Replies ในระดับ Team (ใช้ร่วมกันทุก Bot)
- **FR-007**: ระบบต้องให้ Owner กำหนด shortcut, ชื่อ, และเนื้อหาสำหรับแต่ละ Quick Reply
- **FR-008**: ระบบต้องไม่ยอมให้ shortcut ซ้ำกันภายใน Team เดียวกัน
- **FR-009**: ระบบต้องให้ Owner เปิด/ปิด Quick Reply ได้โดยไม่ต้องลบ
- **FR-010**: ระบบต้องแสดง Quick Replies เรียงตาม sort order ที่ Owner กำหนด

### Key Entities

- **Quick Reply**: คำตอบสำเร็จรูปที่ประกอบด้วย shortcut (คำย่อสำหรับ autocomplete), title (ชื่อแสดงใน list), content (เนื้อหาที่ส่งไปยังลูกค้า), category (หมวดหมู่สำหรับจัดกลุ่ม), sort_order (ลำดับการแสดง), is_active (สถานะเปิด/ปิด)
- **Team**: กลุ่มผู้ใช้ที่ใช้ Quick Replies ร่วมกัน - Owner มีสิทธิ์จัดการ, Member/Agent ใช้งานได้อย่างเดียว

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Agent สามารถส่ง Quick Reply ได้ภายใน 3 คลิก (เปิด list → เลือก → ส่ง) หรือพิมพ์ shortcut ได้ภายใน 2 วินาที
- **SC-002**: Owner สามารถสร้าง Quick Reply ใหม่ได้ภายใน 1 นาที
- **SC-003**: Quick Reply ถูกส่งไปยังลูกค้าสำเร็จ 99% ของครั้งที่ใช้
- **SC-004**: Agent ใช้ Quick Reply แทนการพิมพ์ซ้ำ อย่างน้อย 50% ของข้อความที่มีเนื้อหาเหมือนกัน
- **SC-005**: Autocomplete แสดงผลภายใน 300ms หลังจากพิมพ์ `/`

## Assumptions

- Team และ Owner role มีอยู่ในระบบแล้ว และสามารถตรวจสอบสิทธิ์ได้
- หน้าแชทมี input component ที่รองรับการเพิ่ม UI elements ใกล้ช่อง input
- ระบบสามารถส่งข้อความไปยังลูกค้าผ่าน LINE/Telegram ได้อยู่แล้ว

## Out of Scope

- Quick Reply แบบ per-bot (ใช้เฉพาะบาง Bot)
- Template พร้อม placeholder/variable (เช่น {ชื่อลูกค้า})
- Quick Reply สำหรับ media (รูป, วิดีโอ) - รองรับเฉพาะ text
- Import/Export Quick Replies
- Analytics การใช้งาน Quick Reply
