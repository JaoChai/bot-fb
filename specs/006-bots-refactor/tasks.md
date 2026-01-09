# Tasks: Bots Page Comprehensive Refactoring

**Input**: Design documents from `/specs/006-bots-refactor/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Test tasks are included per FR-027, FR-028, FR-029 requirements.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- **Backend**: `backend/` (Laravel)
- **Frontend**: `frontend/src/` (React)
- **Tests**: `backend/tests/`, `frontend/tests/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and basic structure for refactoring

- [ ] T001 Create branch `006-bots-refactor` from main
- [ ] T002 [P] Create directory `frontend/src/components/bot-settings/`
- [ ] T003 [P] Create directory `frontend/src/components/flow-editor/`
- [ ] T004 [P] Create shared types file `frontend/src/components/bot-settings/types.ts`
- [ ] T005 [P] Create shared types file `frontend/src/components/flow-editor/types.ts`
- [ ] T006 Install L5-Swagger package: `composer require darkaonline/l5-swagger`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T007 Add `encrypted` cast for `channel_access_token` in `backend/app/Models/Bot.php`
- [ ] T008 Add `encrypted` cast for `channel_secret` in `backend/app/Models/Bot.php`
- [ ] T009 Add boolean casts for `hitl_enabled`, `second_ai_enabled` in `backend/app/Models/Flow.php`
- [ ] T010 Create migration for `default_flow_id` foreign key with `nullOnDelete` in `backend/database/migrations/`
- [ ] T011 Add LLM model validation config in `backend/config/services.php` (openrouter.models array)
- [ ] T012 Run migration: `php artisan migrate`

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Secure Bot Management (Priority: P1) 🎯 MVP

**Goal**: Protect channel credentials from unauthorized access with masking and reveal functionality

**Independent Test**: Attempt to access bot credentials via API as non-owner and verify they are hidden; as owner verify masked with reveal option

### Tests for User Story 1

- [ ] T013 [P] [US1] Create `backend/tests/Feature/BotCredentialSecurityTest.php` with tests for:
  - Non-owner cannot see credentials
  - Owner sees masked credentials
  - Owner can reveal credentials via explicit endpoint
- [ ] T014 [P] [US1] Create `backend/tests/Unit/FlowSetDefaultTest.php` with tests for:
  - setDefault uses transaction
  - Concurrent requests result in exactly one default

### Implementation for User Story 1

- [ ] T015 [US1] Modify `backend/app/Http/Resources/BotResource.php` to conditionally hide credentials for non-owners (FR-001)
- [ ] T016 [US1] Add credential masking for owner users in `backend/app/Http/Resources/BotResource.php` (FR-002)
- [ ] T017 [US1] Create reveal credentials endpoint `GET /api/bots/{id}/credentials` in `backend/app/Http/Controllers/Api/BotController.php`
- [ ] T018 [US1] Add route for credentials endpoint in `backend/routes/api.php`
- [ ] T019 [US1] Implement race condition fix with `DB::transaction()` and `lockForUpdate()` in `backend/app/Http/Controllers/Api/FlowController.php` setDefault method (FR-003)
- [ ] T020 [US1] Add LLM model validation in `backend/app/Http/Requests/Bot/UpdateBotRequest.php` (FR-005)
- [ ] T021 [US1] Add KB pivot validation (kb_top_k: 1-20, kb_similarity_threshold: 0.1-1.0) in `backend/app/Http/Requests/Flow/UpdateFlowRequest.php` (FR-007)

**Checkpoint**: User Story 1 complete - credentials are protected, race condition fixed, validations added

---

## Phase 4: User Story 2 - Performant Admin Management (Priority: P1)

**Goal**: Eliminate N+1 queries when loading admin list with conversation counts

**Independent Test**: Create bot with 20+ admins, verify page loads in <500ms with max 5 database queries

### Tests for User Story 2

