---
id: thresh-001-semantic-threshold
title: Semantic Search Threshold Issues
impact: HIGH
impactDescription: "Results filtered out or too much noise due to wrong threshold"
category: thresh
tags: [threshold, semantic, similarity, filtering]
relatedRules: [search-001-no-results, thresh-002-rerank-threshold]
---

## Symptom

- "No results found" despite documents existing
- Too many irrelevant results returned
- Good matches filtered out
- Quality varies wildly between queries

## Root Cause

1. Threshold too high (misses good results)
2. Threshold too low (includes noise)
3. Fixed threshold doesn't fit all query types
4. Embedding model change invalidated threshold
5. Content quality varies, threshold doesn't adapt

## Diagnosis

### Quick Check

```php
// Check current threshold
Log::info('Semantic threshold', [
    'configured' => config('rag.semantic_threshold'),
    'recommended' => '0.65-0.75 for text-embedding-3-large',
]);

// Test threshold effect
$query = "นโยบายคืนเงิน";
$allResults = $this->semanticSearch($query, $botId, threshold: 0);

$distribution = [
    '>0.8' => $allResults->filter(fn($r) => $r->similarity > 0.8)->count(),
    '0.7-0.8' => $allResults->filter(fn($r) => $r->similarity > 0.7 && $r->similarity <= 0.8)->count(),
    '0.6-0.7' => $allResults->filter(fn($r) => $r->similarity > 0.6 && $r->similarity <= 0.7)->count(),
    '<0.6' => $allResults->filter(fn($r) => $r->similarity <= 0.6)->count(),
];

Log::info('Score distribution', $distribution);
```

### Detailed Analysis

```php
// Test queries with different thresholds
$testQueries = [
    'นโยบายคืนเงิน',
    'ติดต่อเรา',
    'ราคาสินค้า',
];

$thresholds = [0.5, 0.6, 0.65, 0.7, 0.75, 0.8];

foreach ($testQueries as $query) {
    foreach ($thresholds as $threshold) {
        $results = $this->semanticSearch($query, $botId, threshold: $threshold);
        Log::info('Threshold test', [
            'query' => $query,
            'threshold' => $threshold,
            'results_count' => $results->count(),
            'top_score' => $results->first()?->similarity,
            'avg_score' => $results->avg('similarity'),
        ]);
    }
}
```

## Solution

### Fix Steps

1. **Adjust base threshold**
```php
// config/rag.php
'semantic_threshold' => 0.65,  // Start lower, can increase

// For different models:
// text-embedding-3-large: 0.65-0.75
// text-embedding-3-small: 0.60-0.70
// text-embedding-ada-002: 0.70-0.80
```

2. **Use adaptive threshold**
```php
// Adjust based on result count
private function adaptiveThreshold(Collection $results, float $baseThreshold): float
{
    $count = $results->count();

    // Too few results - lower threshold
    if ($count < 3) {
        return max($baseThreshold - 0.1, 0.5);
    }

    // Too many results - raise threshold
    if ($count > 20) {
        return min($baseThreshold + 0.05, 0.85);
    }

    return $baseThreshold;
}
```

3. **Separate thresholds by use case**
```php
'thresholds' => [
    'chat' => 0.65,      // More lenient for chat
    'qa' => 0.70,        // Stricter for Q&A
    'strict' => 0.80,    // Very strict matching
],
```

### Code Fix

