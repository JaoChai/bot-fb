# Research: Refactor AI Evaluation System - Phase 1

**Phase**: 0 - Research | **Date**: 2026-01-08 | **Plan**: [plan.md](./plan.md)

## Research Questions

### Q1: Unified Prompt Design for Second AI

**Question**: จะออกแบบ unified prompt ที่รวม Fact Check, Policy Check, และ Personality Check เป็น 1 call ได้อย่างไร โดยให้ LLM สามารถ parse และ apply modifications ตามลำดับที่ถูกต้อง?

**Context**: ปัจจุบัน `SecondAIService` (backend/app/Services/SecondAI/SecondAIService.php:75-122) เรียกใช้ทั้ง 3 checks แบบ sequential:
1. Fact Check (lines 76-90)
2. Policy Check (lines 93-106)
3. Personality Check (lines 109-122)

แต่ละ check ใช้เวลา ~1-2 วินาที รวมเป็น 3-6 วินาที และใช้ 2-3 API calls ต่อ check (extract → verify → rewrite)

**Research Findings**:

#### Unified Prompt Structure

```markdown
## Unified Second AI Check Prompt

You are a Second AI system that improves Primary AI responses through 3 sequential checks:

1. **Fact Check** (if Knowledge Base provided):
   - Extract all factual claims from the response
   - Verify claims against Knowledge Base using semantic search
   - Identify unverified claims

2. **Policy Check**:
   - Extract policy rules from system prompt
   - Check if response violates any rules
   - Identify violations

3. **Personality Check**:
   - Extract brand personality from system prompt
   - Check if response matches brand tone/voice
   - Identify tone mismatches

## Input
- **Original Response**: {response}
- **User Message**: {userMessage}
- **System Prompt**: {systemPrompt}
- **Knowledge Base**: {kbContext}  # ถ้า fact_check enabled
- **Enabled Checks**: {enabledChecks}  # ['fact_check', 'policy', 'personality']

## Output Format
Return JSON with this exact structure:
{
  "passed": boolean,  // false if any check requires modification
  "modifications": {
    "fact_check": {
      "required": boolean,
      "claims_extracted": string[],
      "unverified_claims": string[],
      "rewritten": string | null
    },
    "policy": {
      "required": boolean,
      "violations": string[],
      "rewritten": string | null
    },
    "personality": {
      "required": boolean,
      "issues": string[],
      "rewritten": string | null
    }
  },
  "final_response": string  // final rewritten response หลัง apply ทั้ง 3 checks
}

## Processing Rules
1. Process checks in order: Fact → Policy → Personality
2. Each check receives output from previous check
3. If check finds no issues: required=false, rewritten=null
4. If check requires changes: required=true, rewritten=[improved version]
5. final_response = last rewritten OR original if no changes
```

**Advantages**:
- ✅ 1 API call แทน 6-9 calls (ลด cost 60-70%)
- ✅ Latency ~1-1.5s แทน 3-6s (ลด 50%+)
- ✅ LLM เห็น context ทั้งหมดในครั้งเดียว (better reasoning)
- ✅ Structured JSON output ทำให้ parse ง่าย

**Challenges**:
- ⚠️ Prompt ยาวขึ้น (~2-3K tokens vs 500-800 tokens ต่อ check)
- ⚠️ ต้อง validate JSON format (fallback ถ้า parse fail)
- ⚠️ Context length limit (แต่ Claude 3.5 Sonnet รองรับ 200K tokens เพียงพอ)

**Decision**: ใช้ unified prompt แบบนี้ โดย:
- Primary model: `anthropic/claude-3.5-sonnet` (reasoning ดี, JSON output stable)
- Fallback: Sequential checks ถ้า unified fail หรือ timeout
- Validation: ใช้ `json_decode()` พร้อม try-catch

---

### Q2: Model Tier Selection Strategy

**Question**: จะกำหนด model tier (premium/standard/budget) สำหรับแต่ละ evaluation metric ได้อย่างไร โดยให้ accuracy ≥90% แต่ลด cost ได้ 50-70%?

