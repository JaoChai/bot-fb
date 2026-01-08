# Developer Quickstart: AI Evaluation Refactor

**Feature**: 003-refactor-ai-evaluation | **Date**: 2026-01-08

## Overview

คู่มือสำหรับ developers ที่จะทำงานกับ AI Evaluation refactor โดยครอบคลุม 3 ส่วนหลัก:
1. **Second AI Unified Call**: รวม 3 checks เป็น 1 LLM call
2. **Evaluation Model Tiers**: ใช้ budget/standard/premium models ตาม metric complexity
3. **Knowledge Base Warning UI**: แจ้งเตือน user เมื่อเปิด Fact Check โดยไม่มี KB

---

## Prerequisites

### Required Knowledge

- PHP 8.2+ และ Laravel 12 fundamentals
- React 19 และ TypeScript 5.x
- OpenRouter API (LLM gateway)
- PostgreSQL และ Neon (cloud database)

### Development Environment

```bash
# ตรวจสอบ PHP version
php -v  # ต้อง 8.2+

# ตรวจสอบ Node version
node -v  # ต้อง 20+

# ตรวจสอบ composer
composer --version

# ตรวจสอบ git branch
git branch --show-current  # ควรเป็น 003-refactor-ai-evaluation
```

---

## Project Structure

### Key Files to Work With

```
backend/app/Services/
├── SecondAI/
│   ├── SecondAIService.php          # 🔨 REFACTOR: add unified mode detection
│   ├── UnifiedCheckService.php      # ✨ NEW: implement unified check logic
│   ├── FactCheckService.php         # ✅ UNCHANGED (fallback only)
│   ├── PolicyCheckService.php       # ✅ UNCHANGED (fallback only)
│   └── PersonalityCheckService.php  # ✅ UNCHANGED (fallback only)
├── Evaluation/
│   ├── LLMJudgeService.php          # 🔨 REFACTOR: add model tier selection
│   └── ModelTierSelector.php        # ✨ NEW: implement tier selection logic
└── OpenRouterService.php            # ✅ UNCHANGED (existing LLM client)

frontend/src/
├── pages/
│   └── FlowEditorPage.tsx           # 🔨 REFACTOR: add KB warning
└── components/flow/
    └── KnowledgeBaseWarning.tsx     # ✨ NEW: warning component

specs/003-refactor-ai-evaluation/
├── plan.md                          # Technical implementation plan
├── research.md                      # Unified prompt & tier strategy research
├── data-model.md                    # Entity definitions
├── contracts/                       # API contracts
│   ├── second-ai-unified-response.md
│   └── model-tier-config.md
└── quickstart.md                    # This file
```

**Legend**:
- 🔨 REFACTOR: แก้ไขไฟล์ที่มีอยู่
- ✨ NEW: สร้างไฟล์ใหม่
- ✅ UNCHANGED: ไม่ต้องแก้ไข

---

## Setup Steps

### 1. Check Out Feature Branch

```bash
# ถ้ายังไม่ได้ checkout
git checkout 003-refactor-ai-evaluation

# ถ้ายัง��ม่มี branch (แต่ควรมีอยู่แล้วจาก /speckit.specify)
git checkout -b 003-refactor-ai-evaluation
```

### 2. Install Dependencies

```bash
# Backend dependencies
cd backend
composer install

# Frontend dependencies
cd ../frontend
npm install
```

### 3. Environment Variables

```bash
# backend/.env
OPENROUTER_API_KEY=your_key_here  # ต้องมี (ใช้ทดสอบ LLM calls)

# Optional: Enable/disable features for testing
SECOND_AI_USE_UNIFIED=true        # เปิด unified mode (default: true)
EVALUATION_USE_TIERS=true         # เปิด model tiers (default: true)
EVALUATION_FORCE_PREMIUM=false    # บังคับใช้ premium model ทุก metric (testing only)
```

### 4. Database Setup

```bash
# ไม่ต้อง migrate - refactor นี้ไม่เปลี่ยน schema
# แต่ต้องมี database ที่ setup แล้ว

cd backend
php artisan migrate:status  # ควรเห็น migrations ทั้งหมด run แล้ว
```

---

## Development Workflow

### Phase 1: Backend - Second AI Unified Call

#### Step 1: Create UnifiedCheckService

```bash
cd backend/app/Services/SecondAI
touch UnifiedCheckService.php
```

**Implementation checklist**:
- [ ] Constructor: inject `OpenRouterService` และ `RAGService`
- [ ] `check()` method: รับ response, flow, userMessage, apiKey
- [ ] `buildUnifiedPrompt()`: สร้าง prompt ที่รวม 3 checks
- [ ] `parseResponse()`: parse JSON response และ validate format
- [ ] Return `SecondAICheckResult` object

