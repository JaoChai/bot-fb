# Data Model: Chat Page Refactor

**Created**: 2026-01-09 | **Feature**: [spec.md](./spec.md)

## Overview

This document captures the key entities and their relationships for the Chat Page refactor. These are **existing models** - no schema changes are planned for this refactor.

---

## Entity Relationship Diagram

```
┌─────────────────┐       ┌──────────────────┐       ┌─────────────────┐
│      Bot        │1     N│   Conversation   │N     1│ CustomerProfile │
│─────────────────│───────│──────────────────│───────│─────────────────│
│ id              │       │ id               │       │ id              │
│ name            │       │ bot_id           │       │ external_id     │
│ channel_type    │       │ customer_profile │       │ channel_type    │
│ settings        │       │ channel_type     │       │ display_name    │
│ is_active       │       │ status           │       │ picture_url     │
└─────────────────┘       │ is_handover      │       │ phone           │
                          │ tags[]           │       │ email           │
                          │ memory_notes[]   │       │ tags[]          │
        ┌─────────────────│ assigned_user_id │       │ notes           │
        │                 │ unread_count     │       └─────────────────┘
        │                 │ last_message_at  │
        │                 └──────────────────┘
        │                         │1
        ▼                         │
┌─────────────────┐               │N
│      User       │       ┌───────────────────┐
│─────────────────│       │     Message       │
│ id              │       │───────────────────│
│ name            │       │ id                │
│ email           │       │ conversation_id   │
│ role            │       │ sender            │
└─────────────────┘       │ content           │
                          │ type              │
                          │ media_url         │
                          │ media_type        │
                          │ embedding         │
                          │ created_at        │
                          └───────────────────┘
```

---

## Entities

### 1. Conversation

**Purpose**: Represents a chat session between a customer and bot/agent.

**Location**: `backend/app/Models/Conversation.php`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint (PK) | Primary key |
| bot_id | bigint (FK) | Reference to Bot |
| customer_profile_id | bigint (FK) | Reference to CustomerProfile |
| external_customer_id | string | Platform-specific customer ID |
| channel_type | string | 'line', 'telegram', 'facebook' |
| telegram_chat_type | string? | Group chat type for Telegram |
| telegram_chat_title | string? | Group name for Telegram |
| status | string | 'active', 'archived', etc. |
| is_handover | boolean | True when agent is handling |
| bot_auto_enable_at | datetime? | When to auto-switch back to bot |
| assigned_user_id | bigint? (FK) | Assigned admin user |
| memory_notes | json | AI context notes |
| tags | json | Array of tag strings |
| context | json | Conversation context |
| current_flow_id | bigint? (FK) | Active flow if any |
| message_count | integer | Total messages |
| unread_count | integer | Unread message count |
| last_message_at | datetime | Last message timestamp |
| context_cleared_at | datetime? | When context was last cleared |

**Relationships**:
- `belongsTo` Bot
- `belongsTo` CustomerProfile
- `belongsTo` User (assignedUser)
- `hasMany` Message
- `hasOne` Message (lastMessage)

**Key Scopes**:
- `active()` - Status is 'active'
- `handover()` - Is in handover mode
- `forBot($botId)` - Filter by bot
- `ofChannel($channelType)` - Filter by channel
- `recentFirst()` - Order by last_message_at desc
- `unread()` - Has unread messages

---

### 2. Message

**Purpose**: Individual message in a conversation.

**Location**: `backend/app/Models/Message.php`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint (PK) | Primary key |
| conversation_id | bigint (FK) | Reference to Conversation |
| sender | string | 'user', 'bot', 'agent' |
| content | text | Message content |
| type | string | 'text', 'image', 'sticker', etc. |
| media_url | string? | URL for media content |
| media_type | string? | MIME type |
| media_metadata | json | Media dimensions, etc. |
| model_used | string? | AI model for bot messages |
| prompt_tokens | integer? | Token usage |
| completion_tokens | integer? | Token usage |
| cost | decimal? | AI cost |
| external_message_id | string? | Platform message ID |
| reply_to_message_id | bigint? | Reply reference |
| embedding | vector? | pgvector embedding |
| sentiment | string? | Sentiment analysis |
| intents | json? | Detected intents |

