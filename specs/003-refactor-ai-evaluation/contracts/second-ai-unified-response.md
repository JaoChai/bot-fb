# Contract: Second AI Unified Response Format

**Version**: 1.0.0 | **Date**: 2026-01-08 | **Status**: Draft

## Purpose

กำหนด JSON response format จาก unified Second AI check ที่รวม Fact Check, Policy Check, และ Personality Check ในครั้งเดียว

## Request Context

**Endpoint**: Internal service call (not HTTP API)
**Service**: `UnifiedCheckService::check()`
**Model**: `anthropic/claude-3.5-sonnet`

**Input Parameters**:
```php
[
    'response' => string,           // Original AI response to check
    'userMessage' => string,        // User's message for context
    'systemPrompt' => string,       // Flow system prompt
    'kbContext' => ?array,          // Knowledge Base context (if fact_check enabled)
    'enabledChecks' => array,       // ['fact_check', 'policy', 'personality']
]
```

---

## Response Format

### Success Response (JSON)

```json
{
  "passed": false,
  "modifications": {
    "fact_check": {
      "required": true,
      "claims_extracted": [
        "Our product is the #1 in Thailand",
        "We have 1 million users"
      ],
      "unverified_claims": [
        "Our product is the #1 in Thailand"
      ],
      "rewritten": "We have 1 million users in Thailand."
    },
    "policy": {
      "required": false,
      "violations": [],
      "rewritten": null
    },
    "personality": {
      "required": true,
      "issues": [
        "Tone is too casual for professional brand"
      ],
      "rewritten": "We are pleased to serve 1 million users in Thailand."
    }
  },
  "final_response": "We are pleased to serve 1 million users in Thailand."
}
```

---

## Field Definitions

### Root Level

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `passed` | boolean | ✅ Yes | `false` if any check requires modification, `true` if all checks passed |
| `modifications` | object | ✅ Yes | Object containing results from each check type |
| `final_response` | string | ✅ Yes | Final improved response after applying all modifications sequentially |

---

### modifications.fact_check

**Present when**: `fact_check` is in `enabledChecks` array

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `required` | boolean | ✅ Yes | `true` if fact check found issues and rewrote response |
| `claims_extracted` | string[] | ✅ Yes | All factual claims identified in the response |
| `unverified_claims` | string[] | ✅ Yes | Claims that couldn't be verified against Knowledge Base |
| `rewritten` | string \| null | ✅ Yes | Rewritten response without unverified claims, or `null` if no rewrite needed |

**Example (No issues)**:
```json
{
  "required": false,
  "claims_extracted": ["We offer 24/7 support"],
  "unverified_claims": [],
  "rewritten": null
}
```

**Example (Issues found)**:
```json
{
  "required": true,
  "claims_extracted": ["We're the best", "We have 1M users"],
  "unverified_claims": ["We're the best"],
  "rewritten": "We have 1M users."
}
```

---

### modifications.policy

**Present when**: `policy` is in `enabledChecks` array

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `required` | boolean | ✅ Yes | `true` if policy violations found and rewrote response |
| `violations` | string[] | ✅ Yes | List of policy violations identified |
| `rewritten` | string \| null | ✅ Yes | Rewritten response complying with policies, or `null` if no violations |

**Example (No violations)**:
```json
{
  "required": false,
  "violations": [],
  "rewritten": null
}
```

**Example (Violations found)**:
```json
{
  "required": true,
  "violations": [
    "Mentioned competitor brand name",
    "Made medical claims without disclaimer"
  ],
  "rewritten": "Our product helps improve your health. (Always consult your doctor.)"
}
```

---

### modifications.personality

**Present when**: `personality` is in `enabledChecks` array

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `required` | boolean | ✅ Yes | `true` if personality issues found and rewrote response |
| `issues` | string[] | ✅ Yes | List of tone/personality issues identified |
| `rewritten` | string \| null | ✅ Yes | Rewritten response matching brand personality, or `null` if no issues |

**Example (No issues)**:
```json
{
  "required": false,
  "issues": [],
  "rewritten": null
}
```

**Example (Issues found)**:
```json
{
  "required": true,
  "issues": [
    "Too casual for professional brand",
    "Missing polite particles in Thai"
  ],
  "rewritten": "ขอบคุณที่ติดต่อเราค่ะ เรายินดีให้บริการตลอด 24 ชั่วโมงค่ะ"
}
```

---

## Processing Logic

**Sequential Application**:
1. Start with original response
2. Apply `fact_check.rewritten` (if `required: true`)
3. Apply `policy.rewritten` to result from step 2 (if `required: true`)
4. Apply `personality.rewritten` to result from step 3 (if `required: true`)
5. Final result = `final_response`

**Example Flow**:
```
Original: "We're #1 in Thailand! Contact us 24/7 🎉"
  ↓ Fact Check (remove unverified claim)
"Contact us 24/7 🎉"
  ↓ Policy Check (passed)
"Contact us 24/7 🎉"
  ↓ Personality Check (adjust tone)
"We're available 24/7. Please contact us anytime."
  ↓
Final: "We're available 24/7. Please contact us anytime."
```

---

## Error Handling

### Invalid JSON Response

**Scenario**: LLM returns malformed JSON or non-JSON text

**Backend Behavior**:
```php
try {
    $json = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
    $result = SecondAICheckResult::fromJson($json);
} catch (\JsonException $e) {
    Log::warning('Unified check returned invalid JSON', ['error' => $e->getMessage()]);
    // Fallback to sequential checks
    throw new \RuntimeException('Invalid unified check response');
}
```

