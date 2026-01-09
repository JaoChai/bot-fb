# Implementation Plan: Chat Page Refactor

**Branch**: `005-chat-refactor` | **Date**: 2026-01-09 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/005-chat-refactor/spec.md`

## Summary

Refactor the Chat Page (`/chat`) to improve code maintainability, performance, and developer experience. The refactor will:
- Decompose large frontend components (ChatPage, ChatWindow, CustomerInfoPanel) into smaller focused units (max 200-250 lines)
- Introduce a backend service layer extracting business logic from ConversationController
- Split monolithic hooks into domain-specific hooks (useMessages, useConversationList, useRealtime)
- Apply unified adapter pattern for channel-specific rendering (LINE, Telegram, Facebook)

## Technical Context

**Language/Version**: PHP 8.2 (Backend), TypeScript 5.x (Frontend)
**Primary Dependencies**: Laravel 12, React 19, TanStack Query v5, Zustand, Tailwind CSS
**Storage**: PostgreSQL (Neon) with pgvector
**Testing**: PHPUnit (Backend), Vitest (Frontend)
**Target Platform**: Web (Railway deployment)
**Project Type**: Web application (frontend + backend)
**Performance Goals**: API response < 500ms, Page load < 2s, AI evaluation < 1.5s
**Constraints**: WebSocket via Reverb, Multi-channel support (LINE, Telegram, Facebook)
**Scale/Scope**: Existing codebase refactor - ~4,000 lines frontend, ~1,000 lines backend

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| Single Responsibility | ✅ Pass | Refactor enforces SRP on all components |
| Testability | ✅ Pass | Service layer enables unit testing |
| Minimal Change | ⚠️ Watch | Large refactor but bounded scope |
| No New Features | ✅ Pass | Explicitly excluded in spec |

## Project Structure

### Documentation (this feature)

```text
specs/005-chat-refactor/
├── spec.md              # Feature specification (created)
├── plan.md              # This file (creating now)
├── research.md          # Phase 0 output (creating)
├── data-model.md        # Phase 1 output (creating)
├── quickstart.md        # Phase 1 output (creating)
├── contracts/           # Phase 1 output (creating)
│   └── chat-api.yaml    # OpenAPI spec for chat endpoints
├── checklists/
│   └── requirements.md  # Quality checklist (created)
└── tasks.md             # Phase 2 output (/speckit.tasks command)
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Http/
│   │   └── Controllers/Api/
│   │       └── ConversationController.php    # REFACTOR: Delegate to services
│   ├── Services/
│   │   └── Chat/                             # NEW: Service layer
│   │       ├── ConversationService.php       # Conversation CRUD
│   │       ├── MessageService.php            # Message handling
│   │       ├── NoteService.php               # Admin notes
│   │       └── TagService.php                # Conversation tags
│   └── Models/                               # Existing: Conversation, Message, etc.
└── tests/
    ├── Unit/Services/Chat/                   # NEW: Service unit tests
    └── Feature/Api/                          # Existing: API tests

frontend/
├── src/
│   ├── pages/
│   │   └── ChatPage.tsx                      # REFACTOR: Extract layout only
│   ├── components/
│   │   └── chat/
│   │       ├── ChatWindow.tsx                # REFACTOR: Container only
│   │       ├── MessageList.tsx               # NEW: Message display
│   │       ├── MessageBubble.tsx             # NEW: Single message
│   │       ├── MessageInput.tsx              # NEW: Input + send
│   │       ├── ChatHeader.tsx                # NEW: Header with actions
│   │       ├── ConversationList.tsx          # KEEP: Already reasonable
│   │       ├── ConversationItem.tsx          # NEW: Single conversation row
│   │       ├── CustomerInfoPanel.tsx         # REFACTOR: Container only
│   │       ├── CustomerDetails.tsx           # NEW: Customer info section
│   │       ├── ConversationNotes.tsx         # NEW: Notes section
│   │       ├── ConversationTags.tsx          # NEW: Tags section
│   │       └── adapters/                     # NEW: Channel adapters
│   │           ├── ChannelAdapter.ts         # Adapter interface
│   │           ├── LineAdapter.tsx           # LINE-specific rendering
│   │           ├── TelegramAdapter.tsx       # Telegram-specific rendering
│   │           └── FacebookAdapter.tsx       # Facebook-specific rendering
│   ├── hooks/
│   │   └── chat/                             # REFACTOR: Split by domain
│   │       ├── useMessages.ts                # Message queries/mutations
│   │       ├── useConversationList.ts        # List queries
│   │       ├── useConversationDetails.ts     # Single conversation
│   │       ├── useNotes.ts                   # Notes CRUD
│   │       ├── useTags.ts                    # Tags CRUD
│   │       └── useRealtime.ts                # WebSocket subscriptions
│   └── stores/
│       └── chatStore.ts                      # NEW: Zustand for UI state
└── tests/
    └── components/chat/                      # NEW: Component tests
```

**Structure Decision**: Web application structure with clear separation between backend services and frontend components. Refactor maintains existing directory layout while introducing new service layer in backend and splitting components in frontend.

## Complexity Tracking

> No constitution violations requiring justification - this is a bounded refactor.

| Area | Current Complexity | Target Complexity |
|------|-------------------|-------------------|
| ChatPage.tsx | ~560 lines | ~150 lines (layout only) |
| ChatWindow.tsx | ~786 lines | ~100 lines (container) |
| ConversationController.php | ~1,079 lines | ~200 lines (delegate to services) |
| useConversations.ts | ~885 lines | Split into 6 files (~150 lines each) |

## Next Steps

1. Phase 0: Create `research.md` with best practices research
2. Phase 1: Create `data-model.md`, `contracts/`, `quickstart.md`
3. Phase 2: Run `/speckit.tasks` for task breakdown
4. Implementation: Use agent sets (frontend-dev, backend-dev) per CLAUDE.md
