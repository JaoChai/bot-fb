---
id: test-002-metrics
title: Prompt Quality Metrics
impact: MEDIUM
impactDescription: "Define and track metrics to measure prompt effectiveness"
category: test
tags: [metrics, quality, measurement, analytics]
relatedRules: [test-001-ab-testing, test-003-versioning]
---

## Why This Matters

Without metrics:
- No way to know if prompts are working
- Can't detect degradation
- No data for optimization
- Blind to user experience

## The Problem

Common measurement failures:
- Only measuring response time
- Ignoring user satisfaction
- Not tracking conversation outcomes
- Missing cost metrics
- No baseline comparisons

## Solution

### Metrics Framework

```php
// PromptMetrics.php
class PromptMetrics
{
    // Performance Metrics
    const RESPONSE_TIME = 'response_time_ms';
    const TOKENS_INPUT = 'tokens_input';
    const TOKENS_OUTPUT = 'tokens_output';
    const TOKENS_TOTAL = 'tokens_total';
    const COST_USD = 'cost_usd';

    // Quality Metrics
    const USER_RATING = 'user_rating';           // 1-5 stars
    const HELPFUL_RATE = 'helpful_rate';         // % marked helpful
    const RESOLUTION_RATE = 'resolution_rate';   // % conversations resolved
    const ESCALATION_RATE = 'escalation_rate';   // % escalated to human
    const FOLLOW_UP_RATE = 'follow_up_rate';     // % needing clarification

    // Engagement Metrics
    const CONVERSATION_LENGTH = 'conversation_length';  // # messages
    const RETURN_RATE = 'return_rate';                  // % users who return
    const ABANDONMENT_RATE = 'abandonment_rate';        // % who leave mid-convo

    // Safety Metrics
    const INJECTION_ATTEMPTS = 'injection_attempts';
    const GUARDRAIL_TRIGGERS = 'guardrail_triggers';
    const FILTERED_RESPONSES = 'filtered_responses';
}
```

### Metrics Collection Service

```php
// MetricsCollectionService.php
class MetricsCollectionService
{
    public function recordConversationMetrics(Conversation $conversation): void
    {
        $messages = $conversation->messages;

        // Performance
        $this->record($conversation, PromptMetrics::TOKENS_TOTAL, $messages->sum('tokens'));
        $this->record($conversation, PromptMetrics::RESPONSE_TIME, $this->avgResponseTime($messages));

        // Quality
        if ($conversation->user_rating) {
            $this->record($conversation, PromptMetrics::USER_RATING, $conversation->user_rating);
        }

        // Engagement
        $this->record($conversation, PromptMetrics::CONVERSATION_LENGTH, $messages->count());

        // Calculate resolution
        if ($conversation->status === 'resolved') {
            $this->record($conversation, PromptMetrics::RESOLUTION_RATE, 1);
        } elseif ($conversation->escalated_at) {
            $this->record($conversation, PromptMetrics::ESCALATION_RATE, 1);
        }
    }

    public function recordMessageMetrics(Message $message, array $response): void
    {
        ConversationMetric::create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'metrics' => [
                'response_time_ms' => $response['response_time_ms'] ?? null,
                'tokens_input' => $response['usage']['prompt_tokens'] ?? null,
                'tokens_output' => $response['usage']['completion_tokens'] ?? null,
                'model' => $response['model'] ?? null,
            ],
        ]);
    }

    private function avgResponseTime(Collection $messages): float
    {
        $assistantMessages = $messages->where('role', 'assistant');

        if ($assistantMessages->isEmpty()) {
            return 0;
        }

        return $assistantMessages->avg('response_time_ms') ?? 0;
    }
}
```

### Metrics Dashboard Queries

