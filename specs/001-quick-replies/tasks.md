# Tasks: Quick Replies (Canned Responses)

**Input**: Design documents from `/specs/001-quick-replies/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Included as requested in constitution check (Tests required: PASS)

**Organization**: Tasks grouped by user story for independent implementation

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story (US1, US2, US3)
- Paths based on web app structure: `backend/`, `frontend/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Database schema and shared types

- [X] T001 Create migration for quick_replies table in `backend/database/migrations/xxxx_create_quick_replies_table.php`
- [X] T002 [P] Create QuickReply model in `backend/app/Models/QuickReply.php`
- [X] T003 [P] Create QuickReply TypeScript types in `frontend/src/types/quick-reply.ts`
- [X] T004 Run migration to create table

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: API infrastructure and authorization that all stories depend on

**CRITICAL**: No user story work can begin until this phase is complete

- [X] T005 Create QuickReplyPolicy for Owner-only authorization in `backend/app/Policies/QuickReplyPolicy.php`
- [X] T006 [P] Create QuickReplyRequest for validation in `backend/app/Http/Requests/QuickReplyRequest.php`
- [X] T007 [P] Create QuickReplyResource for API responses in `backend/app/Http/Resources/QuickReplyResource.php`
- [X] T008 Create QuickReplyController with CRUD endpoints in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [X] T009 Register routes in `backend/routes/api.php`
- [X] T010 Create useQuickReplies hook with React Query in `frontend/src/hooks/useQuickReplies.ts`

**Checkpoint**: API ready - user story implementation can begin

---

## Phase 3: User Story 1 - Agent Uses Quick Reply in Chat (Priority: P1) MVP

**Goal**: Agent สามารถเลือก Quick Reply จาก list หรือพิมพ์ `/shortcut` เพื่อส่งข้อความไปยังลูกค้า

**Independent Test**: เปิดหน้าแชท คลิกปุ่ม Quick Reply เลือกรายการ ข้อความถูกส่งไปยังลูกค้า

### Tests for User Story 1

- [ ] T011 [P] [US1] Feature test for list active quick replies in `backend/tests/Feature/QuickReplyTest.php`
- [ ] T012 [P] [US1] Feature test for search quick replies by shortcut in `backend/tests/Feature/QuickReplyTest.php`

### Implementation for User Story 1

- [X] T013 [P] [US1] Add `index` action with is_active filter in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [X] T014 [P] [US1] Add `search` action for shortcut autocomplete in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [X] T015 [US1] Create QuickReplyButton component in `frontend/src/components/chat/QuickReplyButton.tsx`
- [X] T016 [US1] Create QuickReplyList popover component in `frontend/src/components/chat/QuickReplyList.tsx`
- [X] T017 [US1] Create QuickReplyAutocomplete for `/shortcut` in `frontend/src/components/chat/QuickReplyAutocomplete.tsx`
- [X] T018 [US1] Integrate QuickReplyButton into ChatWindow input area in `frontend/src/components/chat/ChatWindow.tsx`
- [X] T019 [US1] Integrate QuickReplyAutocomplete into message input in `frontend/src/components/chat/ChatWindow.tsx`
- [X] T020 [US1] Connect selection to sendAgentMessage hook in `frontend/src/components/chat/ChatWindow.tsx`

**Checkpoint**: Agent can use Quick Replies in chat - MVP complete

---

## Phase 4: User Story 2 - Owner Manages Quick Replies (Priority: P2)

**Goal**: Owner สามารถ CRUD Quick Replies ในหน้า Settings

**Independent Test**: เข้า Settings > Quick Replies สร้าง Quick Reply ใหม่ เห็นในรายการ

### Tests for User Story 2

- [ ] T021 [P] [US2] Feature test for create quick reply (Owner only) in `backend/tests/Feature/QuickReplyTest.php`
- [ ] T022 [P] [US2] Feature test for update quick reply in `backend/tests/Feature/QuickReplyTest.php`
- [ ] T023 [P] [US2] Feature test for delete quick reply in `backend/tests/Feature/QuickReplyTest.php`
- [ ] T024 [P] [US2] Feature test for non-owner cannot create in `backend/tests/Feature/QuickReplyTest.php`

### Implementation for User Story 2

