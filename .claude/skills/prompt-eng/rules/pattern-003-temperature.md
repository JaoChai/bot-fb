---
id: pattern-003-temperature
title: Temperature & Model Parameters
impact: MEDIUM
impactDescription: "Tune model parameters for optimal response quality"
category: pattern
tags: [temperature, parameters, tuning, consistency]
relatedRules: [test-001-ab-testing, design-002-system-prompt]
---

## Why This Matters

Model parameters control:
- Response creativity vs consistency
- Output length
- Stop conditions
- Probability adjustments

Wrong parameters lead to:
- Too random/creative for factual queries
- Too rigid for creative tasks
- Wasted tokens
- Unpredictable quality

## The Problem

Using default parameters everywhere:
- Customer support needs consistency (low temp)
- Brainstorming needs creativity (high temp)
- One size doesn't fit all

## Solution

### Temperature Guidelines

| Temperature | Behavior | Best For |
|-------------|----------|----------|
| 0.0 - 0.3 | Very consistent, deterministic | Facts, data extraction, math |
| 0.3 - 0.6 | Balanced | Customer support, Q&A |
| 0.6 - 0.8 | More creative | Suggestions, alternatives |
| 0.8 - 1.0 | Highly creative | Brainstorming, storytelling |
| > 1.0 | Very random | Avoid for production |

### Parameter Configuration

```php
// LLMParameterService.php
class LLMParameterService
{
    private array $presets = [
        'factual' => [
            'temperature' => 0.2,
            'top_p' => 0.9,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'max_tokens' => 500,
        ],
        'customer_support' => [
            'temperature' => 0.4,
            'top_p' => 0.95,
            'frequency_penalty' => 0.2,
            'presence_penalty' => 0.1,
            'max_tokens' => 800,
        ],
        'conversational' => [
            'temperature' => 0.7,
            'top_p' => 1.0,
            'frequency_penalty' => 0.3,
            'presence_penalty' => 0.2,
            'max_tokens' => 1000,
        ],
        'creative' => [
            'temperature' => 0.9,
            'top_p' => 1.0,
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.5,
            'max_tokens' => 1500,
        ],
    ];

    public function getParameters(string $useCase): array
    {
        return $this->presets[$useCase] ?? $this->presets['customer_support'];
    }

    public function detectUseCase(string $query, string $systemPrompt): string
    {
        // Check for factual queries
        $factualPatterns = ['what is', 'how many', 'when did', 'price', 'ราคา', 'เท่าไหร่'];
        foreach ($factualPatterns as $pattern) {
            if (stripos($query, $pattern) !== false) {
                return 'factual';
            }
        }

        // Check for creative requests
        $creativePatterns = ['suggest', 'ideas', 'brainstorm', 'แนะนำ', 'ไอเดีย'];
        foreach ($creativePatterns as $pattern) {
            if (stripos($query, $pattern) !== false) {
                return 'creative';
            }
        }

        // Default to customer support
        return 'customer_support';
    }
}
```

### Parameter Explanations

```php
// Documentation for each parameter
$parameterDocs = [
    'temperature' => [
        'description' => 'Controls randomness. Lower = more focused, higher = more creative',
        'range' => '0.0 - 2.0',
        'default' => 1.0,
        'recommendation' => 'Use 0.2-0.5 for support, 0.7-1.0 for creative',
    ],
    'top_p' => [
        'description' => 'Nucleus sampling. Considers tokens with top_p probability mass',
        'range' => '0.0 - 1.0',
        'default' => 1.0,
        'recommendation' => 'Usually keep at 0.9-1.0, lower for more focused output',
    ],
    'frequency_penalty' => [
        'description' => 'Penalizes tokens that appear frequently',
        'range' => '-2.0 - 2.0',
        'default' => 0.0,
        'recommendation' => '0.2-0.5 to reduce repetition',
    ],
    'presence_penalty' => [
        'description' => 'Penalizes tokens that have appeared at all',
        'range' => '-2.0 - 2.0',
        'default' => 0.0,
        'recommendation' => '0.1-0.3 for more diverse topics',
    ],
    'max_tokens' => [
        'description' => 'Maximum tokens to generate',
        'range' => '1 - model_max',
        'default' => null,
        'recommendation' => 'Set based on expected response length + buffer',
    ],
];
```

### Implementing Dynamic Parameters

