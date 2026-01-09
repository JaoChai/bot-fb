# Research: Second AI for Improvement

**Feature**: 002-second-ai-improvement
**Date**: 2026-01-07

## R1: Best Approach for Fact Check Against Knowledge Base

### Decision
ใช้ RAG-based verification โดยดึง relevant chunks จาก KB มาให้ Second AI เปรียบเทียบกับ response

### Rationale
- Leverages existing `HybridSearchService` ที่มีอยู่แล้ว
- ไม่ต้อง re-implement search/retrieval logic
- สามารถใช้ reranker (JinaRerankerService) ที่มีอยู่เพื่อให้ได้ context ที่ relevant ที่สุด

### Implementation Approach
```php
// FactCheckService.php
public function check(string $response, Flow $flow, string $userMessage): CheckResult
{
    // 1. Extract claims from response (ใช้ LLM extract)
    $claims = $this->extractClaims($response);

    // 2. For each claim, search KB for supporting evidence
    $verifiedClaims = [];
    foreach ($claims as $claim) {
        $evidence = $this->hybridSearch->search(
            query: $claim,
            knowledgeBases: $flow->knowledgeBases,
            topK: 3
        );

        $verifiedClaims[] = [
            'claim' => $claim,
            'has_evidence' => count($evidence) > 0,
            'evidence' => $evidence,
        ];
    }

    // 3. If any claim lacks evidence, ask Second AI to rewrite
    if (collect($verifiedClaims)->contains('has_evidence', false)) {
        return $this->rewriteWithoutUnverifiedClaims($response, $verifiedClaims);
    }

    return CheckResult::passed($response);
}
```

### Alternatives Considered
1. **Full response comparison with entire KB**: ช้าเกินไป, ไม่ scalable
2. **Embedding similarity only**: ไม่ accurate พอสำหรับ fact verification
3. **External fact-check API**: เพิ่ม dependency และ latency

---

## R2: Policy Check Implementation

### Decision
ใช้ LLM-based policy checker โดย extract policy rules จาก system prompt ของ Flow

### Rationale
- Policy rules ถูกกำหนดใน system prompt อยู่แล้ว (ตาม spec assumptions)
- ไม่ต้องสร้าง UI ใหม่สำหรับ policy management
- Flexible - user กำหนด rules ได้เอง

### Implementation Approach
```php
// PolicyCheckService.php
public function check(string $response, Flow $flow): CheckResult
{
    $systemPrompt = $flow->system_prompt;

    // Extract policy rules using LLM
    $policyRules = $this->extractPolicyRules($systemPrompt);

    // Check response against each rule
    $checkPrompt = $this->buildPolicyCheckPrompt($response, $policyRules);

    $result = $this->openRouter->chat([
        ['role' => 'system', 'content' => 'You are a policy compliance checker...'],
        ['role' => 'user', 'content' => $checkPrompt],
    ]);

    // Parse result and rewrite if needed
    return $this->parseAndRewrite($result, $response);
}
```

### Default Policy Rules (if not specified in prompt)
- ห้ามพูดถึงคู่แข่ง
- ห้ามให้ส่วนลดที่ไม่มีในระบบ
- ห้ามเปิดเผยข้อมูลภายใน
- ห้ามให้ warranty/guarantee ที่ไม่มีจริง

---

## R3: Personality Check Implementation

### Decision
ใช้ LLM-based tone analysis โดย extract brand guidelines จาก system prompt

### Rationale
- Brand guidelines ถูกระบุใน system prompt (ตาม spec assumptions)
- สามารถวิเคราะห์ tone และปรับได้ในครั้งเดียว

### Implementation Approach
```php
// PersonalityCheckService.php
public function check(string $response, Flow $flow): CheckResult
{
    $systemPrompt = $flow->system_prompt;

    // Extract brand personality from system prompt
    $brandGuidelines = $this->extractBrandGuidelines($systemPrompt);

    // Check and adjust tone
    $checkPrompt = <<<PROMPT
    ## Brand Guidelines
    {$brandGuidelines}

    ## Response to Check
    {$response}

    ## Task
    1. Analyze if the response matches the brand personality
    2. If not, rewrite to match while keeping the same information
    3. Return JSON: {"matches": bool, "rewritten": string|null, "issues": string[]}
    PROMPT;

    // ... process result
}
```

---

## R4: Integration Point in AI Response Flow

### Decision
Inject Second AI check หลัง `RAGService::generateResponse()` และก่อน save message

### Rationale
- Single integration point ที่ครอบคลุม LINE, Telegram, และ test endpoints
- ไม่ต้องแก้ไขหลาย controllers
- Follows existing pattern ของ AIService

### Implementation Approach
```php
// AIService.php - modified
public function generateResponse(...): array
{
    // ... existing code ...

    $result = $this->ragService->generateResponse(...);

    // NEW: Apply Second AI check if enabled
    $flow = $conversation?->currentFlow ?? $bot->defaultFlow;
    if ($flow?->second_ai_enabled) {
        $result = $this->secondAIService->process(
            response: $result['content'],
            flow: $flow,
            userMessage: $userMessage,
            options: $flow->second_ai_options
        );
    }

    return $result;
}
```

### Flow Diagram
```
User Message
    │
    ▼
┌─────────────────┐
│   RAGService    │ ← Primary AI Response
└─────────────────┘
    │
    ▼
┌─────────────────┐
│ SecondAIService │ ← Check & Improve (if enabled)
│  ├─FactCheck    │
│  ├─PolicyCheck  │
│  └─PersonalityCheck
└─────────────────┘
    │
    ▼
Save Message & Return
```

---

## R5: Timeout and Fallback Strategy

### Decision
ใช้ timeout 5 วินาที สำหรับ Second AI check และ fallback to original response on failure

### Rationale
- Response latency goal คือ ≤3 seconds เพิ่มเติม
- แต่ต้องรองรับ worst case ที่ model ช้า
- User experience ต้องไม่ถูก block ถ้า Second AI fail

### Implementation Approach
```php
// SecondAIService.php
public function process(...): array
{
    try {
        return rescue(function () use (...) {
            // Run checks with timeout
            return $this->runChecks(...);
        }, function () use ($originalResponse) {
            // Fallback: return original response
            Log::warning('Second AI check failed, using original response');
            return ['content' => $originalResponse, 'second_ai_applied' => false];
        }, false);
    } catch (\Exception $e) {
        // Log and return original
        return ['content' => $originalResponse, 'second_ai_applied' => false];
    }
}
```

---

## R6: Database Schema Addition

### Decision
เพิ่ม 2 columns ใน flows table: `second_ai_enabled` (boolean) และ `second_ai_options` (jsonb)

### Schema
```php
// Migration
Schema::table('flows', function (Blueprint $table) {
    $table->boolean('second_ai_enabled')->default(false);
    $table->jsonb('second_ai_options')->nullable()->default(json_encode([
        'fact_check' => false,
        'policy' => false,
        'personality' => false,
    ]));
});
```

### Model Cast
```php
// Flow.php
protected $casts = [
    // ... existing
    'second_ai_enabled' => 'boolean',
    'second_ai_options' => 'array',
];
```

---

## Summary

| Research Item | Decision | Key Benefit |
|---------------|----------|-------------|
| R1: Fact Check | RAG-based verification | Leverages existing services |
| R2: Policy Check | LLM + system prompt rules | No new UI needed |
| R3: Personality | LLM + brand guidelines | Single-pass analysis |
| R4: Integration | After RAGService | Single integration point |
| R5: Fallback | 5s timeout + original | User experience protected |
| R6: Schema | 2 columns (bool + jsonb) | Backward compatible |
