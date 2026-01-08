# Data Model: Refactor AI Evaluation System - Phase 1

**Phase**: 1 - Data Model | **Date**: 2026-01-08 | **Research**: [research.md](./research.md)

## Entity Overview

This refactor introduces 2 new key entities และ refactors 2 existing services:

| Entity | Type | Purpose | Scope |
|--------|------|---------|-------|
| `SecondAICheckResult` | Value Object | Unified check result from single LLM call | Backend (SecondAI) |
| `ModelTierConfig` | Value Object | Model tier mapping for evaluation metrics | Backend (Evaluation) |
| `UnifiedCheckService` | Service | Execute unified Second AI check | Backend (SecondAI) - NEW |
| `ModelTierSelector` | Service | Select model based on metric complexity | Backend (Evaluation) - NEW |

---

## Entity Definitions

### 1. SecondAICheckResult

**Purpose**: Represents the structured response from a unified Second AI check that combines Fact, Policy, and Personality checks in a single LLM call.

**Type**: Value Object (immutable)

**Properties**:

```php
namespace App\Services\SecondAI;

readonly class SecondAICheckResult
{
    public function __construct(
        /** @var bool Overall pass status (false if any check requires modification) */
        public bool $passed,

        /** @var array<string, array> Modifications from each check type */
        public array $modifications,

        /** @var string Final response after applying all modifications */
        public string $finalResponse,

        /** @var array Metadata about the check execution */
        public array $metadata = [],
    ) {}

    /**
     * Create from unified LLM JSON response
     */
    public static function fromJson(array $json): self
    {
        return new self(
            passed: $json['passed'] ?? true,
            modifications: $json['modifications'] ?? [],
            finalResponse: $json['final_response'] ?? '',
            metadata: [
                'timestamp' => now(),
                'model_used' => $json['model_used'] ?? 'unknown',
                'latency_ms' => $json['latency_ms'] ?? 0,
            ],
        );
    }

    /**
     * Check if specific check type was applied
     */
    public function wasApplied(string $checkType): bool
    {
        return isset($this->modifications[$checkType])
            && ($this->modifications[$checkType]['required'] ?? false);
    }

    /**
     * Get all applied check types
     */
    public function getAppliedChecks(): array
    {
        return array_filter(
            array_keys($this->modifications),
            fn($type) => $this->wasApplied($type)
        );
    }

    /**
     * Convert to legacy format for backward compatibility
     */
    public function toLegacyFormat(): array
    {
        return [
            'content' => $this->finalResponse,
            'second_ai_applied' => !$this->passed,
            'second_ai' => [
                'checks_applied' => $this->getAppliedChecks(),
                'modifications' => $this->modifications,
                'elapsed_ms' => $this->metadata['latency_ms'] ?? 0,
            ],
        ];
    }
}
```

**Relationships**:
- Used by: `UnifiedCheckService` (producer)
- Consumed by: `SecondAIService` (orchestrator)
- Compatible with: Existing `CheckResult` class (for fallback mode)

**Validation Rules**:
- `passed` must be boolean
- `modifications` must contain valid check types: `fact_check`, `policy`, `personality`
- `finalResponse` must not be empty string
- Each modification entry must have `required` boolean field

**Example**:

```php
// Success case (no modifications needed)
$result = new SecondAICheckResult(
    passed: true,
    modifications: [
        'fact_check' => ['required' => false, 'claims_extracted' => [], 'unverified_claims' => []],
        'policy' => ['required' => false, 'violations' => []],
        'personality' => ['required' => false, 'issues' => []],
    ],
    finalResponse: 'Original response unchanged',
    metadata: ['timestamp' => now(), 'model_used' => 'claude-3.5-sonnet', 'latency_ms' => 1234],
);

// Modification case (response improved)
$result = new SecondAICheckResult(
    passed: false,
    modifications: [
        'fact_check' => [
            'required' => true,
            'claims_extracted' => ['Claim 1', 'Claim 2'],
            'unverified_claims' => ['Claim 2'],
            'rewritten' => 'Response without Claim 2',
        ],
        'policy' => ['required' => false, 'violations' => []],
        'personality' => [
            'required' => true,
            'issues' => ['Too casual tone'],
            'rewritten' => 'More formal response',
        ],
    ],
    finalResponse: 'Final improved response',
    metadata: ['timestamp' => now(), 'model_used' => 'claude-3.5-sonnet', 'latency_ms' => 1456],
);
```

---

### 2. ModelTierConfig

**Purpose**: Defines model tier (budget/standard/premium) and actual model to use for each evaluation metric.

**Type**: Value Object (immutable)

**Properties**:

```php
namespace App\Services\Evaluation;

readonly class ModelTierConfig
{
    /** Model tier levels */
    public const TIER_BUDGET = 'budget';
    public const TIER_STANDARD = 'standard';
    public const TIER_PREMIUM = 'premium';

    /** Metric complexity mappings */
    private const METRIC_TIER_MAP = [
        'answer_relevancy' => self::TIER_BUDGET,
        'task_completion' => self::TIER_STANDARD,
        'faithfulness' => self::TIER_PREMIUM,
        'context_precision' => self::TIER_PREMIUM,
        'role_adherence' => self::TIER_STANDARD,
    ];

    /** Model selections per tier */
    private const TIER_MODEL_MAP = [
        self::TIER_BUDGET => [
            'primary' => 'google/gemini-flash-1.5-8b-free',
            'fallback' => 'openai/gpt-4o-mini',
        ],
        self::TIER_STANDARD => [
            'primary' => 'openai/gpt-4o-mini',
            'fallback' => 'anthropic/claude-3.5-sonnet',
        ],
        self::TIER_PREMIUM => [
            'primary' => 'anthropic/claude-3.5-sonnet',
            'fallback' => null,
        ],
    ];

    public function __construct(
        /** @var string Metric name (e.g., 'answer_relevancy') */
        public string $metricName,

        /** @var string Assigned tier (budget/standard/premium) */
        public string $tier,

        /** @var string Actual model ID to use */
        public string $modelId,

        /** @var string|null Fallback model if primary fails */
        public ?string $fallbackModelId = null,
    ) {}

    /**
     * Create config for a specific metric
     */
    public static function forMetric(string $metricName): self
    {
        $tier = self::METRIC_TIER_MAP[$metricName] ?? self::TIER_PREMIUM;
        $models = self::TIER_MODEL_MAP[$tier];

        return new self(
            metricName: $metricName,
            tier: $tier,
            modelId: $models['primary'],
            fallbackModelId: $models['fallback'] ?? null,
        );
    }

    /**
     * Check if this is a budget tier
     */
    public function isBudgetTier(): bool
    {
        return $this->tier === self::TIER_BUDGET;
    }

    /**
     * Check if this is a premium tier
     */
    public function isPremiumTier(): bool
    {
        return $this->tier === self::TIER_PREMIUM;
    }

    /**
     * Get estimated cost per 1M tokens (input + output)
     */
    public function getEstimatedCost(): float
    {
        return match ($this->tier) {
            self::TIER_BUDGET => 0.0,      // Gemini Flash free tier
            self::TIER_STANDARD => 0.75,   // GPT-4o-mini average
            self::TIER_PREMIUM => 9.0,     // Claude 3.5 Sonnet average
            default => 9.0,
        };
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'metric_name' => $this->metricName,
            'tier' => $this->tier,
            'model_id' => $this->modelId,
            'fallback_model_id' => $this->fallbackModelId,
            'estimated_cost' => $this->getEstimatedCost(),
        ];
    }
}
```

**Relationships**:
- Used by: `ModelTierSelector` (producer)
- Consumed by: `LLMJudgeService` (consumer)
- Affects: Cost calculation in evaluation reports

**Validation Rules**:
- `tier` must be one of: `budget`, `standard`, `premium`
- `modelId` must be valid OpenRouter model ID
- `metricName` should match known evaluation metrics

**Example**:

```php
// Budget tier for simple metric
$config = ModelTierConfig::forMetric('answer_relevancy');
// → tier='budget', modelId='google/gemini-flash-1.5-8b-free', cost=0.0

// Premium tier for complex metric
$config = ModelTierConfig::forMetric('faithfulness');
// → tier='premium', modelId='anthropic/claude-3.5-sonnet', cost=9.0

// Standard tier for moderate metric
$config = ModelTierConfig::forMetric('role_adherence');
// → tier='standard', modelId='openai/gpt-4o-mini', cost=0.75
```

---

## Service Definitions

### 3. UnifiedCheckService

**Purpose**: Execute unified Second AI check using single LLM call that combines Fact, Policy, and Personality checks.

**Type**: Service (stateless)

**Key Methods**:

```php
namespace App\Services\SecondAI;

class UnifiedCheckService
{
    public function __construct(
        protected OpenRouterService $openRouter,
        protected RAGService $ragService,
    ) {}

    /**
     * Execute unified check for all enabled options
     *
     * @param string $response Original AI response to check
     * @param Flow $flow Flow with second_ai_options configuration
     * @param string $userMessage Original user message for context
     * @param string|null $apiKey Optional API key override
     * @return SecondAICheckResult Structured check result
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     */
    public function check(
        string $response,
        Flow $flow,
        string $userMessage,
        ?string $apiKey = null
    ): SecondAICheckResult;

    /**
     * Build unified prompt combining all enabled checks
     */
    protected function buildUnifiedPrompt(
        string $response,
        Flow $flow,
        string $userMessage,
        ?array $kbContext = null
    ): string;

    /**
     * Parse and validate LLM JSON response
     */
    protected function parseResponse(string $rawResponse): array;
}
```