```php
// MetricsQueryService.php
class MetricsQueryService
{
    public function getBotMetrics(Bot $bot, Carbon $from, Carbon $to): array
    {
        $conversations = Conversation::where('bot_id', $bot->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        return [
            // Volume
            'total_conversations' => $conversations->count(),
            'total_messages' => $conversations->sum(fn($c) => $c->messages->count()),

            // Performance
            'avg_response_time_ms' => $this->avgMetric($conversations, 'response_time_ms'),
            'avg_tokens_per_conversation' => $this->avgMetric($conversations, 'tokens_total'),
            'total_cost_usd' => $this->sumMetric($conversations, 'cost_usd'),

            // Quality
            'avg_user_rating' => $this->avgRating($conversations),
            'resolution_rate' => $this->resolutionRate($conversations),
            'escalation_rate' => $this->escalationRate($conversations),

            // Safety
            'injection_attempts' => $this->sumMetric($conversations, 'injection_attempts'),
            'guardrail_triggers' => $this->sumMetric($conversations, 'guardrail_triggers'),
        ];
    }

    public function getMetricsTrend(Bot $bot, string $metric, int $days = 30): array
    {
        return DB::table('conversation_metrics')
            ->join('conversations', 'conversations.id', '=', 'conversation_metrics.conversation_id')
            ->where('conversations.bot_id', $bot->id)
            ->where('conversation_metrics.created_at', '>=', now()->subDays($days))
            ->selectRaw("
                DATE(conversation_metrics.created_at) as date,
                AVG(JSON_EXTRACT(metrics, '$.{$metric}')) as value
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function resolutionRate(Collection $conversations): float
    {
        $total = $conversations->count();
        if ($total === 0) return 0;

        $resolved = $conversations->where('status', 'resolved')->count();
        return round($resolved / $total * 100, 2);
    }

    private function escalationRate(Collection $conversations): float
    {
        $total = $conversations->count();
        if ($total === 0) return 0;

        $escalated = $conversations->whereNotNull('escalated_at')->count();
        return round($escalated / $total * 100, 2);
    }
}
```

### Automated Quality Scoring

```php
// QualityScorer.php
class QualityScorer
{
    public function scoreResponse(string $query, string $response): array
    {
        return [
            'relevance' => $this->scoreRelevance($query, $response),
            'completeness' => $this->scoreCompleteness($response),
            'clarity' => $this->scoreClarity($response),
            'helpfulness' => $this->scoreHelpfulness($query, $response),
            'overall' => null, // Calculated from above
        ];
    }

    private function scoreRelevance(string $query, string $response): float
    {
        // Use embeddings to measure semantic similarity
        $queryEmbedding = $this->embeddingService->embed($query);
        $responseEmbedding = $this->embeddingService->embed($response);

        return $this->cosineSimilarity($queryEmbedding, $responseEmbedding);
    }

    private function scoreCompleteness(string $response): float
    {
        // Check if response seems complete
        $indicators = [
            'has_conclusion' => preg_match('/[.!?]$/', trim($response)),
            'adequate_length' => strlen($response) > 50,
            'has_structure' => preg_match('/[\n•\-\d\.]/', $response),
        ];

        return array_sum($indicators) / count($indicators);
    }

    private function scoreClarity(string $response): float
    {
        // Simple readability heuristics
        $sentences = preg_split('/[.!?]+/', $response);
        $avgSentenceLength = collect($sentences)
            ->map(fn($s) => str_word_count($s))
            ->avg();

        // Penalize very long sentences
        if ($avgSentenceLength > 30) {
            return 0.5;
        }
        if ($avgSentenceLength > 20) {
            return 0.7;
        }
        return 1.0;
    }
}
```

## Key Metrics to Track

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Response Time | < 2s | > 5s |
| Resolution Rate | > 70% | < 50% |
| User Rating | > 4.0 | < 3.5 |
| Escalation Rate | < 15% | > 25% |
| Cost per Conversation | < $0.05 | > $0.10 |
| Injection Attempts | Monitor | Spike > 10x |

## Testing

```php
public function test_metrics_are_recorded_correctly(): void
{
    $conversation = Conversation::factory()->create();
    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'tokens' => 100,
    ]);

    $service = new MetricsCollectionService();
    $service->recordConversationMetrics($conversation);

    $metric = ConversationMetric::where('conversation_id', $conversation->id)
        ->where('metric', PromptMetrics::TOKENS_TOTAL)
        ->first();

    $this->assertEquals(500, $metric->value);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Metrics stored in conversation_metrics table
- Dashboard in admin panel
- Daily email summary for bot owners
- Alerts via Sentry for anomalies
