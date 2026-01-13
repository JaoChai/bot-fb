# Research: QA Bot Inspector

**Feature**: 008-qa-bot-inspector
**Date**: 2026-01-13
**Status**: Complete

## Research Questions & Decisions

### 1. How to integrate with existing evaluation system?

**Decision**: Adapt LLMJudgeService for real-time use

**Rationale**:
- LLMJudgeService already implements 5-metric evaluation (answer_relevancy, faithfulness, role_adherence, context_precision, task_completion)
- ModelTierSelector already handles tiered model selection for cost optimization
- Can extract and reuse metric evaluation methods without full test case infrastructure

**Alternatives Considered**:
- Build new evaluation service from scratch → Rejected: duplicates existing logic
- Use LLMJudgeService directly → Rejected: designed for batch test cases, not real-time messages

**Implementation Notes**:
- Create `RealtimeEvaluator` service that extracts evaluation logic from `LLMJudgeService`
- Use same metric calculation but with simpler input (Message instead of EvaluationTestCase)
- Reuse `ModelTierSelector` for model selection per metric

---

### 2. Where to store QA Inspector settings?

**Decision**: Extend Bot model with QA Inspector fields

**Rationale**:
- Consistent with existing pattern (Bot already has primary_chat_model, fallback_chat_model, etc.)
- Single source of truth for bot configuration
- Simpler queries and relationships

**Alternatives Considered**:
- Separate QAInspectorSettings model with BelongsTo Bot → Rejected: unnecessary complexity
- Store in JSON column on Bot → Rejected: harder to query and validate
- BotSetting model (existing) → Reviewed but found it's for different purpose

**Fields to Add to Bot**:
```php
// Enable/disable
'qa_inspector_enabled'           // boolean

// Layer 1 models (Real-time evaluation)
'qa_realtime_model'              // string, default: google/gemini-2.5-flash-preview
'qa_realtime_fallback_model'     // string, default: openai/gpt-4o-mini

// Layer 2 models (Deep analysis)
'qa_analysis_model'              // string, default: anthropic/claude-sonnet-4
'qa_analysis_fallback_model'     // string, default: openai/gpt-4o

// Layer 3 models (Weekly report)
'qa_report_model'                // string, default: anthropic/claude-opus-4-5
'qa_report_fallback_model'       // string, default: anthropic/claude-sonnet-4

// Settings
'qa_score_threshold'             // decimal, default: 0.70
'qa_sampling_rate'               // integer, default: 100 (percent)
'qa_report_schedule'             // string, default: 'monday_00:00'
'qa_notifications'               // json, default: {"email": true, "alert": true, "slack": false}
```

---

### 3. How to trigger real-time evaluation without blocking response?

**Decision**: Dispatch queue job after message is saved

**Rationale**:
- Non-blocking: bot response returns immediately
- Scalable: queue workers can be scaled independently
- Retry-able: failed evaluations can be retried
- Cost-controlled: queue can be throttled if needed

**Alternatives Considered**:
- Synchronous evaluation → Rejected: adds 5-30 seconds latency to response
- Cron-based batch evaluation → Rejected: not truly real-time, loses context
- Event-driven with observer → Selected: Laravel Observer pattern

**Implementation Notes**:
```php
// MessageObserver.php
public function created(Message $message)
{
    if ($message->role === 'assistant' && $message->bot->qa_inspector_enabled) {
        // Check sampling rate
        if (random_int(1, 100) <= $message->bot->qa_sampling_rate) {
            EvaluateConversationJob::dispatch($message)
                ->onQueue('qa-evaluation')
                ->delay(now()->addSeconds(2));
        }
    }
}
```

---

### 4. How to identify specific prompt sections in suggestions?

**Decision**: Use section heading detection + line number references

**Rationale**:
- Most prompts have section headings (STEP 1, STEP 2, etc.)
- Can use regex to find section boundaries
- Line numbers provide fallback for unstructured prompts

**Alternatives Considered**:
- Require structured prompt format → Rejected: too restrictive for users
- AI-based section detection → Selected: use AI to identify sections
- No section reference, just show suggestion → Rejected: loses actionability

**Implementation Notes**:
- Use Claude Opus to analyze prompt structure and identify sections
- Store section name + approximate line range in suggestion
- Show before/after with context around the section

---

### 5. How to handle multiple conversations in the same report period?

**Decision**: Aggregate metrics and group issues by pattern

**Rationale**:
- Users care about patterns, not individual failures
- Aggregation reduces noise and highlights important issues
- Pattern detection enables better prompt suggestions

**Aggregation Strategy**:
```
Weekly Report Structure:
├── Performance Summary
│   ├── Total conversations evaluated
│   ├── Average overall score (0-100)
│   ├── Score distribution (histogram)
│   └── Week-over-week trend
├── Top Issues (grouped by pattern)
│   ├── Issue type (e.g., price_error)
│   ├── Count and percentage
│   ├── Example conversations (3-5)
│   └── Root cause analysis
└── Prompt Suggestions (max 5)
    ├── Priority rank
    ├── Section to modify
    ├── Before/After text
    └── Expected impact
```

---

### 6. Which queue should evaluation jobs use?

**Decision**: Dedicated `qa-evaluation` queue

**Rationale**:
- Isolated from critical queues (webhooks, real-time features)
- Can be scaled/throttled independently
- Clear visibility into QA-specific job processing

**Queue Configuration**:
```php
// config/queue.php
'qa-evaluation' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'qa-evaluation',
    'retry_after' => 120,    // 2 minutes
    'block_for' => 3,
],
```

---

### 7. How to handle API rate limits from AI providers?

**Decision**: Exponential backoff with max 3 retries

**Rationale**:
- Standard pattern for API resilience
- Prevents overwhelming provider during limits
- Falls back to skip after retries exhausted

**Implementation**:
```php
// EvaluateConversationJob.php
public $tries = 3;
public $backoff = [10, 30, 60]; // seconds

public function failed(\Throwable $exception)
{
    // Log failure, don't alert user for individual failures
    Log::warning('QA evaluation failed', [
        'message_id' => $this->message->id,
        'error' => $exception->getMessage(),
    ]);
}
```

---

## Technology Decisions Summary

| Decision | Choice | Key Reason |
|----------|--------|------------|
| Evaluation logic | Adapt LLMJudgeService | Reuse existing 5-metric logic |
| Settings storage | Bot model extension | Consistent with existing patterns |
| Real-time trigger | Queue job via Observer | Non-blocking, scalable |
| Section detection | AI-based with regex fallback | Flexible for various prompt formats |
| Report aggregation | Pattern-based grouping | Actionable insights |
| Queue | Dedicated `qa-evaluation` | Isolation and visibility |
| Rate limits | Exponential backoff | Standard resilience pattern |

## Open Questions (Resolved)

All research questions have been resolved. No open items.