```php
// RAGService.php
public function processMessage(Message $message): array
{
    $paramService = new LLMParameterService();

    // Detect appropriate use case
    $useCase = $paramService->detectUseCase(
        $message->content,
        $message->conversation->bot->settings->system_prompt
    );

    // Get parameters for use case
    $params = $paramService->getParameters($useCase);

    // Allow bot-level overrides
    if ($message->conversation->bot->settings->temperature) {
        $params['temperature'] = $message->conversation->bot->settings->temperature;
    }

    // Call LLM with parameters
    $response = $this->llmService->chat(
        $this->buildMessages($message),
        $params
    );

    // Log parameters used (for analysis)
    $this->logParameters($message, $useCase, $params);

    return $response;
}
```

### Per-Bot Configuration

```php
// BotSettings.php
class BotSettings extends Model
{
    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'llm_parameters' => 'array', // Custom parameters
    ];

    public function getEffectiveParameters(): array
    {
        $defaults = config('llm.default_parameters');

        return array_merge($defaults, array_filter([
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            ...($this->llm_parameters ?? []),
        ]));
    }
}
```

### Model-Specific Considerations

```php
// ModelParameterService.php
class ModelParameterService
{
    // Different models have different optimal ranges
    private array $modelDefaults = [
        'gpt-4o' => [
            'temperature' => 0.5,
            'max_tokens' => 4096,
        ],
        'gpt-4o-mini' => [
            'temperature' => 0.5,
            'max_tokens' => 4096,
        ],
        'claude-3-sonnet' => [
            'temperature' => 0.5,
            'max_tokens' => 4096,
        ],
        'claude-3-haiku' => [
            'temperature' => 0.4, // Slightly lower for faster model
            'max_tokens' => 2048,
        ],
    ];

    public function getModelDefaults(string $model): array
    {
        // Match model name pattern
        foreach ($this->modelDefaults as $pattern => $defaults) {
            if (str_contains($model, $pattern)) {
                return $defaults;
            }
        }

        return [
            'temperature' => 0.5,
            'max_tokens' => 2048,
        ];
    }
}
```

## Testing Temperature Effects

```php
// TemperatureTestService.php
class TemperatureTestService
{
    public function testTemperatureVariation(string $prompt): array
    {
        $results = [];
        $temperatures = [0.0, 0.3, 0.5, 0.7, 1.0];

        foreach ($temperatures as $temp) {
            $responses = [];

            // Generate 3 responses at each temperature
            for ($i = 0; $i < 3; $i++) {
                $response = $this->llmService->chat(
                    [['role' => 'user', 'content' => $prompt]],
                    ['temperature' => $temp]
                );
                $responses[] = $response['content'];
            }

            $results[$temp] = [
                'responses' => $responses,
                'variance' => $this->calculateVariance($responses),
                'avg_length' => collect($responses)->avg(fn($r) => strlen($r)),
            ];
        }

        return $results;
    }

    private function calculateVariance(array $responses): float
    {
        // Simple measure: how different are the responses?
        $unique = array_unique($responses);
        return count($unique) / count($responses);
    }
}
```

## Best Practices

```
1. **Match Temperature to Task**
   - Factual questions: 0.2-0.4
   - Conversations: 0.5-0.7
   - Creative tasks: 0.8-1.0

2. **Don't Over-tune**
   - Start with presets
   - Only adjust if needed
   - A/B test changes

3. **Consider Trade-offs**
   - Lower temp = more consistent but may feel robotic
   - Higher temp = more natural but less predictable

4. **Max Tokens**
   - Set reasonable limits
   - Too high = waste if not used
   - Too low = truncated responses
```

## Testing

```php
public function test_factual_queries_use_low_temperature(): void
{
    $service = new LLMParameterService();

    $useCase = $service->detectUseCase('ราคาเท่าไหร่', '');
    $params = $service->getParameters($useCase);

    $this->assertLessThanOrEqual(0.5, $params['temperature']);
}

public function test_creative_queries_use_high_temperature(): void
{
    $service = new LLMParameterService();

    $useCase = $service->detectUseCase('แนะนำไอเดียหน่อย', '');
    $params = $service->getParameters($useCase);

    $this->assertGreaterThanOrEqual(0.7, $params['temperature']);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Default temperature: 0.5 for support bots
- Bot owners can customize in settings
- Logged for analysis and optimization
- A/B test temperature changes before deploying
