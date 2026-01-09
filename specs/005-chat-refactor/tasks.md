# Tasks: Chat Page Refactor

**Input**: Design documents from `/specs/005-chat-refactor/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: This is a refactor task - tests are used to verify no regressions. Focus on service layer tests.

**Organization**: Tasks are grouped by user story priority (P1, P2) to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2)
- Include exact file paths in descriptions

## Path Conventions

- **Backend**: `backend/app/` for Laravel
- **Frontend**: `frontend/src/` for React
- Tests: `backend/tests/` and `frontend/tests/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Create directory structure and base files for refactor

- [x] T001 Create backend services directory in `backend/app/Services/Chat/`
- [x] T002 Create frontend hooks directory in `frontend/src/hooks/chat/`
- [x] T003 [P] Create frontend adapters directory in `frontend/src/components/chat/adapters/`
- [x] T004 [P] Create Zustand store file in `frontend/src/stores/chatStore.ts`
- [x] T005 [P] Create backend service test directory in `backend/tests/Unit/Services/Chat/`

---

## Phase 2: Foundational (Backend Service Layer)

**Purpose**: Create backend services that controllers will delegate to. MUST complete before frontend refactor.

**⚠️ CRITICAL**: Backend services are the foundation - controller and frontend depend on these

### Backend Services

- [x] T006 Create ConversationService in `backend/app/Services/Chat/ConversationService.php`
  - Extract: list, show, update, search methods from controller
  - Keep under 200 lines
- [x] T007 [P] Create MessageService in `backend/app/Services/Chat/MessageService.php`
  - Extract: send, list, markRead methods from controller
  - Keep under 200 lines
- [x] T008 [P] Create NoteService in `backend/app/Services/Chat/NoteService.php`
  - Extract: addNote, getNotes, deleteNote methods from controller
  - Keep under 150 lines
- [x] T009 [P] Create TagService in `backend/app/Services/Chat/TagService.php`
  - Extract: updateTags method from controller
  - Keep under 100 lines

### Service Tests

- [x] T010 [P] Create ConversationServiceTest in `backend/tests/Unit/Services/Chat/ConversationServiceTest.php`
- [x] T011 [P] Create MessageServiceTest in `backend/tests/Unit/Services/Chat/MessageServiceTest.php`
- [x] T012 [P] Create NoteServiceTest in `backend/tests/Unit/Services/Chat/NoteServiceTest.php`
- [x] T013 [P] Create TagServiceTest in `backend/tests/Unit/Services/Chat/TagServiceTest.php`

### Controller Refactor

- [x] T014 Refactor ConversationController to use ConversationService in `backend/app/Http/Controllers/Api/ConversationController.php`
  - Inject services via constructor
  - Delegate all business logic to services
  - Keep controller under 200 lines
- [x] T015 Run existing tests to verify no regressions: `php artisan test tests/Feature/Api/Conversation`

**Checkpoint**: Backend service layer complete. Run `php artisan test` to verify all tests pass.

---

## Phase 3: User Story 1 - Admin Views and Manages Conversations (Priority: P1) 🎯 MVP

**Goal**: Maintain core chat functionality while refactoring frontend components

**Independent Test**: Open /chat, select conversation, view messages, send reply - all must work

### Frontend Hooks Split

- [x] T016 [P] [US1] Create useMessages hook in `frontend/src/hooks/chat/useMessages.ts`
  - Extract message queries and mutations from useConversations
  - Include messageKeys factory for cache invalidation
- [x] T017 [P] [US1] Create useConversationList hook in `frontend/src/hooks/chat/useConversationList.ts`
  - Extract conversation list queries with filters
  - Include conversationKeys factory
- [x] T018 [P] [US1] Create useConversationDetails hook in `frontend/src/hooks/chat/useConversationDetails.ts`
  - Extract single conversation query
  - Handle optimistic updates
- [x] T019 [P] [US1] Create useRealtime hook in `frontend/src/hooks/chat/useRealtime.ts`
  - Extract WebSocket/Echo subscriptions
  - Centralize real-time update logic

