---
id: test-001-ab-testing
title: A/B Testing Prompts
impact: HIGH
impactDescription: "Systematically compare prompt variations to optimize performance"
category: test
tags: [testing, ab-test, optimization, metrics]
relatedRules: [test-002-metrics, test-003-versioning]
---

## Why This Matters

Without A/B testing:
- Relying on intuition vs data
- No way to measure improvements
- Risk of regression
- Missing optimization opportunities

## The Problem

Common testing failures:
- No baseline measurement
- Testing too many variables at once
- Insufficient sample size
- Not measuring the right metrics
- Confirmation bias in evaluation

## Solution

### A/B Testing Framework

```php
// PromptExperiment.php
class PromptExperiment extends Model
{
    protected $fillable = [
        'name',
        'description',
        'bot_id',
        'control_prompt',
        'variant_prompt',
        'traffic_split', // 0.5 = 50/50
        'status', // draft, running, completed
        'started_at',
        'ended_at',
        'winner',
    ];

    protected $casts = [
        'traffic_split' => 'float',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(PromptExperimentResult::class);
    }

    public function getVariant(): string
    {
        // Consistent assignment based on conversation ID (no re-randomization)
        return random_int(1, 100) / 100 < $this->traffic_split
            ? 'control'
            : 'variant';
    }
}

// PromptExperimentResult.php
class PromptExperimentResult extends Model
{
    protected $fillable = [
        'experiment_id',
        'conversation_id',
        'variant', // 'control' or 'variant'
        'response_time_ms',
        'tokens_used',
        'user_rating', // 1-5 if collected
        'was_helpful', // boolean if collected
        'follow_up_needed', // did user ask clarifying question?
    ];
}
```

### Experiment Service

```php
// PromptExperimentService.php
class PromptExperimentService
{
    public function getPromptForConversation(
        Bot $bot,
        Conversation $conversation
    ): array {
        $experiment = PromptExperiment::where('bot_id', $bot->id)
            ->where('status', 'running')
            ->first();

        if (!$experiment) {
            return [
                'prompt' => $bot->settings->system_prompt,
                'experiment_id' => null,
                'variant' => null,
            ];
        }

        // Consistent variant assignment per conversation
        $variant = $this->getVariant($experiment, $conversation);

        return [
            'prompt' => $variant === 'control'
                ? $experiment->control_prompt
                : $experiment->variant_prompt,
            'experiment_id' => $experiment->id,
            'variant' => $variant,
        ];
    }

    private function getVariant(
        PromptExperiment $experiment,
        Conversation $conversation
    ): string {
        // Use conversation ID for consistent assignment
        $hash = crc32($conversation->id . $experiment->id);
        $normalized = ($hash % 100) / 100;

        return $normalized < $experiment->traffic_split
            ? 'control'
            : 'variant';
    }

    public function recordResult(
        int $experimentId,
        int $conversationId,
        string $variant,
        array $metrics
    ): void {
        PromptExperimentResult::create([
            'experiment_id' => $experimentId,
            'conversation_id' => $conversationId,
            'variant' => $variant,
            'response_time_ms' => $metrics['response_time_ms'] ?? null,
            'tokens_used' => $metrics['tokens_used'] ?? null,
            'user_rating' => $metrics['user_rating'] ?? null,
            'was_helpful' => $metrics['was_helpful'] ?? null,
            'follow_up_needed' => $metrics['follow_up_needed'] ?? null,
        ]);
    }

    public function analyzeExperiment(PromptExperiment $experiment): array
    {
        $results = $experiment->results()
            ->selectRaw("
                variant,
                COUNT(*) as count,
                AVG(response_time_ms) as avg_response_time,
                AVG(tokens_used) as avg_tokens,
                AVG(CASE WHEN user_rating IS NOT NULL THEN user_rating END) as avg_rating,
                SUM(CASE WHEN was_helpful THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as helpful_rate,
                SUM(CASE WHEN follow_up_needed THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as follow_up_rate
            ")
            ->groupBy('variant')
            ->get()
            ->keyBy('variant');

        $control = $results->get('control');
        $variant = $results->get('variant');

        return [
            'control' => $control,
            'variant' => $variant,
            'significance' => $this->calculateSignificance($control, $variant),
            'recommendation' => $this->getRecommendation($control, $variant),
        ];
    }

    private function calculateSignificance($control, $variant): array
    {
        // Simplified significance test
        // In production, use proper statistical library

        $minSampleSize = 100;

        if ($control->count < $minSampleSize || $variant->count < $minSampleSize) {
            return [
                'is_significant' => false,
                'reason' => 'Insufficient sample size',
                'needed' => $minSampleSize - min($control->count, $variant->count),
            ];
        }

        // Check if difference is > 5% for key metrics
        $ratingDiff = abs($variant->avg_rating - $control->avg_rating);
        $helpfulDiff = abs($variant->helpful_rate - $control->helpful_rate);

        return [
            'is_significant' => $ratingDiff > 0.2 || $helpfulDiff > 5,
            'rating_diff' => $ratingDiff,
            'helpful_diff' => $helpfulDiff,
        ];
    }

    private function getRecommendation($control, $variant): string
    {
        if ($variant->helpful_rate > $control->helpful_rate + 5) {
            return 'variant_wins';
        }
        if ($control->helpful_rate > $variant->helpful_rate + 5) {
            return 'control_wins';
        }
        return 'no_clear_winner';
    }
}
```