**Test command**:
```bash
php artisan test --filter=UnifiedCheckServiceTest
```

---

#### Step 2: Refactor SecondAIService

**File**: `backend/app/Services/SecondAI/SecondAIService.php`

**Changes**:
```php
// Add constructor dependency
public function __construct(
    protected FactCheckService $factCheck,
    protected PolicyCheckService $policyCheck,
    protected PersonalityCheckService $personalityCheck,
    protected UnifiedCheckService $unifiedCheck,  // NEW
) {}

// Modify process() method
public function process(...): array {
    if (!$flow->second_ai_enabled) {
        return $this->buildResult($response, false, []);
    }

    // NEW: Try unified mode first
    if ($this->shouldUseUnifiedMode($flow)) {
        try {
            $result = $this->unifiedCheck->check(
                response: $response,
                flow: $flow,
                userMessage: $userMessage,
                apiKey: $apiKey
            );

            return $result->toLegacyFormat();
        } catch (\Exception $e) {
            Log::warning('Unified check failed, falling back to sequential', [
                'error' => $e->getMessage(),
            ]);
            // Continue to sequential checks below
        }
    }

    // Existing sequential logic (lines 76-122)
    // ... unchanged ...
}

// NEW helper method
protected function shouldUseUnifiedMode(Flow $flow): bool
{
    // Use unified if multiple checks enabled
    $enabledCount = count(array_filter($flow->second_ai_options ?? []));
    return $enabledCount >= 2 && config('second_ai.use_unified', true);
}
```

**Test command**:
```bash
php artisan test --filter=SecondAIServiceTest
```

---

### Phase 2: Backend - Evaluation Model Tiers

#### Step 1: Create ModelTierSelector

```bash
cd backend/app/Services/Evaluation
touch ModelTierSelector.php
```

**Implementation checklist**:
- [ ] Define `METRIC_TIER_MAP` constant (metric → tier mapping)
- [ ] Define `TIER_MODEL_MAP` constant (tier → model + fallback)
- [ ] `selectForMetric()`: return `ModelTierConfig` object
- [ ] `getFallbackModel()`: return fallback model ID
- [ ] `estimateTotalCost()`: calculate total cost for evaluation

**Test command**:
```bash
php artisan test --filter=ModelTierSelectorTest
```

---

#### Step 2: Refactor LLMJudgeService

**File**: `backend/app/Services/Evaluation/LLMJudgeService.php`

**Changes**:
```php
// Add constructor dependency
public function __construct(
    protected OpenRouterService $openRouter,
    protected ModelTierSelector $tierSelector,  // NEW
) {}

// Modify evaluateMetric() method (lines 51-79)
protected function evaluateMetric(
    TestCase $testCase,
    string $metricName,
    ?string $apiKey
): float {
    // NEW: Select model based on metric complexity
    $config = $this->tierSelector->selectForMetric($metricName);

    Log::debug('Evaluating metric', [
        'metric' => $metricName,
        'tier' => $config->tier,
        'model' => $config->modelId,
    ]);

    try {
        // Try primary model
        $result = $this->openRouter->chat(
            messages: $this->buildMetricPrompt($testCase, $metricName),
            model: $config->modelId,  // Changed from hardcoded
            temperature: 0.0,
            maxTokens: 1000,
            apiKeyOverride: $apiKey
        );

        $score = $this->parseScore($result['content']);

        // Log model used
        $testCase->metadata = array_merge($testCase->metadata ?? [], [
            'models' => [
                ...$testCase->metadata['models'] ?? [],
                $metricName => $config->modelId,
            ],
        ]);
        $testCase->save();

        return $score;

    } catch (\Exception $e) {
        // Try fallback if available
        if ($config->fallbackModelId) {
            Log::warning('Primary model failed, using fallback', [
                'metric' => $metricName,
                'fallback' => $config->fallbackModelId,
            ]);

            $result = $this->openRouter->chat(
                messages: $this->buildMetricPrompt($testCase, $metricName),
                model: $config->fallbackModelId,
                temperature: 0.0,
                maxTokens: 1000,
                apiKeyOverride: $apiKey
            );

            return $this->parseScore($result['content']);
        }

        throw $e;  // No fallback - let EvaluationService handle
    }
}
```

**Test command**:
```bash
php artisan test --filter=LLMJudgeServiceTest
```

---

### Phase 3: Frontend - Knowledge Base Warning UI

#### Step 1: Create KnowledgeBaseWarning Component