```php
// Adaptive semantic search
class SemanticSearchService
{
    private float $baseThreshold;
    private float $minThreshold = 0.5;
    private float $maxThreshold = 0.85;

    public function __construct()
    {
        $this->baseThreshold = config('rag.semantic_threshold', 0.65);
    }

    public function search(
        string $query,
        int $botId,
        ?float $threshold = null,
        int $limit = 30
    ): Collection {
        $effectiveThreshold = $threshold ?? $this->baseThreshold;

        // Get embedding
        $embedding = $this->embeddingService->embed($query);

        // Search without threshold first
        $results = $this->vectorSearch($embedding, $botId, $limit);

        // Log score distribution
        $this->logScoreDistribution($query, $results);

        // Apply adaptive threshold
        if ($threshold === null) {
            $effectiveThreshold = $this->calculateAdaptiveThreshold($results);
        }

        // Filter by threshold
        $filtered = $results->filter(
            fn($doc) => $doc->similarity >= $effectiveThreshold
        );

        // Ensure minimum results
        if ($filtered->isEmpty() && $results->isNotEmpty()) {
            Log::warning('All results filtered by threshold', [
                'query' => $query,
                'threshold' => $effectiveThreshold,
                'top_score' => $results->first()->similarity,
            ]);

            // Return top N regardless of threshold
            return $this->fallbackResults($results);
        }

        return $filtered->values();
    }

    private function calculateAdaptiveThreshold(Collection $results): float
    {
        if ($results->isEmpty()) {
            return $this->baseThreshold;
        }

        $topScore = $results->first()->similarity;
        $avgScore = $results->avg('similarity');

        // If top score is very high, be stricter
        if ($topScore > 0.85) {
            return min($topScore - 0.15, $this->maxThreshold);
        }

        // If scores are low overall, be more lenient
        if ($topScore < 0.7) {
            return max($topScore - 0.1, $this->minThreshold);
        }

        // Dynamic threshold based on score distribution
        $threshold = $avgScore + (($topScore - $avgScore) / 2);

        return max(min($threshold, $this->maxThreshold), $this->minThreshold);
    }

    private function fallbackResults(Collection $results): Collection
    {
        // Return top 3 regardless of score
        return $results->take(3);
    }

    private function logScoreDistribution(string $query, Collection $results): void
    {
        if ($results->isEmpty()) {
            return;
        }

        $scores = $results->pluck('similarity');

        Log::debug('Semantic search scores', [
            'query' => mb_substr($query, 0, 50),
            'count' => $results->count(),
            'max' => round($scores->max(), 3),
            'min' => round($scores->min(), 3),
            'avg' => round($scores->avg(), 3),
            'std' => round($this->standardDeviation($scores), 3),
        ]);
    }

    private function standardDeviation(Collection $values): float
    {
        $avg = $values->avg();
        $variance = $values->map(fn($v) => pow($v - $avg, 2))->avg();
        return sqrt($variance);
    }

    private function vectorSearch(array $embedding, int $botId, int $limit): Collection
    {
        $vector = '[' . implode(',', $embedding) . ']';

        return DB::table('knowledge_base_documents')
            ->select([
                'id',
                'content',
                DB::raw("1 - (embedding <=> '{$vector}'::vector) as similarity"),
            ])
            ->where('bot_id', $botId)
            ->whereNotNull('embedding')
            ->orderByRaw("embedding <=> '{$vector}'::vector")
            ->limit($limit)
            ->get();
    }
}

// Threshold tuning command
class TuneSemanticThreshold extends Command
{
    protected $signature = 'rag:tune-threshold {--bot-id=} {--test-cases=}';

    public function handle(SemanticSearchService $service): int
    {
        $testCases = $this->loadTestCases();
        $thresholds = [0.55, 0.60, 0.65, 0.70, 0.75, 0.80];

        $results = [];

        foreach ($thresholds as $threshold) {
            $precision = 0;
            $recall = 0;

            foreach ($testCases as $case) {
                $searchResults = $service->search(
                    $case['query'],
                    $this->option('bot-id'),
                    $threshold
                );

                $found = $searchResults->pluck('id')->toArray();
                $expected = $case['expected_ids'];

                $tp = count(array_intersect($found, $expected));
                $precision += $tp / max(count($found), 1);
                $recall += $tp / max(count($expected), 1);
            }

            $count = count($testCases);
            $results[$threshold] = [
                'precision' => $precision / $count,
                'recall' => $recall / $count,
                'f1' => 2 * ($precision * $recall) / max($precision + $recall, 1) / $count,
            ];
        }

        // Find best F1
        $best = collect($results)->sortByDesc('f1')->keys()->first();
        $this->info("Best threshold: {$best} (F1: " . number_format($results[$best]['f1'], 3) . ")");

        return 0;
    }
}
```

## Verification

```php
// Verify threshold is working correctly
$service = app(SemanticSearchService::class);

// Test 1: Should find results
$results = $service->search("นโยบายคืนเงิน", $botId);
assert($results->isNotEmpty(), 'Should find results for common query');

// Test 2: Check score range
$topScore = $results->first()->similarity;
$threshold = config('rag.semantic_threshold');
assert($topScore >= $threshold, "Top score should be >= threshold");

// Test 3: Fallback works
$obscureResults = $service->search("xyz123random", $botId);
// Should either be empty or have low-score fallbacks

Log::info('Threshold verification', [
    'common_query_results' => $results->count(),
    'top_score' => $topScore,
    'threshold' => $threshold,
    'obscure_query_results' => $obscureResults->count(),
]);
```

## Prevention

- Start with lower threshold, increase gradually
- Log score distributions regularly
- Test with edge cases (short queries, rare terms)
- Retune after embedding model changes
- Monitor "no results" rate

## Project-Specific Notes

**BotFacebook Context:**
- Default threshold: 0.65 (text-embedding-3-large)
- Adaptive threshold enabled
- Fallback: Return top 3 if all filtered
- Tune command: `php artisan rag:tune-threshold`
