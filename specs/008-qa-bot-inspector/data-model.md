# Data Model: QA Bot Inspector

**Feature**: 008-qa-bot-inspector
**Date**: 2026-01-13

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  ┌──────────┐         ┌────────────────────┐         ┌───────────────┐ │
│  │   Bot    │─────────│  QAEvaluationLog   │─────────│    Message    │ │
│  │          │ 1    *  │                    │ 1     1 │               │ │
│  │+qa_*     │         │+scores             │         │+role          │ │
│  │ fields   │         │+issue_flags        │         │+content       │ │
│  └──────────┘         └────────────────────┘         └───────────────┘ │
│       │                                                                 │
│       │ 1                                                               │
│       │                                                                 │
│       │ *                                                               │
│  ┌────────────────────┐                                                 │
│  │  QAWeeklyReport    │                                                 │
│  │                    │                                                 │
│  │+performance_summary│                                                 │
│  │+top_issues         │                                                 │
│  │+prompt_suggestions │                                                 │
│  └────────────────────┘                                                 │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

## Entities

### 1. Bot (Extended)

**Purpose**: Store QA Inspector settings per bot

**New Fields**:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| qa_inspector_enabled | boolean | false | Enable/disable QA Inspector |
| qa_realtime_model | string(100) | google/gemini-2.5-flash-preview | Layer 1 primary model |
| qa_realtime_fallback_model | string(100) | openai/gpt-4o-mini | Layer 1 fallback model |
| qa_analysis_model | string(100) | anthropic/claude-sonnet-4 | Layer 2 primary model |
| qa_analysis_fallback_model | string(100) | openai/gpt-4o | Layer 2 fallback model |
| qa_report_model | string(100) | anthropic/claude-opus-4-5 | Layer 3 primary model |
| qa_report_fallback_model | string(100) | anthropic/claude-sonnet-4 | Layer 3 fallback model |
| qa_score_threshold | decimal(3,2) | 0.70 | Score below this = issue |
| qa_sampling_rate | integer | 100 | Percentage of conversations to evaluate |
| qa_report_schedule | string(50) | monday_00:00 | When to generate weekly report |
| qa_notifications | json | {"email":true,"alert":true,"slack":false} | Notification settings |

**Validation Rules**:
- qa_realtime_model: nullable, max:100, format: `provider/model-name`
- qa_score_threshold: between:0,1
- qa_sampling_rate: between:1,100
- qa_report_schedule: in allowed values (monday_00:00, friday_18:00, etc.)

---

### 2. QAEvaluationLog (New)

**Purpose**: Store real-time evaluation results for each conversation turn

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| id | bigint | NO | Primary key |
| bot_id | bigint | NO | Foreign key to bots |
| conversation_id | bigint | NO | Foreign key to conversations |
| message_id | bigint | NO | Foreign key to messages (assistant response) |
| flow_id | bigint | YES | Foreign key to flows (for prompt reference) |
| answer_relevancy | decimal(3,2) | YES | Score 0.00-1.00 |
| faithfulness | decimal(3,2) | YES | Score 0.00-1.00 |
| role_adherence | decimal(3,2) | YES | Score 0.00-1.00 |
| context_precision | decimal(3,2) | YES | Score 0.00-1.00 |
| task_completion | decimal(3,2) | YES | Score 0.00-1.00 |
| overall_score | decimal(3,2) | NO | Weighted average |
| is_flagged | boolean | NO | true if overall_score < threshold |
| issue_type | string(50) | YES | price_error, hallucination, etc. |
| issue_details | json | YES | Detailed analysis (Layer 2 output) |
| user_question | text | NO | Customer's question |
| bot_response | text | NO | Bot's response |
| system_prompt_used | text | YES | Snapshot of prompt used |
| kb_chunks_used | json | YES | RAG context used |
| model_metadata | json | YES | Models used, tokens, cost |
| evaluated_at | timestamp | NO | When evaluation completed |
| created_at | timestamp | NO | Record creation |
| updated_at | timestamp | NO | Record update |