- [ ] T022 [P] [US2] Create `backend/tests/Feature/AdminN1QueryTest.php` with tests for:
  - Admin list uses max 3 queries for 20 admins
  - Active conversation counts loaded via eager loading

### Implementation for User Story 2

- [ ] T023 [US2] Fix N+1 query with `withCount()` in `backend/app/Http/Controllers/Api/AdminController.php` index method (FR-004)
- [ ] T024 [US2] Verify query count with Laravel Debugbar or DB::enableQueryLog()

**Checkpoint**: User Story 2 complete - admin list loads efficiently

---

## Phase 5: User Story 3 - Facebook Integration (Priority: P2)

**Goal**: Enable Facebook Messenger integration with webhook processing and message sending

**Independent Test**: Create Facebook bot, send test webhook, verify message is processed and response sent

### Tests for User Story 3

- [ ] T025 [P] [US3] Create `backend/tests/Feature/FacebookWebhookTest.php` with tests for:
  - Webhook verification challenge
  - Signature validation
  - Message processing
  - Invalid payload handling

### Implementation for User Story 3

- [ ] T026 [P] [US3] Create `backend/app/Services/FacebookService.php` with methods:
  - sendMessage()
  - getProfile()
  - validateSignature()
- [ ] T027 [US3] Create `backend/app/Http/Controllers/Webhook/FacebookWebhookController.php` with:
  - verify() for webhook challenge
  - handle() for webhook events
- [ ] T028 [US3] Create `backend/app/Jobs/ProcessFacebookWebhook.php` for async message processing
- [ ] T029 [US3] Add Facebook webhook routes in `backend/routes/web.php`:
  - GET /webhook/facebook/{webhookId} (verify)
  - POST /webhook/facebook/{webhookId} (handle)
- [ ] T030 [US3] Add `setWebhook()` method to `backend/app/Services/LINEService.php` for auto webhook setup (FR-014)
- [ ] T031 [US3] Remove `webhook_forwarder_enabled` toggle from `frontend/src/pages/EditConnectionPage.tsx` (FR-011)
- [ ] T032 [US3] Remove Plugins section from `frontend/src/pages/FlowEditorPage.tsx` (FR-012)
- [ ] T033 [US3] Remove External Data Sources section from `frontend/src/pages/FlowEditorPage.tsx` (FR-013)

**Checkpoint**: User Story 3 complete - Facebook integration works, unused UI removed

---

## Phase 6: User Story 4 - Maintainable Settings Interface (Priority: P2)

**Goal**: Split BotSettingsPage and FlowEditorPage into focused sub-components for maintainability

**Independent Test**: Modify RateLimitSection, verify no changes to other sections; all 11 features work identically

### Tests for User Story 4

- [ ] T034 [P] [US4] Create `frontend/tests/components/bot-settings/RateLimitSection.test.tsx`
- [ ] T035 [P] [US4] Create `frontend/tests/components/bot-settings/HITLSection.test.tsx`

### Implementation for User Story 4

#### BotSettingsPage Split (FR-015)

- [ ] T036 [P] [US4] Extract `frontend/src/components/bot-settings/RateLimitSection.tsx`
- [ ] T037 [P] [US4] Extract `frontend/src/components/bot-settings/HITLSection.tsx`
- [ ] T038 [P] [US4] Extract `frontend/src/components/bot-settings/ResponseHoursSection.tsx`
- [ ] T039 [P] [US4] Extract `frontend/src/components/bot-settings/SmartAggregationSection.tsx`
- [ ] T040 [P] [US4] Extract `frontend/src/components/bot-settings/MultipleBubblesSection.tsx`
- [ ] T041 [P] [US4] Extract `frontend/src/components/bot-settings/ReplyStickerSection.tsx`
- [ ] T042 [P] [US4] Extract `frontend/src/components/bot-settings/LeadRecoverySection.tsx`
- [ ] T043 [P] [US4] Extract `frontend/src/components/bot-settings/EasySlipSection.tsx`
- [ ] T044 [P] [US4] Extract `frontend/src/components/bot-settings/ReplyWhenCalledSection.tsx`
- [ ] T045 [P] [US4] Extract `frontend/src/components/bot-settings/AutoAssignmentSection.tsx`
- [ ] T046 [P] [US4] Extract `frontend/src/components/bot-settings/AnalyticsSection.tsx`
- [ ] T047 [US4] Create `frontend/src/components/bot-settings/index.ts` re-export file
- [ ] T048 [US4] Refactor `frontend/src/pages/BotSettingsPage.tsx` to use sub-components

