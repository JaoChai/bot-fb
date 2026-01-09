# Data Model: Laravel Cloud Migration

**Feature Branch**: `007-laravel-cloud-migration`
**Date**: 2026-01-09
**Status**: Complete

## Overview

The data model remains **unchanged** during migration. All existing Eloquent models continue to work with Laravel Cloud Serverless Postgres. This document maps the existing models to their Inertia.js page props.

## Core Entities

### User

**Description**: Platform administrators who manage bots and conversations

**Attributes**:
- `id`: Primary key
- `name`: Display name
- `email`: Unique email address
- `password`: Hashed password (session auth)
- `created_at`, `updated_at`: Timestamps

**Relationships**:
- `bots`: Has many Bot (through AdminBotAssignment)
- `settings`: Has one UserSetting

**Inertia Shared Data**:
```php
'auth.user' => fn () => $request->user()?->only('id', 'name', 'email')
```

---

### Bot

**Description**: AI-powered chatbot with configurable settings

**Attributes**:
- `id`: Primary key
- `name`: Bot display name
- `platform`: Channel type (facebook, line, telegram)
- `page_id`, `page_access_token`: Channel credentials (encrypted)
- `status`: Active/inactive/paused
- `created_at`, `updated_at`: Timestamps

**Relationships**:
- `admins`: Belongs to many User (through AdminBotAssignment)
- `settings`: Has one BotSetting
- `limits`: Has one BotLimits
- `hitlSettings`: Has one BotHITLSettings
- `responseHours`: Has one BotResponseHours
- `aggregationSettings`: Has one BotAggregationSettings
- `flows`: Has many Flow
- `conversations`: Has many Conversation
- `knowledgeBase`: Has one KnowledgeBase

**Inertia Page Props** (BotsController):
```php
return Inertia::render('Bots/Index', [
    'bots' => Bot::with(['settings', 'limits'])->paginate(10),
]);
```

---

### BotSetting

**Description**: AI configuration for a bot

**Attributes**:
- `bot_id`: Foreign key
- `model`: AI model identifier (gpt-4o, claude-sonnet, etc.)
- `temperature`: Float 0-2
- `max_tokens`: Integer
- `system_prompt`: Text
- `greeting_message`: Text
- `fallback_message`: Text

**Inertia Page Props** (BotSettingsController):
```php
return Inertia::render('Bots/Settings', [
    'bot' => $bot->load(['settings', 'limits', 'hitlSettings', 'responseHours']),
]);
```

---

### Conversation

**Description**: Thread of messages between customer and bot/admin

**Attributes**:
- `id`: Primary key
- `bot_id`: Foreign key
- `customer_profile_id`: Foreign key
- `status`: open, closed, paused
- `is_hitl_active`: Boolean (Human-in-the-Loop mode)
- `last_message_at`: Timestamp
- `unread_count`: Integer

**Relationships**:
- `bot`: Belongs to Bot
- `customer`: Belongs to CustomerProfile
- `messages`: Has many Message

**Inertia Page Props** (ChatController):
```php
return Inertia::render('Chat/Index', [
    'conversations' => Conversation::with(['customer', 'lastMessage'])
        ->where('bot_id', $botId)
        ->orderByDesc('last_message_at')
        ->cursorPaginate(20),
    'activeConversation' => $activeConversation?->load('messages'),
]);
```

---

### Message

**Description**: Individual message in a conversation

**Attributes**:
- `id`: Primary key
- `conversation_id`: Foreign key
- `sender_type`: customer, bot, admin
- `content`: Text/JSON (varies by platform)
- `metadata`: JSON (platform-specific data)
- `created_at`: Timestamp

**Relationships**:
- `conversation`: Belongs to Conversation

**Real-time Updates**:
```php
// Broadcasting event
broadcast(new MessageReceived($message))->toOthers();
```

---

### Flow

**Description**: Conversation flow configuration

**Attributes**:
- `id`: Primary key
- `bot_id`: Foreign key
- `name`: Flow name
- `nodes`: JSON (flow editor nodes)
- `edges`: JSON (flow editor edges)
- `is_active`: Boolean
- `version`: Integer