**Indexes**:
- `idx_qa_eval_bot_id` on (bot_id)
- `idx_qa_eval_conversation_id` on (conversation_id)
- `idx_qa_eval_flagged` on (bot_id, is_flagged, created_at)
- `idx_qa_eval_created` on (bot_id, created_at)

**Relationships**:
- BelongsTo: Bot, Conversation, Message, Flow

---

### 3. QAWeeklyReport (New)

**Purpose**: Store generated weekly reports

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| id | bigint | NO | Primary key |
| bot_id | bigint | NO | Foreign key to bots |
| week_start | date | NO | Start of report period (Monday) |
| week_end | date | NO | End of report period (Sunday) |
| status | string(20) | NO | generating, completed, failed |
| performance_summary | json | NO | Aggregated metrics |
| top_issues | json | NO | Top issues with analysis |
| prompt_suggestions | json | NO | Suggested prompt improvements |
| total_conversations | integer | NO | Count of evaluated conversations |
| total_flagged | integer | NO | Count of flagged issues |
| average_score | decimal(5,2) | NO | Average overall score |
| previous_average_score | decimal(5,2) | YES | Last week's average for trend |
| generation_cost | decimal(8,4) | YES | Cost to generate report |
| generated_at | timestamp | YES | When report was generated |
| notification_sent | boolean | NO | Whether notification was sent |
| created_at | timestamp | NO | Record creation |
| updated_at | timestamp | NO | Record update |

**Indexes**:
- `idx_qa_report_bot_week` on (bot_id, week_start) UNIQUE
- `idx_qa_report_status` on (status)

**Relationships**:
- BelongsTo: Bot

---

## JSON Field Schemas

### performance_summary (QAWeeklyReport)

```json
{
  "total_conversations": 1247,
  "total_evaluated": 1247,
  "total_flagged": 154,
  "error_rate": 12.35,
  "average_score": 78.5,
  "score_trend": "+3.2",
  "score_distribution": {
    "excellent": 45,   // 90-100
    "good": 320,       // 70-89
    "needs_improvement": 120, // 50-69
    "poor": 12         // 0-49
  },
  "metric_averages": {
    "answer_relevancy": 0.85,
    "faithfulness": 0.72,
    "role_adherence": 0.81,
    "context_precision": 0.68,
    "task_completion": 0.79
  }
}
```

### top_issues (QAWeeklyReport)

```json
[
  {
    "rank": 1,
    "issue_type": "price_error",
    "count": 23,
    "percentage": 14.9,
    "pattern": "ลูกค้าพิมพ์ 'ยืนยัน' โดยไม่ตอบ upsell",
    "prompt_section": "STEP 2 CONFIRM",
    "example_conversations": [
      {
        "evaluation_log_id": 12345,
        "user_question": "ยืนยันครับ",
        "bot_response": "ยอดรวม 1,299 บาท...",
        "expected": "ยอดรวม 1,100 บาท..."
      }
    ],
    "root_cause": "STEP 2 ไม่มี validation rule สำหรับ upsell acceptance"
  }
]
```

### prompt_suggestions (QAWeeklyReport)

```json
[
  {
    "priority": 1,
    "section": "STEP 2 CONFIRM",
    "line_range": "45-67",
    "issue_addressed": "price_error",
    "expected_impact": "Fix 23 cases (15% of flagged)",
    "before": "เมื่อลูกค้าพิมพ์ \"ยืนยัน\" → สรุปยอดรวมทันที",
    "after": "เมื่อลูกค้าพิมพ์ \"ยืนยัน\":\n1. ตรวจสอบก่อนว่า upsell ได้รับการตอบรับหรือไม่\n2. ถ้าไม่ได้ตอบ → ไม่รวม upsell ในยอด\n3. ถ้าตอบ \"เอาด้วย\"/\"รับ\" → รวม upsell",
    "applied": false,
    "applied_at": null
  }
]
```

### issue_details (QAEvaluationLog)

```json
{
  "analysis_model": "anthropic/claude-sonnet-4",
  "analysis_timestamp": "2026-01-13T15:30:00Z",
  "root_cause": "Bot คิดราคา upsell 199 บาทโดยไม่ได้รับการยืนยัน",
  "prompt_section_identified": "STEP 2 CONFIRM",
  "similar_issues_count": 22,
  "severity": "high",
  "confidence": 0.92
}
```

