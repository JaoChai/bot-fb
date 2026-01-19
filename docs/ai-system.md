# AI Evaluation System

Multi-tier AI evaluation system for cost optimization.

---

## Overview

The AI evaluation system uses tiered models to balance cost and quality:
- **57% cost reduction** compared to single-model approach
- Automatic tier selection based on task complexity
- Fallback chains for reliability

---

## Model Tiers

| Tier | Use Case | Models | Cost |
|------|----------|--------|------|
| Budget | Simple tasks, formatting | Haiku, Gemini Flash | $ |
| Standard | General tasks | Sonnet, GPT-4o-mini | $$ |
| Premium | Complex reasoning | Opus, GPT-4o, Gemini Pro | $$$ |

---

## Key Services

### ModelTierSelector
Selects appropriate model tier based on:
- Task complexity score
- Required capabilities (vision, long context)
- Budget constraints
- Historical performance

```php
$tier = $modelTierSelector->selectTier($task);
// Returns: 'budget', 'standard', or 'premium'
```

### LLMJudgeService
Evaluates AI responses with fallback:
1. Try primary judge model
2. Fallback to secondary if fails
3. Return structured evaluation

```php
$evaluation = $llmJudge->evaluate($response, $criteria);
// Returns: score, feedback, suggestions
```

### UnifiedCheckService
Combined Second AI checks:
- Quality check
- Safety check
- Relevance check
- All in single API call

```php
$result = $unifiedCheck->check($response);
// Returns: passed, issues[], suggestions[]
```

---

## Configuration

### config/llm-models.php
```php
return [
    'tiers' => [
        'budget' => ['haiku', 'gemini-flash'],
        'standard' => ['sonnet', 'gpt-4o-mini'],
        'premium' => ['opus', 'gpt-4o'],
    ],
    'pricing' => [
        'haiku' => ['input' => 0.25, 'output' => 1.25],
        // ... per million tokens
    ],
];
```

---

## Testing

```bash
# Test unified mode
php artisan test:unified-mode

# Test model tier selection
php artisan test:model-tiers --test-cases=40

# Benchmark cost savings
php artisan benchmark:ai-cost
```

---

## Metrics

| Metric | Target | Current |
|--------|--------|---------|
| Cost per 1K requests | < $5 | $4.30 |
| Average latency | < 1.5s | 1.2s |
| Quality score | > 85% | 88% |
| Fallback rate | < 5% | 2.3% |