**Fallback**: Execute sequential checks (existing implementation)

---

### Missing Required Fields

**Scenario**: JSON is valid but missing required fields (e.g., `passed`, `final_response`)

**Backend Behavior**:
```php
if (!isset($json['passed']) || !isset($json['final_response'])) {
    Log::warning('Unified check missing required fields', ['json' => $json]);
    // Fallback to sequential checks
    throw new \RuntimeException('Incomplete unified check response');
}
```

**Fallback**: Execute sequential checks

---

### Timeout

**Scenario**: LLM call exceeds 5 second timeout

**Backend Behavior**:
```php
// In SecondAIService::process()
try {
    $result = rescue(function() use ($unifiedService, ...) {
        $this->checkTimeout($startTime);  // throws if >5s elapsed
        return $unifiedService->check(...);
    }, function() {
        Log::warning('Unified check timeout, falling back to sequential');
        return false;  // trigger fallback
    }, false);
} catch (\RuntimeException $e) {
    // Fallback to sequential checks
}
```

**Fallback**: Execute sequential checks

---

## Backward Compatibility

**Legacy Format** (from sequential checks):
```php
[
    'content' => string,
    'second_ai_applied' => boolean,
    'second_ai' => [
        'checks_applied' => array,
        'modifications' => array,
        'elapsed_ms' => int,
    ],
]
```

**Unified Format Conversion** (via `SecondAICheckResult::toLegacyFormat()`):
```php
[
    'content' => $result->finalResponse,
    'second_ai_applied' => !$result->passed,
    'second_ai' => [
        'checks_applied' => $result->getAppliedChecks(),  // ['fact_check', 'personality']
        'modifications' => $result->modifications,
        'elapsed_ms' => $result->metadata['latency_ms'],
    ],
]
```

**Frontend Compatibility**: ✅ No changes needed - response format identical

---

## Validation Rules

### JSON Schema

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["passed", "modifications", "final_response"],
  "properties": {
    "passed": {"type": "boolean"},
    "modifications": {
      "type": "object",
      "properties": {
        "fact_check": {
          "type": "object",
          "required": ["required", "claims_extracted", "unverified_claims", "rewritten"],
          "properties": {
            "required": {"type": "boolean"},
            "claims_extracted": {"type": "array", "items": {"type": "string"}},
            "unverified_claims": {"type": "array", "items": {"type": "string"}},
            "rewritten": {"type": ["string", "null"]}
          }
        },
        "policy": {
          "type": "object",
          "required": ["required", "violations", "rewritten"],
          "properties": {
            "required": {"type": "boolean"},
            "violations": {"type": "array", "items": {"type": "string"}},
            "rewritten": {"type": ["string", "null"]}
          }
        },
        "personality": {
          "type": "object",
          "required": ["required", "issues", "rewritten"],
          "properties": {
            "required": {"type": "boolean"},
            "issues": {"type": "array", "items": {"type": "string"}},
            "rewritten": {"type": ["string", "null"]}
          }
        }
      }
    },
    "final_response": {"type": "string", "minLength": 1}
  }
}
```

---

## Testing Scenarios

### Test Case 1: All Checks Pass

**Input**:
- Response: "We offer 24/7 customer support."
- Enabled: `['fact_check', 'policy', 'personality']`

**Expected Output**:
```json
{
  "passed": true,
  "modifications": {
    "fact_check": {"required": false, "claims_extracted": ["We offer 24/7 customer support"], "unverified_claims": [], "rewritten": null},
    "policy": {"required": false, "violations": [], "rewritten": null},
    "personality": {"required": false, "issues": [], "rewritten": null}
  },
  "final_response": "We offer 24/7 customer support."
}
```

---

### Test Case 2: Fact Check Fails

**Input**:
- Response: "We're the #1 product in Thailand with 10M users!"
- Enabled: `['fact_check']`
- KB Context: Only verifies "10M users", not "#1 product"

**Expected Output**:
```json
{
  "passed": false,
  "modifications": {
    "fact_check": {
      "required": true,
      "claims_extracted": ["We're the #1 product in Thailand", "We have 10M users"],
      "unverified_claims": ["We're the #1 product in Thailand"],
      "rewritten": "We have 10M users!"
    }
  },
  "final_response": "We have 10M users!"
}
```

---

### Test Case 3: Multiple Checks Fail

**Input**:
- Response: "Hey! We're awesome 😎 No refunds!"
- Enabled: `['policy', 'personality']`

**Expected Output**:
```json
{
  "passed": false,
  "modifications": {
    "policy": {
      "required": true,
      "violations": ["No refund policy violates consumer protection law"],
      "rewritten": "We offer a 30-day satisfaction guarantee."
    },
    "personality": {
      "required": true,
      "issues": ["Too casual tone", "Excessive emoji usage"],
      "rewritten": "We offer a 30-day satisfaction guarantee. Thank you for choosing us."
    }
  },
  "final_response": "We offer a 30-day satisfaction guarantee. Thank you for choosing us."
}
```

---

## Performance Requirements

| Metric | Target | Measurement |
|--------|--------|-------------|
| Response Time | ≤1.5s | 95th percentile |
| Success Rate | ≥95% | Valid JSON responses |
| Fallback Rate | ≤5% | Times sequential checks used |
| Token Usage | ≤3K input + 1K output | Average per request |

---

## Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01-08 | Initial contract definition |
