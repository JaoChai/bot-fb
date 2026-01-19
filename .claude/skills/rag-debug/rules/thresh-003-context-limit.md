---
id: thresh-003-context-limit
title: Context Window and Token Limits
impact: MEDIUM
impactDescription: "Context truncated, AI misses important information"
category: thresh
tags: [threshold, context, tokens, limit, truncation]
relatedRules: [thresh-001-semantic-threshold, search-001-no-results]
---

## Symptom

- AI doesn't use information from later documents
- "I don't have information about X" despite relevant docs
- Response quality drops with many results
- Token limit errors from API

## Root Cause

1. Context window exceeded
2. Too many documents stuffed into prompt
3. Important info truncated
4. No prioritization of content
5. Token counting inaccurate

## Diagnosis

### Quick Check

```php
// Check context configuration
Log::info('Context limits', [
    'max_context_tokens' => config('rag.max_context_tokens'),
    'max_documents' => config('rag.max_documents'),
    'max_doc_length' => config('rag.max_doc_length'),
]);

// Measure actual context size
$context = $this->buildContext($query, $botId);
$tokens = $this->countTokens($context);

Log::info('Actual context', [
    'doc_count' => count($context['documents']),
    'total_chars' => mb_strlen(implode('', $context['documents'])),
    'estimated_tokens' => $tokens,
    'model_limit' => 128000,  // GPT-4 Turbo
]);
```

### Detailed Analysis

```php
// Analyze what gets included vs excluded
$allResults = $this->search($query, $botId, limit: 50);
$includedResults = $this->search($query, $botId, limit: config('rag.max_documents'));

$excluded = $allResults->filter(
    fn($r) => !$includedResults->contains('id', $r->id)
);

Log::info('Context exclusion analysis', [
    'total_found' => $allResults->count(),
    'included' => $includedResults->count(),
    'excluded' => $excluded->count(),
    'excluded_scores' => $excluded->pluck('similarity')->toArray(),
    'excluded_previews' => $excluded->map(fn($r) => mb_substr($r->content, 0, 50))->toArray(),
]);
```

## Solution

### Fix Steps

1. **Set appropriate limits**
```php
// config/rag.php
'max_context_tokens' => 4000,   // Reserve for system + user prompt
'max_documents' => 10,          // Quality over quantity
'max_doc_length' => 1000,       // Truncate long docs
```

2. **Prioritize by relevance**
```php
// Take best results, not most results
$results = $this->search($query, $botId)
    ->sortByDesc('final_score')
    ->take(config('rag.max_documents'));
```

3. **Smart truncation**
```php
// Truncate from middle, keep start and end
private function smartTruncate(string $text, int $maxLength): string
{
    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }

    $halfLength = (int) ($maxLength / 2) - 10;
    $start = mb_substr($text, 0, $halfLength);
    $end = mb_substr($text, -$halfLength);

    return $start . ' [...] ' . $end;
}
```

### Code Fix

