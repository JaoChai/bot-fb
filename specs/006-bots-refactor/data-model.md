# Data Model: Bots Page Refactoring

**Date**: 2026-01-09
**Feature**: 006-bots-refactor

## Entity Overview

This document describes the data model changes required for the Bots Page refactoring, including new entities, modified entities, and their relationships.

---

## Existing Entities (Modified)

### Bot

**Purpose**: Represents a channel connection (LINE, Telegram, Facebook, Testing)

**Modifications**:
- Add encryption for credential fields
- No schema changes, only encryption logic

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | Existing |
| user_id | bigint | FK(users), not null | Owner |
| name | string(255) | not null | Display name |
| channel_type | enum | 'line','telegram','facebook','testing' | Platform |
| channel_access_token | text | nullable, **encrypted** | API token |
| channel_secret | text | nullable, **encrypted** | API secret |
| page_id | string(255) | nullable | Facebook Page ID |
| webhook_url | string(255) | nullable | Webhook endpoint |
| primary_chat_model | string(100) | nullable | LLM model |
| fallback_chat_model | string(100) | nullable | Backup model |
| decision_model | string(100) | nullable | Decision model |
| fallback_decision_model | string(100) | nullable | Backup decision |
| default_flow_id | bigint | FK(flows), nullable, **onDelete:nullOnDelete** | Default flow |
| status | enum | 'active','inactive','paused' | Bot state |
| ... | ... | ... | Other existing fields |

**Encryption Implementation**:
```php
protected $casts = [
    'channel_access_token' => 'encrypted',
    'channel_secret' => 'encrypted',
];
```

---

### BotSetting

**Purpose**: Core bot configuration settings

**Modifications**:
- Move specialized settings to sub-tables
- Keep common/simple settings here

| Field | Type | Constraints | After Split |
|-------|------|-------------|-------------|
| id | bigint | PK | Keep |
| bot_id | bigint | FK, unique | Keep |
| welcome_message | text | nullable | Keep |
| fallback_message | text | nullable | Keep |
| typing_indicator | boolean | default:true | Keep |
| typing_delay_ms | int | default:1000 | Keep |
| language | string(10) | default:'th' | Keep |
| response_style | string(50) | nullable | Keep |
| auto_archive_days | int | nullable | Keep |
| save_conversations | boolean | default:true | Keep |
| ~~daily_message_limit~~ | ~~int~~ | | → BotLimits |
| ~~per_user_limit~~ | ~~int~~ | | → BotLimits |
| ~~rate_limit_per_minute~~ | ~~int~~ | | → BotLimits |
| ~~hitl_enabled~~ | ~~boolean~~ | | → BotHITLSettings |
| ~~hitl_triggers~~ | ~~json~~ | | → BotHITLSettings |
| ~~smart_aggregation_enabled~~ | ~~boolean~~ | | → BotAggregationSettings |
| ~~smart_min_wait_ms~~ | ~~int~~ | | → BotAggregationSettings |
| ... | ... | | ... |

---

### Flow

**Purpose**: AI conversation flow configuration

**Modifications**:
- Add relationship to FlowAuditLog
- Add boolean casting for safety fields

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK | Existing |
| bot_id | bigint | FK(bots) | Parent |
| name | string(255) | not null | Flow name |
| is_default | boolean | default:false | Default flow marker |
| hitl_enabled | boolean | default:false | **Cast to boolean** |
| second_ai_enabled | boolean | default:false | **Cast to boolean** |
| ... | ... | ... | Other existing fields |

**Relationship Addition**:
```php
public function auditLogs(): HasMany {
    return $this->hasMany(FlowAuditLog::class);
}
```

---

## New Entities

### BotLimits

**Purpose**: Rate limiting and usage cap settings

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| bot_setting_id | bigint | FK(bot_settings), unique | One-to-one |
| daily_message_limit | int | nullable | Max messages/day |
| per_user_limit | int | nullable | Max per user/day |
| rate_limit_per_minute | int | default:60 | Requests/minute |
| max_tokens_per_response | int | nullable | Token limit |
| rate_limit_bot_message | text | nullable | Message when bot limit hit |
| rate_limit_user_message | text | nullable | Message when user limit hit |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Relationship**:
```php
class BotLimits extends Model {
    public function botSetting(): BelongsTo {
        return $this->belongsTo(BotSetting::class);
    }
}
```

---

### BotHITLSettings

**Purpose**: Human-in-the-loop configuration

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| bot_setting_id | bigint | FK(bot_settings), unique | One-to-one |
| hitl_enabled | boolean | default:false | Enable HITL |
| hitl_triggers | json | nullable | Trigger keywords/patterns |
| lead_recovery_enabled | boolean | default:false | Auto follow-up |
| reply_when_called_enabled | boolean | default:false | Conditional response |
| easy_slip_enabled | boolean | default:false | Easy slip feature |
| created_at | timestamp | | |
| updated_at | timestamp | | |

---

### BotAggregationSettings

**Purpose**: Smart message aggregation settings

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| bot_setting_id | bigint | FK(bot_settings), unique | One-to-one |
| multiple_bubbles_enabled | boolean | default:false | Split responses |
| multiple_bubbles_min | int | default:2 | Min bubbles |
| multiple_bubbles_max | int | default:5 | Max bubbles |
| multiple_bubbles_delimiter | string(10) | default:'\n\n' | Split delimiter |
| wait_multiple_bubbles_enabled | boolean | default:false | Wait for multiple |
| wait_multiple_bubbles_ms | int | default:3000 | Wait time |
| smart_aggregation_enabled | boolean | default:false | Adaptive wait |
| smart_min_wait_ms | int | default:1000 | Min wait |
| smart_max_wait_ms | int | default:5000 | Max wait |
| smart_early_trigger_enabled | boolean | default:false | Early trigger |
| smart_per_user_learning_enabled | boolean | default:false | Per-user learning |
| created_at | timestamp | | |
| updated_at | timestamp | | |