**Relationships**:
- `bot`: Belongs to Bot
- `auditLogs`: Has many FlowAuditLog

**Inertia Page Props** (FlowController):
```php
return Inertia::render('Flows/Editor', [
    'flow' => $flow,
    'bot' => $flow->bot->only('id', 'name'),
]);
```

---

### KnowledgeBase

**Description**: Container for RAG documents

**Attributes**:
- `id`: Primary key
- `bot_id`: Foreign key
- `name`: KB name
- `description`: Text
- `embedding_model`: Model used for embeddings

**Relationships**:
- `bot`: Belongs to Bot
- `documents`: Has many Document

---

### Document

**Description**: Uploaded file in knowledge base

**Attributes**:
- `id`: Primary key
- `knowledge_base_id`: Foreign key
- `filename`: Original filename
- `file_path`: S3 path
- `status`: pending, processing, completed, failed
- `chunk_count`: Integer
- `metadata`: JSON

**Relationships**:
- `knowledgeBase`: Belongs to KnowledgeBase
- `chunks`: Has many DocumentChunk

**Real-time Updates**:
```php
// Broadcasting document processing status
broadcast(new DocumentStatusUpdated($document))->toOthers();
```

---

### DocumentChunk

**Description**: Vector-embedded text chunk for RAG

**Attributes**:
- `id`: Primary key
- `document_id`: Foreign key
- `content`: Text chunk
- `embedding`: Vector (1536 dimensions, pgvector)
- `metadata`: JSON (page number, position)

**Vector Search**:
```php
// Using pgvector for semantic search
DocumentChunk::query()
    ->orderByRaw('embedding <=> ?', [$queryEmbedding])
    ->limit(5)
    ->get();
```

---

### CustomerProfile

**Description**: Customer information from messaging platforms

**Attributes**:
- `id`: Primary key
- `bot_id`: Foreign key
- `platform_id`: Platform-specific user ID
- `name`: Customer name
- `avatar_url`: Profile picture
- `metadata`: JSON (platform-specific data)

**Relationships**:
- `bot`: Belongs to Bot
- `conversations`: Has many Conversation

---

## State Transitions

### Conversation Status

```
open → (customer inactive 24h) → closed
open → (admin closes) → closed
open → (HITL activated) → open (is_hitl_active=true)
closed → (customer message) → open
```

### Document Status

```
pending → (job starts) → processing → (success) → completed
pending → (job starts) → processing → (error) → failed
failed → (retry) → processing
```

---

## Inertia Props Summary

### Shared Data (All Pages)

```php
// HandleInertiaRequests.php
return [
    'auth' => [
        'user' => fn () => $request->user()?->only('id', 'name', 'email'),
    ],
    'flash' => [
        'success' => fn () => $request->session()->get('success'),
        'error' => fn () => $request->session()->get('error'),
    ],
    'bots' => fn () => $request->user()?->bots()->select('id', 'name')->get(),
];
```

### Page-Specific Props

| Page | Props |
|------|-------|
| `Dashboard` | `stats`, `recentConversations`, `costAnalytics` |
| `Bots/Index` | `bots` (paginated) |
| `Bots/Settings` | `bot` (with relations) |
| `Chat/Index` | `conversations` (cursor paginated), `activeConversation`, `messages` |
| `Flows/Editor` | `flow`, `bot` |
| `KnowledgeBase/Index` | `knowledgeBase`, `documents` |
| `Evaluations/Index` | `evaluations` (paginated) |
| `Settings/Index` | `settings`, `team` |

---

## Migration Notes

1. **No schema changes required** - Models work as-is with Laravel Cloud Postgres
2. **pgvector extension** - Confirmed compatible with Laravel Cloud
3. **Encrypted fields** - Laravel encryption works unchanged
4. **Relationships** - Eager loading strategy remains the same
5. **Pagination** - Switch to cursor pagination where appropriate for real-time data