```bash
cd frontend/src/components/flow
mkdir -p flow  # ถ้ายังไม่มี
touch KnowledgeBaseWarning.tsx
```

**Implementation**:
```tsx
import { AlertTriangle } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

interface KnowledgeBaseWarningProps {
  visible: boolean;
}

export function KnowledgeBaseWarning({ visible }: KnowledgeBaseWarningProps) {
  const navigate = useNavigate();

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
            onClick={() => navigate('/knowledge-bases')}
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

**Test command**:
```bash
npm test -- KnowledgeBaseWarning.test.tsx
```

---

#### Step 2: Refactor FlowEditorPage

**File**: `frontend/src/pages/FlowEditorPage.tsx`

**Changes** (around line 845+):
```tsx
import { KnowledgeBaseWarning } from '@/components/flow/KnowledgeBaseWarning';

// ... inside render

{/* Second AI Options */}
<div className="space-y-3">
  {/* Fact Check checkbox */}
  <label className="flex items-center gap-2">
    <input
      type="checkbox"
      checked={secondAI.options.fact_check ?? false}
      onChange={(e) => {
        setSecondAI({
          ...secondAI,
          options: {
            ...secondAI.options,
            fact_check: e.target.checked,
          },
        });
      }}
    />
    <span>Fact Check</span>
  </label>

  {/* NEW: Knowledge Base Warning */}
  <KnowledgeBaseWarning
    visible={
      (secondAI.options.fact_check ?? false) &&
      (flow.knowledge_bases?.length ?? 0) === 0
    }
  />

  {/* ... other checkboxes ... */}
</div>
```

**Test command**:
```bash
npm run dev  # ทดสอบด้วย browser
# 1. เปิด flow editor
# 2. เปิด Fact Check toggle โดยไม่มี KB
# 3. ควรเห็น warning สีเหลือง
# 4. คลิก "เพิ่ม Knowledge Base" ควร navigate ไป /knowledge-bases
```

---

## Testing

### Unit Tests

```bash
# Backend tests
cd backend

# Test individual services
php artisan test --filter=UnifiedCheckServiceTest
php artisan test --filter=ModelTierSelectorTest
php artisan test --filter=SecondAIServiceTest
php artisan test --filter=LLMJudgeServiceTest

# Test all new/modified services
php artisan test tests/Unit/Services/SecondAI/
php artisan test tests/Unit/Services/Evaluation/
```

---

### Integration Tests

```bash
# Test unified check flow with real LLM (requires API key)
php artisan test --filter=UnifiedCheckIntegrationTest

# Test fallback scenario
php artisan test --filter=FallbackTest

# Test model tier selection with real evaluation
php artisan test --filter=ModelTierIntegrationTest
```

---

### Frontend Tests

```bash
cd frontend

# Test warning component
npm test -- KnowledgeBaseWarning.test.tsx

# Test flow editor integration
npm test -- FlowEditorPage.test.tsx

# Run all tests
npm test
```

---

## Manual Testing

### Test Scenario 1: Second AI Unified Call

1. สร้าง/แก้ไข flow ใน UI
2. เปิด Second AI พร้อมทั้ง 3 options (Fact + Policy + Personality)
3. ส่งข้อความทดสอบ bot
4. ตรวจสอบ response time และ log:

```bash
# ดู logs
tail -f backend/storage/logs/laravel.log | grep "SecondAI"

# ควรเห็น:
# - "Starting checks" (options: fact_check, policy, personality)
# - "Checks completed" (elapsed_ms ควร ≤1500ms)
# - "checks_applied" array
```

**Expected Results**:
- ✅ Response time ≤1.5s (ลดจาก 3-6s)
- ✅ Log แสดง unified mode ถูกใช้
- ✅ ถ้า unified fail → fallback ไป sequential automatically

---

### Test Scenario 2: Evaluation Model Tiers

1. สร้าง evaluation ใหม่ (40 test cases)
2. รัน evaluation
3. ตรวจสอบ cost และ model usage:

```bash
# ดู logs
tail -f backend/storage/logs/laravel.log | grep "Evaluating metric"