**Responsibilities**:
- Build unified prompt with all enabled check types
- Fetch Knowledge Base context (if fact_check enabled)
- Call LLM with structured prompt
- Parse and validate JSON response
- Create `SecondAICheckResult` object
- Handle errors and invalid responses

**Dependencies**:
- `OpenRouterService`: LLM API calls
- `RAGService`: Knowledge Base retrieval (for fact checking)
- `Flow` model: Second AI configuration

---

### 4. ModelTierSelector

**Purpose**: Select appropriate model tier and model ID based on evaluation metric complexity.

**Type**: Service (stateless)

**Key Methods**:

```php
namespace App\Services\Evaluation;

class ModelTierSelector
{
    /**
     * Select model configuration for a specific metric
     *
     * @param string $metricName Metric to evaluate (e.g., 'answer_relevancy')
     * @return ModelTierConfig Model tier configuration
     */
    public function selectForMetric(string $metricName): ModelTierConfig;

    /**
     * Get fallback model if primary fails
     *
     * @param string $metricName Metric name
     * @return string|null Fallback model ID or null if none available
     */
    public function getFallbackModel(string $metricName): ?string;

    /**
     * Calculate total estimated cost for an evaluation
     *
     * @param array $metrics List of metric names to evaluate
     * @param int $testCaseCount Number of test cases
     * @return float Total estimated cost in USD
     */
    public function estimateTotalCost(array $metrics, int $testCaseCount): float;
}
```

**Responsibilities**:
- Map metric names to appropriate tiers
- Return model configuration with fallback options
- Estimate costs for evaluation planning
- Log tier selections for monitoring

**Dependencies**:
- `ModelTierConfig`: Value object for tier configuration
- No external services (pure logic)

---

## Database Schema

**Important**: No database schema changes required for this refactor.

**Existing schema** (remains unchanged):
- `flows` table already has `second_ai_enabled` and `second_ai_options` JSON columns
- `evaluations` table already stores model information in metadata
- No new tables needed

**Backward Compatibility**:
- ✅ Existing `second_ai_options` format: `{"fact_check": true, "policy": true, "personality": false}`
- ✅ Existing evaluation metadata: `{"model_used": "claude-3.5-sonnet", "metrics": [...]}`
- ✅ Response format for frontend: Same structure as before

---

## Data Flow Diagrams

### Second AI Unified Check Flow

```
User Message → Primary AI → Original Response
                                    ↓
                            SecondAIService
                                    ↓
                    [Check: unified mode enabled?]
                        /                    \
                    YES                      NO
                     ↓                        ↓
            UnifiedCheckService      Sequential Checks
                     ↓                   (existing logic)
            [Build unified prompt]
                     ↓
            [Fetch KB context if needed]
                     ↓
            [Single LLM call]
                     ↓
            [Parse JSON response]
                     ↓
            SecondAICheckResult ←────┘
                     ↓
            [Apply modifications]
                     ↓
            Final Response → User
```

### Evaluation Model Selection Flow

```
Start Evaluation → LLMJudgeService
                          ↓
              [For each metric to evaluate]
                          ↓
                  ModelTierSelector
                          ↓
              ModelTierConfig (tier + model)
                          ↓
              [Try primary model]
                    /         \
               Success      Failure
                 ↓              ↓
              Score      [Try fallback model]
                              /         \
                         Success      Failure
                           ↓              ↓
                        Score      [Log error, skip]
                          ↓
              [Store score + model used]
                          ↓
              Continue to next metric
```

---

## Migration Path

**Phase 0** (Current state):
- Sequential Second AI checks (3-6 API calls)
- Single premium model for all evaluations

**Phase 1** (After refactor):
- Unified Second AI check (1 API call) as default
- Sequential checks as fallback
- Model tier system for evaluations

**Rollback strategy**:
- Disable unified mode via config: `SECOND_AI_USE_UNIFIED=false`
- Disable model tiers via config: `EVALUATION_USE_TIERS=false`
- Both features have fallback mechanisms built-in

**Testing strategy**:
- Unit tests: `SecondAICheckResult`, `ModelTierConfig` value objects
- Integration tests: Unified check flow, fallback scenarios
- A/B testing: Compare unified vs sequential response quality
- Cost monitoring: Track actual cost savings
