# Feature Specification: Laravel Cloud Migration with Inertia.js

**Feature Branch**: `007-laravel-cloud-migration`
**Created**: 2026-01-09
**Status**: Draft
**Input**: Migrate BotFacebook project to Laravel Cloud with Inertia.js monolith architecture

## Executive Summary

Migrate the BotFacebook platform from a separate Frontend/Backend architecture (Railway) to a unified monolith (Laravel Cloud) using Inertia.js. This consolidates infrastructure, simplifies deployment, and reduces operational complexity while maintaining all existing functionality including real-time chat, AI-powered bot responses, and multi-channel support (Facebook, LINE, Telegram).

## Current State vs Target State

| Aspect | Current (Railway) | Target (Laravel Cloud) |
|--------|-------------------|------------------------|
| **Frontend** | React 19 SPA (Vite) | React 19 via Inertia.js |
| **Backend** | Laravel 12 API | Laravel 12 Monolith |
| **Database** | Neon PostgreSQL + pgvector | Laravel Cloud Serverless Postgres + pgvector |
| **WebSocket** | Reverb (Railway worker) | Reverb (Laravel Cloud managed) |
| **Auth** | Token-based (localStorage) | Session-based (Laravel native) |
| **Routing** | React Router | Laravel + Inertia |
| **State** | TanStack Query + Zustand | Inertia props + minimal client state |
| **Deployment** | 2 services (Frontend + Backend) | 1 service (Monolith) |

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Admin Manages Bot Settings (Priority: P1)

An admin logs into the platform, views their bots dashboard, selects a bot, and configures its AI settings including model selection, knowledge base, and response parameters.

**Why this priority**: Core functionality - if admins cannot manage bots, the platform has no value.

**Independent Test**: Can be tested by creating a bot, modifying settings, and verifying changes persist across page refreshes.

**Acceptance Scenarios**:

1. **Given** an authenticated admin, **When** they navigate to the bots page, **Then** they see a list of all their bots with status indicators
2. **Given** an admin viewing a bot's settings, **When** they modify the AI model and save, **Then** the changes are persisted and reflected immediately
3. **Given** an admin on the bot settings page, **When** they refresh the browser, **Then** all previously saved settings are retained

---

### User Story 2 - Admin Monitors Live Chat Conversations (Priority: P1)

An admin views real-time chat conversations between customers and bots, can take over conversations manually (Human-in-the-Loop), and receives instant notifications when new messages arrive.

**Why this priority**: Real-time monitoring is essential for quality assurance and customer service.

**Independent Test**: Can be tested by sending a message through a connected channel and verifying it appears in the admin dashboard within 2 seconds.

**Acceptance Scenarios**:

1. **Given** an active conversation, **When** a customer sends a message, **Then** the message appears in the admin chat view within 2 seconds without page refresh
2. **Given** an admin viewing a conversation, **When** they enable Human-in-the-Loop mode, **Then** bot responses are paused and the admin can respond directly
3. **Given** multiple conversations, **When** a new message arrives, **Then** the unread indicator updates in real-time

---

### User Story 3 - Admin Configures Knowledge Base for RAG (Priority: P2)

An admin uploads documents to a bot's knowledge base, the system processes and indexes them using vector embeddings, and the bot can then answer questions using this knowledge.

**Why this priority**: Knowledge base enables intelligent responses but requires bot setup first.

**Independent Test**: Can be tested by uploading a document, waiting for processing, then asking the bot a question that requires that knowledge.

**Acceptance Scenarios**:

1. **Given** an admin on the knowledge base page, **When** they upload a PDF document, **Then** they see processing status updates in real-time
2. **Given** a processed document, **When** the admin tests semantic search, **Then** relevant chunks are returned with similarity scores
3. **Given** a configured knowledge base, **When** a customer asks a related question, **Then** the bot's response includes information from the uploaded documents

---

### User Story 4 - Admin Tests Bot Flows with Streaming (Priority: P2)

An admin creates or edits a conversation flow, tests it with sample inputs, and sees the AI's reasoning process streamed in real-time.

**Why this priority**: Flow testing enables iterative improvement but depends on basic bot functionality.

**Independent Test**: Can be tested by opening the flow editor, running a test conversation, and observing streamed responses.

**Acceptance Scenarios**:

1. **Given** an admin in the flow editor, **When** they send a test message, **Then** the bot's response streams token-by-token
2. **Given** a streaming response, **When** the AI processes the request, **Then** internal reasoning logs are displayed alongside the response
3. **Given** an ongoing test, **When** the admin cancels, **Then** the streaming stops immediately

---

### User Story 5 - Admin Views Analytics Dashboard (Priority: P3)

An admin views aggregated metrics including conversation counts, response times, customer satisfaction scores, and AI cost analytics.

**Why this priority**: Analytics provide business insights but are not essential for core operations.

**Independent Test**: Can be tested by generating some conversation data and verifying dashboard metrics update accordingly.

**Acceptance Scenarios**:

1. **Given** an authenticated admin, **When** they visit the dashboard, **Then** they see key metrics for the current period
2. **Given** historical data, **When** the admin changes the date range, **Then** metrics update to reflect the selected period

---

### User Story 6 - Admin Authenticates Securely (Priority: P1)

An admin can register, log in, and log out securely. Sessions are managed server-side with appropriate timeout and security measures.

**Why this priority**: Authentication gates all other functionality.

**Independent Test**: Can be tested by registering a new account, logging in, and verifying protected pages are accessible.

