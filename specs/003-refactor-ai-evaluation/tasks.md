# Tasks: Refactor AI Evaluation System - Phase 1

**Input**: Design documents from `/specs/003-refactor-ai-evaluation/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Tests are NOT included in this task list (not requested in specification)

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- **Backend**: `backend/app/Services/`, `backend/tests/`
- **Frontend**: `frontend/src/`, `frontend/tests/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and verification

- [X] T001 Verify development environment (PHP 8.2+, Node 20+, composer, npm)
- [X] T002 [P] Verify OpenRouter API key in backend/.env
- [X] T003 [P] Install backend dependencies: `cd backend && composer install`
- [X] T004 [P] Install frontend dependencies: `cd frontend && npm install`
- [X] T005 Verify existing tests pass: `php artisan test` (establish baseline)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core value objects and base structure that ALL user stories depend on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T006 [P] Create SecondAICheckResult value object in backend/app/Services/SecondAI/SecondAICheckResult.php
- [X] T007 [P] Create ModelTierConfig value object in backend/app/Services/Evaluation/ModelTierConfig.php
- [X] T008 Verify value objects with unit tests: Create backend/tests/Unit/SecondAI/SecondAICheckResultTest.php
- [X] T009 [P] Verify ModelTierConfig with unit tests: Create backend/tests/Unit/Evaluation/ModelTierConfigTest.php

**Checkpoint**: Foundation ready - user story implementation can now begin in parallel

---

## Phase 3: User Story 1 - Second AI Performance Improvement (Priority: P1) 🎯 MVP

**Goal**: Reduce Second AI latency from 3-6s to ≤1.5s and cost by 60% through unified LLM call

**Independent Test**:
1. สร้าง flow ที่เปิด Second AI ทั้ง 3 options (Fact + Policy + Personality)
2. ส่งข้อความทดสอบ bot
3. ตรวจสอบ logs: ควรเห็น "unified mode" ถูกใช้, elapsed_ms ≤1500
4. ทดสอบ fallback: Mock unified call fail → ควร fallback ไป sequential

### Implementation for User Story 1

**Backend: UnifiedCheckService (NEW)**

- [X] T010 [P] [US1] Create UnifiedCheckService class in backend/app/Services/SecondAI/UnifiedCheckService.php
- [X] T011 [US1] Implement buildUnifiedPrompt() method with fact/policy/personality sections
- [X] T012 [US1] Implement check() method: call LLM with unified prompt
- [X] T013 [US1] Implement parseResponse() method: parse JSON and validate structure
- [X] T014 [US1] Add error handling: JSON parse errors, timeout, invalid format
- [X] T015 [US1] Return SecondAICheckResult from check() method

**Backend: SecondAIService Refactor**

- [X] T016 [US1] Add UnifiedCheckService dependency in SecondAIService constructor (backend/app/Services/SecondAI/SecondAIService.php)
- [X] T017 [US1] Create shouldUseUnifiedMode() helper method: check if ≥2 options enabled
- [X] T018 [US1] Modify process() method: try unified mode first, fallback to sequential
- [X] T019 [US1] Add logging: unified mode status, fallback events, performance metrics
- [X] T020 [US1] Convert SecondAICheckResult to legacy format using toLegacyFormat()

**Integration**

- [X] T021 [US1] Create UnifiedCheckService integration test in backend/tests/Feature/SecondAI/UnifiedCheckTest.php
- [X] T022 [US1] Create fallback scenario test in backend/tests/Feature/SecondAI/FallbackTest.php
- [X] T023 [US1] Manual testing: Follow quickstart.md "Test Scenario 1" steps
- [X] T024 [US1] Verify performance: Check logs for latency ≤1500ms, cost reduction ≥60%

**Checkpoint**: At this point, User Story 1 should be fully functional with unified mode working and fallback tested

---

## Phase 4: User Story 2 - Evaluation Cost Reduction (Priority: P2)

**Goal**: Reduce evaluation cost by 50% through model tier system (budget/standard/premium)

