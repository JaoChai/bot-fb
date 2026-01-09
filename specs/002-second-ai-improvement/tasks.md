# Tasks: Second AI for Improvement

**Input**: Design documents from `/specs/002-second-ai-improvement/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/flow-api.yaml, quickstart.md

**Tests**: Not explicitly requested - tests excluded from task list

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3, US4)
- Include exact file paths in descriptions

---

## Phase 1: Setup (Database & Model)

**Purpose**: Database migration and model preparation

- [ ] T001 Create migration for second_ai columns in `backend/database/migrations/xxxx_add_second_ai_columns_to_flows_table.php`
- [ ] T002 Run migration and verify schema in database
- [ ] T003 [P] Add second_ai_enabled and second_ai_options to Flow model fillable in `backend/app/Models/Flow.php`
- [ ] T004 [P] Add casts for second_ai fields in Flow model in `backend/app/Models/Flow.php`

---

## Phase 2: Foundational (API Layer)

**Purpose**: Core API infrastructure that MUST be complete before service implementation

**⚠️ CRITICAL**: No service implementation can begin until this phase is complete

- [ ] T005 [P] Add validation rules to StoreFlowRequest in `backend/app/Http/Requests/Flow/StoreFlowRequest.php`
- [ ] T006 [P] Add validation rules to UpdateFlowRequest in `backend/app/Http/Requests/Flow/UpdateFlowRequest.php`
- [ ] T007 Add second_ai fields to FlowResource response in `backend/app/Http/Resources/FlowResource.php`
- [ ] T008 Create SecondAI services directory structure at `backend/app/Services/SecondAI/`

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Enable Second AI Settings (Priority: P1) 🎯 MVP

**Goal**: Bot owner สามารถเปิด/ปิด Second AI และเลือก options แล้ว save ลง database ได้

**Independent Test**: เปิด Flow Editor → toggle Second AI → เลือก options → บันทึก → reload หน้า → ตรวจสอบว่า settings ยังอยู่

### Implementation for User Story 1

- [ ] T009 [US1] Update handleSave to include second_ai fields in payload in `frontend/src/pages/FlowEditorPage.tsx`
- [ ] T010 [US1] Update useEffect to load second_ai settings from API response in `frontend/src/pages/FlowEditorPage.tsx`
- [ ] T011 [US1] Add SecondAIOptions type definition in `frontend/src/types/api.ts`
- [ ] T012 [US1] Update Flow type to include second_ai fields in `frontend/src/types/api.ts`
- [ ] T013 [US1] Test save and reload flow to verify persistence works end-to-end

**Checkpoint**: User Story 1 complete - settings persist correctly after page refresh

---

## Phase 4: User Story 2 - Fact Check Service (Priority: P2)

**Goal**: ระบบตรวจสอบว่าคำตอบมีข้อมูลตรงกับ Knowledge Base หรือไม่

**Independent Test**: ถาม chatbot คำถามที่ไม่มีใน KB → ตรวจสอบว่า response ไม่มีข้อมูลที่ AI สร้างขึ้นเอง

### Implementation for User Story 2

- [ ] T014 [P] [US2] Create FactCheckService skeleton in `backend/app/Services/SecondAI/FactCheckService.php`
- [ ] T015 [US2] Implement extractClaims method to extract factual claims from response in `backend/app/Services/SecondAI/FactCheckService.php`
- [ ] T016 [US2] Implement check method using HybridSearchService for KB verification in `backend/app/Services/SecondAI/FactCheckService.php`
- [ ] T017 [US2] Implement rewriteWithoutUnverifiedClaims method for response correction in `backend/app/Services/SecondAI/FactCheckService.php`
- [ ] T018 [US2] Create CheckResult value object for standardized check results in `backend/app/Services/SecondAI/CheckResult.php`

**Checkpoint**: Fact Check service can verify claims against KB and correct response

---

## Phase 5: User Story 3 - Policy Check Service (Priority: P2)

**Goal**: ระบบตรวจสอบว่าคำตอบไม่ละเมิดนโยบายธุรกิจ

**Independent Test**: ถาม chatbot เกี่ยวกับคู่แข่งหรือขอส่วนลดพิเศษ → ตรวจสอบว่า response ไม่มีเนื้อหาที่ละเมิดนโยบาย

### Implementation for User Story 3

- [ ] T019 [P] [US3] Create PolicyCheckService skeleton in `backend/app/Services/SecondAI/PolicyCheckService.php`
- [ ] T020 [US3] Implement extractPolicyRules method to extract rules from system prompt in `backend/app/Services/SecondAI/PolicyCheckService.php`
- [ ] T021 [US3] Implement buildPolicyCheckPrompt method for LLM policy check in `backend/app/Services/SecondAI/PolicyCheckService.php`
- [ ] T022 [US3] Implement check method with OpenRouter LLM call in `backend/app/Services/SecondAI/PolicyCheckService.php`
- [ ] T023 [US3] Implement parseAndRewrite method for policy-compliant response in `backend/app/Services/SecondAI/PolicyCheckService.php`

**Checkpoint**: Policy Check service can detect and correct policy violations

---

## Phase 6: User Story 4 - Personality Check Service (Priority: P3)

**Goal**: ระบบตรวจสอบว่าคำตอบมี tone ตรงตาม brand guidelines

**Independent Test**: ดู response หลายๆ ครั้ง → ตรวจสอบว่า tone สม่ำเสมอตาม brand

### Implementation for User Story 4

- [ ] T024 [P] [US4] Create PersonalityCheckService skeleton in `backend/app/Services/SecondAI/PersonalityCheckService.php`
- [ ] T025 [US4] Implement extractBrandGuidelines method from system prompt in `backend/app/Services/SecondAI/PersonalityCheckService.php`
- [ ] T026 [US4] Implement buildPersonalityCheckPrompt method for LLM tone analysis in `backend/app/Services/SecondAI/PersonalityCheckService.php`
- [ ] T027 [US4] Implement check method with OpenRouter LLM call in `backend/app/Services/SecondAI/PersonalityCheckService.php`

**Checkpoint**: Personality Check service can detect and correct tone inconsistencies

---

## Phase 7: Integration & Orchestration

**Purpose**: Wire all services together and integrate into response flow

- [ ] T028 Create SecondAIService orchestrator in `backend/app/Services/SecondAI/SecondAIService.php`
- [ ] T029 Implement process method with sequential check execution (Fact → Policy → Personality) in `backend/app/Services/SecondAI/SecondAIService.php`
- [ ] T030 Add 5-second timeout and fallback logic using Laravel rescue helper in `backend/app/Services/SecondAI/SecondAIService.php`
- [ ] T031 Register SecondAIService in AppServiceProvider in `backend/app/Providers/AppServiceProvider.php`
- [ ] T032 Inject SecondAIService into AIService in `backend/app/Services/AIService.php`
- [ ] T033 Modify AIService generateResponse to call SecondAIService after RAGService in `backend/app/Services/AIService.php`
- [ ] T034 Add logging for Second AI operations in `backend/app/Services/SecondAI/SecondAIService.php`

**Checkpoint**: Second AI fully integrated - responses are checked when enabled

---

## Phase 8: Polish & Validation

**Purpose**: Final testing and edge case handling

- [ ] T035 Test end-to-end: Enable Second AI → Send message → Verify check applied
- [ ] T036 Test fallback: Simulate timeout → Verify original response returned
- [ ] T037 Test edge case: Empty KB → Verify Fact Check skips gracefully
- [ ] T038 Test edge case: All 3 options enabled → Verify sequential execution
- [ ] T039 Verify latency increase ≤3 seconds with Second AI enabled
- [ ] T040 Run quickstart.md manual validation checklist

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 (migration must exist before validation rules)
- **User Story 1 (Phase 3)**: Depends on Phase 2 - Frontend save logic needs API ready
- **User Stories 2-4 (Phase 4-6)**: Can run in parallel after Phase 3 complete
- **Integration (Phase 7)**: Depends on all services being complete (Phase 4-6)
- **Polish (Phase 8)**: Depends on Phase 7 complete

### User Story Dependencies

```
Phase 1 (Setup)
    │
    ▼