#### FlowEditorPage Split (FR-016)

- [ ] T049 [P] [US4] Extract `frontend/src/components/flow-editor/FlowBasicInfo.tsx`
- [ ] T050 [P] [US4] Extract `frontend/src/components/flow-editor/AgenticModeSection.tsx`
- [ ] T051 [P] [US4] Extract `frontend/src/components/flow-editor/KnowledgeBaseSection.tsx`
- [ ] T052 [P] [US4] Extract `frontend/src/components/flow-editor/SystemPromptEditor.tsx`
- [ ] T053 [P] [US4] Extract `frontend/src/components/flow-editor/SafetySettingsSection.tsx`
- [ ] T054 [P] [US4] Extract `frontend/src/components/flow-editor/SecondAISection.tsx`
- [ ] T055 [US4] Create `frontend/src/components/flow-editor/index.ts` re-export file
- [ ] T056 [US4] Refactor `frontend/src/pages/FlowEditorPage.tsx` to use sub-components

#### Hook Consolidation (FR-017, FR-018, FR-019, FR-020)

- [ ] T057 [US4] Move `useBots()` hook to dedicated `frontend/src/hooks/useBots.ts`
- [ ] T058 [US4] Consolidate `BotEditPage` into `EditConnectionPage` and remove deprecated page
- [ ] T059 [US4] Standardize refetch strategies in `frontend/src/hooks/useConnections.ts` (use invalidateQueries)
- [ ] T060 [US4] Establish naming convention (use "bots" consistently) across codebase

**Checkpoint**: User Story 4 complete - components split, hooks consolidated

---

## Phase 7: User Story 5 - Structured Bot Configuration (Priority: P3)

**Goal**: Split bot_settings table into logical sub-tables for better maintainability

**Independent Test**: Verify all 50+ settings accessible after migration, no data loss

### Tests for User Story 5

- [ ] T061 [P] [US5] Create `backend/tests/Feature/BotSettingsMigrationTest.php` for data integrity

### Implementation for User Story 5

- [ ] T062 [P] [US5] Create `backend/app/Models/BotLimits.php` with relationship to BotSetting
- [ ] T063 [P] [US5] Create `backend/app/Models/BotHITLSettings.php` with relationship to BotSetting
- [ ] T064 [P] [US5] Create `backend/app/Models/BotAggregationSettings.php` with relationship to BotSetting
- [ ] T065 [P] [US5] Create `backend/app/Models/BotResponseHours.php` with relationship to BotSetting
- [ ] T066 [US5] Create migration `backend/database/migrations/2026_01_xx_create_bot_limits_table.php`
- [ ] T067 [US5] Create migration `backend/database/migrations/2026_01_xx_create_bot_hitl_settings_table.php`
- [ ] T068 [US5] Create migration `backend/database/migrations/2026_01_xx_create_bot_aggregation_settings_table.php`
- [ ] T069 [US5] Create migration `backend/database/migrations/2026_01_xx_create_bot_response_hours_table.php`
- [ ] T070 [US5] Create data migration command `backend/app/Console/Commands/MigrateBotSettings.php`
- [ ] T071 [US5] Add HasOne relationships in `backend/app/Models/BotSetting.php`:
  - limits()
  - hitlSettings()
  - aggregationSettings()
  - responseHours()
