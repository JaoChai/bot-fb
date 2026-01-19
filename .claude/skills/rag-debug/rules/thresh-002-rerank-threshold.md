---
id: thresh-002-rerank-threshold
title: Reranker Threshold Configuration
impact: HIGH
impactDescription: "Reranker filtering good results or keeping bad ones"
category: thresh
tags: [threshold, reranker, jina, filtering]
relatedRules: [rerank-001-filter-too-much, thresh-001-semantic-threshold]
---

## Symptom

- Good semantic results removed after reranking
- Context has fewer chunks than expected
- Reranker returns empty despite input
- Inconsistent result counts

## Root Cause

1. Rerank threshold too high for content type
2. Threshold not calibrated for model
3. No fallback when all filtered
4. Different scale than semantic scores
5. Query-document mismatch scoring low

## Diagnosis

### Quick Check

```php
// Check rerank threshold
$threshold = config('rag.rerank_threshold');
Log::info('Rerank threshold', [
    'configured' => $threshold,
    'recommended' => '0.3-0.5 for jina-reranker-v2',
]);

// Test with sample query
$semanticResults = $this->semanticSearch($query, $botId);
$rerankScores = $this->reranker->rerankWithScores($query, $semanticResults);

$belowThreshold = $rerankScores->filter(fn($r) => $r->rerank_score < $threshold);

Log::info('Rerank threshold analysis', [
    'input_count' => $semanticResults->count(),
    'below_threshold' => $belowThreshold->count(),
    'would_be_filtered' => $belowThreshold->pluck('id')->toArray(),
    'scores' => $rerankScores->pluck('rerank_score', 'id')->toArray(),
]);
```

### Detailed Analysis

```php
// Compare semantic vs rerank ordering
$query = "นโยบายคืนเงิน";
$semantic = $this->semanticSearch($query, $botId);
$reranked = $this->reranker->rerankWithScores($query, $semantic);

foreach ($reranked as $idx => $doc) {
    $semanticRank = $semantic->pluck('id')->search($doc->id);
    Log::info('Rerank comparison', [
        'doc_id' => $doc->id,
        'semantic_rank' => $semanticRank,
        'rerank_rank' => $idx,
        'semantic_score' => $semantic->firstWhere('id', $doc->id)?->similarity,
        'rerank_score' => $doc->rerank_score,
        'rank_change' => $semanticRank - $idx,
    ]);
}
```

## Solution

### Fix Steps

1. **Set appropriate threshold**
```php
// config/rag.php
'rerank_threshold' => 0.3,  // Lower for Jina v2

// Different models have different scales:
// jina-reranker-v2: 0.3-0.5
// jina-reranker-v1: 0.2-0.4
// cohere-rerank: 0.5-0.7
```

2. **Add minimum results guarantee**
```php
'reranker' => [
    'threshold' => 0.3,
    'min_results' => 3,  // Always return at least 3
    'max_results' => 10,
],
```

3. **Use percentile-based threshold**
```php
// Instead of absolute threshold, use relative
private function percentileThreshold(Collection $results, float $percentile = 0.7): float
{
    $scores = $results->pluck('rerank_score')->sort()->values();
    $index = (int) floor(count($scores) * $percentile);
    return $scores[$index] ?? 0;
}
```

### Code Fix

