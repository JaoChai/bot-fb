# Tasks: Lead Recovery

**Input**: Design documents from `/specs/007-lead-recovery/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/lead-recovery-api.yaml

**Organization**: Tasks are grouped by user story to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3, US4)

## Path Conventions

- **Backend**: `backend/app/`, `backend/database/`, `backend/routes/`
- **Frontend**: `frontend/src/`

---

## Phase 1: Setup (Database Migrations)

**Purpose**: Create database schema changes required for Lead Recovery

- [ ] T001 [P] Create migration for BotHITLSettings extension in `backend/database/migrations/2026_01_12_000001_add_lead_recovery_to_bot_hitl_settings.php`
- [ ] T002 [P] Create migration for Conversation extension in `backend/database/migrations/2026_01_12_000002_add_recovery_fields_to_conversations.php`
- [ ] T003 [P] Create migration for LeadRecoveryLog table in `backend/database/migrations/2026_01_12_000003_create_lead_recovery_logs_table.php`
- [ ] T004 Run migrations and verify schema in database

**Checkpoint**: Database schema ready for Lead Recovery feature

---

## Phase 2: Foundational (Core Models & Service)

**Purpose**: Core infrastructure that MUST be complete before user story implementation

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T005 [P] Extend BotHITLSettings model with lead recovery fields in `backend/app/Models/BotHITLSettings.php`
- [ ] T006 [P] Extend Conversation model with recovery tracking fields and scopeNeedsRecovery in `backend/app/Models/Conversation.php`
- [ ] T007 [P] Create LeadRecoveryLog model in `backend/app/Models/LeadRecoveryLog.php`
- [ ] T008 Add recoveryLogs relationship to Conversation model in `backend/app/Models/Conversation.php`
- [ ] T009 Create LeadRecoveryService skeleton in `backend/app/Services/LeadRecoveryService.php`

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 + 4 - Static Follow-up & Settings (Priority: P1) 🎯 MVP

**Goal**: Bot owners can enable Lead Recovery, configure settings, and send static follow-up messages automatically

**Independent Test**: Enable Lead Recovery, set timeout to 1 hour and static message, wait for timeout, verify customer receives the message

### US4: Configuration Settings

- [ ] T010 [US4] Update BotSettingController to handle lead recovery settings in `backend/app/Http/Controllers/Api/BotSettingController.php`
- [ ] T011 [US4] Add validation rules for lead recovery settings in BotHITLSettings or FormRequest
- [ ] T012 [US4] Create LeadRecoverySection component in `frontend/src/components/bot-settings/LeadRecoverySection.tsx`
- [ ] T013 [US4] Integrate LeadRecoverySection into HITLSettingsSection in `frontend/src/components/bot-settings/HITLSettingsSection.tsx`
- [ ] T014 [US4] Add API types for lead recovery settings in `frontend/src/types/bot.ts`

### US1: Static Message Follow-up

- [ ] T015 [US1] Implement findEligibleConversations method in `backend/app/Services/LeadRecoveryService.php`
- [ ] T016 [US1] Implement sendStaticFollowUp method in `backend/app/Services/LeadRecoveryService.php`
- [ ] T017 [US1] Implement logRecoveryAttempt method in `backend/app/Services/LeadRecoveryService.php`
- [ ] T018 [US1] Create ProcessLeadRecovery job in `backend/app/Jobs/ProcessLeadRecovery.php`
- [ ] T019 [US1] Integrate ResponseHoursService check in ProcessLeadRecovery job
- [ ] T020 [US1] Integrate channel services (LINE/Telegram/Facebook) for message sending
- [ ] T021 [US1] Register hourly scheduler in `backend/routes/console.php`
- [ ] T022 [US1] Add default follow-up message constant in config or LeadRecoveryService

**Checkpoint**: Static mode Lead Recovery is fully functional and testable

---

## Phase 4: User Story 2 - AI-Generated Follow-up (Priority: P2)

**Goal**: Bot owners can use AI to generate personalized follow-up messages based on System Prompt and conversation context

**Independent Test**: Enable AI mode, have customer discuss a product, wait for timeout, verify follow-up message references the product and matches bot personality

### Implementation for User Story 2

- [ ] T023 [US2] Implement getSystemPromptFromDefaultFlow method in `backend/app/Services/LeadRecoveryService.php`
- [ ] T024 [US2] Implement getConversationContext method (last 5 messages) in `backend/app/Services/LeadRecoveryService.php`
- [ ] T025 [US2] Implement generateAIFollowUp method using OpenRouterService in `backend/app/Services/LeadRecoveryService.php`
- [ ] T026 [US2] Create AI prompt template for follow-up generation in `backend/app/Services/LeadRecoveryService.php`
- [ ] T027 [US2] Implement fallback to static mode when AI fails or no Default Flow exists
- [ ] T028 [US2] Add mode selector (static/ai) to LeadRecoverySection in `frontend/src/components/bot-settings/LeadRecoverySection.tsx`

**Checkpoint**: AI mode Lead Recovery is fully functional and falls back to static when needed

---

## Phase 5: User Story 3 - Recovery Tracking & Analytics (Priority: P3)

**Goal**: Bot owners can view statistics on Lead Recovery performance including sent count and response rate

**Independent Test**: After system sends several follow-ups, view analytics page and verify counts and rates are accurate

### Implementation for User Story 3

- [ ] T029 [P] [US3] Create LeadRecoveryController in `backend/app/Http/Controllers/Api/LeadRecoveryController.php`
- [ ] T030 [US3] Implement getStats endpoint (GET /api/bots/{botId}/lead-recovery/stats) in LeadRecoveryController
- [ ] T031 [US3] Implement getLogs endpoint (GET /api/bots/{botId}/lead-recovery/logs) in LeadRecoveryController
- [ ] T032 [US3] Add routes for stats and logs endpoints in `backend/routes/api.php`
- [ ] T033 [US3] Implement markCustomerResponded method in `backend/app/Services/LeadRecoveryService.php`
- [ ] T034 [US3] Call markCustomerResponded when customer sends message after follow-up
- [ ] T035 [US3] Add analytics API hooks in frontend (optional for MVP)

**Checkpoint**: Analytics endpoints are functional and return accurate statistics

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] T036 [P] Add error handling for blocked users and expired tokens in channel services
- [ ] T037 [P] Add logging for Lead Recovery operations (sent, failed, responded)
- [ ] T038 Handle rate limiting for follow-up messages
- [ ] T039 Add unit tests for LeadRecoveryService in `backend/tests/Unit/Services/LeadRecoveryServiceTest.php`
- [ ] T040 Add feature tests for Lead Recovery endpoints in `backend/tests/Feature/LeadRecoveryTest.php`
- [ ] T041 Run quickstart.md validation to verify end-to-end flow
- [ ] T042 Update API documentation if needed

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3-5)**: All depend on Foundational phase completion
  - Phase 3 (US1+US4) → Phase 4 (US2) → Phase 5 (US3)
  - US2 depends on US1 for fallback logic
- **Polish (Phase 6)**: Depends on all user stories being complete

### User Story Dependencies

```
Phase 1: Setup
     ↓
