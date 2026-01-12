# Feature Specification: Lead Recovery

**Feature Branch**: `007-lead-recovery`
**Created**: 2026-01-12
**Status**: Draft
**Input**: User description: "Lead Recovery - ระบบติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ โดยใช้ System Prompt จาก Flow ในการสร้างข้อความ follow-up ที่มี personality ตรงกับ Bot รองรับทั้ง Static mode และ AI mode"

## Executive Summary

Lead Recovery เป็นระบบติดตามลูกค้าอัตโนมัติ เมื่อบทสนทนาเงียบเกินกำหนด ระบบจะส่งข้อความ follow-up เพื่อกระตุ้นให้ลูกค้ากลับมาสนทนาต่อ โดยสามารถเลือกใช้ข้อความแบบ Static (กำหนดเอง) หรือ AI (สร้างจาก System Prompt ของ Flow โดยอ้างอิง context บทสนทนา)

**Business Value**: เพิ่มโอกาสการปิดการขายจากลูกค้าที่สนใจแต่ยังไม่ตัดสินใจ

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Static Message Follow-up (Priority: P1)

เจ้าของ Bot ต้องการให้ระบบส่งข้อความติดตามลูกค้าอัตโนมัติ โดยใช้ข้อความที่กำหนดไว้ล่วงหน้า เมื่อบทสนทนาเงียบเกินเวลาที่ตั้งค่า

**Why this priority**: เป็น core functionality ที่ใช้งานได้ทันทีโดยไม่ต้องพึ่ง AI ลดความเสี่ยงและค่าใช้จ่าย

**Independent Test**: สามารถทดสอบได้โดยเปิด Lead Recovery, ตั้งค่า timeout และข้อความ, รอให้เวลาผ่าน แล้วตรวจสอบว่าลูกค้าได้รับข้อความ follow-up

**Acceptance Scenarios**:

1. **Given** Bot ที่เปิด Lead Recovery แบบ Static พร้อมตั้งค่า timeout 4 ชั่วโมง, **When** บทสนทนาเงียบครบ 4 ชั่วโมง, **Then** ระบบส่งข้อความ follow-up ที่กำหนดไว้ให้ลูกค้า
2. **Given** Bot ที่เปิด Lead Recovery ตั้งค่า max attempts 2 ครั้ง, **When** ลูกค้าไม่ตอบหลังจากส่ง follow-up ครั้งที่ 2, **Then** ระบบหยุดส่งข้อความ follow-up สำหรับบทสนทนานั้น
3. **Given** บทสนทนาที่รอ follow-up, **When** ลูกค้าส่งข้อความก่อนถึงเวลา timeout, **Then** ระบบ reset นับเวลาใหม่และไม่ส่ง follow-up

---

### User Story 2 - AI-Generated Follow-up (Priority: P2)

เจ้าของ Bot ต้องการให้ระบบสร้างข้อความ follow-up โดยใช้ AI ที่อ้างอิง System Prompt ของ Flow และ context จากบทสนทนาล่าสุด เพื่อให้ข้อความมี personality ตรงกับ Bot และเกี่ยวข้องกับสิ่งที่ลูกค้าสนใจ

**Why this priority**: เพิ่มประสิทธิภาพการ recover lead ด้วยข้อความที่ personalized แต่ต้องมี Static mode ทำงานก่อน

**Independent Test**: สามารถทดสอบได้โดยเปิด Lead Recovery แบบ AI, ให้ลูกค้าสนทนาเกี่ยวกับสินค้า รอ timeout แล้วตรวจสอบว่าข้อความ follow-up อ้างอิงสินค้าที่สนใจและมี personality ตรงกับ Bot

**Acceptance Scenarios**:

1. **Given** Bot ที่เปิด Lead Recovery แบบ AI และมี Default Flow ที่มี System Prompt, **When** ระบบต้องส่ง follow-up, **Then** ข้อความที่สร้างมี personality ตรงกับ System Prompt ของ Flow
2. **Given** บทสนทนาที่ลูกค้าสนใจ "ครีมกันแดด" ก่อนเงียบ, **When** ระบบสร้างข้อความ follow-up, **Then** ข้อความอ้างอิงถึง "ครีมกันแดด" ที่ลูกค้าสนใจ
3. **Given** Bot ที่ไม่มี Default Flow, **When** ระบบต้องสร้าง AI follow-up, **Then** ระบบ fallback ใช้ข้อความ Static แทน

---

### User Story 3 - Recovery Tracking & Analytics (Priority: P3)

เจ้าของ Bot ต้องการดูสถิติว่า Lead Recovery ทำงานได้ดีแค่ไหน เช่น จำนวนข้อความที่ส่ง, อัตราการตอบกลับ, อัตราการ recover สำเร็จ

**Why this priority**: ช่วยให้เจ้าของ Bot ปรับปรุงข้อความและ timing ได้ แต่ไม่จำเป็นสำหรับ core functionality

**Independent Test**: สามารถทดสอบได้โดยดู dashboard หลังจากระบบส่ง follow-up ไปหลายครั้ง แล้วตรวจสอบว่าสถิติถูกต้อง

**Acceptance Scenarios**:

1. **Given** ระบบส่ง follow-up 10 ครั้ง และลูกค้าตอบกลับ 4 ครั้ง, **When** เจ้าของ Bot ดูสถิติ, **Then** แสดงอัตราการตอบกลับ 40%
2. **Given** ข้อมูล Lead Recovery ของ Bot, **When** เจ้าของ Bot ดู analytics, **Then** เห็นจำนวนข้อความที่ส่ง แยกตามวัน/สัปดาห์/เดือน

---

### User Story 4 - Configuration Settings (Priority: P1)

เจ้าของ Bot ต้องการตั้งค่า Lead Recovery ได้ง่าย รวมถึง timeout, รูปแบบข้อความ, จำนวนครั้งที่ติดตาม

**Why this priority**: จำเป็นต้องมีเพื่อให้ User Story 1 และ 2 ทำงานได้

**Independent Test**: สามารถทดสอบได้โดยเปิดหน้า Bot Settings, ตั้งค่า Lead Recovery, บันทึก และตรวจสอบว่าค่าถูกเก็บถูกต้อง

**Acceptance Scenarios**:

1. **Given** เจ้าของ Bot อยู่ในหน้า Bot Settings, **When** เปิดสวิตช์ Lead Recovery, **Then** แสดง options ให้ตั้งค่าเพิ่มเติม
2. **Given** หน้าตั้งค่า Lead Recovery, **When** เจ้าของ Bot ตั้งค่า timeout, message mode, และ max attempts, **Then** ระบบบันทึกค่าและใช้งานได้ทันที
3. **Given** Lead Recovery แบบ Static, **When** เจ้าของ Bot ไม่กรอกข้อความ, **Then** ระบบใช้ข้อความ default

---

### Edge Cases

- ลูกค้าบล็อค Bot หรือยกเลิกการติดตาม - ระบบต้องจัดการ error และหยุดส่งข้อความ
- Bot มีหลาย Flow - ระบบใช้ Default Flow หรือ Flow แรกที่พบ
- บทสนทนาเกิดนอกเวลาทำการ (Response Hours) - ระบบควรรอจนถึงเวลาทำการก่อนส่ง follow-up
- ลูกค้าอยู่ใน HITL mode (มนุษย์กำลังคุย) - ระบบไม่ควรส่ง follow-up อัตโนมัติ
- ข้อความ follow-up ถูก rate limit - ระบบควร retry ในภายหลัง

---

## Requirements *(mandatory)*

### Functional Requirements