**Context**: ปัจจุบัน `LLMJudgeService` (backend/app/Services/Evaluation/LLMJudgeService.php:51-79) ใช้ `anthropic/claude-3.5-sonnet` สำหรับทุก metric แม้ metric ง่ายๆ เช่น `answer_relevancy`

**Research Findings**:

#### Model Tier Mapping

| Metric | Complexity | Tier | Model | Cost/1M tokens | Reasoning |
|--------|-----------|------|-------|----------------|-----------|
| `answer_relevancy` | Simple | Budget | `google/gemini-flash-1.5-8b-free` | $0.00 (free tier) | เปรียบเทียบ keyword/topic overlap - ไม่ต้องการ deep reasoning |
| `task_completion` | Simple | Standard | `openai/gpt-4o-mini` | $0.15 (input) + $0.60 (output) | ตรวจสอบว่าทำงานตาม instruction - ต้องการ basic reasoning |
| `faithfulness` | Complex | Premium | `anthropic/claude-3.5-sonnet` | $3.00 (input) + $15.00 (output) | ตรวจสอบ hallucination - ต้องการ strong reasoning |
| `context_precision` | Complex | Premium | `anthropic/claude-3.5-sonnet` | $3.00 (input) + $15.00 (output) | วิเคราะห์ว่าใช้ context ถูกต้อง - critical metric |
| `role_adherence` | Medium | Standard | `openai/gpt-4o-mini` | $0.15 (input) + $0.60 (output) | ตรวจสอบว่า follow persona - moderate reasoning |

**Cost Calculation Example** (40 test cases × 5 metrics = 200 evaluations):

**ปัจจุบัน** (Claude 3.5 Sonnet ทั้งหมด):
- Input: ~500 tokens/eval × 200 = 100K tokens → $0.30
- Output: ~200 tokens/eval × 200 = 40K tokens → $0.60
- **Total: $0.90 per evaluation**

**หลัง refactor** (mixed tiers):
- Budget tier (80 evals): ~$0.00 (Gemini Flash free)
- Standard tier (40 evals): ~$0.03
- Premium tier (80 evals): ~$0.36
- **Total: $0.39 per evaluation** → **ลด 57%**

**Tier Selection Logic**:

```php
class ModelTierSelector
{
    private const TIER_MAP = [
        'answer_relevancy' => 'budget',
        'task_completion' => 'standard',
        'faithfulness' => 'premium',
        'context_precision' => 'premium',
        'role_adherence' => 'standard',
    ];

    private const MODEL_MAP = [
        'budget' => [
            'primary' => 'google/gemini-flash-1.5-8b-free',
            'fallback' => 'openai/gpt-4o-mini',
        ],
        'standard' => [
            'primary' => 'openai/gpt-4o-mini',
            'fallback' => 'anthropic/claude-3.5-sonnet',
        ],
        'premium' => [
            'primary' => 'anthropic/claude-3.5-sonnet',
            'fallback' => null,  // no cheaper fallback
        ],
    ];

    public function selectModel(string $metricName): string
    {
        $tier = self::TIER_MAP[$metricName] ?? 'premium';  // default to safe option
        return self::MODEL_MAP[$tier]['primary'];
    }

    public function getFallback(string $metricName): ?string
    {
        $tier = self::TIER_MAP[$metricName] ?? 'premium';
        return self::MODEL_MAP[$tier]['fallback'];
    }
}
```

**Validation Strategy**:
- เปรียบเทียบ scores ระหว่าง budget/standard models กับ premium model
- ถ้า difference >10% → upgrade tier
- Log model used สำหรับทุก evaluation เพื่อ analyze performance

**Decision**: ใช้ tier-based strategy แบบนี้ โดย:
- Simple metrics → Budget/Standard models
- Complex metrics → Premium model
- Fallback chain: budget → standard → premium (ถ้า rate limit หรือ unavailable)
- Monitor accuracy: alert ถ้า score difference >10%

---

### Q3: Knowledge Base Warning UI Implementation