- [ ] T072 [US5] Update `backend/app/Http/Controllers/Api/BotSettingController.php` to handle sub-relations
- [ ] T073 [US5] Update `frontend/src/hooks/useBotSettings.ts` to support sub-table structure
- [ ] T074 [US5] Run migrations and data migration: `php artisan migrate && php artisan bot:migrate-settings`

#### Flow Audit Trail (FR-022)

- [ ] T075 [P] [US5] Create `backend/app/Models/FlowAuditLog.php`
- [ ] T076 [US5] Create migration `backend/database/migrations/2026_01_xx_create_flow_audit_logs_table.php`
- [ ] T077 [US5] Add auditLogs relationship in `backend/app/Models/Flow.php`
- [ ] T078 [US5] Add audit logging in `backend/app/Http/Controllers/Api/FlowController.php` for create/update/delete

**Checkpoint**: User Story 5 complete - database restructured, audit trail implemented

---

## Phase 8: User Story 6 - Documented API (Priority: P3)

**Goal**: Provide comprehensive OpenAPI documentation for all bot endpoints

**Independent Test**: New developer can implement API client using only documentation

### Implementation for User Story 6

- [ ] T079 [US6] Configure L5-Swagger in `backend/config/l5-swagger.php`
- [ ] T080 [P] [US6] Add OpenAPI annotations to `backend/app/Http/Controllers/Api/BotController.php`
- [ ] T081 [P] [US6] Add OpenAPI annotations to `backend/app/Http/Controllers/Api/BotSettingController.php`
- [ ] T082 [P] [US6] Add OpenAPI annotations to `backend/app/Http/Controllers/Api/FlowController.php`
- [ ] T083 [P] [US6] Add OpenAPI annotations to `backend/app/Http/Controllers/Api/AdminController.php`
- [ ] T084 [P] [US6] Add OpenAPI annotations to webhook controllers
- [ ] T085 [US6] Create base OpenAPI info annotation in `backend/app/Http/Controllers/Controller.php`
- [ ] T086 [US6] Generate documentation: `php artisan l5-swagger:generate`
- [ ] T087 [US6] Verify documentation at `/api/documentation` matches actual API

**Checkpoint**: User Story 6 complete - all 13 endpoints documented

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Final integration tests, E2E tests, and cleanup

### Integration Tests (FR-027)

- [ ] T088 [P] Create `backend/tests/Feature/LINEWebhookIntegrationTest.php`
- [ ] T089 [P] Create `backend/tests/Feature/TelegramWebhookIntegrationTest.php`

### E2E Tests (FR-029)

- [ ] T090 Create `frontend/e2e/bot-create-configure.spec.ts` with Playwright for critical flow

### Final Cleanup

- [ ] T091 Run full test suite: `php artisan test && npm test`
- [ ] T092 Review all TODO comments and resolve
- [ ] T093 Update `specs/006-bots-refactor/quickstart.md` with any changes
- [ ] T094 Final code review using `code-review` agent set
- [ ] T095 Merge to main branch

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3-8)**: All depend on Foundational phase completion
  - US1 and US2 can run in parallel (both P1, no shared dependencies)
  - US3 depends on US1 (credentials security)
  - US4 can run in parallel with US3 (frontend vs backend)
  - US5 depends on US4 (frontend needs to support new structure)
  - US6 depends on US5 (document final API structure)
- **Polish (Phase 9)**: Depends on all user stories being complete

### User Story Dependencies