```php
// Context builder with proper limits
class ContextBuilder
{
    private int $maxTokens;
    private int $maxDocuments;
    private int $maxDocLength;
    private int $reservedTokens = 2000;  // For system + user prompt

    public function __construct()
    {
        $this->maxTokens = config('rag.max_context_tokens', 4000);
        $this->maxDocuments = config('rag.max_documents', 10);
        $this->maxDocLength = config('rag.max_doc_length', 1000);
    }

    public function build(string $query, Collection $documents): array
    {
        $context = [];
        $totalTokens = 0;
        $included = 0;

        // Sort by relevance score
        $sorted = $documents->sortByDesc(function ($doc) {
            return $doc->final_score ?? $doc->rerank_score ?? $doc->similarity ?? 0;
        });

        foreach ($sorted as $doc) {
            // Check document limit
            if ($included >= $this->maxDocuments) {
                Log::debug('Context: Max documents reached', ['count' => $included]);
                break;
            }

            // Prepare document
            $content = $this->prepareDocument($doc);
            $docTokens = $this->estimateTokens($content);

            // Check token limit
            if ($totalTokens + $docTokens > $this->maxTokens) {
                // Try truncating
                $availableTokens = $this->maxTokens - $totalTokens;
                $truncated = $this->truncateToTokens($content, $availableTokens);

                if ($this->estimateTokens($truncated) < 100) {
                    // Not worth including
                    Log::debug('Context: Token limit reached', [
                        'total' => $totalTokens,
                        'limit' => $this->maxTokens,
                    ]);
                    break;
                }

                $content = $truncated;
                $docTokens = $this->estimateTokens($content);
            }

            $context[] = [
                'id' => $doc->id,
                'content' => $content,
                'score' => $doc->final_score ?? $doc->similarity ?? 0,
                'tokens' => $docTokens,
            ];

            $totalTokens += $docTokens;
            $included++;
        }

        Log::debug('Context built', [
            'documents' => $included,
            'total_tokens' => $totalTokens,
            'max_tokens' => $this->maxTokens,
            'utilization' => round($totalTokens / $this->maxTokens * 100) . '%',
        ]);

        return [
            'documents' => $context,
            'metadata' => [
                'total_tokens' => $totalTokens,
                'document_count' => $included,
                'truncated' => $sorted->count() - $included,
            ],
        ];
    }

    private function prepareDocument(object $doc): string
    {
        $content = $doc->content;

        // Clean up
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Truncate if too long
        if (mb_strlen($content) > $this->maxDocLength) {
            $content = $this->smartTruncate($content, $this->maxDocLength);
        }

        return $content;
    }

    private function smartTruncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        // Try to truncate at sentence boundary
        $truncated = mb_substr($text, 0, $maxLength);
        $lastPeriod = mb_strrpos($truncated, '.');
        $lastNewline = mb_strrpos($truncated, "\n");
        $lastBreak = max($lastPeriod, $lastNewline);

        if ($lastBreak > $maxLength * 0.7) {
            return mb_substr($text, 0, $lastBreak + 1) . '...';
        }

        // Otherwise truncate and add ellipsis
        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    private function truncateToTokens(string $text, int $maxTokens): string
    {
        $estimatedChars = $maxTokens * 4;  // Rough estimate
        return $this->smartTruncate($text, $estimatedChars);
    }

    /**
     * Estimate token count (rough approximation)
     * More accurate: use tiktoken library
     */
    private function estimateTokens(string $text): int
    {
        // Thai: ~1-2 chars per token
        // English: ~4 chars per token
        $thaiChars = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $text);
        $otherChars = mb_strlen($text) - $thaiChars;

        return (int) ceil($thaiChars / 1.5 + $otherChars / 4);
    }

    /**
     * Format context for prompt
     */
    public function formatForPrompt(array $context): string
    {
        $formatted = "## Reference Information\n\n";

        foreach ($context['documents'] as $idx => $doc) {
            $num = $idx + 1;
            $formatted .= "[{$num}] {$doc['content']}\n\n";
        }

        return $formatted;
    }
}

// Usage in RAG service
class RAGService
{
    public function query(string $query, int $botId): array
    {
        // 1. Search
        $documents = $this->search($query, $botId);

        // 2. Build context with limits
        $context = $this->contextBuilder->build($query, $documents);

        // 3. Check if we have context
        if (empty($context['documents'])) {
            return $this->noContextResponse();
        }

        // 4. Generate response
        $prompt = $this->contextBuilder->formatForPrompt($context);
        $response = $this->llm->generate($this->buildPrompt($query, $prompt));

        return [
            'response' => $response,
            'sources' => $context['documents'],
            'metadata' => $context['metadata'],
        ];
    }
}
```

## Verification

```php
// Test context limits
$builder = app(ContextBuilder::class);

// Create test documents
$docs = collect(range(1, 20))->map(fn($i) => (object)[
    'id' => $i,
    'content' => str_repeat('Test content for document. ', 100),
    'similarity' => 1 - ($i * 0.05),
]);

$context = $builder->build('test query', $docs);

// Verify limits
assert($context['metadata']['document_count'] <= config('rag.max_documents'));
assert($context['metadata']['total_tokens'] <= config('rag.max_context_tokens'));

// Verify ordering (highest score first)
$scores = collect($context['documents'])->pluck('score');
assert($scores->toArray() === $scores->sortDesc()->toArray(), 'Should be sorted by score');

Log::info('Context limit verification', [
    'input_docs' => $docs->count(),
    'included_docs' => $context['metadata']['document_count'],
    'total_tokens' => $context['metadata']['total_tokens'],
    'truncated' => $context['metadata']['truncated'],
]);
```

## Prevention

- Set conservative limits, increase gradually
- Monitor token usage per request
- Log truncation frequency
- Test with long documents
- Review "no context" response rates

## Project-Specific Notes

**BotFacebook Context:**
- Max context: 4000 tokens
- Max documents: 10
- Max doc length: 1000 chars
- Token estimation: Thai ~1.5 chars/token
- Model limits:
  - GPT-4 Turbo: 128K
  - GPT-4o: 128K
  - Claude 3: 200K
