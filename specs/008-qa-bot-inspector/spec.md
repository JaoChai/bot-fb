# Feature Specification: QA Bot Inspector

**Feature Branch**: `008-qa-bot-inspector`
**Created**: 2026-01-13
**Status**: Draft
**Input**: User description: "QA Bot Inspector - ระบบ AI Agent สำหรับตรวจสอบคุณภาพการตอบของ Bot อัตโนมัติ พร้อม Weekly Report และ Prompt Improvement Suggestions"

## Overview

QA Bot Inspector เป็นระบบ AI Agent ที่ทำหน้าที่ตรวจสอบคุณภาพการตอบของ Bot แบบอัตโนมัติ โดยมี 3 layers:

1. **Layer 1: Real-time Evaluation** - ประเมินทุก conversation แบบ real-time
2. **Layer 2: Deep Analysis** - วิเคราะห์ root cause สำหรับ issues ที่ถูก flag
3. **Layer 3: Weekly Report** - สรุปผลทุกสัปดาห์ พร้อมเสนอ prompt improvement

ระบบนี้ช่วยให้ผู้ใช้สามารถ:
- ติดตามคุณภาพการตอบของ Bot แบบอัตโนมัติ
- ระบุปัญหาและ root cause ได้อย่างแม่นยำ
- ปรับปรุง system prompt ได้ตรงจุด

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enable QA Inspector for Bot (Priority: P1)

ในฐานะเจ้าของ Bot ฉันต้องการเปิดใช้งานระบบ QA Inspector เพื่อให้ระบบเริ่มตรวจสอบคุณภาพการตอบของ Bot อัตโนมัติ

**Why this priority**: เป็น core functionality ที่ต้องทำก่อน หากไม่มีการเปิดใช้งาน feature อื่นๆ จะไม่สามารถทำงานได้

**Independent Test**: สามารถทดสอบได้โดยเปิด toggle QA Inspector ใน Bot settings และตรวจสอบว่า system เริ่มบันทึก evaluation logs

**Acceptance Scenarios**:

1. **Given** ฉันอยู่ในหน้า Bot Settings, **When** ฉันเปิด toggle "QA Inspector", **Then** ระบบจะเริ่มประเมินทุก conversation ของ Bot นี้
2. **Given** QA Inspector เปิดอยู่, **When** ฉันปิด toggle, **Then** ระบบจะหยุดประเมินและไม่สร้าง evaluation logs ใหม่
3. **Given** QA Inspector เปิดอยู่, **When** มี conversation ใหม่เกิดขึ้น, **Then** ระบบจะสร้าง evaluation log พร้อมคะแนน 5 metrics

---

### User Story 2 - Configure QA Inspector Models (Priority: P1)

ในฐานะเจ้าของ Bot ฉันต้องการเลือก AI model ที่ใช้ในแต่ละ layer ของ QA Inspector เพื่อควบคุม cost และ quality ตามความต้องการ

**Why this priority**: ความสามารถในการ configure model เป็น core requirement ตามที่ user ระบุ

**Independent Test**: สามารถทดสอบได้โดยเปลี่ยน model ใน settings และตรวจสอบว่า evaluation ใช้ model ที่เลือก

**Acceptance Scenarios**:

1. **Given** ฉันอยู่ในหน้า QA Inspector Settings, **When** ฉันใส่ชื่อ model ในรูปแบบ `provider/model-name`, **Then** ระบบจะบันทึกและใช้ model นั้นสำหรับ layer ที่เลือก
2. **Given** ฉันตั้งค่า Primary Model และ Fallback Model, **When** Primary Model fail, **Then** ระบบจะใช้ Fallback Model แทนโดยอัตโนมัติ
3. **Given** ฉันไม่ได้ตั้งค่า model, **When** ระบบทำ evaluation, **Then** ระบบจะใช้ default models (Gemini Flash, Claude Sonnet, Claude Opus)

---

### User Story 3 - View Real-time Evaluation Results (Priority: P2)

ในฐานะเจ้าของ Bot ฉันต้องการดูผลการประเมินแบบ real-time เพื่อติดตามคุณภาพการตอบของ Bot

**Why this priority**: ให้ visibility เข้าสู่การทำงานของระบบ แต่ไม่จำเป็นสำหรับ core evaluation functionality

**Independent Test**: สามารถทดสอบได้โดยดู evaluation logs ใน dashboard หลังจาก Bot มี conversation

**Acceptance Scenarios**:

1. **Given** QA Inspector เปิดอยู่และมี conversations, **When** ฉันเข้าหน้า QA Dashboard, **Then** ฉันจะเห็นรายการ evaluation logs พร้อมคะแนนแต่ละ metric
2. **Given** มี evaluation log ที่ score < threshold, **When** ฉันดู log นั้น, **Then** จะเห็น flag "Issue Detected" พร้อม issue type
3. **Given** ฉันอยู่ใน QA Dashboard, **When** มี evaluation ใหม่เกิดขึ้น, **Then** dashboard จะ update แบบ real-time (ภายใน 5 วินาที)

