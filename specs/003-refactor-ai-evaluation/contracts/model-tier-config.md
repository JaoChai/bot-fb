# Contract: Model Tier Configuration

**Version**: 1.0.0 | **Date**: 2026-01-08 | **Status**: Draft

## Purpose

กำหนด model tier selection strategy และ configuration format สำหรับ Evaluation system โดยเลือก model ตาม metric complexity เพื่อลด cost

## Tier Definitions

### Tier Levels

| Tier | Use Case | Cost Range | Models |
|------|----------|------------|--------|
| `budget` | Simple metrics requiring basic comparison | Free - $0.50/1M tokens | Gemini Flash 8B (free), GPT-4o-mini (fallback) |
| `standard` | Moderate metrics requiring reasoning | $0.50 - $3.00/1M tokens | GPT-4o-mini (primary), Claude 3.5 Sonnet (fallback) |
| `premium` | Complex metrics requiring deep analysis | $3.00+/1M tokens | Claude 3.5 Sonnet (primary), no fallback |

---

## Metric-to-Tier Mapping

### Default Mapping

```php
const METRIC_TIER_MAP = [
    // Simple metrics - basic semantic comparison
    'answer_relevancy' => 'budget',        // Does answer relate to question?

    // Moderate metrics - requires reasoning
    'task_completion' => 'standard',       // Did bot complete the requested task?
    'role_adherence' => 'standard',        // Does response match persona?

    // Complex metrics - requires deep analysis
    'faithfulness' => 'premium',           // Is response grounded in context? (hallucination detection)
    'context_precision' => 'premium',      // Was context used appropriately?
];
```

**Rationale**:

| Metric | Tier | Reasoning |
|--------|------|-----------|
| `answer_relevancy` | budget | Simple keyword/topic matching - budget model sufficient |
| `task_completion` | standard | Requires understanding of instructions - moderate reasoning |
| `role_adherence` | standard | Requires comparing tone/style - moderate complexity |
| `faithfulness` | premium | Critical hallucination detection - needs strong reasoning |
| `context_precision` | premium | Complex context analysis - critical for quality |

---

## Model Selection

### Tier Model Map

```php
const TIER_MODEL_MAP = [
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
        'fallback' => null,  // no cheaper alternative
    ],
];
```