### Usage in RAGService

```php
// RAGService.php
public function processMessage(Message $message): array
{
    $experimentService = new PromptExperimentService();

    // Get prompt (may be from experiment)
    $promptData = $experimentService->getPromptForConversation(
        $message->conversation->bot,
        $message->conversation
    );

    $startTime = microtime(true);

    // Process with the prompt
    $response = $this->llmService->chat([
        ['role' => 'system', 'content' => $promptData['prompt']],
        ...$this->getHistory($message->conversation),
        ['role' => 'user', 'content' => $message->content],
    ]);

    $responseTime = (microtime(true) - $startTime) * 1000;

    // Record experiment result if in experiment
    if ($promptData['experiment_id']) {
        $experimentService->recordResult(
            experimentId: $promptData['experiment_id'],
            conversationId: $message->conversation_id,
            variant: $promptData['variant'],
            metrics: [
                'response_time_ms' => $responseTime,
                'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            ]
        );
    }

    return $response;
}
```

## Best Practices

### 1. Define Clear Hypothesis

```
Hypothesis: Adding specific examples to the system prompt will increase
user satisfaction by 10%.

Control: Current system prompt
Variant: System prompt + 3 specific examples

Metric: User rating (1-5 stars) after conversation
```

### 2. Test One Variable at a Time

```
❌ Bad: Change tone, add examples, modify constraints all at once
✅ Good: Test only adding examples, keep everything else the same
```

### 3. Sufficient Sample Size

```php
// Minimum samples before drawing conclusions
$minSamples = 100; // per variant
$recommendedSamples = 500; // for statistical significance
```

### 4. Run for Adequate Duration

```php
// Don't conclude too quickly
$minDuration = '3 days';
$recommendedDuration = '1 week';
```

## Testing

```php
public function test_variant_assignment_is_consistent(): void
{
    $experiment = PromptExperiment::factory()->create(['traffic_split' => 0.5]);
    $conversation = Conversation::factory()->create();
    $service = new PromptExperimentService();

    // Same conversation should get same variant
    $variant1 = $service->getVariant($experiment, $conversation);
    $variant2 = $service->getVariant($experiment, $conversation);

    $this->assertEquals($variant1, $variant2);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Experiments stored in prompt_experiments table
- Analysis dashboard in admin panel
- Minimum 100 conversations per variant
- Auto-stop experiments after 7 days