Phase 2 (Foundational)
    │
    ▼
Phase 3 (US1: Settings) ─────── 🎯 MVP CHECKPOINT
    │
    ├─────────────────┬─────────────────┐
    ▼                 ▼                 ▼
Phase 4 (US2)     Phase 5 (US3)    Phase 6 (US4)
[Fact Check]      [Policy Check]   [Personality]
    │                 │                 │
    └─────────────────┴─────────────────┘
                      │
                      ▼
              Phase 7 (Integration)
                      │
                      ▼
              Phase 8 (Polish)
```

### Parallel Opportunities

**Within Phase 1:**
```
T003 [P] + T004 [P]  ← Both modify Flow.php but different sections
```

**Within Phase 2:**
```
T005 [P] + T006 [P]  ← Different files (Store vs Update request)
```

**Phase 4-6 (After Phase 3 complete):**
```
Phase 4: T014-T018 (FactCheckService)
Phase 5: T019-T023 (PolicyCheckService)
Phase 6: T024-T027 (PersonalityCheckService)
↑ All three phases can run in parallel ↑
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (T001-T004)
2. Complete Phase 2: Foundational (T005-T008)
3. Complete Phase 3: User Story 1 (T009-T013)
4. **STOP and VALIDATE**: Toggle saves and persists correctly
5. Deploy/demo settings persistence

### Incremental Delivery

| Stage | Deliverable | Value |
|-------|-------------|-------|
| MVP | US1 Complete | Settings persist - no more lost configuration |
| +US2 | Fact Check | Hallucination prevention - major quality improvement |
| +US3 | Policy Check | Business risk mitigation |
| +US4 | Personality Check | Brand consistency |
| Full | All integrated | Complete Second AI system |

### Estimated Effort

| Phase | Tasks | Estimate |
|-------|-------|----------|
| Phase 1-2 | T001-T008 | 30 min |
| Phase 3 (US1) | T009-T013 | 30 min |
| Phase 4 (US2) | T014-T018 | 1-2 hours |
| Phase 5 (US3) | T019-T023 | 1-2 hours |
| Phase 6 (US4) | T024-T027 | 1 hour |
| Phase 7 | T028-T034 | 1 hour |
| Phase 8 | T035-T040 | 30 min |
| **Total** | 40 tasks | ~4-6 hours |

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- Each user story should be independently completable and testable
- Commit after each phase or logical group
- Stop at MVP checkpoint to validate before proceeding
- Fallback strategy (T030) is critical for production reliability
