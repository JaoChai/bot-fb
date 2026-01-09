# Data Model: Second AI for Improvement

**Feature**: 002-second-ai-improvement
**Date**: 2026-01-07

## Entity Changes

### Flow (Extended)

เพิ่ม fields ใหม่ใน Flow entity ที่มีอยู่:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `second_ai_enabled` | boolean | false | Toggle เปิด/ปิด Second AI check |
| `second_ai_options` | jsonb | `{}` | Options object สำหรับ check types |

#### SecondAIOptions Structure

```typescript
interface SecondAIOptions {
  fact_check: boolean;     // ตรวจข้อเท็จจริงเทียบ KB
  policy: boolean;         // ตรวจนโยบายธุรกิจ
  personality: boolean;    // ตรวจ tone/บุคลิกภาพ
}
```

### SecondAICheckLog (New Entity - Optional)

สำหรับ logging และ analytics (implement in later phase if needed):

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `flow_id` | bigint | FK to flows |
| `conversation_id` | bigint | FK to conversations |
| `message_id` | bigint | FK to messages |
| `check_type` | enum | 'fact_check', 'policy', 'personality' |
| `original_response` | text | Response before check |
| `modified_response` | text | Response after check (null if no change) |
| `was_modified` | boolean | Whether response was changed |
| `modifications` | jsonb | Details of what was changed |
| `latency_ms` | integer | Time taken for check |
| `created_at` | timestamp | When check was performed |

## Relationships

```
Flow (1) ────────── (*) SecondAICheckLog
     │
     │ second_ai_enabled
     │ second_ai_options
     │
     ▼
┌─────────────────────────────┐
│ When enabled, affects:      │
│ - AIService.generateResponse│
│ - All chat responses        │
└─────────────────────────────┘
```

## Validation Rules

### Flow Fields

```php
// StoreFlowRequest.php / UpdateFlowRequest.php
'second_ai_enabled' => 'sometimes|boolean',
'second_ai_options' => 'sometimes|array',
'second_ai_options.fact_check' => 'sometimes|boolean',
'second_ai_options.policy' => 'sometimes|boolean',
'second_ai_options.personality' => 'sometimes|boolean',
```

## Migration SQL

```sql
-- Add Second AI columns to flows table
ALTER TABLE flows
ADD COLUMN second_ai_enabled BOOLEAN NOT NULL DEFAULT FALSE,
ADD COLUMN second_ai_options JSONB DEFAULT '{"fact_check": false, "policy": false, "personality": false}'::jsonb;

-- Index for querying enabled flows (optional, for analytics)
CREATE INDEX idx_flows_second_ai_enabled ON flows (second_ai_enabled) WHERE second_ai_enabled = true;
```

## State Transitions

### Second AI Enabled State

```
┌─────────────┐    toggle on     ┌─────────────┐
│  Disabled   │ ───────────────► │   Enabled   │
│ (default)   │ ◄─────────────── │             │
└─────────────┘    toggle off    └─────────────┘
```

### Check Options State

```
Each option (fact_check, policy, personality) is independent:

┌─────────────┐   checkbox click  ┌─────────────┐
│  Unchecked  │ ◄───────────────► │   Checked   │
└─────────────┘                   └─────────────┘

Valid combinations: All permutations of 3 booleans (8 states)
At least one option should be checked when second_ai_enabled = true
```

## Sample Data

### Flow with Second AI Enabled

```json
{
  "id": 1,
  "bot_id": 1,
  "name": "Sales Flow",
  "system_prompt": "คุณเป็นที่ปรึกษาการขาย...",
  "second_ai_enabled": true,
  "second_ai_options": {
    "fact_check": true,
    "policy": true,
    "personality": false
  }
}
```

### Flow with Second AI Disabled (Default)

```json
{
  "id": 2,
  "bot_id": 1,
  "name": "Support Flow",
  "system_prompt": "คุณเป็นเจ้าหน้าที่ดูแลลูกค้า...",
  "second_ai_enabled": false,
  "second_ai_options": {
    "fact_check": false,
    "policy": false,
    "personality": false
  }
}
```