---

### User Story 4 - View Weekly Report (Priority: P2)

ในฐานะเจ้าของ Bot ฉันต้องการดู Weekly Report ที่สรุปผลการทำงานของ Bot ในรอบสัปดาห์

**Why this priority**: เป็น key deliverable ที่ช่วยให้ user ตัดสินใจปรับปรุง prompt

**Independent Test**: สามารถทดสอบได้โดยดู Weekly Report หลังจากระบบ generate รายงานตาม schedule

**Acceptance Scenarios**:

1. **Given** ถึงเวลาที่กำหนด (เช่น Monday 00:00), **When** ระบบ run scheduled job, **Then** ระบบจะสร้าง Weekly Report สำหรับ Bot ที่เปิด QA Inspector
2. **Given** Weekly Report ถูกสร้าง, **When** ฉันเปิดดู report, **Then** ฉันจะเห็น Performance Summary, Top Issues, และ Prompt Improvement Suggestions
3. **Given** ฉันเปิด email notification, **When** Weekly Report ถูกสร้าง, **Then** ฉันจะได้รับ email พร้อม link ไปยัง report

---

### User Story 5 - Apply Prompt Improvements (Priority: P3)

ในฐานะเจ้าของ Bot ฉันต้องการนำ Prompt Improvement Suggestions ไปใช้กับ Flow ของฉัน เพื่อแก้ไขปัญหาที่พบ

**Why this priority**: เป็น advanced feature ที่ต่อยอดจาก Weekly Report

**Independent Test**: สามารถทดสอบได้โดยดู suggestion ใน report และ apply ไปยัง Flow

**Acceptance Scenarios**:

1. **Given** Weekly Report มี Prompt Improvement Suggestion, **When** ฉันกด "View Suggestion", **Then** ฉันจะเห็น exact section ใน system prompt ที่ต้องแก้ พร้อม before/after
2. **Given** ฉันดู Prompt Suggestion, **When** ฉันกด "Apply to Flow", **Then** ระบบจะนำ suggestion ไป update Flow.system_prompt โดยอัตโนมัติ
3. **Given** ฉัน apply suggestion แล้ว, **When** ฉันดู Flow settings, **Then** จะเห็น system prompt ที่ถูก update

---

### User Story 6 - Configure Additional Settings (Priority: P3)

ในฐานะเจ้าของ Bot ฉันต้องการปรับ settings เพิ่มเติม เช่น threshold, sampling rate, และ schedule

**Why this priority**: เป็น customization ที่ไม่จำเป็นสำหรับ core functionality

**Independent Test**: สามารถทดสอบได้โดยเปลี่ยน settings และตรวจสอบว่าระบบทำงานตาม settings ใหม่

**Acceptance Scenarios**:

1. **Given** ฉันตั้ง score threshold เป็น 0.80, **When** มี conversation ที่ได้ score 0.75, **Then** ระบบจะ flag เป็น issue
2. **Given** ฉันตั้ง sampling rate เป็น 50%, **When** มี 100 conversations, **Then** ระบบจะ evaluate ~50 conversations
3. **Given** ฉันตั้ง schedule เป็น "Friday 18:00", **When** ถึงเวลาวันศุกร์ 18:00, **Then** ระบบจะสร้าง Weekly Report

---

### Edge Cases

- **ไม่มี conversations ในสัปดาห์**: Weekly Report จะแสดง "No data available" พร้อมคำแนะนำให้ตรวจสอบ Bot status
- **AI Model fail ทั้ง Primary และ Fallback**: ระบบจะบันทึก error log และส่ง alert ไปยัง user ถ้าเปิด notification
- **Flow ไม่มี system_prompt**: ระบบจะใช้ Bot.system_prompt เป็น fallback ในการอ้างอิง
- **Evaluation ใช้เวลานานเกินไป (> 30 วินาที)**: ระบบจะ timeout และบันทึก partial results พร้อม warning
- **Rate limit จาก AI provider**: ระบบจะ queue evaluations และ retry ด้วย exponential backoff

## Requirements *(mandatory)*

### Functional Requirements

#### Core Functionality
- **FR-001**: System MUST allow users to enable/disable QA Inspector per Bot
- **FR-002**: System MUST evaluate every conversation (or sampled) when QA Inspector is enabled
- **FR-003**: System MUST calculate 5 metrics: answer_relevancy, faithfulness, role_adherence, context_precision, task_completion
- **FR-004**: System MUST calculate overall_score as weighted average (relevancy 25%, faithfulness 25%, role 20%, context 15%, task 15%)
- **FR-005**: System MUST flag conversations with score below threshold as "issue"

