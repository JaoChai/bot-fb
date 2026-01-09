# Tasks: Laravel Cloud Migration with Inertia.js

**Input**: Design documents from `/specs/007-laravel-cloud-migration/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1-US6)
- Include exact file paths in descriptions

## Path Conventions

- **Backend**: `backend/` directory
- **Resources**: `backend/resources/js/` for React + Inertia
- **Controllers**: `backend/app/Http/Controllers/`
- **Routes**: `backend/routes/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Install Inertia.js, configure Vite, and set up project structure for migration

- [ ] T001 Install Inertia.js server-side package via `composer require inertiajs/inertia-laravel` in backend/
- [ ] T002 Run `php artisan inertia:middleware` to publish HandleInertiaRequests middleware
- [ ] T003 Register HandleInertiaRequests middleware in backend/bootstrap/app.php
- [ ] T004 [P] Install @inertiajs/react adapter via npm in backend/
- [ ] T005 [P] Install @vitejs/plugin-react via npm in backend/
- [ ] T006 Configure Vite for React + Inertia in backend/vite.config.ts
- [ ] T007 Create root Blade template in backend/resources/views/app.blade.php
- [ ] T008 Create Inertia entry point in backend/resources/js/app.tsx
- [ ] T009 [P] Copy TailwindCSS config from frontend/ to backend/
- [ ] T010 [P] Configure tsconfig.json with path aliases in backend/
- [ ] T011 Create TypeScript types file in backend/resources/js/types/index.d.ts (SharedProps, PageProps)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**CRITICAL**: No user story work can begin until this phase is complete

- [ ] T012 Create GuestLayout component in backend/resources/js/Layouts/GuestLayout.tsx
- [ ] T013 Create AuthenticatedLayout component in backend/resources/js/Layouts/AuthenticatedLayout.tsx
- [ ] T014 Configure HandleInertiaRequests shared data (auth, flash, bots) in backend/app/Http/Middleware/HandleInertiaRequests.php
- [ ] T015 [P] Copy UI components (Radix) from frontend/src/components/ui/ to backend/resources/js/Components/ui/
- [ ] T016 [P] Copy utility functions from frontend/src/lib/utils.ts to backend/resources/js/Lib/utils.ts
- [ ] T017 [P] Configure Echo client in backend/resources/js/Lib/echo.ts
- [ ] T018 Update import paths in copied components (@ alias to /resources/js)

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 6 - Admin Authenticates Securely (Priority: P1) 🎯 MVP

**Goal**: Admin can register, log in, and log out securely with session-based authentication

**Independent Test**: Register a new account, log in, verify dashboard accessible, log out, verify redirect to login

### Implementation for User Story 6

- [ ] T019 [P] [US6] Create Login page in backend/resources/js/Pages/Auth/Login.tsx
- [ ] T020 [P] [US6] Create Register page in backend/resources/js/Pages/Auth/Register.tsx
- [ ] T021 [US6] Create LoginController with Inertia responses in backend/app/Http/Controllers/Auth/LoginController.php
- [ ] T022 [US6] Create RegisterController with Inertia responses in backend/app/Http/Controllers/Auth/RegisterController.php
- [ ] T023 [US6] Configure auth routes (login, register, logout) in backend/routes/web.php
- [ ] T024 [US6] Add session timeout handling and redirect logic
- [ ] T025 [US6] Test login/logout flow with session persistence

**Checkpoint**: Authentication complete - users can register, login, logout securely

---

## Phase 4: User Story 1 - Admin Manages Bot Settings (Priority: P1)

**Goal**: Admin can view, create, edit bots and configure AI settings

**Independent Test**: Create a bot, modify AI model/temperature settings, save, refresh page, verify settings persist

### Implementation for User Story 1

