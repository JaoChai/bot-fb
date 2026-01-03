# Research: Quick Replies

**Feature**: 001-quick-replies
**Date**: 2026-01-03

## Summary

ไม่มี NEEDS CLARIFICATION จาก spec - requirements ชัดเจน Document นี้บันทึก decisions และ alternatives ที่พิจารณา

## Decisions

### 1. Data Storage Level

**Decision**: Team level (shared across all bots)
**Rationale**: ผู้ใช้เลือก Global scope - ทุก Bot ใน Team ใช้ร่วมกัน
**Alternatives considered**:
- Per-bot: ยืดหยุ่นกว่าแต่ต้องจัดการหลาย set
- Per-user: แต่ละคนมี set ของตัวเอง - ซับซ้อนเกินไป

### 2. Shortcut Format

**Decision**: `/keyword` pattern (slash + alphanumeric + hyphen + underscore)
**Rationale**: เป็น pattern ที่ familiar จาก Slack, Discord และ chat apps อื่นๆ
**Alternatives considered**:
- `#keyword`: อาจสับสนกับ hashtag
- `@keyword`: อาจสับสนกับ mention
- `:keyword:`: ใช้สำหรับ emoji แล้ว

### 3. Autocomplete Trigger

**Decision**: Trigger เมื่อพิมพ์ `/` ที่ต้นข้อความหรือหลัง space
**Rationale**: ป้องกัน false trigger เมื่อพิมพ์ URL หรือข้อความปกติ
**Alternatives considered**:
- Trigger ทุกครั้งที่เห็น `/`: อาจ trigger ผิดพลาดบ่อย
- ต้องกด hotkey ก่อน: เพิ่มขั้นตอนโดยไม่จำเป็น

### 4. Permission Model

**Decision**: Owner-only for CRUD, All team members can use
**Rationale**: ป้องกันความสับสนจากการแก้ไขโดยหลายคน แต่ยังให้ทุกคนใช้ได้
**Alternatives considered**:
- Everyone can CRUD: อาจเกิดความสับสน
- Separate "Quick Reply Manager" role: ซับซ้อนเกินไปสำหรับฟีเจอร์นี้

### 5. Content Validation

**Decision**: Max 5000 bytes (LINE limit) + warn on create/edit
**Rationale**: LINE มี limit 5000 bytes, Telegram ไม่มี limit ที่ใกล้เคียง
**Alternatives considered**:
- No limit: อาจ fail เมื่อส่งไป LINE
- Per-channel limit: ซับซ้อนเกินไป

## Technical Findings

### Existing Patterns

1. **Laravel Resource Pattern**: ใช้ใน BotResource, FlowResource, etc.
2. **React Query Hooks**: ใช้ใน useBots, useFlows, useConversations
3. **Policy Authorization**: ใช้ใน BotPolicy, TeamPolicy
4. **shadcn/ui Components**: ใช้ทั้ง project

### Integration Points

1. **Message Input**: ChatWindow.tsx มี input area ที่ต้องเพิ่ม Quick Reply button
2. **Send Message**: `useSendAgentMessage` hook พร้อมใช้งาน
3. **Settings Page**: มี settings routes อยู่แล้ว สามารถเพิ่ม Quick Replies menu

### Team Relationship

- User belongs to Team via `team_users` table
- Team has owner_id field
- Check ownership via `$team->owner_id === $user->id`

## No Outstanding Unknowns

ทุก requirements ชัดเจน พร้อมดำเนินการ Phase 1