**Independent Test**:
1. สร้าง evaluation ใหม่ (20-40 test cases)
2. รัน evaluation
3. ตรวจสอบ logs: simple metrics ใช้ budget/standard, complex metrics ใช้ premium
4. ตรวจสอบ cost: ควรลด ≥50% จาก baseline

### Implementation for User Story 2

**Backend: ModelTierSelector (NEW)**

- [ ] T025 [P] [US2] Create ModelTierSelector class in backend/app/Services/Evaluation/ModelTierSelector.php
- [ ] T026 [US2] Define METRIC_TIER_MAP constant: map metrics to tiers
- [ ] T027 [US2] Define TIER_MODEL_MAP constant: map tiers to models with fallbacks
- [ ] T028 [US2] Implement selectForMetric() method: return ModelTierConfig
- [ ] T029 [US2] Implement getFallbackModel() method: return fallback model ID
- [ ] T030 [US2] Implement estimateTotalCost() method: calculate evaluation cost

**Backend: LLMJudgeService Refactor**

- [X] T031 [US2] Add ModelTierSelector dependency in LLMJudgeService constructor (backend/app/Services/Evaluation/LLMJudgeService.php)
- [X] T032 [US2] Modify evaluateMetric() method: use tierSelector to get model config
- [X] T033 [US2] Implement primary model call with try-catch
- [X] T034 [US2] Implement fallback logic: use fallback model if primary fails
- [X] T035 [US2] Add logging: tier used, model used, fallback events
- [X] T036 [US2] Store model used in test case metadata for cost tracking

**Integration**

- [X] T037 [US2] Create ModelTierSelector unit test in backend/tests/Unit/Evaluation/ModelTierSelectorTest.php
- [X] T038 [US2] Create model tier integration test in backend/tests/Feature/Evaluation/ModelTierTest.php
- [X] T039 [US2] Manual testing: Follow quickstart.md "Test Scenario 2" steps
- [X] T040 [US2] Verify cost reduction: Check actual cost vs baseline (target ≥50% reduction)

**Checkpoint**: At this point, User Stories 1 AND 2 should both work independently

---

## Phase 5: User Story 3 - Knowledge Base Warning UI (Priority: P3)

**Goal**: Reduce user confusion by warning when Fact Check enabled without Knowledge Base

**Independent Test**:
1. เปิด flow editor
2. ลบ Knowledge Base (ถ้ามี)
3. เปิด "Fact Check" checkbox
4. ควรเห็น yellow warning ทันที
5. คลิก "เพิ่ม Knowledge Base" → navigate ไป /knowledge-bases
6. เพิ่ม KB → warning หายไป

### Implementation for User Story 3

**Frontend: KnowledgeBaseWarning Component (NEW)**

- [X] T041 [P] [US3] Create component directory: frontend/src/components/flow/
- [X] T042 [US3] Create KnowledgeBaseWarning.tsx component in frontend/src/components/flow/KnowledgeBaseWarning.tsx
- [X] T043 [US3] Implement component props: visible (boolean)
- [X] T044 [US3] Implement warning UI: yellow background, AlertTriangle icon, message
- [X] T045 [US3] Implement navigation: button click → navigate('/knowledge-bases')
- [X] T046 [US3] Add conditional rendering: return null if !visible

**Frontend: FlowEditorPage Integration**

- [X] T047 [US3] Import KnowledgeBaseWarning in FlowEditorPage.tsx (frontend/src/pages/FlowEditorPage.tsx)
- [X] T048 [US3] Add KnowledgeBaseWarning component below Fact Check checkbox
- [X] T049 [US3] Calculate visible prop: secondAI.options.fact_check && flow.knowledge_bases.length === 0
- [X] T050 [US3] Test visibility: warning shows when conditions met, hides otherwise

**Integration**

- [X] T051 [US3] Manual testing: Follow quickstart.md "Test Scenario 3" steps
- [X] T052 [US3] Test user flow: toggle Fact Check → see warning → click button → navigate
- [X] T053 [US3] Test edge cases: warning hides when KB attached, when Fact Check disabled