- [X] T026 [P] [US1] Create Bots/Index page in backend/resources/js/Pages/Bots/Index.tsx
- [X] T027 [P] [US1] Create Bots/Settings page in backend/resources/js/Pages/Bots/Settings.tsx
- [X] T028 [P] [US1] Create Bots/Edit page in backend/resources/js/Pages/Bots/Edit.tsx
- [X] T029 [US1] Copy bot-settings components from frontend/src/components/bot-settings/ to backend/resources/js/Components/bot-settings/
- [X] T030 [US1] Update bot-settings component imports for Inertia context
- [X] T031 [US1] Create BotController with index/show Inertia methods in backend/app/Http/Controllers/Bot/BotController.php
- [X] T032 [US1] Create BotSettingsController for settings page in backend/app/Http/Controllers/Bot/BotSettingsController.php
- [X] T033 [US1] Define bot routes (index, show, edit, settings) in backend/routes/web.php
- [X] T034 [US1] Implement useForm for bot settings updates in Settings page
- [X] T035 [US1] Test bot CRUD operations and settings persistence

**Checkpoint**: Bot management complete - admins can create/edit bots and configure AI settings

---

## Phase 5: User Story 2 - Admin Monitors Live Chat Conversations (Priority: P1)

**Goal**: Admin can view real-time conversations, take over HITL mode, receive instant message notifications

**Independent Test**: Send message via channel, verify appears in chat view within 2 seconds, toggle HITL mode

### Implementation for User Story 2

- [X] T036 [P] [US2] Create Chat/Index page in backend/resources/js/Pages/Chat/Index.tsx
- [X] T037 [US2] Copy chat components from frontend/src/components/chat/ to backend/resources/js/Components/chat/
- [X] T038 [US2] Update chat components for Inertia props (remove TanStack Query)
- [X] T039 [US2] Create useEcho hook for real-time updates in backend/resources/js/Hooks/useEcho.ts
- [X] T040 [US2] Implement infinite scroll using router.get() with preserveState in Chat/Index
- [X] T041 [US2] Create ChatController with Inertia response in backend/app/Http/Controllers/Chat/ChatController.php
- [X] T042 [US2] Define chat routes in backend/routes/web.php
- [X] T043 [US2] Implement HITL toggle API endpoint in backend/routes/web.php (ChatController::toggleHitl)
- [X] T044 [US2] Create channel-specific message renderers (FB/LINE/Telegram) in backend/resources/js/Components/chat/adapters/
- [X] T045 [US2] Connect Echo listener to trigger router.reload({ only: ['conversations', 'messages'] })
- [ ] T046 [US2] Test real-time message updates (< 2s latency)

**Checkpoint**: Live chat complete - real-time messaging with HITL support working

---

## Phase 6: User Story 3 - Admin Configures Knowledge Base (Priority: P2)

**Goal**: Admin can upload documents, view processing status, test semantic search

**Independent Test**: Upload a PDF, wait for processing, run semantic search query, verify relevant results

### Implementation for User Story 3

- [X] T047 [P] [US3] Create KnowledgeBase/Index page in backend/resources/js/Pages/KnowledgeBase/Index.tsx
- [X] T048 [US3] Create document upload component in backend/resources/js/Components/knowledge-base/DocumentUpload.tsx
- [X] T049 [US3] Create document status component in backend/resources/js/Components/knowledge-base/DocumentStatus.tsx
- [X] T050 [US3] Create KnowledgeBaseController in backend/app/Http/Controllers/KnowledgeBase/KnowledgeBaseController.php
- [X] T051 [US3] Define knowledge base routes in backend/routes/web.php
- [X] T052 [US3] Connect Echo listener for real-time document processing status updates
- [X] T053 [US3] Create semantic search test component in backend/resources/js/Components/knowledge-base/SearchTest.tsx
- [ ] T054 [US3] Verify pgvector queries work with Laravel Cloud Postgres
- [ ] T055 [US3] Test document upload → processing → search flow

**Checkpoint**: Knowledge base complete - document upload, processing, and semantic search working

---

## Phase 7: User Story 4 - Admin Tests Bot Flows with Streaming (Priority: P2)

**Goal**: Admin can test flows with SSE streaming responses, see reasoning logs, cancel in-progress tests