---

## Migration SQL Reference

### Add QA Inspector fields to bots

```sql
ALTER TABLE bots
ADD COLUMN qa_inspector_enabled BOOLEAN DEFAULT false,
ADD COLUMN qa_realtime_model VARCHAR(100) DEFAULT 'google/gemini-2.5-flash-preview',
ADD COLUMN qa_realtime_fallback_model VARCHAR(100) DEFAULT 'openai/gpt-4o-mini',
ADD COLUMN qa_analysis_model VARCHAR(100) DEFAULT 'anthropic/claude-sonnet-4',
ADD COLUMN qa_analysis_fallback_model VARCHAR(100) DEFAULT 'openai/gpt-4o',
ADD COLUMN qa_report_model VARCHAR(100) DEFAULT 'anthropic/claude-opus-4-5',
ADD COLUMN qa_report_fallback_model VARCHAR(100) DEFAULT 'anthropic/claude-sonnet-4',
ADD COLUMN qa_score_threshold DECIMAL(3,2) DEFAULT 0.70,
ADD COLUMN qa_sampling_rate INTEGER DEFAULT 100,
ADD COLUMN qa_report_schedule VARCHAR(50) DEFAULT 'monday_00:00',
ADD COLUMN qa_notifications JSONB DEFAULT '{"email": true, "alert": true, "slack": false}';
```

### Create qa_evaluation_logs table

```sql
CREATE TABLE qa_evaluation_logs (
    id BIGSERIAL PRIMARY KEY,
    bot_id BIGINT NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    message_id BIGINT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    flow_id BIGINT REFERENCES flows(id) ON DELETE SET NULL,
    answer_relevancy DECIMAL(3,2),
    faithfulness DECIMAL(3,2),
    role_adherence DECIMAL(3,2),
    context_precision DECIMAL(3,2),
    task_completion DECIMAL(3,2),
    overall_score DECIMAL(3,2) NOT NULL,
    is_flagged BOOLEAN NOT NULL DEFAULT false,
    issue_type VARCHAR(50),
    issue_details JSONB,
    user_question TEXT NOT NULL,
    bot_response TEXT NOT NULL,
    system_prompt_used TEXT,
    kb_chunks_used JSONB,
    model_metadata JSONB,
    evaluated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_qa_eval_bot_id ON qa_evaluation_logs(bot_id);
CREATE INDEX idx_qa_eval_conversation_id ON qa_evaluation_logs(conversation_id);
CREATE INDEX idx_qa_eval_flagged ON qa_evaluation_logs(bot_id, is_flagged, created_at);
CREATE INDEX idx_qa_eval_created ON qa_evaluation_logs(bot_id, created_at);
```

### Create qa_weekly_reports table

```sql
CREATE TABLE qa_weekly_reports (
    id BIGSERIAL PRIMARY KEY,
    bot_id BIGINT NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'generating',
    performance_summary JSONB NOT NULL DEFAULT '{}',
    top_issues JSONB NOT NULL DEFAULT '[]',
    prompt_suggestions JSONB NOT NULL DEFAULT '[]',
    total_conversations INTEGER NOT NULL DEFAULT 0,
    total_flagged INTEGER NOT NULL DEFAULT 0,
    average_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    previous_average_score DECIMAL(5,2),
    generation_cost DECIMAL(8,4),
    generated_at TIMESTAMP,
    notification_sent BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(bot_id, week_start)
);

CREATE INDEX idx_qa_report_bot_week ON qa_weekly_reports(bot_id, week_start);
CREATE INDEX idx_qa_report_status ON qa_weekly_reports(status);
```

---

## Data Retention

| Table | Retention | Cleanup Strategy |
|-------|-----------|------------------|
| qa_evaluation_logs | 90 days | Scheduled job deletes older records |
| qa_weekly_reports | Indefinite | Keep all for historical trends |

```php
// Scheduled cleanup command
$this->schedule(function () {
    QAEvaluationLog::where('created_at', '<', now()->subDays(90))->delete();
})->daily();
```