**Question**: จะ implement warning UI ใน Flow Editor อย่างไร เมื่อ user เปิด Fact Check แต่ไม่มี Knowledge Base attached?

**Context**: ปัจจุบัน `FlowEditorPage.tsx` (frontend/src/pages/FlowEditorPage.tsx:845+) มี toggles สำหรับ Second AI options แต่ไม่มี validation หรือ warning

**Research Findings**:

#### UI/UX Design

**Placement**: แสดง warning ใต้ "Fact Check" checkbox เมื่อ:
1. `secondAI.options.fact_check === true` AND
2. `flow.knowledge_bases.length === 0`

**Warning Component Structure**:

```tsx
<KnowledgeBaseWarning
  visible={secondAI.options.fact_check && flow.knowledge_bases.length === 0}
  onAddKnowledgeBase={() => navigate('/knowledge-bases')}
/>
```

**Component Design**:

```tsx
// frontend/src/components/flow/KnowledgeBaseWarning.tsx
export function KnowledgeBaseWarning({ visible, onAddKnowledgeBase }) {
  if (!visible) return null;

  return (
    <div className="mt-2 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
      <div className="flex items-start gap-2">
        <AlertTriangle className="h-5 w-5 text-yellow-600 flex-shrink-0 mt-0.5" />
        <div className="flex-1">
          <p className="text-sm text-yellow-800 font-medium">
            Fact Check ต้องการ Knowledge Base
          </p>
          <p className="text-sm text-yellow-700 mt-1">
            เพื่อตรวจสอบข้อเท็จจริง คุณต้องเพิ่ม Knowledge Base ก่อน
          </p>
          <button
            onClick={onAddKnowledgeBase}
            className="mt-2 text-sm font-medium text-yellow-800 hover:text-yellow-900 underline"
          >
            เพิ่ม Knowledge Base →
          </button>
        </div>
      </div>
    </div>
  );
}
```

**Behavior**:
- แสดง warning ทันทีเมื่อ user เปิด Fact Check toggle
- ซ่อน warning เมื่อ user attach KB หรือปิด Fact Check
- ไม่ block user จากการ save (graceful degradation)
- Fact Check จะไม่ทำงานจริง backend ถ้าไม่มี KB (existing behavior ใน FactCheckService.php:48)

**Decision**: ใช้ design นี้ เนื่องจาก:
- ✅ Clear visual feedback (yellow warning style)
- ✅ Actionable: มีปุ่มไปเพิ่ม KB ทันที
- ✅ Non-blocking: ไม่ขัดขวาง user workflow
- ✅ Consistent: ใช้ existing navigation pattern

---

## Research Summary

### Key Decisions

1. **Unified Prompt Design**:
   - Single LLM call with structured JSON output
   - Model: `anthropic/claude-3.5-sonnet`
   - Fallback: Sequential checks ถ้า fail
   - Expected: 60-70% cost reduction, 50%+ latency reduction

2. **Model Tier Strategy**:
   - 3 tiers: Budget (free), Standard ($0.15-0.60), Premium ($3-15)
   - Simple metrics → cheaper models
   - Complex metrics → premium model
   - Expected: 50-60% cost reduction

3. **KB Warning UI**:
   - Yellow warning box ใต้ Fact Check toggle
   - Navigate to KB management page
   - Non-blocking validation
   - Expected: ลด user confusion

### Technical Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Unified prompt parse fail | High | Fallback to sequential checks |
| Budget model low accuracy | Medium | Monitor scores, upgrade tier if >10% diff |
| Context length exceeded | Low | Claude 3.5 Sonnet รองรับ 200K tokens |
| Rate limit (cheaper models) | Medium | Fallback chain: budget → standard → premium |

### Next Steps

- ✅ Research complete → ready for Phase 1 (data model & contracts)
- Phase 1 จะสร้าง:
  - `data-model.md`: SecondAICheckResult, ModelTierConfig entities
  - `contracts/`: API response formats
  - `quickstart.md`: Developer setup guide