**Checkpoint**: All user stories should now be independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [X] T054 [P] Add environment variable documentation in backend/.env.example
- [X] T055 [P] Update CLAUDE.md with new services and refactor patterns
- [X] T056 Verify all existing tests still pass: `php artisan test` (zero breaking changes)
- [X] T057 [P] Verify frontend tests pass: `npm test` in frontend/ (N/A - no test script configured)
- [X] T058 Run code style check: `./vendor/bin/pint --test` in backend/
- [X] T059 [P] Run frontend linting: `npm run lint` in frontend/
- [X] T060 Performance validation: Compare actual vs target metrics (latency, cost)
- [X] T061 Create commit with conventional format: `feat(ai-evaluation): implement Phase 1 refactor`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3-5)**: All depend on Foundational phase completion
  - US1, US2, US3 can proceed in parallel (if staffed)
  - Or sequentially in priority order (P1 → P2 → P3)
- **Polish (Phase 6)**: Depends on all user stories being complete

### User Story Dependencies

- **User Story 1 (US1)**: Can start after Foundational (Phase 2) - No dependencies on other stories
- **User Story 2 (US2)**: Can start after Foundational (Phase 2) - Independent of US1
- **User Story 3 (US3)**: Can start after Foundational (Phase 2) - Independent of US1 and US2

### Within Each User Story

**US1 (Second AI Unified Call)**:
- T010-T015 (UnifiedCheckService) can be done first
- T016-T020 (SecondAIService refactor) depends on T010-T015 completion
- T021-T024 (Integration tests) depends on all implementation complete

**US2 (Evaluation Model Tiers)**:
- T025-T030 (ModelTierSelector) can be done first
- T031-T036 (LLMJudgeService refactor) depends on T025-T030 completion
- T037-T040 (Integration tests) depends on all implementation complete

**US3 (KB Warning UI)**:
- T041-T046 (Component creation) can be done first
- T047-T050 (Integration) depends on T041-T046 completion
- T051-T053 (Manual tests) depends on all implementation complete

### Parallel Opportunities

- Phase 1 Setup: All tasks (T001-T005) can run in parallel
- Phase 2 Foundational: T006-T007 (value objects) can run in parallel, T008-T009 (tests) depend on value objects
- **After Foundational**: All 3 user stories can start in parallel:
  - Developer A: US1 (T010-T024)
  - Developer B: US2 (T025-T040)
  - Developer C: US3 (T041-T053)
- Phase 6 Polish: T054, T055, T057, T058, T059 can run in parallel

---

## Parallel Example: User Story 1 (Second AI)

```bash
# After Foundational phase complete, launch US1 implementation:

# Step 1: Create UnifiedCheckService (parallel within service)
Task T010: "Create UnifiedCheckService class"
Task T011: "Implement buildUnifiedPrompt()"
Task T012: "Implement check()"
Task T013: "Implement parseResponse()"
Task T014: "Add error handling"
Task T015: "Return SecondAICheckResult"

# Step 2: Refactor SecondAIService (sequential - depends on Step 1)
Task T016-T020: Refactor existing service

# Step 3: Integration tests (parallel)
Task T021: "Create UnifiedCheckTest.php"
Task T022: "Create FallbackTest.php"
```

---

## Parallel Example: User Story 2 (Evaluation Tiers)

```bash
# After Foundational phase complete, launch US2 implementation:

# Step 1: Create ModelTierSelector (parallel within service)
Task T025: "Create ModelTierSelector class"
Task T026-T030: Implement tier selection logic

# Step 2: Refactor LLMJudgeService (sequential - depends on Step 1)
Task T031-T036: Refactor existing service

# Step 3: Tests (parallel)
Task T037: "Create ModelTierSelectorTest.php"
Task T038: "Create ModelTierTest.php"
```

---

## Parallel Example: User Story 3 (KB Warning)

```bash
# After Foundational phase complete, launch US3 implementation:

# Step 1: Create component (parallel)
Task T041: "Create component directory"
Task T042-T046: Implement KnowledgeBaseWarning component

# Step 2: Integration (sequential - depends on Step 1)
Task T047-T050: Integrate into FlowEditorPage

# Step 3: Manual tests (after integration)
Task T051-T053: Test user flows
```