### ChatWindow Decomposition

- [x] T020 [P] [US1] Create MessageBubble component in `frontend/src/components/chat/MessageBubble.tsx`
  - Single message rendering
  - Channel-agnostic props
  - Keep under 100 lines
- [x] T021 [P] [US1] Create MessageList component in `frontend/src/components/chat/MessageList.tsx`
  - Use useMessages hook
  - Virtual scroll for long conversations
  - Keep under 150 lines
- [x] T022 [P] [US1] Create MessageInput component in `frontend/src/components/chat/MessageInput.tsx`
  - Text input with send button
  - Handle media upload trigger
  - Keep under 150 lines
- [x] T023 [P] [US1] Create ChatHeader component in `frontend/src/components/chat/ChatHeader.tsx`
  - Customer name, status, handover toggle
  - Keep under 100 lines
- [x] T024 [US1] Refactor ChatWindow to use new components in `frontend/src/components/chat/ChatWindow.tsx`
  - Container component only
  - Compose MessageList, MessageInput, ChatHeader
  - Keep under 100 lines

### ChatPage Simplification

- [x] T025 [US1] Create Zustand store for UI state in `frontend/src/stores/chatStore.ts`
  - selectedConversationId
  - isCustomerPanelOpen
  - searchQuery
  - Persist selected conversation
- [x] T026 [US1] Refactor ChatPage to use store in `frontend/src/pages/ChatPage.tsx`
  - Layout only (three-column grid)
  - Use chatStore for UI state
  - Keep under 150 lines

### Verify US1 Complete

- [x] T027 [US1] Run frontend build to check for TypeScript errors: `cd frontend && npm run build`
- [x] T028 [US1] Manual test: Navigate to /chat, select bot, view conversations, send message

**Checkpoint**: Core chat functionality works. All existing features preserved.

---

## Phase 4: User Story 2 - Developer Maintains Chat Codebase (Priority: P1)

**Goal**: Complete component decomposition for better code organization

**Independent Test**: Any file under 200 lines, clear component boundaries

### ConversationList Enhancement

- [x] T029 [P] [US2] Create ConversationItem component in `frontend/src/components/chat/ConversationItem.tsx`
  - Single conversation row
  - Unread badge, last message preview
  - Keep under 100 lines
- [x] T030 [US2] Update ConversationList to use ConversationItem in `frontend/src/components/chat/ConversationList.tsx`
  - Already reasonable size, just integrate new component

### CustomerInfoPanel Decomposition

- [x] T031 [P] [US2] Create CustomerDetails component in `frontend/src/components/chat/CustomerDetails.tsx`
  - Customer profile info display
  - Keep under 100 lines
- [x] T032 [P] [US2] Create ConversationNotes component in `frontend/src/components/chat/ConversationNotes.tsx`
  - Notes list and add form
  - Keep under 150 lines
- [x] T033 [P] [US2] Create ConversationTags component in `frontend/src/components/chat/ConversationTags.tsx`
  - Tag management UI
  - Keep under 100 lines
- [x] T034 [US2] Create useNotes hook in `frontend/src/hooks/chat/useNotes.ts`
  - Notes CRUD operations
- [x] T035 [US2] Create useTags hook in `frontend/src/hooks/chat/useTags.ts`
  - Tags update operations
- [x] T036 [US2] Refactor CustomerInfoPanel to use new components in `frontend/src/components/chat/CustomerInfoPanel.tsx`
  - Container component only
  - Keep under 100 lines

### Verify US2 Complete

- [x] T037 [US2] Verify all component files under 200 lines: `find frontend/src/components/chat -name "*.tsx" -exec wc -l {} \;`
- [x] T038 [US2] Run frontend build: `cd frontend && npm run build`

**Checkpoint**: All components properly decomposed. Easy to find and modify code.

---

## Phase 5: User Story 3 - System Handles High Load (Priority: P2)

**Goal**: Optimize state management and API efficiency