```
              ┌─────────────────────────────────────┐
              │        Foundational (P2)             │
              │    (encryption, casts, FK, config)   │
              └──────────────┬──────────────────────┘
                             │
           ┌─────────────────┼─────────────────┐
           │                 │                 │
           ▼                 ▼                 ▼
    ┌──────────┐      ┌──────────┐      ┌──────────┐
    │   US1    │      │   US2    │      │   US4    │
    │ Security │      │  N+1 Fix │      │ Frontend │
    │   (P1)   │      │   (P1)   │      │  Split   │
    └────┬─────┘      └──────────┘      │   (P2)   │
         │                              └────┬─────┘
         │                                   │
         ▼                                   │
    ┌──────────┐                             │
    │   US3    │                             │
    │ Facebook │                             │
    │   (P2)   │                             │
    └──────────┘                             │
                                             ▼
                                       ┌──────────┐
                                       │   US5    │
                                       │ Database │
                                       │  Split   │
                                       │   (P3)   │
                                       └────┬─────┘
                                             │
                                             ▼
                                       ┌──────────┐
                                       │   US6    │
                                       │  API Doc │
                                       │   (P3)   │
                                       └──────────┘
```

### Within Each User Story

- Tests MUST be written and FAIL before implementation
- Models before services
- Services before endpoints
- Core implementation before integration
- Story complete before moving to next priority

### Parallel Opportunities

**Phase 2 (Foundational)**:
- T007, T008 (encryption casts) can run in parallel
- T009, T010, T011 can run in parallel

**Phase 3 (US1)**:
- T013, T014 (tests) can run in parallel

**Phase 5 (US3)**:
- T025 (test) and T026 (service) can run in parallel

**Phase 6 (US4)**:
- All 11 BotSettings sections (T036-T046) can run in parallel
- All 6 FlowEditor sections (T049-T054) can run in parallel

**Phase 7 (US5)**:
- All 4 sub-models (T062-T065) can run in parallel

**Phase 8 (US6)**:
- All controller annotations (T080-T084) can run in parallel

---

## Parallel Example: User Story 4 Component Extraction

```bash
# Launch all BotSettings sections in parallel:
Task: "Extract RateLimitSection.tsx"
Task: "Extract HITLSection.tsx"
Task: "Extract ResponseHoursSection.tsx"
Task: "Extract SmartAggregationSection.tsx"
Task: "Extract MultipleBubblesSection.tsx"
Task: "Extract ReplyStickerSection.tsx"
Task: "Extract LeadRecoverySection.tsx"
Task: "Extract EasySlipSection.tsx"
Task: "Extract ReplyWhenCalledSection.tsx"
Task: "Extract AutoAssignmentSection.tsx"
Task: "Extract AnalyticsSection.tsx"

# Then integrate after all complete:
Task: "Refactor BotSettingsPage.tsx to use sub-components"
```

---

## Implementation Strategy

### MVP First (User Stories 1 + 2 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL - blocks all stories)
3. Complete Phase 3: User Story 1 (Credential Security)
4. Complete Phase 4: User Story 2 (N+1 Fix)
5. **STOP and VALIDATE**: Test both stories independently
6. Deploy security fixes immediately

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. US1 + US2 → Test independently → Deploy (Security MVP!)
3. US3 (Facebook) → Test independently → Deploy
4. US4 (Frontend Split) → Test independently → Deploy
5. US5 (Database Split) → Test independently → Deploy
6. US6 (Documentation) → Test independently → Deploy
7. Polish → Final Deploy

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: User Story 1 (Security)
   - Developer B: User Story 2 (N+1 Fix)
3. After US1:
   - Developer A: User Story 3 (Facebook)
   - Developer B: User Story 4 (Frontend - can run parallel with US3)
4. After US4:
   - Full team: User Story 5 (Database - requires coordination)
5. After US5:
   - Developer A: User Story 6 (Documentation)
   - Developer B: Start Polish phase

---

## Notes

- [P] tasks = different files, no dependencies within same phase
- [Story] label maps task to specific user story for traceability
- Each user story should be independently completable and testable
- Verify tests fail before implementing
- Commit after each task or logical group
- Stop at any checkpoint to validate story independently
- Avoid: vague tasks, same file conflicts, cross-story dependencies that break independence
- **Security First**: US1 (credential protection) must be the first deployed change
