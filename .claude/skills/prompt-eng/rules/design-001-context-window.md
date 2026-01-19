---
id: design-001-context-window
title: Context Window Management
impact: HIGH
impactDescription: "Optimize context window usage for better responses and lower costs"
category: design
tags: [context, tokens, optimization, rag]
relatedRules: [design-005-prompt-structure, pattern-002-few-shot]
---

## Why This Matters

Context window is limited and expensive. Poorly managed context leads to:
- Truncated conversations (lost information)
- High token costs
- Slow response times
- Irrelevant responses when important context is cut

## The Problem

What happens without proper context management:
- Including entire conversation history (hits limit)
- Stuffing all knowledge base results
- Redundant information repeated
- Important context pushed out by fluff

## Solution

### Before (Poor Context Management)

```php
public function buildContext(Conversation $conversation): string
{
    // ❌ Include ALL messages
    $messages = $conversation->messages()->get();

    // ❌ Include ALL knowledge base results
    $knowledge = $this->search($query, limit: 100);

    // ❌ No structure
    return "History:\n" . $messages->pluck('content')->join("\n")
        . "\n\nKnowledge:\n" . $knowledge->pluck('content')->join("\n");
}
```

### After (Optimized Context)

```php
public function buildContext(Conversation $conversation, string $query): array
{
    // ✅ Token budget allocation
    $totalBudget = 8000; // Reserve from model's context window
    $systemBudget = 500;
    $knowledgeBudget = 3000;
    $historyBudget = 2500;
    $queryBudget = 500;
    $responseBudget = 1500;

    // ✅ Prioritized knowledge retrieval
    $knowledge = $this->searchKnowledge($query, [
        'limit' => 5,
        'maxTokens' => $knowledgeBudget,
        'rerank' => true, // Most relevant first
    ]);

    // ✅ Smart conversation history
    $history = $this->buildHistory($conversation, $historyBudget, [
        'strategy' => 'sliding_window', // Recent messages
        'preserveFirst' => true, // Keep first message for context
        'summarize' => true, // Summarize old messages
    ]);

    return [
        'system' => $this->buildSystemPrompt($systemBudget),
        'context' => $knowledge,
        'history' => $history,
        'query' => $this->truncate($query, $queryBudget),
    ];
}

private function buildHistory(
    Conversation $conversation,
    int $tokenBudget,
    array $options = []
): array {
    $messages = $conversation->messages()
        ->orderBy('created_at', 'desc')
        ->limit(20) // Max messages to consider
        ->get()
        ->reverse();

    $result = [];
    $usedTokens = 0;

    // Always include first message if option set
    if ($options['preserveFirst'] && $messages->isNotEmpty()) {
        $first = $messages->first();
        $tokens = $this->countTokens($first->content);
        $result[] = [
            'role' => $first->role,
            'content' => $first->content,
        ];
        $usedTokens += $tokens;
        $messages = $messages->skip(1);
    }

    // Add recent messages within budget
    foreach ($messages->reverse() as $message) {
        $tokens = $this->countTokens($message->content);

        if ($usedTokens + $tokens > $tokenBudget) {
            break;
        }

        array_unshift($result, [
            'role' => $message->role,
            'content' => $message->content,
        ]);
        $usedTokens += $tokens;
    }

    return $result;
}
```

### Key Improvements

1. **Token Budget Allocation**: Reserve tokens for each component
2. **Prioritized Retrieval**: Most relevant content first
3. **Sliding Window**: Keep recent + first messages
4. **Summarization**: Compress old conversation history

## Implementation

### Token Counting

```php
// In TokenService
public function countTokens(string $text): int
{
    // Rough estimate: ~4 characters per token for English
    // Adjust for Thai: ~2-3 characters per token
    return (int) ceil(strlen($text) / 3.5);
}

public function truncateToTokens(string $text, int $maxTokens): string
{
    $currentTokens = $this->countTokens($text);

    if ($currentTokens <= $maxTokens) {
        return $text;
    }

    // Binary search for optimal truncation point
    $ratio = $maxTokens / $currentTokens;
    $targetLength = (int) (strlen($text) * $ratio * 0.9); // 10% safety margin

    return mb_substr($text, 0, $targetLength) . '...';
}
```

### Context Window by Model

```php
// config/llm-models.php
return [
    'gpt-4o' => [
        'context_window' => 128000,
        'recommended_max' => 100000, // Leave room for response
    ],
    'gpt-4o-mini' => [
        'context_window' => 128000,
        'recommended_max' => 100000,
    ],
    'claude-3-sonnet' => [
        'context_window' => 200000,
        'recommended_max' => 180000,
    ],
    'claude-3-haiku' => [
        'context_window' => 200000,
        'recommended_max' => 180000,
    ],
];
```

## Testing

```php
public function test_context_fits_within_budget(): void
{
    $context = $this->service->buildContext($conversation, $query);

    $totalTokens = array_sum([
        $this->countTokens($context['system']),
        $this->countTokens(json_encode($context['context'])),
        $this->countTokens(json_encode($context['history'])),
        $this->countTokens($context['query']),
    ]);

    $this->assertLessThan(8000, $totalTokens);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Use RAGService for context building
- Default budget: 8K tokens for context
- Knowledge retrieval: max 5 chunks, reranked
- History: sliding window of 10-20 messages