```php
// Robust reranker with threshold handling
class JinaRerankerService
{
    private float $threshold;
    private int $minResults;
    private int $maxResults;

    public function __construct()
    {
        $config = config('rag.reranker');
        $this->threshold = $config['threshold'] ?? 0.3;
        $this->minResults = $config['min_results'] ?? 3;
        $this->maxResults = $config['max_results'] ?? 10;
    }

    public function rerank(string $query, Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }

        // Call API and get scores
        $scored = $this->callJinaAPI($query, $documents);

        // Log score distribution
        $this->logScoreDistribution($query, $scored);

        // Apply threshold with fallback
        $filtered = $this->applyThresholdWithFallback($scored);

        return $filtered->take($this->maxResults);
    }

    private function applyThresholdWithFallback(Collection $scored): Collection
    {
        // Sort by score descending
        $sorted = $scored->sortByDesc('rerank_score')->values();

        // Filter by threshold
        $filtered = $sorted->filter(fn($doc) => $doc->rerank_score >= $this->threshold);

        // Check if we have enough results
        if ($filtered->count() < $this->minResults) {
            $needed = $this->minResults - $filtered->count();

            Log::warning('Rerank threshold too strict', [
                'threshold' => $this->threshold,
                'passed' => $filtered->count(),
                'adding_fallback' => $needed,
                'scores' => $sorted->take(5)->pluck('rerank_score')->toArray(),
            ]);

            // Add top N that didn't pass threshold
            $belowThreshold = $sorted->filter(
                fn($doc) => $doc->rerank_score < $this->threshold
            )->take($needed);

            $filtered = $filtered->concat($belowThreshold);
        }

        return $filtered->sortByDesc('rerank_score')->values();
    }

    private function logScoreDistribution(string $query, Collection $scored): void
    {
        $scores = $scored->pluck('rerank_score');

        Log::debug('Rerank score distribution', [
            'query' => mb_substr($query, 0, 50),
            'count' => $scored->count(),
            'max' => round($scores->max(), 3),
            'min' => round($scores->min(), 3),
            'avg' => round($scores->avg(), 3),
            'above_threshold' => $scores->filter(fn($s) => $s >= $this->threshold)->count(),
            'threshold' => $this->threshold,
        ]);
    }

    /**
     * Rerank with automatic threshold adjustment
     */
    public function rerankAdaptive(string $query, Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }

        $scored = $this->callJinaAPI($query, $documents);
        $sorted = $scored->sortByDesc('rerank_score')->values();

        // Calculate adaptive threshold
        $adaptiveThreshold = $this->calculateAdaptiveThreshold($sorted);

        Log::debug('Adaptive rerank threshold', [
            'base_threshold' => $this->threshold,
            'adaptive_threshold' => $adaptiveThreshold,
        ]);

        // Apply adaptive threshold
        $filtered = $sorted->filter(fn($doc) => $doc->rerank_score >= $adaptiveThreshold);

        // Still ensure minimum
        if ($filtered->count() < $this->minResults) {
            $filtered = $sorted->take($this->minResults);
        }

        return $filtered->take($this->maxResults);
    }

    private function calculateAdaptiveThreshold(Collection $sorted): float
    {
        if ($sorted->isEmpty()) {
            return $this->threshold;
        }

        $topScore = $sorted->first()->rerank_score;
        $avgScore = $sorted->avg('rerank_score');

        // If top score is high, be stricter
        if ($topScore > 0.7) {
            return max($topScore * 0.6, $this->threshold);
        }

        // If all scores are low, be lenient
        if ($topScore < 0.4) {
            return min($avgScore, $this->threshold);
        }

        // Standard case: use gap analysis
        $scores = $sorted->pluck('rerank_score')->toArray();
        $threshold = $this->findScoreGap($scores);

        return max(min($threshold, $this->threshold + 0.1), $this->threshold - 0.1);
    }

    private function findScoreGap(array $scores): float
    {
        if (count($scores) < 2) {
            return $this->threshold;
        }

        // Find largest gap in top scores
        $maxGap = 0;
        $gapThreshold = $this->threshold;

        for ($i = 0; $i < min(count($scores) - 1, 10); $i++) {
            $gap = $scores[$i] - $scores[$i + 1];
            if ($gap > $maxGap && $gap > 0.1) {
                $maxGap = $gap;
                $gapThreshold = $scores[$i + 1];
            }
        }

        return $gapThreshold;
    }

    private function callJinaAPI(string $query, Collection $documents): Collection
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.jina.api_key'),
        ])->post('https://api.jina.ai/v1/rerank', [
            'model' => config('rag.reranker.model', 'jina-reranker-v2-base-multilingual'),
            'query' => $query,
            'documents' => $documents->pluck('content')->toArray(),
            'top_n' => $documents->count(),
        ]);

        if (!$response->successful()) {
            throw new JinaAPIException($response->body());
        }

        $results = $response->json('results');

        return $documents->map(function ($doc, $index) use ($results) {
            $doc->rerank_score = $results[$index]['relevance_score'] ?? 0;
            return $doc;
        });
    }
}
```

## Verification

```php
// Test rerank threshold behavior
$service = app(JinaRerankerService::class);

// Create test documents
$docs = collect([
    ['id' => 1, 'content' => 'นโยบายการคืนเงินสินค้า'],
    ['id' => 2, 'content' => 'ข้อมูลติดต่อบริษัท'],
    ['id' => 3, 'content' => 'รายละเอียดสินค้าทั้งหมด'],
]);

$query = "คืนเงิน";
$reranked = $service->rerank($query, $docs);

// Should have at least min_results
assert($reranked->count() >= config('rag.reranker.min_results', 3));

// First result should be most relevant
assert($reranked->first()->id === 1, 'Refund doc should rank first');

// Scores should be descending
$scores = $reranked->pluck('rerank_score');
$sorted = $scores->sortDesc()->values();
assert($scores->toArray() === $sorted->toArray(), 'Should be sorted descending');

Log::info('Rerank threshold verification passed');
```

## Prevention

- Start with lower threshold (0.3)
- Always have min_results fallback
- Log filtered count for monitoring
- Test with various query lengths
- Review score distributions regularly

## Project-Specific Notes

**BotFacebook Context:**
- Model: `jina-reranker-v2-base-multilingual`
- Threshold: 0.3 (Thai content)
- Min results: 3 guaranteed
- Adaptive threshold: Optional, use for variable quality content
