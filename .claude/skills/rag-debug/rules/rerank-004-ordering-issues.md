---
id: rerank-004-ordering-issues
title: Reranker Result Ordering Issues
impact: MEDIUM
impactDescription: "Results in wrong order despite reranking"
category: rerank
tags: [reranker, ordering, sorting, results]
relatedRules: [rerank-002-scoring-wrong, search-002-wrong-results]
---

## Symptom

- Results not sorted by rerank score
- Top result has lower score than #2
- Duplicate documents in results
- Missing documents after rerank

## Root Cause

1. Sort order bug (ascending vs descending)
2. Index mismatch in response handling
3. Duplicate handling issues
4. Score normalization problems
5. Collection mutation bugs

## Diagnosis

### Quick Check

```php
// Check sorting
$reranked = $this->reranker->rerankWithScores($query, $documents);

$scores = $reranked->pluck('rerank_score')->toArray();
$sortedScores = $scores;
rsort($sortedScores);

if ($scores !== $sortedScores) {
    Log::error('Reranked results not sorted descending', [
        'actual' => $scores,
        'expected' => $sortedScores,
    ]);
}
```

### Detailed Analysis

```php
// Log complete rerank flow
public function rerankWithDebug(string $query, Collection $documents): array
{
    // Before
    Log::debug('Before rerank', [
        'input_order' => $documents->pluck('id')->toArray(),
    ]);

    $response = $this->callAPI($query, $documents);

    // Raw response
    Log::debug('API response', [
        'results' => $response['results'],
    ]);

    // After processing
    $processed = $this->processResponse($response, $documents);

    Log::debug('After rerank', [
        'output_order' => $processed->pluck('id')->toArray(),
        'scores' => $processed->pluck('rerank_score')->toArray(),
    ]);

    return [
        'results' => $processed,
        'debug' => ['...'],
    ];
}
```

## Solution

### Fix Steps

1. **Fix sort order**
```php
// Ensure descending sort by score
$results = $results->sortByDesc('rerank_score')->values();
```

2. **Handle API response correctly**
```php
// API returns results with original index reference
private function processResponse(array $response, Collection $original): Collection
{
    return collect($response['results'])
        ->map(function ($result) use ($original) {
            $doc = $original[$result['index']];
            $doc->rerank_score = $result['relevance_score'];
            return $doc;
        })
        ->sortByDesc('rerank_score')
        ->values();  // Reset keys
}
```

3. **Remove duplicates**
```php
$results = $results->unique('id')->values();
```

### Code Fix

```php
// Correct reranker implementation
class JinaRerankerService
{
    public function rerank(string $query, Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }

        // Remove duplicates before sending
        $unique = $documents->unique('id')->values();

        // Store original mapping
        $indexMap = $unique->pluck('id')->flip()->toArray();

        // Call API
        $response = $this->callAPI($query, $unique);

        // Process response with correct mapping
        $results = collect($response['results'])->map(function ($result) use ($unique) {
            // Jina returns index into our input array
            $originalIndex = $result['index'];

            if (!isset($unique[$originalIndex])) {
                Log::warning('Invalid index from Jina', ['index' => $originalIndex]);
                return null;
            }

            $doc = clone $unique[$originalIndex];  // Clone to avoid mutation
            $doc->rerank_score = $result['relevance_score'];
            return $doc;
        })->filter();  // Remove nulls

        // Sort descending and reset keys
        $sorted = $results->sortByDesc('rerank_score')->values();

        // Verify sorting
        $this->verifySorting($sorted);

        return $sorted;
    }

    private function verifySorting(Collection $results): void
    {
        $prevScore = PHP_FLOAT_MAX;

        foreach ($results as $i => $doc) {
            if ($doc->rerank_score > $prevScore) {
                Log::error('Results not properly sorted', [
                    'position' => $i,
                    'score' => $doc->rerank_score,
                    'prev_score' => $prevScore,
                ]);
            }
            $prevScore = $doc->rerank_score;
        }
    }

    private function callAPI(string $query, Collection $documents): array
    {
        $texts = $documents->map(fn($d) => mb_substr($d->content, 0, 1000))->toArray();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.jina.api_key'),
        ])->post('https://api.jina.ai/v1/rerank', [
            'model' => config('rag.reranker.model'),
            'query' => $query,
            'documents' => $texts,
            'top_n' => count($texts),
            'return_documents' => false,  // Only need scores
        ]);

        if (!$response->successful()) {
            throw new JinaAPIException($response->body());
        }

        return $response->json();
    }
}
```

## Verification

```php
// Test sorting is correct
$reranked = $this->reranker->rerank($query, $documents);

$scores = $reranked->pluck('rerank_score')->toArray();

for ($i = 1; $i < count($scores); $i++) {
    assert($scores[$i] <= $scores[$i-1], "Score at $i not <= previous");
}

// Test no duplicates
$ids = $reranked->pluck('id');
assert($ids->count() === $ids->unique()->count(), 'Duplicates found');

// Test all input preserved (if no threshold)
$inputIds = $documents->pluck('id')->sort()->values();
$outputIds = $reranked->pluck('id')->sort()->values();
assert($inputIds == $outputIds, 'Documents lost during reranking');
```

## Prevention

- Always clone documents before mutation
- Verify sorting after rerank
- Test with duplicate inputs
- Log score distribution
- Add sorting assertions

## Project-Specific Notes

**BotFacebook Context:**
- Results sorted by `rerank_score` DESC
- Duplicates removed before API call
- Documents cloned to prevent mutation
- Verification in debug mode