**Fallback Strategy**:
1. Try primary model
2. If rate limit or unavailable → try fallback model
3. If both fail → log error and skip metric (don't block evaluation)

---

## Configuration Object Format

### ModelTierConfig Structure

```php
readonly class ModelTierConfig {
    public function __construct(
        public string $metricName,        // e.g., 'answer_relevancy'
        public string $tier,              // 'budget' | 'standard' | 'premium'
        public string $modelId,           // e.g., 'google/gemini-flash-1.5-8b-free'
        public ?string $fallbackModelId,  // e.g., 'openai/gpt-4o-mini' or null
    ) {}
}
```

**Example Instances**:

```php
// Budget tier configuration
$config = new ModelTierConfig(
    metricName: 'answer_relevancy',
    tier: 'budget',
    modelId: 'google/gemini-flash-1.5-8b-free',
    fallbackModelId: 'openai/gpt-4o-mini',
);

// Premium tier configuration
$config = new ModelTierConfig(
    metricName: 'faithfulness',
    tier: 'premium',
    modelId: 'anthropic/claude-3.5-sonnet',
    fallbackModelId: null,
);
```

---

## Usage in LLMJudgeService

### Before Refactor (Single Model)

```php
// LLMJudgeService.php (OLD)
protected function evaluateMetric(
    TestCase $testCase,
    string $metricName,
    ?string $apiKey
): float {
    $model = 'anthropic/claude-3.5-sonnet';  // hardcoded premium model

    $result = $this->openRouter->chat(
        messages: $this->buildMetricPrompt($testCase, $metricName),
        model: $model,
        temperature: 0.0,
        maxTokens: 1000,
        apiKeyOverride: $apiKey
    );

    return $this->parseScore($result['content']);
}
```

**Cost**: 40 test cases × 5 metrics × $9/1M tokens ≈ **$0.90 per evaluation**

---

### After Refactor (Model Tiers)

```php
// LLMJudgeService.php (NEW)
public function __construct(
    protected OpenRouterService $openRouter,
    protected ModelTierSelector $tierSelector,  // NEW dependency
) {}

protected function evaluateMetric(
    TestCase $testCase,
    string $metricName,
    ?string $apiKey
): float {
    // Select model based on metric complexity
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
            model: $config->modelId,
            temperature: 0.0,
            maxTokens: 1000,
            apiKeyOverride: $apiKey
        );

        $score = $this->parseScore($result['content']);

        // Log model used for cost tracking
        $testCase->update([
            'metadata->model_used->' . $metricName => $config->modelId,
        ]);

        return $score;

    } catch (\Exception $e) {
        // Try fallback model if available
        if ($config->fallbackModelId) {
            Log::warning('Primary model failed, trying fallback', [
                'metric' => $metricName,
                'primary' => $config->modelId,
                'fallback' => $config->fallbackModelId,
                'error' => $e->getMessage(),
            ]);

            $result = $this->openRouter->chat(
                messages: $this->buildMetricPrompt($testCase, $metricName),
                model: $config->fallbackModelId,
                temperature: 0.0,
                maxTokens: 1000,
                apiKeyOverride: $apiKey
            );

            $score = $this->parseScore($result['content']);

            $testCase->update([
                'metadata->model_used->' . $metricName => $config->fallbackModelId,
            ]);

            return $score;
        }

        // No fallback available - log error and skip metric
        Log::error('Metric evaluation failed with no fallback', [
            'metric' => $metricName,
            'error' => $e->getMessage(),
        ]);

        throw $e;  // Will be caught by EvaluationService
    }
}
```

**Cost Breakdown** (40 test cases × 5 metrics):
- Budget tier (80 evals @ $0): $0.00
- Standard tier (40 evals @ $0.75): $0.03
- Premium tier (80 evals @ $9): $0.36
- **Total: $0.39 per evaluation** → **57% reduction**

---

## Cost Estimation

### estimateTotalCost() Method

```php
// ModelTierSelector.php
public function estimateTotalCost(array $metrics, int $testCaseCount): float
{
    $totalCost = 0;

    foreach ($metrics as $metricName) {
        $config = $this->selectForMetric($metricName);
        $costPerMetric = $config->getEstimatedCost();

        // Average 500 input tokens + 200 output tokens per evaluation
        $tokenCost = ($costPerMetric / 1_000_000) * 700;
        $totalCost += $tokenCost * $testCaseCount;
    }

    return $totalCost;
}
```

**Example**:
```php
$selector = new ModelTierSelector();

$cost = $selector->estimateTotalCost(
    metrics: ['answer_relevancy', 'task_completion', 'faithfulness', 'context_precision', 'role_adherence'],
    testCaseCount: 40
);
// → Returns: $0.39 (vs $0.90 with all premium)
```

---

## Validation & Monitoring

### Accuracy Validation

**Strategy**: Compare scores from budget/standard models vs premium model for a sample of evaluations

```php
// Validation test (run during deployment)
public function validateTierAccuracy()
{
    $sampleSize = 10;  // 10 random test cases
    $metrics = ['answer_relevancy', 'task_completion'];  // non-premium metrics

    foreach ($metrics as $metric) {
        $budgetScores = [];
        $premiumScores = [];

        for ($i = 0; $i < $sampleSize; $i++) {
            $testCase = $this->getRandomTestCase();

            // Evaluate with budget/standard model
            $config = $this->tierSelector->selectForMetric($metric);
            $budgetScore = $this->evaluateWithModel($testCase, $metric, $config->modelId);

            // Evaluate same case with premium model
            $premiumScore = $this->evaluateWithModel($testCase, $metric, 'anthropic/claude-3.5-sonnet');

            $budgetScores[] = $budgetScore;
            $premiumScores[] = $premiumScore;
        }

        // Calculate average difference
        $avgDiff = abs(array_sum($budgetScores) - array_sum($premiumScores)) / $sampleSize;

        // Alert if difference >10%
        if ($avgDiff > 0.10) {
            Log::warning('Model tier accuracy below threshold', [
                'metric' => $metric,
                'average_difference' => $avgDiff,
                'budget_scores' => $budgetScores,
                'premium_scores' => $premiumScores,
            ]);
        }
    }
}
```

---

### Cost Monitoring

**Log model usage for every evaluation**:

```php
// After each metric evaluation
Log::info('Metric evaluated', [
    'evaluation_id' => $evaluation->id,
    'test_case_id' => $testCase->id,
    'metric' => $metricName,
    'tier' => $config->tier,
    'model_used' => $actualModelUsed,  // could be fallback
    'estimated_cost' => $config->getEstimatedCost(),
    'score' => $score,
]);
```

**Aggregate metrics** (query logs):
```sql
-- Daily cost by tier
SELECT
    tier,
    COUNT(*) as evaluations,
    AVG(estimated_cost) as avg_cost,
    SUM(estimated_cost) as total_cost
FROM evaluation_logs
WHERE date = CURRENT_DATE
GROUP BY tier;
```

---

## Configuration Options

### Environment Variables (Optional Override)

```env
# Force all evaluations to use premium model (for accuracy testing)
EVALUATION_FORCE_PREMIUM=false

# Force specific metric to use specific tier
EVALUATION_TIER_OVERRIDE_answer_relevancy=standard

# Disable tier system entirely (use premium for all)
EVALUATION_USE_TIERS=true
```

**Usage**:
```php
public function selectForMetric(string $metricName): ModelTierConfig
{
    // Check for forced premium mode
    if (config('evaluation.force_premium', false)) {
        return ModelTierConfig::forMetric($metricName, 'premium');
    }

    // Check for metric-specific override
    $override = config("evaluation.tier_override.{$metricName}");
    if ($override) {
        return ModelTierConfig::forMetric($metricName, $override);
    }

    // Use default tier mapping
    return ModelTierConfig::forMetric($metricName);
}
```

---

## Testing Scenarios

### Test Case 1: Budget Tier Evaluation

**Input**:
- Metric: `answer_relevancy`
- Test case: "What are your hours?" → "We're open 9-5 daily."

**Expected**:
```php
$config = ModelTierConfig::forMetric('answer_relevancy');
// tier='budget', modelId='google/gemini-flash-1.5-8b-free', cost=0.0

$score = $this->evaluateMetric($testCase, 'answer_relevancy', $apiKey);
// score ≈ 0.95 (highly relevant)
```

---

### Test Case 2: Fallback Scenario

**Input**:
- Metric: `task_completion`
- Primary model: `openai/gpt-4o-mini` (rate limited)
- Fallback model: `anthropic/claude-3.5-sonnet`

**Expected Flow**:
1. Try `gpt-4o-mini` → HTTP 429 Rate Limit
2. Log warning + try fallback
3. Use `claude-3.5-sonnet` → success
4. Log actual model used: `claude-3.5-sonnet`

---

### Test Case 3: Cost Estimation

**Input**:
```php
$metrics = ['answer_relevancy', 'faithfulness', 'context_precision'];
$testCaseCount = 20;
```

**Expected Output**:
```php
$cost = $selector->estimateTotalCost($metrics, $testCaseCount);
// answer_relevancy: 20 × $0.00 = $0.00
// faithfulness: 20 × ~$0.0063 = $0.13
// context_precision: 20 × ~$0.0063 = $0.13
// Total: $0.26
```

---

## Performance Requirements

| Metric | Target | Measurement |
|--------|--------|-------------|
| Tier Selection Latency | <1ms | Time to call `selectForMetric()` |
| Accuracy Difference | ≤10% | Budget/Standard vs Premium scores |
| Cost Reduction | ≥50% | vs all-premium baseline |
| Fallback Success Rate | ≥95% | When primary model fails |

---

## Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01-08 | Initial contract definition |