#### Model Configuration
- **FR-006**: System MUST allow users to configure AI models for each layer (Layer 1, 2, 3)
- **FR-007**: System MUST support primary + fallback model configuration per layer
- **FR-008**: System MUST use fallback model automatically when primary fails
- **FR-009**: System MUST accept model names in format `provider/model-name`
- **FR-010**: System MUST use default models when user doesn't configure (Gemini Flash, Claude Sonnet, Claude Opus)

#### Deep Analysis (Layer 2)
- **FR-011**: System MUST automatically analyze flagged issues to identify root cause
- **FR-012**: System MUST identify the specific section in system prompt that caused the issue
- **FR-013**: System MUST categorize issues by type (price_error, hallucination, wrong_tone, missing_info, off_topic, unanswered)

#### Weekly Report (Layer 3)
- **FR-014**: System MUST generate Weekly Report according to configured schedule
- **FR-015**: System MUST include Performance Summary (total conversations, average score, error rate, trends)
- **FR-016**: System MUST include Top Issues with root cause analysis
- **FR-017**: System MUST include Prompt Improvement Suggestions with exact location in system prompt
- **FR-018**: System MUST reference the actual Flow.system_prompt when generating suggestions

#### Settings
- **FR-019**: System MUST allow users to configure score threshold (default: 0.70)
- **FR-020**: System MUST allow users to configure sampling rate (default: 100%)
- **FR-021**: System MUST allow users to configure report schedule (default: Monday 00:00)
- **FR-022**: System MUST allow users to configure notifications (email, alert, slack)

#### Data Management
- **FR-023**: System MUST store evaluation logs with all metrics and metadata
- **FR-024**: System MUST retain evaluation logs for at least 90 days
- **FR-025**: System MUST store Weekly Reports for historical reference

### Key Entities

- **EvaluationLog**: บันทึกผลการประเมินแต่ละ conversation พร้อมคะแนน 5 metrics, overall score, issue flags, และ metadata
- **WeeklyReport**: รายงานสรุปประจำสัปดาห์ พร้อม performance summary, top issues, และ prompt suggestions
- **QAInspectorSettings**: การตั้งค่า QA Inspector ต่อ Bot รวมถึง model configuration, threshold, sampling rate, schedule, notifications
- **PromptSuggestion**: คำแนะนำการปรับปรุง prompt พร้อม before/after text และ location reference

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Users can enable QA Inspector and see evaluation results within 30 seconds of a conversation completing
- **SC-002**: System correctly identifies 90%+ of issues (verified against manual review sample)
- **SC-003**: Weekly Report is generated automatically on schedule with 99% reliability
- **SC-004**: Prompt Improvement Suggestions correctly reference the problematic section in system prompt 85%+ of the time
- **SC-005**: Total evaluation cost stays within estimated budget (~$62/month for 200 conversations/day)
- **SC-006**: System handles 500+ conversations/day without performance degradation
- **SC-007**: Users report 40%+ improvement in Bot response quality after applying prompt suggestions (measured over 4 weeks)
- **SC-008**: 80% of users find Weekly Report "useful" or "very useful" (based on feedback)

## Assumptions

1. **OpenRouter API availability**: ระบบใช้ OpenRouter เป็น AI provider หลัก ซึ่งถือว่า available 99.9%+
2. **Model pricing stability**: ราคาโมเดลอาจเปลี่ยนแปลง แต่คาดว่าจะอยู่ในช่วงใกล้เคียงกับที่ประมาณไว้
3. **Flow.system_prompt structure**: System prompt ของ user มีโครงสร้างที่อ่านได้ (มี sections, headings) เพื่อให้ระบบอ้างอิงได้
4. **Existing evaluation infrastructure**: ระบบมี LLMJudgeService, ModelTierSelector ที่สามารถ reuse ได้
5. **Queue infrastructure**: ระบบมี Laravel Queue infrastructure พร้อมสำหรับ background processing
6. **Email notification**: ระบบมี email service configuration พร้อมใช้งาน

## Out of Scope

1. **Automatic prompt editing**: ระบบเสนอ suggestions แต่ไม่ auto-apply โดยไม่มี user confirmation
2. **Multi-language support**: รองรับเฉพาะภาษาไทยและอังกฤษในเบื้องต้น
3. **Custom metrics**: ไม่สามารถ define metrics ใหม่ได้ (ใช้ 5 metrics ที่กำหนดเท่านั้น)
4. **Cross-bot comparison**: ไม่เปรียบเทียบ performance ระหว่าง Bots ในรายงาน
5. **Real-time alerting via SMS/Phone**: รองรับเฉพาะ email และ slack notification