**Core Settings**
- **FR-001**: System MUST allow bot owners to enable/disable Lead Recovery per bot
- **FR-002**: System MUST allow configuration of inactivity timeout (minimum 1 hour, maximum 72 hours, default 4 hours)
- **FR-003**: System MUST allow configuration of maximum follow-up attempts (1-5 times, default 2 times)
- **FR-004**: System MUST allow selection of message mode: Static or AI

**Static Mode**
- **FR-005**: System MUST allow bot owners to set a custom follow-up message for Static mode
- **FR-006**: System MUST use a sensible default message if custom message is not provided
- **FR-007**: System MUST send the exact configured message to inactive conversations

**AI Mode**
- **FR-008**: System MUST use the bot's Default Flow System Prompt as personality reference
- **FR-009**: System MUST include last 5 messages from conversation as context for AI generation
- **FR-010**: System MUST generate short follow-up messages (maximum 2 sentences)
- **FR-011**: System MUST fallback to Static mode if no Default Flow exists or AI generation fails

**Scheduling & Execution**
- **FR-012**: System MUST scan for inactive conversations periodically (every 1 hour)
- **FR-013**: System MUST respect Response Hours settings - only send during business hours
- **FR-014**: System MUST NOT send follow-up when conversation is in HITL mode (human takeover)
- **FR-015**: System MUST reset inactivity timer when customer sends a new message
- **FR-016**: System MUST stop follow-up attempts after reaching max attempts limit

**Tracking**
- **FR-017**: System MUST log all follow-up attempts with timestamp, message content, and delivery status
- **FR-018**: System MUST track when customer responds after receiving follow-up
- **FR-019**: System MUST provide recovery rate statistics to bot owners

**Channel Support**
- **FR-020**: System MUST support sending follow-up via LINE, Telegram, and Facebook Messenger
- **FR-021**: System MUST handle channel-specific errors gracefully (blocked users, expired tokens)

---

### Key Entities

- **LeadRecoveryLog**: บันทึกการส่ง follow-up แต่ละครั้ง รวมถึง conversation_id, attempt_number, message_sent, sent_at, customer_responded, responded_at

- **BotHITLSettings (extended)**: เพิ่ม settings สำหรับ Lead Recovery ได้แก่ lead_recovery_timeout_hours, lead_recovery_message, lead_recovery_mode, lead_recovery_max_attempts

- **Conversation (extended)**: เพิ่ม fields สำหรับ tracking ได้แก่ recovery_attempts, last_recovery_at

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Bot owners can enable and configure Lead Recovery in under 2 minutes
- **SC-002**: Follow-up messages are delivered within 15 minutes after inactivity timeout is reached
- **SC-003**: AI-generated messages maintain consistent personality with the bot's configured tone
- **SC-004**: Customer response rate after follow-up is at least 20% (indicating message relevance)
- **SC-005**: System successfully sends 99% of scheduled follow-up messages (handling channel errors gracefully)
- **SC-006**: Zero follow-up messages sent during HITL mode or outside business hours
- **SC-007**: Recovery statistics are accurately calculated and displayed to bot owners

---

## Assumptions

1. **AI Model**: System will use a cost-effective AI model (e.g., GPT-4o-mini) for generating follow-up messages to minimize costs
2. **Scheduling**: Laravel's built-in scheduler will run the recovery job hourly
3. **Default Message**: Thai language default: "สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ"
4. **Conversation Status**: Only "active" conversations will be considered for follow-up
5. **Rate Limiting**: Follow-up messages respect existing bot rate limits
6. **Timezone**: Response Hours use the bot's configured timezone

---

## Dependencies

1. **Existing BotHITLSettings table**: Already has lead_recovery_enabled field
2. **Conversation model**: Already has last_message_at field for tracking inactivity
3. **LLMService**: Existing service for AI text generation
4. **Channel Services**: LINE, Telegram, Facebook services for sending messages

---

## Out of Scope

1. Multi-language follow-up messages (future enhancement)
2. A/B testing of different follow-up messages
3. Machine learning optimization of send timing
4. Integration with external CRM systems
5. SMS/Email follow-up channels