**Acceptance Scenarios**:

1. **Given** a guest user, **When** they submit valid registration details, **Then** they receive a confirmation and can log in
2. **Given** valid credentials, **When** a user logs in, **Then** they are redirected to the dashboard with a secure session
3. **Given** an authenticated user, **When** they log out, **Then** they cannot access protected pages without logging in again
4. **Given** an inactive session, **When** the timeout expires, **Then** the user is logged out and redirected to login

---

### Edge Cases

- What happens when a user's session expires while viewing real-time chat?
  - System should gracefully redirect to login and resume after re-authentication
- How does the system handle WebSocket disconnection during a live conversation?
  - Automatic reconnection with message queue replay
- What happens when document processing fails mid-upload?
  - Clear error message with retry option, partial uploads are cleaned up
- How does the system behave when Laravel Cloud database is temporarily unavailable?
  - Graceful degradation with user-friendly error messages and automatic retry

## Requirements *(mandatory)*

### Functional Requirements

**Authentication & Authorization**
- **FR-001**: System MUST authenticate users via server-side sessions (replacing token-based auth)
- **FR-002**: System MUST maintain session state across page navigation without re-authentication
- **FR-003**: System MUST automatically reconnect WebSocket connections after session refresh

**Bot Management**
- **FR-004**: System MUST allow admins to view, create, edit, and delete bots
- **FR-005**: System MUST persist bot configurations including AI model, temperature, and system prompts
- **FR-006**: System MUST support multiple channel connections per bot (Facebook, LINE, Telegram)

**Real-time Chat**
- **FR-007**: System MUST display new messages within 2 seconds of receipt via WebSocket
- **FR-008**: System MUST support infinite scroll for conversation history
- **FR-009**: System MUST render messages appropriately based on channel type (FB/LINE/Telegram formatting)
- **FR-010**: System MUST support Human-in-the-Loop takeover of automated conversations

**Knowledge Base & RAG**
- **FR-011**: System MUST process and index uploaded documents using vector embeddings
- **FR-012**: System MUST store vector embeddings using pgvector extension
- **FR-013**: System MUST provide semantic search across indexed documents
- **FR-014**: System MUST display real-time document processing status

**Flow Testing & Streaming**
- **FR-015**: System MUST stream AI responses token-by-token during flow testing
- **FR-016**: System MUST display AI reasoning/process logs during streaming
- **FR-017**: System MUST support cancellation of in-progress streaming requests

**Infrastructure**
- **FR-018**: System MUST deploy as a single monolith application on Laravel Cloud
- **FR-019**: System MUST use Laravel Cloud Serverless Postgres with pgvector extension
- **FR-020**: System MUST use Laravel Cloud managed Reverb for WebSocket connections

### Key Entities

- **User/Admin**: Platform users who manage bots and conversations
- **Bot**: AI-powered chatbot with configurable settings and channel connections
- **Conversation**: Thread of messages between a customer and bot/admin
- **Message**: Individual message within a conversation (supports multiple formats per channel)
- **Document**: Uploaded file in knowledge base with vector embeddings
- **Flow**: Conversation flow configuration for AI behavior
- **Channel Connection**: Integration with Facebook/LINE/Telegram platforms

## Success Criteria *(mandatory)*

### Measurable Outcomes

**Functional Completeness**
- **SC-001**: All 16 existing pages are accessible and functional after migration
- **SC-002**: Real-time messages appear within 2 seconds of sending (matching current performance)
- **SC-003**: SSE streaming responses display progressively without buffering
- **SC-004**: Semantic search returns relevant results with similarity scores

**User Experience**
- **SC-005**: Page navigation feels instant (no full page reloads for internal navigation)
- **SC-006**: Authentication flow completes in under 3 seconds
- **SC-007**: Users can resume work after session timeout without data loss

**Infrastructure**
- **SC-008**: Single deployment command deploys the complete application
- **SC-009**: Database queries perform within 100ms for standard operations
- **SC-010**: WebSocket connections maintain stability for 24+ hours

**Migration Quality**
- **SC-011**: Zero data loss during database migration
- **SC-012**: All existing bot configurations preserved after migration
- **SC-013**: All channel integrations (FB/LINE/Telegram) continue functioning

## Assumptions

- Laravel Cloud Serverless Postgres supports pgvector extension (verified)
- Laravel Cloud supports managed Reverb for WebSocket connections
- Inertia.js can coexist with SSE streaming for specific endpoints
- Existing React components can be adapted to Inertia.js pages with minimal changes
- Echo/Reverb WebSocket integration works identically in Inertia.js context

## Out of Scope

- New features not present in current system
- Mobile native applications
- Additional channel integrations beyond FB/LINE/Telegram
- Multi-tenant architecture changes
- Performance optimization beyond current baseline

## Dependencies

- Laravel Cloud account with appropriate plan (Growth recommended)
- Laravel Cloud Serverless Postgres availability in desired region
- Access to current Neon database for data export
- DNS management access for domain transfer

## Risks & Mitigations

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| pgvector incompatibility | Low | High | Verified compatible; fallback to Neon BYOD |
| TanStack Query refactor complexity | Medium | Medium | Phase migration; keep both systems running during transition |
| Real-time chat disruption | Medium | High | Extensive testing in staging; feature flags for gradual rollout |
| Session management issues | Low | Medium | Leverage Laravel's proven session handling |
| Data migration errors | Low | High | Full backup; validation scripts; staged migration |
