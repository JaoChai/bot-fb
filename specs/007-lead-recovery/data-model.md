# Data Model: Lead Recovery

**Feature**: 007-lead-recovery
**Date**: 2026-01-12

## Entity Overview

This document describes the data model changes required for Lead Recovery, including extended entities and new tables.

---

## Modified Entities

### BotHITLSettings (Extended)

**Purpose**: Store Lead Recovery configuration per bot

**Current Fields** (existing):
| Field | Type | Notes |
|-------|------|-------|
| id | bigint | PK |
| bot_setting_id | bigint | FK(bot_settings) |
| hitl_enabled | boolean | HITL toggle |
| hitl_triggers | json | Trigger keywords |
| auto_assignment_enabled | boolean | Auto assign |
| auto_assignment_mode | string | Assignment mode |

**New Fields** (to add):
| Field | Type | Constraints | Default | Notes |
|-------|------|-------------|---------|-------|
| lead_recovery_enabled | boolean | not null | false | Master toggle |
| lead_recovery_timeout_hours | integer | min:1, max:72 | 4 | Hours of inactivity |
| lead_recovery_mode | string(10) | enum: static, ai | 'static' | Message mode |
| lead_recovery_message | text | nullable | null | Static message |
| lead_recovery_max_attempts | integer | min:1, max:5 | 2 | Max follow-ups |

**Migration**:
```php
Schema::table('bot_hitl_settings', function (Blueprint $table) {
    $table->boolean('lead_recovery_enabled')->default(false);
    $table->integer('lead_recovery_timeout_hours')->default(4);
    $table->string('lead_recovery_mode', 10)->default('static');
    $table->text('lead_recovery_message')->nullable();
    $table->integer('lead_recovery_max_attempts')->default(2);
});
```

---

### Conversation (Extended)

**Purpose**: Track recovery attempts per conversation

**New Fields** (to add):
| Field | Type | Constraints | Default | Notes |
|-------|------|-------------|---------|-------|
| recovery_attempts | integer | not null | 0 | Count of attempts |
| last_recovery_at | timestamp | nullable | null | Last follow-up sent |

**Migration**:
```php
Schema::table('conversations', function (Blueprint $table) {
    $table->integer('recovery_attempts')->default(0);
    $table->timestamp('last_recovery_at')->nullable();
});
```

**Scopes** (to add):
```php
// Conversations eligible for lead recovery
public function scopeNeedsRecovery($query, int $timeoutHours, int $maxAttempts)
{
    return $query
        ->where('status', 'active')
        ->where('is_handover', false)
        ->where('recovery_attempts', '<', $maxAttempts)
        ->where('last_message_at', '<', now()->subHours($timeoutHours))
        ->where(function ($q) {
            $q->whereNull('last_recovery_at')
              ->orWhere('last_recovery_at', '<', now()->subHours(24));
        });
}
```

---

## New Entities

### LeadRecoveryLog

**Purpose**: Log all follow-up attempts for tracking and analytics

| Field | Type | Constraints | Notes |
|-------|------|-------------|-------|
| id | bigint | PK, auto | |
| conversation_id | bigint | FK(conversations), index | Parent conversation |
| bot_id | bigint | FK(bots), index | For analytics queries |
| attempt_number | integer | not null | 1, 2, 3... |
| message_mode | string(10) | enum: static, ai | Mode used |
| message_sent | text | not null | Actual message content |
| sent_at | timestamp | not null | When message was sent |
| delivery_status | string(20) | enum: sent, failed, blocked | Delivery result |
| error_message | text | nullable | Error details if failed |
| customer_responded | boolean | default: false | Did customer reply? |
| responded_at | timestamp | nullable | When customer replied |
| created_at | timestamp | | |
| updated_at | timestamp | | |

**Migration**:
```php
Schema::create('lead_recovery_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
    $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
    $table->integer('attempt_number');
    $table->string('message_mode', 10);
    $table->text('message_sent');
    $table->timestamp('sent_at');
    $table->string('delivery_status', 20)->default('sent');
    $table->text('error_message')->nullable();
    $table->boolean('customer_responded')->default(false);
    $table->timestamp('responded_at')->nullable();
    $table->timestamps();

    $table->index(['bot_id', 'sent_at']);
    $table->index(['conversation_id', 'customer_responded']);
});
```

**Relationships**:
```php
class LeadRecoveryLog extends Model
{
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}

// In Conversation model
public function recoveryLogs(): HasMany
{
    return $this->hasMany(LeadRecoveryLog::class);
}
```

---

## Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                              Bot                                 │
│  (channel_type, default_flow_id)                                │
└───────────────┬──────────────────────────────────────────────────┘
                │ 1:1
                ▼
┌─────────────────────────────────────────────────────────────────┐
│                          BotSetting                              │
└───────────────┬──────────────────────────────────────────────────┘
                │ 1:1
                ▼
┌─────────────────────────────────────────────────────────────────┐
│                      BotHITLSettings                             │
│  + lead_recovery_enabled                                         │
│  + lead_recovery_timeout_hours                                   │
│  + lead_recovery_mode                                            │
│  + lead_recovery_message                                         │
│  + lead_recovery_max_attempts                                    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                        Conversation                              │
│  + recovery_attempts                                             │
│  + last_recovery_at                                              │
│  (existing: last_message_at, is_handover, status)               │
└───────────────┬──────────────────────────────────────────────────┘
                │ 1:N
                ▼
┌─────────────────────────────────────────────────────────────────┐
│                      LeadRecoveryLog                             │
│  (attempt_number, message_sent, delivery_status, etc.)          │
└─────────────────────────────────────────────────────────────────┘
```

---

## Validation Rules

### BotHITLSettings
```php
'lead_recovery_enabled' => 'boolean',
'lead_recovery_timeout_hours' => 'integer|min:1|max:72',
'lead_recovery_mode' => 'in:static,ai',
'lead_recovery_message' => 'nullable|string|max:1000',
'lead_recovery_max_attempts' => 'integer|min:1|max:5',
```

### Business Rules

1. **Timeout minimum**: 1 hour to prevent spam
2. **Max attempts**: 5 to prevent harassment
3. **24-hour cooldown**: Between recovery attempts
4. **No HITL overlap**: Skip if is_handover = true
5. **Active only**: Only process status = 'active' conversations

---

## State Transitions

### Conversation Recovery State
```
initial (attempts=0)
    │
    │ (inactive for timeout hours)
    ▼
eligible for recovery
    │
    │ (send follow-up)
    ▼
attempt sent (attempts=1, last_recovery_at=now)
    │
    ├──(customer responds)──→ recovered (customer_responded=true)
    │
    └──(24 hours pass, attempts < max)──→ eligible for recovery
                                              │
                                              │ (attempts = max)
                                              ▼
                                        exhausted (no more attempts)
```

### Message Reset Trigger
```
When customer sends new message:
    → reset last_message_at to now
    → (recovery_attempts stays, but eligibility resets)
```

---

## Indexes

### Existing (verify)
- `conversations.bot_id`
- `conversations.status`
- `conversations.last_message_at`

### New
- `lead_recovery_logs.bot_id, sent_at` (analytics queries)
- `lead_recovery_logs.conversation_id, customer_responded` (response tracking)
- `bot_hitl_settings.lead_recovery_enabled` (filtering enabled bots)