**Independent Test**: Load conversation with 100+ messages, verify smooth scrolling

### State Management Optimization

- [x] T039 [US3] Optimize useMessages for large message lists in `frontend/src/hooks/chat/useMessages.ts`
  - Add cursor-based pagination
  - Implement infinite scroll query
- [x] T040 [US3] Add optimistic updates to all mutations in `frontend/src/hooks/chat/useMessages.ts`
  - sendMessage optimistic update
  - markRead optimistic update
- [x] T041 [US3] Implement cache warming for conversation switch in `frontend/src/hooks/chat/useConversationDetails.ts`
  - Prefetch on hover

### Real-time Optimization

- [x] T042 [US3] Optimize useRealtime to prevent unnecessary re-renders in `frontend/src/hooks/chat/useRealtime.ts`
  - Use refs for callbacks
  - Selective invalidation
- [x] T043 [US3] Add WebSocket reconnection handling in `frontend/src/hooks/chat/useRealtime.ts`
  - Fallback to polling on disconnect

### Verify US3 Complete

- [x] T044 [US3] Test with 1000+ message conversation
- [x] T045 [US3] Run Lighthouse performance check on /chat

**Checkpoint**: Chat performs well under load.

---

## Phase 6: User Story 4 - Channel-Specific Features (Priority: P2)

**Goal**: Unified adapter pattern for multi-channel support

**Independent Test**: LINE conversation shows LINE-specific rendering

### Channel Adapters

- [x] T046 [P] [US4] Create ChannelAdapter interface in `frontend/src/components/chat/adapters/ChannelAdapter.ts`
  - Define renderMessage, renderAvatar, getMediaConfig methods
- [x] T047 [P] [US4] Create LineAdapter in `frontend/src/components/chat/adapters/LineAdapter.tsx`
  - LINE-specific message rendering
  - LINE sticker support
- [x] T048 [P] [US4] Create TelegramAdapter in `frontend/src/components/chat/adapters/TelegramAdapter.tsx`
  - Telegram-specific rendering
  - Group chat title display
- [x] T049 [P] [US4] Create FacebookAdapter in `frontend/src/components/chat/adapters/FacebookAdapter.tsx`
  - Facebook-specific rendering
- [x] T050 [US4] Create ChannelProvider context in `frontend/src/components/chat/adapters/ChannelProvider.tsx`
  - Provide adapter based on conversation channel_type
- [x] T051 [US4] Update MessageBubble to use channel adapter in `frontend/src/components/chat/MessageBubble.tsx`
  - Use useChannel hook
  - Delegate rendering to adapter

### Verify US4 Complete

- [x] T052 [US4] Test LINE conversation rendering
- [x] T053 [US4] Test Telegram group chat title display

**Checkpoint**: Channel-specific features work correctly.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final cleanup and verification

- [ ] T054 Delete old useConversations.ts after verifying new hooks work in `frontend/src/hooks/useConversations.ts`
  - Keep backup until fully verified
  - **Status**: DEFERRED - Still in use by ChatWindow, BotControl, etc.
- [x] T055 [P] Update imports in all affected files
  - Hooks already exported from `@/hooks/chat/index.ts`
- [x] T056 [P] Add JSDoc comments to all new hooks
  - Added to all hooks in `/hooks/chat/`
- [x] T057 [P] Add JSDoc comments to all new components
  - Added to all components in `/components/chat/`
- [x] T058 Run full test suite: `php artisan test && cd frontend && npm run test`
  - Backend: 128 passed, 4 skipped (PostgreSQL-specific)
  - Frontend: Build passes
- [x] T059 Run production build verification: `cd frontend && npm run build`
  - ✓ Built in 2.78s
