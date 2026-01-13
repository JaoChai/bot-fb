# API Contracts: QA Bot Inspector

**Feature**: 008-qa-bot-inspector
**Date**: 2026-01-13
**Base URL**: `/api/v1`

## Authentication

All endpoints require Bearer token authentication via Laravel Sanctum.

```
Authorization: Bearer {token}
```

## Endpoints

### 1. QA Inspector Settings

#### GET /bots/{bot}/qa-inspector/settings

Get QA Inspector settings for a bot.

**Response** `200 OK`:
```json
{
  "data": {
    "qa_inspector_enabled": true,
    "models": {
      "realtime": {
        "primary": "google/gemini-2.5-flash-preview",
        "fallback": "openai/gpt-4o-mini"
      },
      "analysis": {
        "primary": "anthropic/claude-sonnet-4",
        "fallback": "openai/gpt-4o"
      },
      "report": {
        "primary": "anthropic/claude-opus-4-5",
        "fallback": "anthropic/claude-sonnet-4"
      }
    },
    "settings": {
      "score_threshold": 0.70,
      "sampling_rate": 100,
      "report_schedule": "monday_00:00"
    },
    "notifications": {
      "email": true,
      "alert": true,
      "slack": false
    }
  }
}
```

---

#### PUT /bots/{bot}/qa-inspector/settings

Update QA Inspector settings.

**Request**:
```json
{
  "qa_inspector_enabled": true,
  "qa_realtime_model": "google/gemini-2.5-flash-preview",
  "qa_realtime_fallback_model": "openai/gpt-4o-mini",
  "qa_analysis_model": "anthropic/claude-sonnet-4",
  "qa_analysis_fallback_model": "openai/gpt-4o",
  "qa_report_model": "anthropic/claude-opus-4-5",
  "qa_report_fallback_model": "anthropic/claude-sonnet-4",
  "qa_score_threshold": 0.70,
  "qa_sampling_rate": 100,
  "qa_report_schedule": "monday_00:00",
  "qa_notifications": {
    "email": true,
    "alert": true,
    "slack": false
  }
}
```

**Response** `200 OK`:
```json
{
  "data": {
    "message": "QA Inspector settings updated successfully"
  }
}
```

