# Feature Specification: Bots Page Comprehensive Refactoring

**Feature Branch**: `006-bots-refactor`
**Created**: 2026-01-09
**Status**: Draft
**Input**: User description: "Refactor Bots Page - Comprehensive refactoring of botjao.com/bots including: Phase 1 (Critical Security & Bug Fixes), Phase 2 (Complete Missing Features), Phase 3 (Frontend Refactor), Phase 4 (Backend Refactor), Phase 5 (Testing & Documentation)"

## Executive Summary

This specification covers a comprehensive 5-phase refactoring of the Bots management system at botjao.com/bots. The refactoring addresses critical security vulnerabilities, completes missing features, improves code maintainability through component splitting, restructures backend data models, and establishes comprehensive testing and documentation.

**Current State Issues Identified:**
- 5 Critical issues (security vulnerabilities, race conditions, N+1 queries)
- 8 High-priority issues (missing validations, incomplete features)
- Multiple Medium/Low issues (code organization, naming inconsistencies)

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Secure Bot Management (Priority: P1)

As a bot owner, I want my channel credentials (API tokens, secrets) to be protected so that unauthorized users cannot access my bot integrations.

**Why this priority**: Security vulnerabilities can lead to account compromise, data breaches, and service disruption. This is the highest risk item.

**Independent Test**: Can be fully tested by attempting to access bot credentials through API responses and verifying they are properly masked or hidden based on user role.

**Acceptance Scenarios**:

1. **Given** a bot with LINE channel credentials, **When** an admin (non-owner) views the bot details via API, **Then** the channel_access_token and channel_secret are not exposed in the response
2. **Given** a bot owner viewing their own bot, **When** they request bot details, **Then** credentials are masked with option to reveal through explicit action
3. **Given** multiple concurrent requests to set default flow, **When** processed simultaneously, **Then** exactly one flow remains as default (no race condition)

---

### User Story 2 - Performant Admin Management (Priority: P1)

As a bot owner with multiple admins, I want the admin list to load quickly even when I have many admins assigned to my bot.

**Why this priority**: N+1 query issues cause exponential performance degradation as data grows, affecting all users.

**Independent Test**: Can be fully tested by creating a bot with 20+ admins and verifying the page loads within acceptable time with minimal database queries.

**Acceptance Scenarios**:

1. **Given** a bot with 20 admins, **When** loading the admin list, **Then** the system uses at most 3 database queries (not 1+N)
2. **Given** the admin list endpoint, **When** called, **Then** active conversation counts are loaded efficiently via eager loading

---

### User Story 3 - Facebook Integration (Priority: P2)

As a user, I want to connect my Facebook Page to the bot system so that I can manage Facebook Messenger conversations alongside LINE and Telegram.

**Why this priority**: Facebook integration UI exists but has no backend implementation, creating a broken user experience.

**Independent Test**: Can be tested by creating a new connection with Facebook platform and verifying webhook events are processed.

**Acceptance Scenarios**:

1. **Given** a user with a Facebook Page, **When** they create a new Facebook connection, **Then** they can enter Page ID and Access Token
2. **Given** a configured Facebook bot, **When** a message arrives via webhook, **Then** the system processes it like LINE/Telegram messages
3. **Given** a Facebook conversation, **When** the bot generates a response, **Then** the message is sent back to the user via Facebook Messenger API

---

### User Story 4 - Maintainable Settings Interface (Priority: P2)

As a developer maintaining the codebase, I want the BotSettingsPage to be split into focused sub-components so that I can understand, test, and modify individual features independently.

**Why this priority**: 934-line component with 11 features makes maintenance error-prone and slows development velocity.

**Independent Test**: Can be tested by modifying one settings section (e.g., Rate Limiting) and verifying no unintended changes to other sections.

**Acceptance Scenarios**:

1. **Given** the refactored BotSettingsPage, **When** a developer needs to modify Rate Limiting logic, **Then** they only need to edit the RateLimitSection component
2. **Given** the refactored components, **When** unit tests are written, **Then** each section can be tested in isolation
3. **Given** 11 feature sections, **When** displayed on the page, **Then** all functionality works exactly as before (no regression)