---

## Implementation Strategy

### MVP First (User Story 1 Only) 🎯

**Minimum Viable Product = Just US1 (Second AI Unified Call)**

1. Complete Phase 1: Setup (T001-T005)
2. Complete Phase 2: Foundational (T006-T009) - CRITICAL
3. Complete Phase 3: User Story 1 (T010-T024)
4. **STOP and VALIDATE**:
   - Run unified check tests
   - Measure actual latency (target ≤1.5s)
   - Measure actual cost reduction (target ≥60%)
   - Test fallback scenario
5. Deploy/demo if metrics meet targets
6. **Optional**: Continue to US2 and US3

**Why this is MVP**:
- US1 delivers the biggest impact (60% cost reduction, 50% latency improvement)
- US1 is independently testable
- US2 and US3 can be added later without breaking US1

### Incremental Delivery

**Week 1**: Foundation + MVP
1. Days 1-2: Complete Setup + Foundational (T001-T009)
2. Days 3-5: Complete User Story 1 (T010-T024)
3. End of week: **Demo MVP** - unified Second AI working

**Week 2**: Additional Value
1. Days 1-3: User Story 2 (T025-T040) - Evaluation cost reduction
2. Days 4-5: User Story 3 (T041-T053) - KB Warning UI
3. End of week: **Demo full feature**

**Week 3**: Polish
1. Days 1-2: Polish & Cross-Cutting (T054-T061)
2. Day 3: Final validation and PR creation
3. Days 4-5: Code review and deployment

### Parallel Team Strategy (If Multiple Developers)

With 3 developers available:

**Week 1**: Everyone together
- All developers: Setup + Foundational (T001-T009)
- **Checkpoint**: Foundation complete

**Week 2**: Parallel user stories
- Developer A: User Story 1 (T010-T024) - Second AI Unified
- Developer B: User Story 2 (T025-T040) - Evaluation Tiers
- Developer C: User Story 3 (T041-T053) - KB Warning UI
- Stories complete and integrate independently

**Week 3**: Polish together
- All developers: Polish & Cross-Cutting (T054-T061)

**Benefits**:
- 3x faster delivery (3 weeks → 2 weeks)
- Each developer owns a complete user story
- Stories don't block each other
- MVP can still be delivered early (just US1)

---

## Performance Targets

### Success Criteria (from spec.md)

| Metric | Baseline | Target | How to Measure |
|--------|----------|--------|----------------|
| Second AI latency | 3-6s | ≤1.5s | Check logs: `elapsed_ms` field |
| Second AI cost | 100% | ≤40% | Count API calls: 6-9 calls → 1 call |
| Evaluation cost (40 cases) | $0.90 | ≤$0.45 | Calculate from tier usage in logs |
| Backward compatibility | - | 100% | All existing tests pass (T056) |
| Response quality | - | ≥95% | No degradation >5% in user feedback |

### Validation Commands

```bash
# After US1 completion (T024)
tail -f backend/storage/logs/laravel.log | grep "SecondAI"
# Look for: "elapsed_ms" ≤1500, "unified mode" used

# After US2 completion (T040)
tail -f backend/storage/logs/laravel.log | grep "Evaluating metric"
# Look for: tier assignments, model usage, fallback events

# After all completion (T056)
php artisan test
# Should pass 100% (zero breaking changes)
```

---

## Notes

- **[P] tasks** = different files, no dependencies within phase
- **[Story] label** maps task to specific user story for traceability
- Each user story should be independently completable and testable
- Commit after each logical task group (e.g., after completing a service)
- Stop at any checkpoint to validate story independently
- Use quickstart.md for detailed implementation guidance and debugging tips
- Follow CLAUDE.md minimal change principle: แก้เฉพาะจุด, ห้าม refactor ที่ไม่เกี่ยวข้อง
- Use conventional commits: `feat(second-ai):`, `feat(evaluation):`, `feat(flow-editor):`