**Relationships**:
- `belongsTo` Conversation

**Key Scopes**:
- `fromBot()` - Sender is 'bot'
- `fromUser()` - Sender is 'user'
- `fromAgent()` - Sender is 'agent'
- `recentFirst()` - Order by created_at desc

---

### 3. CustomerProfile

**Purpose**: Customer information linked to conversations.

**Location**: `backend/app/Models/CustomerProfile.php`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint (PK) | Primary key |
| external_id | string | Platform-specific user ID |
| channel_type | string | 'line', 'telegram', 'facebook' |
| display_name | string | Customer display name |
| picture_url | string? | Profile picture URL |
| phone | string? | Phone number |
| email | string? | Email address |
| interaction_count | integer | Total interactions |
| first_interaction_at | datetime | First interaction |
| last_interaction_at | datetime | Last interaction |
| metadata | json | Platform-specific data |
| tags | json | Customer tags |
| notes | text? | Admin notes |

**Relationships**:
- `hasMany` Conversation

---

### 4. Bot

**Purpose**: Bot configuration and settings.

**Location**: `backend/app/Models/Bot.php`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint (PK) | Primary key |
| name | string | Bot name |
| channel_type | string | Primary channel |
| is_active | boolean | Bot enabled status |
| settings | json | Bot configuration |

**Relationships**:
- `hasMany` Conversation
- `hasMany` AdminBotAssignment

---

### 5. User (Admin)

**Purpose**: Admin users who can manage conversations.

**Location**: `backend/app/Models/User.php`

| Field | Type | Description |
|-------|------|-------------|
| id | bigint (PK) | Primary key |
| name | string | User name |
| email | string | Email (unique) |
| role | string | 'admin', 'owner', etc. |

**Relationships**:
- `hasMany` Conversation (as assignedUser)
- `hasMany` AdminBotAssignment

---

## State Transitions

### Conversation Status

```
          ┌───────────┐
          │  active   │◄──── New conversation
          └─────┬─────┘
                │
                ▼
          ┌───────────┐
          │ archived  │◄──── Manual archive or inactivity
          └───────────┘
```

### Handover Mode

```
                              is_handover = true
       ┌───────────┐  ─────────────────────────>  ┌───────────┐
       │  Bot Mode │                              │Agent Mode │
       │           │  <─────────────────────────  │           │
       └───────────┘      is_handover = false     └───────────┘
                          (manual or auto-timer)
```

---

## Query Patterns for Frontend

### Conversation List Query

```sql
SELECT c.*, cp.display_name, cp.picture_url, m.content as last_message
FROM conversations c
JOIN customer_profiles cp ON c.customer_profile_id = cp.id
LEFT JOIN messages m ON m.id = (
  SELECT id FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1
)
WHERE c.bot_id = :bot_id
  AND c.status = 'active'
ORDER BY c.last_message_at DESC
LIMIT 50
```

### Messages for Conversation

```sql
SELECT m.*
FROM messages m
WHERE m.conversation_id = :conversation_id
ORDER BY m.created_at ASC
LIMIT 100 OFFSET :offset
```

---

## API Resource Mapping

| Entity | List Endpoint | Detail Fields |
|--------|---------------|---------------|
| Conversation | id, customer name, last message, unread count, is_handover | + tags, notes, assigned user |
| Message | id, sender, content, type, created_at | + media_url, metadata |
| CustomerProfile | id, display_name, picture_url | + all fields |

---

## Notes for Refactor

1. **No schema changes** - All models remain unchanged
2. **Eager loading** - Services must use `with()` to prevent N+1
3. **Cache keys** - Based on conversation_id for message lists
4. **Real-time updates** - Use conversation.{id} channel for broadcasts