---

### User Story 5 - Structured Bot Configuration (Priority: P3)

As a system administrator, I want bot settings to be organized into logical tables so that database queries and schema changes are more manageable.

**Why this priority**: 50+ column tables are difficult to maintain, migrate, and optimize. Structural improvement benefits long-term maintainability.

**Independent Test**: Can be tested by verifying all existing settings are accessible after migration and no data is lost.

**Acceptance Scenarios**:

1. **Given** existing bot settings, **When** the database is migrated to new structure, **Then** all 50+ settings values are preserved
2. **Given** the new table structure, **When** querying rate limit settings, **Then** only rate limit related data is loaded
3. **Given** a settings update, **When** only HITL settings change, **Then** only the HITL table is updated

---

### User Story 6 - Documented API (Priority: P3)

As a developer integrating with the Bots API, I want comprehensive API documentation so that I can understand available endpoints without reading source code.

**Why this priority**: Documentation enables external integrations and reduces onboarding time for new developers.

**Independent Test**: Can be tested by having a new developer implement an API client using only the documentation.

**Acceptance Scenarios**:

1. **Given** the API documentation, **When** a developer views bot endpoints, **Then** they see request/response schemas, authentication requirements, and examples
2. **Given** all 13 bot-related endpoints, **When** documentation is generated, **Then** each endpoint is fully documented
3. **Given** the documentation, **When** compared to actual API behavior, **Then** responses match documented schemas

---

### Edge Cases

- What happens when migrating bot settings with null values in optional fields?
- How does the system handle concurrent setDefault() calls for different flows?
- What happens when Facebook webhook receives malformed payload?
- How does the system handle existing credentials during credential encryption migration?
- What happens when a component import fails after splitting BotSettingsPage?

---

## Requirements *(mandatory)*

### Phase 1: Critical Security & Bug Fixes

- **FR-001**: System MUST hide channel credentials (channel_access_token, channel_secret) from API responses for non-owner users
- **FR-002**: System MUST mask credentials for owner users with explicit reveal action required
- **FR-003**: System MUST use database transactions for setDefault() flow operations to prevent race conditions
- **FR-004**: System MUST use eager loading for admin conversation counts to eliminate N+1 queries
- **FR-005**: System MUST validate LLM model names against allowed models list
- **FR-006**: System MUST add foreign key constraint on default_flow_id with nullOnDelete behavior
- **FR-007**: System MUST validate KB pivot data (kb_top_k: 1-20, kb_similarity_threshold: 0.1-1.0) on backend

### Phase 2: Complete Missing Features

- **FR-008**: System MUST implement Facebook webhook handler to process Page webhook events
- **FR-009**: System MUST implement Facebook service for Graph API interactions (send message, get profile)
- **FR-010**: System MUST implement Facebook message processing job for async handling
- **FR-011**: System MUST implement or remove webhook_forwarder_enabled feature (currently unused field)
- **FR-012**: System MUST complete Plugins backend (or remove UI if not implementing)
- **FR-013**: System MUST complete External Data Sources backend (or remove UI if not implementing)
- **FR-014**: System MUST implement LINE auto webhook setup (like Telegram)

### Phase 3: Frontend Refactor

- **FR-015**: System MUST split BotSettingsPage into sub-components: RateLimitSection, HITLSection, ResponseHoursSection, SmartAggregationSection, MultipleBubblesSection, ReplyStickerSection, LeadRecoverySection, EasySlipSection, ReplyWhenCalledSection, AutoAssignmentSection, AnalyticsSection
- **FR-016**: System MUST split FlowEditorPage into sub-components: FlowBasicInfo, AgenticModeSection, KnowledgeBaseSection, SystemPromptEditor, SafetySettingsSection, SecondAISection
- **FR-017**: System MUST move useBots() hook from useKnowledgeBase.ts to dedicated useBots.ts
- **FR-018**: System MUST consolidate BotEditPage with EditConnectionPage (remove deprecated page)
- **FR-019**: System MUST standardize refetch strategies across all connection hooks (use invalidateQueries consistently)
- **FR-020**: System MUST establish consistent naming convention (decide: "bots" or "connections")

