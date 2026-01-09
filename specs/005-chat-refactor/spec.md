# Feature Specification: Chat Page Refactor

**Feature Branch**: `005-chat-refactor`
**Created**: 2026-01-09
**Status**: Draft
**Input**: User description: "Refactor Chat Page - ปรับปรุงโครงสร้าง Chat Page ทั้ง Frontend และ Backend โดยแยก Component ที่ใหญ่เกินไป, สร้าง Service Layer, ปรับปรุง State Management และ API Optimization"

## Overview

Refactor the Chat Page (`/chat`) to improve code maintainability, performance, and developer experience. The current implementation has large monolithic components and lacks proper separation of concerns. This refactor will decompose large components, introduce a service layer in the backend, optimize state management, and improve API efficiency.

### Current State Analysis

| Area | Current State | Issue |
|------|--------------|-------|
| Frontend Components | 4 main files, ~2,000 lines | Components too large, logic mixed with UI |
| Backend Controller | 1 file, ~1,079 lines | No service layer, controller does everything |
| Hooks | 1 file, ~885 lines | All conversation logic in single file |
| Real-time | Logic embedded in components | Hard to test and maintain |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Admin Views and Manages Conversations (Priority: P1)

As an admin, I want to view and manage customer conversations efficiently so that I can provide timely support.

**Why this priority**: Core functionality - if this breaks, the entire chat system is unusable.

**Independent Test**: Can be tested by opening the chat page, selecting a conversation, viewing messages, and sending a reply. Delivers immediate value by maintaining existing functionality.

**Acceptance Scenarios**:

1. **Given** I am logged in as an admin, **When** I navigate to /chat, **Then** I see my bot list and can select a bot to view conversations
2. **Given** I have selected a bot, **When** conversations load, **Then** I see the conversation list sorted by most recent message
3. **Given** a conversation is selected, **When** I view the chat window, **Then** I see all messages in chronological order
4. **Given** I am in handover mode, **When** I type and send a message, **Then** the message is delivered to the customer and appears in the chat
5. **Given** I receive a new message, **When** viewing the conversation, **Then** the new message appears instantly via real-time updates

---

### User Story 2 - Developer Maintains Chat Codebase (Priority: P1)

As a developer, I want well-organized, modular code so that I can easily add features, fix bugs, and understand the system.

**Why this priority**: Developer productivity directly impacts feature velocity and bug fix turnaround.

**Independent Test**: Can be tested by reviewing code structure, measuring component sizes, and verifying separation of concerns. Delivers value through improved code quality metrics.

**Acceptance Scenarios**:

1. **Given** I need to modify message rendering, **When** I look for the component, **Then** I find a dedicated MessageBubble component under 200 lines
2. **Given** I need to add a new chat feature, **When** I review the code structure, **Then** I understand where to add it within 5 minutes
3. **Given** I need to fix a backend bug, **When** I trace the code, **Then** business logic is in services, not controllers
4. **Given** I want to test conversation logic, **When** I write tests, **Then** I can test services independently from HTTP layer

---

### User Story 3 - System Handles High Load (Priority: P2)

As a system, I need to handle multiple concurrent users efficiently so that response times remain acceptable under load.

**Why this priority**: Performance is important but not blocking - current system works, just not optimally.

**Independent Test**: Can be tested by measuring API response times and UI rendering performance before and after refactor.

**Acceptance Scenarios**:

1. **Given** multiple admins are viewing conversations, **When** they interact simultaneously, **Then** the system maintains responsive UI
2. **Given** a conversation has many messages, **When** loading the chat window, **Then** messages load progressively without blocking the UI
3. **Given** real-time updates are occurring, **When** the WebSocket pushes updates, **Then** the UI updates without full re-renders

---

### User Story 4 - Channel-Specific Features Work Correctly (Priority: P2)

As an admin managing multiple channels (LINE, Telegram, Facebook), I want channel-specific features to work correctly without duplicated code.

**Why this priority**: Multi-channel support is a key feature but refactoring for code quality.

**Independent Test**: Can be tested by verifying each channel's unique features (media upload, stickers) work correctly after refactor.

**Acceptance Scenarios**:

1. **Given** I am viewing a LINE conversation, **When** I send an image, **Then** it uses LINE's media format
2. **Given** I am viewing a Telegram group chat, **When** the group title displays, **Then** it shows correctly with group icon
3. **Given** channel-specific code changes, **When** I modify one channel, **Then** other channels are unaffected

---

### Edge Cases

- What happens when WebSocket disconnects during conversation? (Fallback to polling)
- How does the system handle when message send fails? (Show error, retain draft)
- What happens with very long conversations (1000+ messages)? (Pagination/virtualization)
- How does the system behave when switching bots rapidly? (Cancel pending requests)

## Requirements *(mandatory)*

### Functional Requirements

**Frontend Component Decomposition**

- **FR-001**: ChatPage component MUST be split into smaller, focused components (max 200 lines each)
- **FR-002**: ChatWindow MUST be decomposed into MessageList, MessageInput, and ChatHeader components
- **FR-003**: Channel-specific rendering MUST use a unified adapter pattern to avoid code duplication
- **FR-004**: All existing functionality MUST continue working after refactor (no regressions)

**State Management**

- **FR-005**: Local UI state (selected conversation, panel visibility) MUST be managed via dedicated store
- **FR-006**: Real-time update logic MUST be extracted into reusable hooks
- **FR-007**: Cache management MUST use consistent patterns across all conversation queries

**Backend Service Layer**

- **FR-008**: ConversationController MUST delegate business logic to dedicated services
- **FR-009**: System MUST have separate services for: Conversations, Messages, Notes, Tags
- **FR-010**: Each service MUST be independently testable

**API Optimization**

- **FR-011**: API responses MUST only include necessary data for each use case
- **FR-012**: Frequently-used queries MUST leverage caching appropriately
- **FR-013**: Optimistic updates MUST be consistent across all mutations

**Code Quality**

- **FR-014**: All new/refactored code MUST have appropriate test coverage
- **FR-015**: Components MUST follow single responsibility principle
- **FR-016**: Hooks MUST be split by domain (useMessages, useConversationList, useRealtime)

### Key Entities

- **Conversation**: Chat session between customer and bot/agent, contains messages and metadata
- **Message**: Individual message with sender, content, type, and timestamps
- **CustomerProfile**: Customer information linked to conversation
- **Note**: Admin notes attached to conversations for context
- **Tag**: Labels for categorizing conversations

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: No component file exceeds 250 lines of code
- **SC-002**: Backend controller files are under 200 lines each (business logic moved to services)
- **SC-003**: All existing automated tests pass after refactor
- **SC-004**: Chat page loads and displays conversations within current performance baseline
- **SC-005**: Developers can locate and understand any chat-related code within 5 minutes
- **SC-006**: New features in chat can be added without modifying more than 3 files
- **SC-007**: Each service has at least 80% test coverage
- **SC-008**: Zero regression bugs reported within first week after deployment

## Assumptions

- Current functionality is fully documented through existing code and tests
- Performance baseline measurements exist or will be taken before refactor
- The team is familiar with React patterns (hooks, composition) and Laravel services
- Refactor can be done incrementally without requiring a complete rewrite
- Real-time WebSocket infrastructure remains unchanged

## Out of Scope

- Adding new features (this is purely refactor for maintainability)
- Changing the visual design or UX of the chat page
- Database schema changes
- Migration to different frontend/backend frameworks
- Performance optimization beyond what code restructuring naturally provides