**Validation Errors** `422 Unprocessable Entity`:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "qa_score_threshold": ["The score threshold must be between 0 and 1."],
    "qa_sampling_rate": ["The sampling rate must be between 1 and 100."]
  }
}
```

---

### 2. Evaluation Logs

#### GET /bots/{bot}/qa-inspector/logs

List evaluation logs for a bot.

**Query Parameters**:
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | integer | 1 | Page number |
| per_page | integer | 20 | Items per page (max 100) |
| is_flagged | boolean | null | Filter by flagged status |
| issue_type | string | null | Filter by issue type |
| date_from | date | null | Start date (YYYY-MM-DD) |
| date_to | date | null | End date (YYYY-MM-DD) |
| min_score | decimal | null | Minimum overall score |
| max_score | decimal | null | Maximum overall score |

**Response** `200 OK`:
```json
{
  "data": [
    {
      "id": 12345,
      "conversation_id": 67890,
      "message_id": 11111,
      "scores": {
        "answer_relevancy": 0.85,
        "faithfulness": 0.45,
        "role_adherence": 0.90,
        "context_precision": 0.60,
        "task_completion": 0.75
      },
      "overall_score": 0.68,
      "is_flagged": true,
      "issue_type": "hallucination",
      "user_question": "สินค้า Nolimit ราคาเท่าไหร่",
      "bot_response": "Nolimit ราคา 1,299 บาทครับ...",
      "evaluated_at": "2026-01-13T15:30:00Z",
      "created_at": "2026-01-13T15:29:55Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 20,
    "total": 195
  }
}
```

---

#### GET /bots/{bot}/qa-inspector/logs/{log}

Get detailed evaluation log.

**Response** `200 OK`:
```json
{
  "data": {
    "id": 12345,
    "conversation_id": 67890,
    "message_id": 11111,
    "flow_id": 1,
    "scores": {
      "answer_relevancy": 0.85,
      "faithfulness": 0.45,
      "role_adherence": 0.90,
      "context_precision": 0.60,
      "task_completion": 0.75
    },
    "overall_score": 0.68,
    "is_flagged": true,
    "issue_type": "hallucination",
    "issue_details": {
      "analysis_model": "anthropic/claude-sonnet-4",
      "root_cause": "Bot คิดราคา upsell 199 บาทโดยไม่ได้รับการยืนยัน",
      "prompt_section_identified": "STEP 2 CONFIRM",
      "severity": "high",
      "confidence": 0.92
    },
    "user_question": "สินค้า Nolimit ราคาเท่าไหร่",
    "bot_response": "Nolimit ราคา 1,299 บาทครับ...",
    "system_prompt_used": "คุณคือ Captain Ad...",
    "kb_chunks_used": [
      {
        "content": "Nolimit ราคา 1,100 บาท",
        "similarity": 0.92
      }
    ],
    "model_metadata": {
      "realtime_model": "google/gemini-2.5-flash-preview",
      "tokens_used": 1250,
      "cost_estimate": 0.002
    },
    "evaluated_at": "2026-01-13T15:30:00Z",
    "created_at": "2026-01-13T15:29:55Z"
  }
}
```

---

### 3. Dashboard Stats

#### GET /bots/{bot}/qa-inspector/stats

Get real-time dashboard statistics.

**Query Parameters**:
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| period | string | 7d | Period: 1d, 7d, 30d |

**Response** `200 OK`:
```json
{
  "data": {
    "summary": {
      "total_evaluated": 1247,
      "total_flagged": 154,
      "error_rate": 12.35,
      "average_score": 78.5
    },
    "score_trend": [
      {"date": "2026-01-07", "average_score": 75.3},
      {"date": "2026-01-08", "average_score": 76.1},
      {"date": "2026-01-09", "average_score": 77.8},
      {"date": "2026-01-10", "average_score": 78.2},
      {"date": "2026-01-11", "average_score": 77.9},
      {"date": "2026-01-12", "average_score": 79.1},
      {"date": "2026-01-13", "average_score": 78.5}
    ],
    "issue_breakdown": [
      {"type": "price_error", "count": 23, "percentage": 14.9},
      {"type": "hallucination", "count": 18, "percentage": 11.7},
      {"type": "wrong_tone", "count": 12, "percentage": 7.8},
      {"type": "missing_info", "count": 15, "percentage": 9.7},
      {"type": "other", "count": 86, "percentage": 55.9}
    ],
    "metric_averages": {
      "answer_relevancy": 0.85,
      "faithfulness": 0.72,
      "role_adherence": 0.81,
      "context_precision": 0.68,
      "task_completion": 0.79
    },
    "cost_this_period": 12.45
  }
}
```

---

### 4. Weekly Reports

#### GET /bots/{bot}/qa-inspector/reports

List weekly reports.

**Query Parameters**:
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | integer | 1 | Page number |
| per_page | integer | 10 | Items per page |

**Response** `200 OK`:
```json
{
  "data": [
    {
      "id": 5,
      "week_start": "2026-01-06",
      "week_end": "2026-01-12",
      "status": "completed",
      "total_conversations": 1247,
      "total_flagged": 154,
      "average_score": 78.5,
      "previous_average_score": 75.3,
      "generated_at": "2026-01-13T00:05:23Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 10,
    "total": 25
  }
}
```

---

#### GET /bots/{bot}/qa-inspector/reports/{report}

Get detailed weekly report.

**Response** `200 OK`:
```json
{
  "data": {
    "id": 5,
    "week_start": "2026-01-06",
    "week_end": "2026-01-12",
    "status": "completed",
    "performance_summary": {
      "total_conversations": 1247,
      "total_evaluated": 1247,
      "total_flagged": 154,
      "error_rate": 12.35,
      "average_score": 78.5,
      "score_trend": "+3.2",
      "score_distribution": {
        "excellent": 45,
        "good": 320,
        "needs_improvement": 120,
        "poor": 12
      },
      "metric_averages": {
        "answer_relevancy": 0.85,
        "faithfulness": 0.72,
        "role_adherence": 0.81,
        "context_precision": 0.68,
        "task_completion": 0.79
      }
    },
    "top_issues": [
      {
        "rank": 1,
        "issue_type": "price_error",
        "count": 23,
        "percentage": 14.9,
        "pattern": "ลูกค้าพิมพ์ 'ยืนยัน' โดยไม่ตอบ upsell",
        "prompt_section": "STEP 2 CONFIRM",
        "root_cause": "STEP 2 ไม่มี validation rule สำหรับ upsell acceptance"
      }
    ],
    "prompt_suggestions": [
      {
        "priority": 1,
        "section": "STEP 2 CONFIRM",
        "line_range": "45-67",
        "issue_addressed": "price_error",
        "expected_impact": "Fix 23 cases (15% of flagged)",
        "before": "เมื่อลูกค้าพิมพ์ \"ยืนยัน\" → สรุปยอดรวมทันที",
        "after": "เมื่อลูกค้าพิมพ์ \"ยืนยัน\":\n1. ตรวจสอบก่อนว่า upsell ได้รับการตอบรับหรือไม่...",
        "applied": false
      }
    ],
    "generation_cost": 3.45,
    "generated_at": "2026-01-13T00:05:23Z"
  }
}
```

---

#### POST /bots/{bot}/qa-inspector/reports/generate

Manually trigger report generation.

**Request**:
```json
{
  "week_start": "2026-01-06"
}
```

**Response** `202 Accepted`:
```json
{
  "data": {
    "report_id": 6,
    "status": "generating",
    "message": "Report generation started. You will be notified when complete."
  }
}
```

---

### 5. Apply Prompt Suggestions

#### POST /bots/{bot}/qa-inspector/reports/{report}/suggestions/{index}/apply

Apply a prompt suggestion to the flow.

**Request**:
```json
{
  "flow_id": 1,
  "confirm": true
}
```

**Response** `200 OK`:
```json
{
  "data": {
    "message": "Prompt suggestion applied successfully",
    "flow_id": 1,
    "section_updated": "STEP 2 CONFIRM",
    "characters_changed": 245
  }
}
```

**Conflict** `409 Conflict`:
```json
{
  "message": "Flow prompt has been modified since report generation. Please review manually.",
  "current_section": "...",
  "expected_section": "..."
}
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "message": "This action is unauthorized."
}
```

### 404 Not Found
```json
{
  "message": "Bot not found."
}
```

### 422 Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

### 500 Server Error
```json
{
  "message": "An error occurred while processing your request."
}
```

---

## Rate Limits

| Endpoint | Limit |
|----------|-------|
| GET endpoints | 60 requests/minute |
| PUT/POST endpoints | 30 requests/minute |
| Report generation | 5 requests/hour per bot |