Phase 2: Foundational
     ↓
Phase 3: US1+US4 (Static + Settings) ← MVP Release Point
     ↓
Phase 4: US2 (AI Mode) ← Depends on US1 for fallback
     ↓
Phase 5: US3 (Analytics) ← Can run after US1, independent of US2
     ↓
Phase 6: Polish
```

### Within Each User Story

- Models before services
- Services before controllers/jobs
- Backend before frontend (for settings)
- Core implementation before integrations

### Parallel Opportunities

**Phase 1 (all can run in parallel):**
```
T001: BotHITLSettings migration
T002: Conversation migration
T003: LeadRecoveryLog migration
```

**Phase 2 (models can run in parallel):**
```
T005: BotHITLSettings extension
T006: Conversation extension
T007: LeadRecoveryLog model
```

**Phase 3 (US4 frontend can parallel with backend):**
```
Backend: T010, T011 → T015-T022
Frontend: T012-T014 (parallel with backend after T010)
```

---

## Parallel Example: Phase 2 (Foundational)

```bash
# Launch all model tasks together:
Task: "Extend BotHITLSettings model in backend/app/Models/BotHITLSettings.php"
Task: "Extend Conversation model in backend/app/Models/Conversation.php"
Task: "Create LeadRecoveryLog model in backend/app/Models/LeadRecoveryLog.php"
```

---

## Implementation Strategy

### MVP First (Phase 3 Only)

1. Complete Phase 1: Setup (migrations)
2. Complete Phase 2: Foundational (models, service skeleton)
3. Complete Phase 3: US1 + US4 (Static mode + Settings)
4. **STOP and VALIDATE**: Test static follow-up independently
5. Deploy/demo MVP

### Incremental Delivery

1. Setup + Foundational → Schema and models ready
2. Add US1 + US4 → Static mode works → **Deploy MVP**
3. Add US2 → AI mode works → Deploy
4. Add US3 → Analytics works → Deploy
5. Polish → Production ready

### Estimated Task Counts

| Phase | Tasks | Purpose |
|-------|-------|---------|
| Phase 1 | 4 | Database setup |
| Phase 2 | 5 | Core models & service |
| Phase 3 | 13 | Static mode + Settings (MVP) |
| Phase 4 | 6 | AI mode |
| Phase 5 | 7 | Analytics |
| Phase 6 | 7 | Polish |
| **Total** | **42** | Full feature |

### MVP Scope

- Phase 1-3 only = 22 tasks
- Delivers: Static follow-up with configurable settings
- Ready for production use without AI costs

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- US1 and US4 combined in Phase 3 because settings are prerequisite for static mode
- AI mode (US2) requires static mode for fallback
- Analytics (US3) is independent but useful after lead recovery is working
- Tests are included in Phase 6 (not TDD approach per spec)