- [ ] T025 [P] [US2] Add `store` action with validation in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [ ] T026 [P] [US2] Add `update` action in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [ ] T027 [P] [US2] Add `destroy` action in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [ ] T028 [P] [US2] Add `toggle` action for is_active in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [ ] T029 [P] [US2] Add `reorder` action for sort_order in `backend/app/Http/Controllers/Api/QuickReplyController.php`
- [ ] T030 [US2] Create QuickRepliesPage with list and CRUD UI in `frontend/src/pages/settings/QuickRepliesPage.tsx`
- [ ] T031 [US2] Add create/edit dialog component in `frontend/src/pages/settings/QuickRepliesPage.tsx`
- [ ] T032 [US2] Add delete confirmation in `frontend/src/pages/settings/QuickRepliesPage.tsx`
- [ ] T033 [US2] Add drag-and-drop reorder in `frontend/src/pages/settings/QuickRepliesPage.tsx`
- [ ] T034 [US2] Add Quick Replies menu to settings navigation in `frontend/src/components/layout/SettingsSidebar.tsx`
- [ ] T035 [US2] Add route for Quick Replies page in `frontend/src/App.tsx`

**Checkpoint**: Owner can fully manage Quick Replies

---

## Phase 5: User Story 3 - Agent Searches Quick Replies (Priority: P3)

**Goal**: Agent สามารถค้นหา Quick Reply ด้วยคำสำคัญ

**Independent Test**: เปิด Quick Reply list พิมพ์คำค้นหา เห็นเฉพาะรายการที่ตรง

### Implementation for User Story 3

- [ ] T036 [US3] Add search/filter input to QuickReplyList in `frontend/src/components/chat/QuickReplyList.tsx`
- [ ] T037 [US3] Implement client-side filtering by title and content in `frontend/src/components/chat/QuickReplyList.tsx`
- [ ] T038 [US3] Add "no results" empty state in `frontend/src/components/chat/QuickReplyList.tsx`

**Checkpoint**: All user stories complete and independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] T039 [P] Add content length validation warning (5000 bytes) in `frontend/src/pages/settings/QuickRepliesPage.tsx`
- [ ] T040 [P] Add shortcut format validation (a-z, 0-9, -, _) in `backend/app/Http/Requests/QuickReplyRequest.php`
- [ ] T041 Add loading states and error handling in all components
- [ ] T042 Run quickstart.md validation checklist

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: Depends on Setup - BLOCKS all user stories
- **User Stories (Phase 3-5)**: All depend on Foundational completion
- **Polish (Phase 6)**: Depends on all user stories complete

### User Story Dependencies

- **US1 (P1)**: Can start after Foundational - No dependencies on other stories
- **US2 (P2)**: Can start after Foundational - Independent from US1
- **US3 (P3)**: Depends on US1 (extends QuickReplyList component)

### Within Each User Story

- Tests written FIRST, verify they FAIL
- Backend before frontend
- Core implementation before integration

### Parallel Opportunities

- T002, T003 can run in parallel (different layers)
- T005, T006, T007 can run in parallel (different files)
- T011, T012 can run in parallel (different test cases)
- T021-T024 can run in parallel (different test cases)
- T025-T029 can run in parallel (different controller methods)
- US1 and US2 can be developed in parallel by different developers

---

## Parallel Example: Phase 2 (Foundational)

```bash
# Launch in parallel:
Task: "Create QuickReplyPolicy in backend/app/Policies/QuickReplyPolicy.php"
Task: "Create QuickReplyRequest in backend/app/Http/Requests/QuickReplyRequest.php"
Task: "Create QuickReplyResource in backend/app/Http/Resources/QuickReplyResource.php"
```

## Parallel Example: User Story 2 Backend

```bash
# Launch in parallel:
Task: "Add store action in backend/app/Http/Controllers/Api/QuickReplyController.php"
Task: "Add update action in backend/app/Http/Controllers/Api/QuickReplyController.php"
Task: "Add destroy action in backend/app/Http/Controllers/Api/QuickReplyController.php"
Task: "Add toggle action in backend/app/Http/Controllers/Api/QuickReplyController.php"
Task: "Add reorder action in backend/app/Http/Controllers/Api/QuickReplyController.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (T001-T004)
2. Complete Phase 2: Foundational (T005-T010)
3. Complete Phase 3: User Story 1 (T011-T020)
4. **STOP and VALIDATE**: Test Quick Reply usage in chat
5. Deploy if ready - Agent can use Quick Replies!

### Incremental Delivery

1. Setup + Foundational = Foundation ready
2. Add US1 = MVP (Agent uses Quick Replies)
3. Add US2 = Owner can manage
4. Add US3 = Search enhancement
5. Each story adds value independently

---

## Summary

| Metric | Count |
|--------|-------|
| Total Tasks | 42 |
| Setup Phase | 4 |
| Foundational Phase | 6 |
| User Story 1 (MVP) | 10 |
| User Story 2 | 15 |
| User Story 3 | 3 |
| Polish Phase | 4 |
| Parallelizable | 22 |

---

## Notes

- [P] = safe to run in parallel
- [USx] = belongs to User Story x
- Backend tests use PHPUnit
- Frontend uses Vitest
- Commit after each task or logical group
- Stop at any checkpoint to validate