# ควรเห็น:
# - answer_relevancy → tier='budget', model='gemini-flash-1.5-8b-free'
# - task_completion → tier='standard', model='gpt-4o-mini'
# - faithfulness → tier='premium', model='claude-3.5-sonnet'
```

**Expected Results**:
- ✅ Total cost ลด ≥50% (จาก ~$0.90 → ~$0.39)
- ✅ Simple metrics ใช้ budget/standard models
- ✅ Complex metrics ใช้ premium model
- ✅ Score accuracy difference ≤10%

---

### Test Scenario 3: KB Warning UI

1. เปิด flow editor
2. ลบ Knowledge Base ออกหมด (ถ้ามี)
3. เปิด "Fact Check" checkbox
4. ตรวจสอบ UI:

**Expected Results**:
- ✅ แสดง warning สีเหลืองทันที
- ✅ คลิก "เพิ่ม Knowledge Base" → navigate ไป /knowledge-bases
- ✅ ปิด "Fact Check" → warning หายไป
- ✅ เพิ่ม KB → warning หายไป

---

## Debugging Tips

### Issue: Unified check always falls back to sequential

**Check**:
```php
// backend/config/second_ai.php (อาจต้องสร้างใหม่)
return [
    'use_unified' => env('SECOND_AI_USE_UNIFIED', true),
    'timeout' => env('SECOND_AI_TIMEOUT', 5),  // seconds
];
```

**Debug**:
```bash
# ดู logs
tail -f storage/logs/laravel.log | grep "SecondAI"

# ถ้าเห็น "Unified check failed, falling back"
# → ตรวจสอบ error message
# → อาจเป็น JSON parse error หรือ timeout
```

---

### Issue: Budget model returning errors

**Check**:
```bash
# ตรวจสอบว่า Gemini Flash free tier ยังใช้ได้
curl https://openrouter.ai/api/v1/models | jq '.[] | select(.id == "google/gemini-flash-1.5-8b-free")'
```

**Fallback**:
```php
// ModelTierSelector.php
// ถ้า budget model ไม่ available → จะใช้ fallback (gpt-4o-mini) อัตโนมัติ
```

---

### Issue: Frontend warning not showing

**Check React DevTools**:
1. เปิด React DevTools
2. หา `<KnowledgeBaseWarning>` component
3. ตรวจสอบ `visible` prop:
   - `secondAI.options.fact_check` = true?
   - `flow.knowledge_bases.length` = 0?

---

## Performance Benchmarks

### Before Refactor

| Metric | Baseline |
|--------|----------|
| Second AI (3 checks) latency | 3-6 seconds |
| Second AI API calls | 6-9 calls |
| Evaluation cost (40 cases) | $0.90 |
| Evaluation latency | ~5 minutes |

### After Refactor (Target)

| Metric | Target | Actual (fill after implementation) |
|--------|--------|-----------------------------------|
| Second AI (unified) latency | ≤1.5s | ___ |
| Second AI API calls | 1 call | ___ |
| Evaluation cost (40 cases) | ≤$0.45 | ___ |
| Evaluation latency | ~3-4 minutes | ___ |

---

## Rollback Plan

### Disable Unified Mode

```bash
# backend/.env
SECOND_AI_USE_UNIFIED=false  # กลับไปใช้ sequential checks
```

### Disable Model Tiers

```bash
# backend/.env
EVALUATION_USE_TIERS=false   # กลับไปใช้ premium model ทั้งหมด
```

### Full Rollback

```bash
git checkout main
git branch -D 003-refactor-ai-evaluation
```

---

## Commit Guidelines

ใช้ conventional commits:

```bash
# Backend changes
git commit -m "feat(second-ai): implement unified check service"
git commit -m "refactor(second-ai): add fallback to sequential checks"
git commit -m "feat(evaluation): add model tier selection"

# Frontend changes
git commit -m "feat(flow-editor): add KB warning component"

# Tests
git commit -m "test(second-ai): add unified check integration tests"

# Docs
git commit -m "docs(refactor): add quickstart guide"
```

---

## Next Steps

หลังจากเสร็จแล้ว:

1. **Run full test suite**: `php artisan test` และ `npm test`
2. **Performance testing**: วัด actual latency และ cost
3. **Create PR**: ใช้ `/commit-push-pr` skill
4. **Request review**: tag team members
5. **Deploy to staging**: ทดสอบบน Railway staging environment
6. **Monitor metrics**: ดู cost และ latency ใน production

---

## Helpful Commands

```bash
# Backend dev server
cd backend && php artisan serve

# Frontend dev server
cd frontend && npm run dev

# Watch logs
tail -f backend/storage/logs/laravel.log

# Clear cache
php artisan cache:clear && php artisan config:clear

# Run specific test
php artisan test --filter=UnifiedCheckServiceTest::test_check_with_all_options

# Check code style
cd backend && ./vendor/bin/pint --test
cd frontend && npm run lint
```

---

## Support

หากมีปัญหา:
- ดู logs: `backend/storage/logs/laravel.log`
- ตรวจสอบ contracts: `specs/003-refactor-ai-evaluation/contracts/`
- อ่าน research: `specs/003-refactor-ai-evaluation/research.md`
- ถาม team ใน Slack channel