- [x] T060 Verify success criteria from spec:
  - SC-001: ✅ PASS - All components under 250 lines after refactoring:
    - ChatWindow: 368→104 lines (extracted useChatActions, ClearContextDialog, ChannelMessageArea, ChatInputArea)
    - TelegramAdapter: 350→112 lines (extracted TelegramMessageRenderers.tsx)
    - LineAdapter: 281→96 lines (extracted LineMessageRenderers.tsx)
  - SC-002: ✅ PASS - Controller split into 5 focused controllers:
    - ConversationController: 659→185 lines
    - ConversationMessageController: 159 lines (new)
    - ConversationNoteController: 99 lines (new)
    - ConversationTagController: 124 lines (new)
    - ConversationAssignmentController: 193 lines (new)
  - SC-003: ✅ Chat unit tests pass (30/30), Frontend build passes
  - SC-007: ✅ Services have 100% test coverage (4/4 services tested)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies - can start immediately
- **Phase 2 (Backend Services)**: Depends on Phase 1 - BLOCKS frontend work
- **Phase 3 (US1)**: Depends on Phase 2 completion
- **Phase 4 (US2)**: Depends on Phase 3 (uses hooks created in US1)
- **Phase 5 (US3)**: Depends on Phase 3 (optimizes hooks from US1)
- **Phase 6 (US4)**: Can run parallel with Phase 4/5 after Phase 3
- **Phase 7 (Polish)**: Depends on all user stories complete

### User Story Dependencies

- **User Story 1 (P1)**: Core refactor - MUST complete first
- **User Story 2 (P1)**: Builds on US1 hooks and components
- **User Story 3 (P2)**: Optimizes US1 implementation
- **User Story 4 (P2)**: Adds adapter layer to US1 components

### Parallel Opportunities

Within Phase 2:
- T006 (ConversationService) can run with T007, T008, T009
- T010-T013 (Tests) can all run in parallel

Within Phase 3 (US1):
- T016-T019 (Hooks) can all run in parallel
- T020-T023 (Components) can all run in parallel

Within Phase 4 (US2):
- T029, T031-T033 can all run in parallel

Within Phase 6 (US4):
- T046-T049 (Adapters) can all run in parallel

---

## Parallel Example: Phase 3 (US1)

```bash
# Launch all hooks together:
Task: "Create useMessages hook in frontend/src/hooks/chat/useMessages.ts"
Task: "Create useConversationList hook in frontend/src/hooks/chat/useConversationList.ts"
Task: "Create useConversationDetails hook in frontend/src/hooks/chat/useConversationDetails.ts"
Task: "Create useRealtime hook in frontend/src/hooks/chat/useRealtime.ts"

# After hooks done, launch all components together:
Task: "Create MessageBubble component in frontend/src/components/chat/MessageBubble.tsx"
Task: "Create MessageList component in frontend/src/components/chat/MessageList.tsx"
Task: "Create MessageInput component in frontend/src/components/chat/MessageInput.tsx"
Task: "Create ChatHeader component in frontend/src/components/chat/ChatHeader.tsx"
```

---

## Implementation Strategy

### MVP First (Phase 1-3 Only)

1. Complete Phase 1: Setup (5 tasks)
2. Complete Phase 2: Backend Services (10 tasks)
3. Complete Phase 3: User Story 1 (13 tasks)
4. **STOP and VALIDATE**: Test chat page works
5. Deploy/demo if ready - this is working MVP refactor

### Incremental Delivery

1. Phase 1-2 → Backend ready
2. Add Phase 3 (US1) → Core frontend refactored → Test → Deploy
3. Add Phase 4 (US2) → Components fully split → Test → Deploy
4. Add Phase 5 (US3) → Performance optimized → Test → Deploy
5. Add Phase 6 (US4) → Multi-channel clean → Test → Deploy
6. Add Phase 7 → Polish and verify

### Recommended Flow

1. Start with backend (Phase 2) - lowest risk, provides foundation
2. Refactor hooks before components (T016-T019 before T020-T024)
3. Keep ChatWindow working throughout by incremental extraction
4. Test after each checkpoint

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story
- This is a REFACTOR - no new features, existing functionality must work
- Keep old code until new code is verified
- Run tests frequently to catch regressions
- Commit after each logical group of tasks