**Independent Test**: Open flow editor, send test message, observe token-by-token streaming, cancel mid-stream

### Implementation for User Story 4

- [X] T056 [P] [US4] Create Flows/Editor page in backend/resources/js/Pages/Flows/Editor.tsx
- [X] T057 [US4] Copy flow-editor components from frontend/src/components/flow-editor/ to backend/resources/js/Components/flow-editor/
- [X] T058 [US4] Update flow-editor components imports for Inertia context
- [X] T059 [US4] Create useStreamingChat hook for SSE in backend/resources/js/Hooks/useStreamingChat.ts
- [X] T060 [US4] Create FlowController for flow pages in backend/app/Http/Controllers/Flow/FlowController.php
- [X] T061 [US4] Define flow routes (index, show, edit) in backend/routes/web.php
- [X] T062 [US4] Keep SSE streaming endpoint in backend/routes/api.php (POST /api/flows/{flow}/test)
- [X] T063 [US4] Implement streaming cancellation with AbortController
- [ ] T064 [US4] Test SSE streaming end-to-end (token-by-token display)

**Checkpoint**: Flow testing complete - SSE streaming with cancellation working

---

## Phase 8: User Story 5 - Admin Views Analytics Dashboard (Priority: P3)

**Goal**: Admin can view aggregated metrics, conversation stats, AI cost analytics

**Independent Test**: Generate some conversation data, visit dashboard, verify metrics display correctly

### Implementation for User Story 5

- [X] T065 [P] [US5] Create Dashboard page in backend/resources/js/Pages/Dashboard.tsx
- [X] T066 [US5] Create stats card components in backend/resources/js/Components/dashboard/StatsCard.tsx
- [X] T067 [US5] Create cost analytics component in backend/resources/js/Components/dashboard/CostAnalytics.tsx
- [X] T068 [US5] Create DashboardController in backend/app/Http/Controllers/DashboardController.php
- [X] T069 [US5] Define dashboard route in backend/routes/web.php
- [X] T070 [US5] Add date range filter using URL params
- [ ] T071 [US5] Test dashboard metrics accuracy

**Checkpoint**: Dashboard complete - analytics and metrics displaying correctly

---

## Phase 9: User Settings & Final Pages

**Goal**: Complete remaining pages (Settings)

- [X] T072 [P] Create Settings/Index page in backend/resources/js/Pages/Settings/Index.tsx
- [X] T073 Create SettingsController in backend/app/Http/Controllers/Settings/SettingsController.php
- [X] T074 Define settings routes in backend/routes/web.php
- [ ] T075 Test settings page functionality

---

## Phase 10: Database & Laravel Cloud Deployment

**Purpose**: Migrate database and deploy to Laravel Cloud

- [ ] T076 Create Laravel Cloud project via dashboard
- [ ] T077 Create Serverless Postgres database with pgvector extension
- [ ] T078 Export data from current Neon database (pg_dump)
- [ ] T079 Import data to Laravel Cloud Postgres (pg_restore)
- [ ] T080 Verify pgvector extension and RAG queries work
- [ ] T081 Configure environment variables in Laravel Cloud
- [ ] T082 Configure managed Reverb for WebSockets
- [ ] T083 Deploy application to Laravel Cloud
- [ ] T084 Test all functionality in staging environment

---

## Phase 11: Cutover & Validation

**Purpose**: Complete migration and validate production

