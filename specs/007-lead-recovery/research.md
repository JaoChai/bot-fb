# Research: Lead Recovery

**Feature**: 007-lead-recovery
**Date**: 2026-01-12

## Technical Decisions

### 1. Scheduling Strategy

**Decision**: Laravel Scheduler with hourly Job dispatch

**Rationale**:
- Laravel 12 has built-in scheduler support via `routes/console.php`
- Hourly scanning is sufficient (timeout minimum is 1 hour)
- Using Queue for async processing prevents blocking

**Alternatives Considered**:
- Real-time event-based: More complex, not needed for this use case
- Per-conversation timer: Database overhead, harder to manage
- Cron-based: Less flexible, harder to test

### 2. AI Model Selection

**Decision**: GPT-4o-mini via OpenRouter (existing integration)

**Rationale**:
- Cost: ~$0.00015/1K input, $0.0006/1K output tokens
- Speed: ~500ms average response time
- Quality: Sufficient for short follow-up messages
- Existing OpenRouterService already supports this model

**Alternatives Considered**:
- Gemini 2.0 Flash: Free tier available, but requires new integration
- Claude Haiku: Higher quality but more expensive
- GPT-4: Overkill for simple follow-up messages

### 3. Message Generation Approach

**Decision**: Use System Prompt from Default Flow + Last 5 messages as context

**Rationale**:
- Maintains consistent personality with bot
- Context from recent messages enables personalized follow-up
- Limiting to 5 messages keeps token usage low (~200 input tokens)
- Fallback to static message if AI fails

**Prompt Template**:
```
{system_prompt}

## Task
ลูกค้าเคยสนทนาแต่หายไป ให้ส่งข้อความติดตามสั้นๆ 1-2 ประโยค

## Recent Conversation
{last_5_messages}

## Rules
- เป็นมิตร ไม่กดดัน
- อ้างอิงสิ่งที่ลูกค้าสนใจ (ถ้ามี)
- รักษา personality จาก system prompt
```

### 4. Database Schema Extension

**Decision**: Extend existing tables + new LeadRecoveryLog table

**Rationale**:
- BotHITLSettings already exists with lead_recovery_enabled
- Adding new columns is safer than new table for settings
- Separate log table for audit trail and analytics
- Minimal migration risk

**Schema Changes**:
- `bot_hitl_settings`: Add timeout, message, mode, max_attempts
- `conversations`: Add recovery_attempts, last_recovery_at
- `lead_recovery_logs`: New table for tracking

### 5. Channel Integration

**Decision**: Use existing LINEService, TelegramService, FacebookService

**Rationale**:
- Services already have pushMessage/sendMessage methods
- Error handling already implemented
- Rate limiting already in place

**Implementation**:
- Determine channel from conversation.channel_type
- Call appropriate service method
- Handle errors gracefully (log and skip)

### 6. Response Hours Integration

**Decision**: Check ResponseHoursService before sending

**Rationale**:
- Existing service already handles timezone and schedule checking
- Prevents sending messages outside business hours
- Consistent with other bot features

**Implementation**:
- Call `ResponseHoursService::isWithinResponseHours($bot)`
- Skip conversation if outside hours (will be picked up next run)

### 7. HITL Mode Handling

**Decision**: Check conversation.is_handover flag

**Rationale**:
- Existing flag indicates human takeover
- No automatic messages should be sent during handover
- Simple boolean check

### 8. Frontend Component Placement

**Decision**: Expand LeadRecoverySection in HITLSettingsSection

**Rationale**:
- Lead Recovery toggle already exists in HITLSettingsSection
- Add expanded settings when toggle is ON
- Consistent with existing UI patterns

---

## Best Practices Applied

### From Laravel Documentation
- Use `Schedule::job()` for queue-based scheduling
- Use database transactions for multi-table updates
- Use Eloquent relationships for eager loading

### From Existing Codebase
- Service pattern: Single responsibility, dependency injection
- Job pattern: Implements ShouldQueue, has handle() method
- Migration pattern: Incremental, with rollback support

### From AI Integration Patterns
- Use try-catch with fallback for AI generation
- Keep prompts concise to minimize token usage
- Log AI costs for monitoring

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| AI generation fails | Fallback to static message |
| Channel API error | Log error, mark as failed, continue |
| High volume | Queue-based processing, rate limiting |
| Duplicate sends | Check last_recovery_at before sending |
| Outside business hours | ResponseHoursService check |

---

## Dependencies Verified

| Dependency | Status | Location |
|------------|--------|----------|
| OpenRouterService | ✅ Exists | `backend/app/Services/OpenRouterService.php` |
| LINEService | ✅ Exists | `backend/app/Services/LINEService.php` |
| TelegramService | ✅ Exists | `backend/app/Services/TelegramService.php` |
| FacebookService | ✅ Exists | `backend/app/Services/FacebookService.php` |
| ResponseHoursService | ✅ Exists | `backend/app/Services/ResponseHoursService.php` |
| BotHITLSettings | ✅ Exists | `backend/app/Models/BotHITLSettings.php` |
| Conversation | ✅ Exists | `backend/app/Models/Conversation.php` |
| HITLSettingsSection | ✅ Exists | `frontend/src/components/bot-settings/HITLSettingsSection.tsx` |