---

### BotResponseHours

**Purpose**: Business hours and timezone settings

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| bot_setting_id | bigint | FK(bot_settings), unique | One-to-one |
| response_hours_enabled | boolean | default:false | Enable hours |
| response_hours | json | nullable | Day schedules |
| response_hours_timezone | string(50) | default:'Asia/Bangkok' | Timezone |
| offline_message | text | nullable | Message when offline |
| reply_sticker_enabled | boolean | default:false | Auto sticker |
| reply_sticker_message | text | nullable | Sticker message |
| created_at | timestamp | | |
| updated_at | timestamp | | |

---

### FlowAuditLog

**Purpose**: Track changes to flow configurations

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| flow_id | bigint | FK(flows), index | Parent flow |
| user_id | bigint | FK(users), nullable | Who made change |
| action | enum | 'created','updated','deleted','duplicated' | Action type |
| field_changes | json | nullable | Before/after values |
| created_at | timestamp | | When changed |

**Example field_changes**:
```json
{
  "system_prompt": {
    "old": "You are a helpful assistant...",
    "new": "You are a friendly Thai customer service..."
  },
  "temperature": {
    "old": 0.7,
    "new": 0.5
  }
}
```

---

## Relationship Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                              Bot                                  │
│  (encrypted credentials, FK to default_flow with nullOnDelete)   │
└───────────────┬──────────────────────────────────────────────────┘
                │ 1:1
                ▼
┌──────────────────────────────────────────────────────────────────┐
│                          BotSetting                               │
│  (core settings: welcome_message, language, typing, etc.)         │
└───┬───────────────┬───────────────┬───────────────┬──────────────┘
    │ 1:1           │ 1:1           │ 1:1           │ 1:1
    ▼               ▼               ▼               ▼
┌─────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────────┐
│BotLimits│  │BotHITLSettings│  │BotAggregation│  │BotResponseHours │
│ - daily │  │ - enabled     │  │Settings      │  │ - hours         │
│ - rate  │  │ - triggers    │  │ - bubbles    │  │ - timezone      │
│ - tokens│  │ - lead_recov  │  │ - wait_time  │  │ - offline_msg   │
└─────────┘  └──────────────┘  └──────────────┘  └─────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│                              Flow                                 │
│  (system_prompt, models, agentic_mode, safety settings)          │
└───────────────┬──────────────────────────────────────────────────┘
                │ 1:N
                ▼
┌──────────────────────────────────────────────────────────────────┐
│                         FlowAuditLog                              │
│  (action, field_changes, user_id, created_at)                     │
└──────────────────────────────────────────────────────────────────┘
```

---

## Migration Strategy

### Step 1: Create New Tables (Non-Breaking)
```php
Schema::create('bot_limits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bot_setting_id')->unique()->constrained()->cascadeOnDelete();
    // ... columns
});
// Repeat for other sub-tables
```

### Step 2: Migrate Data
```php
// In migration or command
BotSetting::chunk(100, function ($settings) {
    foreach ($settings as $setting) {
        BotLimits::create([
            'bot_setting_id' => $setting->id,
            'daily_message_limit' => $setting->daily_message_limit,
            // ... map other fields
        ]);
    }
});
```

### Step 3: Update Model Relationships
```php
class BotSetting extends Model {
    public function limits(): HasOne { ... }
    public function hitlSettings(): HasOne { ... }
    public function aggregationSettings(): HasOne { ... }
    public function responseHours(): HasOne { ... }
}
```

### Step 4: Deprecate Old Columns (Future Migration)
```php
// Separate migration after verification
Schema::table('bot_settings', function (Blueprint $table) {
    $table->dropColumn([
        'daily_message_limit',
        'per_user_limit',
        // ... other migrated columns
    ]);
});
```

---

## Validation Rules

### KB Pivot Validation (FR-007)
```php
'knowledge_bases.*.kb_top_k' => 'integer|min:1|max:20',
'knowledge_bases.*.kb_similarity_threshold' => 'numeric|min:0.1|max:1.0',
```

### LLM Model Validation (FR-005)
```php
'primary_chat_model' => ['nullable', Rule::in(config('services.openrouter.models'))],
```

---

## State Transitions

### Bot Status
```
inactive ──(activate)──→ active
active ──(deactivate)──→ inactive
active ──(pause)──→ paused
paused ──(resume)──→ active
```

### Flow Default
```
is_default: false ──(setDefault)──→ is_default: true
                   [transaction: unset others, set this]
```

---

## Indexes

### Existing (Verify)
- `bots.user_id`
- `bots.webhook_url`
- `bot_settings.bot_id` (unique)
- `flows.bot_id`
- `flows.bot_id, is_default`

### New
- `bot_limits.bot_setting_id` (unique)
- `bot_hitl_settings.bot_setting_id` (unique)
- `bot_aggregation_settings.bot_setting_id` (unique)
- `bot_response_hours.bot_setting_id` (unique)
- `flow_audit_logs.flow_id`
- `flow_audit_logs.user_id`
- `flow_audit_logs.created_at`