- [ ] T085 Update DNS to point to Laravel Cloud
- [ ] T086 Validate all 16 pages accessible and functional
- [ ] T087 Validate real-time chat (< 2s latency)
- [ ] T088 Validate SSE streaming (progressive display)
- [ ] T089 Validate channel integrations (FB/LINE/Telegram webhooks)
- [ ] T090 Monitor production for 24 hours
- [ ] T091 Deprecate Railway services

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] T092 [P] Remove deprecated frontend/ directory
- [ ] T093 [P] Update CLAUDE.md with new architecture
- [ ] T094 Code cleanup - remove unused TanStack Query imports
- [ ] T095 Performance audit - verify API responses < 500ms
- [ ] T096 Security audit - verify session handling
- [ ] T097 Run quickstart.md validation

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **US6 Auth (Phase 3)**: Depends on Foundational - BLOCKS other user stories
- **US1 Bot Settings (Phase 4)**: Depends on US6 Auth
- **US2 Live Chat (Phase 5)**: Depends on US6 Auth (can parallel with US1)
- **US3 Knowledge Base (Phase 6)**: Depends on US1 (needs bot context)
- **US4 Flow Testing (Phase 7)**: Depends on US1 (needs bot context)
- **US5 Dashboard (Phase 8)**: Depends on US6 Auth (can parallel with others)
- **Database & Deploy (Phase 10)**: Depends on all story phases complete
- **Cutover (Phase 11)**: Depends on Phase 10
- **Polish (Phase 12)**: Depends on Phase 11

### User Story Dependencies

```
US6 (Auth) ─────────────┬─── US5 (Dashboard)
                        │
                        ├─── US1 (Bot Settings) ─── US3 (Knowledge Base)
                        │                       └─── US4 (Flow Testing)
                        │
                        └─── US2 (Live Chat)
```

### Within Each User Story

- Pages before controllers
- Controllers before routes
- Echo hooks before real-time features
- Core implementation before integration

### Parallel Opportunities

**Phase 1 (Setup)**:
- T004, T005 can run in parallel
- T009, T010 can run in parallel

**Phase 2 (Foundational)**:
- T015, T016, T017 can run in parallel

**Phase 3-8 (User Stories)**:
- Page components within same story marked [P] can run in parallel
- US1 and US2 can run in parallel after US6 complete
- US3 and US4 can run in parallel after US1 complete
- US5 can run in parallel with US1-US4

---

## Parallel Example: Bot Settings (US1)

```bash
# Launch all pages for US1 together:
Task: "Create Bots/Index page in backend/resources/js/Pages/Bots/Index.tsx"
Task: "Create Bots/Settings page in backend/resources/js/Pages/Bots/Settings.tsx"
Task: "Create Bots/Edit page in backend/resources/js/Pages/Bots/Edit.tsx"

# Then sequential:
Task: "Copy bot-settings components..."
Task: "Create BotController..."
Task: "Define routes..."
```

---

## Implementation Strategy

### MVP First (US6 Auth + US1 Bot Settings)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: US6 Authentication
4. Complete Phase 4: US1 Bot Management
5. **STOP and VALIDATE**: Test bot creation and settings
6. Deploy to staging for early feedback

### Incremental Delivery

1. Setup + Foundational → Infrastructure ready
2. Add US6 Auth → Test auth flow → First checkpoint
3. Add US1 Bot Settings → Test bot CRUD → MVP 1
4. Add US2 Live Chat → Test real-time → MVP 2
5. Add US3 + US4 (parallel) → Test RAG + Streaming → Feature complete
6. Add US5 Dashboard → Full feature set
7. Phase 10-11 → Production deployment

### Risk Mitigation

- Keep Railway services running during migration
- Use feature flags if needed
- Test each story independently before proceeding
- Full database backup before migration

---

## Summary

| Metric | Count |
|--------|-------|
| **Total Tasks** | 97 |
| **Setup Tasks** | 11 |
| **Foundational Tasks** | 7 |
| **US6 Auth Tasks** | 7 |
| **US1 Bot Settings Tasks** | 10 |
| **US2 Live Chat Tasks** | 11 |
| **US3 Knowledge Base Tasks** | 9 |
| **US4 Flow Testing Tasks** | 9 |
| **US5 Dashboard Tasks** | 7 |
| **Settings Tasks** | 4 |
| **Deployment Tasks** | 9 |
| **Cutover Tasks** | 7 |
| **Polish Tasks** | 6 |
| **Parallel Opportunities** | 25+ tasks |

**MVP Scope**: Phase 1-4 (Setup + Foundation + Auth + Bot Settings) = 35 tasks