### Phase 4: Backend Refactor

- **FR-021**: System MUST split bot_settings table into: bot_limits, bot_hitl_settings, bot_aggregation_settings, bot_response_hours
- **FR-022**: System MUST add audit trail for flow changes (created_by, updated_by, change history)
- **FR-023**: System MUST encrypt channel credentials at rest
- **FR-024**: System MUST consolidate bot-related migrations into clean schema
- **FR-025**: System MUST add boolean casting for all boolean fields (hitl_enabled, second_ai_enabled)

### Phase 5: Testing & Documentation

- **FR-026**: System MUST provide OpenAPI/Swagger documentation for all bot endpoints
- **FR-027**: System MUST have integration tests for LINE, Telegram, and Facebook webhooks
- **FR-028**: System MUST have unit tests for all new sub-components
- **FR-029**: System MUST have E2E tests for critical flows (create bot, configure settings, test connection)

---

### Key Entities

- **Bot**: Represents a channel connection (LINE, Telegram, Facebook, Testing). Contains channel credentials, LLM configuration, KB settings. One owner, multiple admins.

- **BotSettings**: Configuration for bot behavior. Currently 50+ fields covering rate limits, HITL, response hours, message aggregation, auto-assignment.

- **Flow**: AI conversation flow configuration. Contains system prompt, model selection, agentic mode settings, KB associations, safety controls.

- **AdminBotAssignment**: Many-to-many relationship between users (admins) and bots they can manage.

- **Proposed New Entities**:
  - **BotLimits**: Rate limiting and usage cap settings
  - **BotHITLSettings**: Human-in-the-loop configuration
  - **BotAggregationSettings**: Smart message aggregation settings
  - **BotResponseHours**: Business hours and timezone settings
  - **FlowAuditLog**: Change history for flow modifications

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All credential-related API responses pass security audit (0 exposure of raw tokens to non-owners)
- **SC-002**: Admin list with 50 admins loads in under 500ms with maximum 5 database queries
- **SC-003**: setDefault() operation has 0 race condition failures under concurrent load test (100 simultaneous requests)
- **SC-004**: Facebook integration processes messages with same reliability as LINE/Telegram (99.9% success rate)
- **SC-005**: BotSettingsPage split results in 0 functionality regressions (all 11 features work identically)
- **SC-006**: Each settings sub-component is independently testable (100% unit test coverage possible)
- **SC-007**: Database migration preserves 100% of existing settings data (0 data loss)
- **SC-008**: New table structure reduces settings query payload by 70% (load only needed data)
- **SC-009**: All 13 bot endpoints have complete OpenAPI documentation with examples
- **SC-010**: Webhook integration tests cover 90% of message types (text, image, sticker, location, etc.)
- **SC-011**: E2E test suite covers all critical user journeys (create, configure, test, activate)
- **SC-012**: Developer onboarding time reduced by 50% with improved documentation and code organization

---

## Assumptions

1. **Authentication**: Existing JWT-based authentication will be maintained
2. **Authorization**: BotPolicy authorization structure is correct; only credential exposure needs fixing
3. **Facebook API**: Facebook Graph API v18+ will be used for integration
4. **Migration Strategy**: Zero-downtime migration using Laravel's migration system
5. **Backwards Compatibility**: API response structure changes will be communicated to frontend
6. **Performance Baseline**: Current admin list with 10 admins takes ~500ms (unacceptable with N+1)
7. **Credential Storage**: Built-in encryption will be used for credential encryption at rest
8. **Test Coverage**: Target 80% coverage for new code, 60% for refactored code

---

## Dependencies

1. Facebook Developer Account with Page access for integration testing
2. OpenAPI/Swagger generator package selection
3. E2E testing framework decision (existing or new)
4. Database backup before migration execution
5. Coordination with frontend team for API response changes

---

## Out of Scope

1. New channel integrations beyond Facebook (Instagram, WhatsApp, etc.)
2. Bot analytics dashboard redesign
3. User role/permission system changes (beyond credential visibility)
4. LLM model management interface
5. Knowledge Base management (separate feature area)
6. Real-time collaboration features for flow editing
