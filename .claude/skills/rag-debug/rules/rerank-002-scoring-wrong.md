---
id: rerank-002-scoring-wrong
title: Reranker Scoring Seems Wrong
impact: MEDIUM
impactDescription: "Less relevant results ranked higher than better matches"
category: rerank
tags: [reranker, scoring, relevance, quality]
relatedRules: [rerank-004-ordering-issues, search-002-wrong-results]
---

## Symptom

- Obviously relevant document ranked low
- Irrelevant documents get high scores
- Order doesn't match human judgment
- Reranker makes results worse

## Root Cause

1. Query-document format mismatch
2. Reranker model limitations
3. Very long documents truncated
4. Language-specific issues
5. Content type not suited for model

## Diagnosis

### Quick Check

```php
// Compare semantic vs rerank ordering
$semantic = $this->semanticSearch($query, $botId)
    ->map(fn($d, $i) => ['id' => $d->id, 'semantic_rank' => $i]);

$reranked = $this->reranker->rerankWithScores($query, $semantic)
    ->map(fn($d, $i) => ['id' => $d->id, 'rerank_rank' => $i, 'score' => $d->rerank_score]);

// Log order changes
Log::info('Ranking comparison', [
    'query' => $query,
    'semantic_top3' => $semantic->take(3)->pluck('id'),
    'reranked_top3' => $reranked->take(3)->pluck('id'),
    'rerank_scores' => $reranked->take(5)->pluck('score', 'id'),
]);
```

### Detailed Analysis

```php
// Manual evaluation
$testCases = [
    ['query' => 'What is the refund policy?', 'expected_top' => 123],
    ['query' => 'How to contact support?', 'expected_top' => 456],
];

foreach ($testCases as $case) {
    $results = $this->search($case['query'], $botId);
    $topId = $results->first()->id;

    Log::info('Relevance test', [
        'query' => $case['query'],
        'expected' => $case['expected_top'],
        'actual' => $topId,
        'match' => $topId === $case['expected_top'],
    ]);
}
```

## Solution

### Fix Steps

1. **Try different model**
```php
// config/rag.php
'reranker' => [
    'model' => 'jina-reranker-v2-base-multilingual', // Good for Thai
    // Alternative: 'jina-reranker-v1-base-en' for English only
],
```

2. **Truncate long documents**
```php
// Truncate before sending to reranker
$truncated = $documents->map(function ($doc) {
    $doc->content = mb_substr($doc->content, 0, 1000);
    return $doc;
});
```

3. **Adjust input format**
```php
// Format content for better reranking
private function formatForReranker(string $content): string
{
    // Remove excessive whitespace
    $content = preg_replace('/\s+/', ' ', $content);

    // Ensure reasonable length
    return mb_substr(trim($content), 0, 1000);
}
```

### Code Fix

```php
// Improved reranker with diagnostics
class JinaRerankerService
{
    public function rerankWithDiagnostics(string $query, Collection $documents): array
    {
        $originalOrder = $documents->pluck('id')->toArray();

        // Prepare documents
        $prepared = $documents->map(function ($doc) {
            return [
                'id' => $doc->id,
                'text' => $this->prepareText($doc->content),
            ];
        });

        // Call reranker
        $response = $this->callAPI($query, $prepared);

        // Build results with diagnostics
        $results = collect($response['results'])->map(function ($r, $idx) use ($documents) {
            $doc = $documents[$r['index']];
            return [
                'id' => $doc->id,
                'original_rank' => array_search($doc->id, $documents->pluck('id')->toArray()),
                'rerank_rank' => $idx,
                'score' => $r['relevance_score'],
                'rank_change' => array_search($doc->id, $documents->pluck('id')->toArray()) - $idx,
            ];
        });

        return [
            'results' => $results,
            'diagnostics' => [
                'original_top3' => array_slice($originalOrder, 0, 3),
                'reranked_top3' => $results->take(3)->pluck('id')->toArray(),
                'avg_rank_change' => $results->avg('rank_change'),
                'score_distribution' => [
                    'max' => $results->max('score'),
                    'min' => $results->min('score'),
                    'avg' => $results->avg('score'),
                ],
            ],
        ];
    }

    private function prepareText(string $content): string
    {
        // Clean content
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Truncate to reasonable length
        $maxLength = config('rag.reranker.max_doc_length', 1000);
        if (mb_strlen($content) > $maxLength) {
            $content = mb_substr($content, 0, $maxLength) . '...';
        }

        return $content;
    }
}

// Evaluation command
class EvaluateReranker extends Command
{
    protected $signature = 'rag:evaluate-reranker {--bot-id=}';

    public function handle(): int
    {
        $testCases = $this->loadTestCases();

        $results = [];
        foreach ($testCases as $case) {
            $searchResults = $this->searchService->search($case['query'], $this->option('bot-id'));

            $results[] = [
                'query' => $case['query'],
                'expected_in_top3' => in_array($case['expected_id'], $searchResults->take(3)->pluck('id')->toArray()),
                'actual_rank' => $searchResults->pluck('id')->search($case['expected_id']),
            ];
        }

        $accuracy = collect($results)->where('expected_in_top3', true)->count() / count($results);
        $this->info("Top-3 accuracy: " . number_format($accuracy * 100, 1) . "%");

        return 0;
    }
}
```

## Verification

```php
// Run evaluation
php artisan rag:evaluate-reranker --bot-id=1

// Expected output:
// Top-3 accuracy: 80%+ for good configuration

// Manual spot check
$query = "ขอคืนเงิน";  // Thai: refund request
$results = $this->search($query, $botId);
$this->assertStringContains('คืนเงิน', $results->first()->content);
```

## Prevention

- Build evaluation test suite
- Monitor reranker accuracy metrics
- Compare with/without reranker
- Test with multilingual queries
- Log ranking changes

## Project-Specific Notes

**BotFacebook Context:**
- Model: `jina-reranker-v2-base-multilingual` (Thai support)
- Max doc length: 1000 chars
- Evaluation: `php artisan rag:evaluate-reranker`
- Test cases in `tests/fixtures/rag_test_cases.json`
