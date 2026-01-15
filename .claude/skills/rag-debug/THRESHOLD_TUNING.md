# Threshold Tuning Guide

## Understanding Similarity Scores

### Cosine Similarity Scale

| Score | Interpretation | Action |
|-------|----------------|--------|
| 0.95+ | Near exact match | High confidence |
| 0.85-0.95 | Very relevant | Include in context |
| 0.75-0.85 | Moderately relevant | Include with caution |
| 0.65-0.75 | Somewhat related | May include if needed |
| < 0.65 | Likely irrelevant | Exclude |

### Factors Affecting Scores

1. **Embedding Model** - Different models have different distributions
2. **Content Type** - Technical vs conversational
3. **Language** - Thai vs English vs Mixed
4. **Query Length** - Short queries tend to have lower scores
5. **Chunk Size** - Smaller chunks = more focused but less context

## Threshold Selection

### By Use Case

| Use Case | Threshold | Rationale |
|----------|-----------|-----------|
| Customer FAQ | 0.80 | High precision needed |
| Product search | 0.75 | Balance precision/recall |
| Knowledge base | 0.70 | Broader coverage |
| Creative writing | 0.65 | More diverse context |

### By Query Type

| Query Type | Threshold | Example |
|------------|-----------|---------|
| Exact question | 0.85 | "ราคาสินค้า A คือเท่าไหร่" |
| Topic search | 0.75 | "สินค้าลดราคา" |
| Exploratory | 0.65 | "แนะนำสินค้าให้หน่อย" |

## Dynamic Threshold

### Adaptive Threshold Strategy

```php
public function getAdaptiveThreshold(string $query, array $results): float
{
    $baseThreshold = 0.75;

    // Adjust based on query length
    $queryWords = str_word_count($query);
    if ($queryWords < 3) {
        $baseThreshold -= 0.05; // Lower for short queries
    }

    // Adjust based on result distribution
    $topScore = $results[0]['similarity'] ?? 0;
    $avgScore = collect($results)->avg('similarity');

    if ($topScore - $avgScore > 0.2) {
        // Clear winner, can be stricter
        $baseThreshold = max($baseThreshold, $topScore - 0.15);
    }

    return $baseThreshold;
}
```

### Score Calibration

```php
public function calibrateScores(array $results): array
{
    $scores = collect($results)->pluck('similarity');
    $mean = $scores->avg();
    $std = sqrt($scores->map(fn($s) => pow($s - $mean, 2))->avg());

    // Z-score normalization
    return collect($results)->map(function ($result) use ($mean, $std) {
        $zScore = ($result['similarity'] - $mean) / max($std, 0.01);
        $result['calibrated_score'] = 1 / (1 + exp(-$zScore)); // Sigmoid
        return $result;
    })->toArray();
}
```

## Testing Thresholds

### A/B Testing Framework

```php
public function testThreshold(string $query, array $thresholds): array
{
    $results = [];

    foreach ($thresholds as $threshold) {
        $search = $this->search($query, $threshold);
        $results[$threshold] = [
            'count' => count($search),
            'top_score' => $search[0]['similarity'] ?? 0,
            'relevant' => $this->countRelevant($search), // Manual label
        ];
    }

    return $results;
}
```

### Evaluation Metrics

```php
// Precision@K
$precision = count($relevantInTopK) / $k;

// Recall@K
$recall = count($relevantInTopK) / $totalRelevant;

// Mean Reciprocal Rank
$mrr = 1 / $firstRelevantRank;

// NDCG (Normalized Discounted Cumulative Gain)
$dcg = collect($results)->reduce(function ($carry, $item, $i) {
    return $carry + ($item['relevant'] ? log(2) / log($i + 2) : 0);
}, 0);
```

## Tuning Process

### Step 1: Collect Sample Queries

```sql
-- Get diverse sample of recent queries
SELECT DISTINCT query, COUNT(*) as frequency
FROM search_logs
WHERE created_at > NOW() - INTERVAL '7 days'
GROUP BY query
ORDER BY frequency DESC
LIMIT 100;
```

### Step 2: Manual Relevance Labeling

```php
// Label results as relevant/not relevant
$testSet = [
    ['query' => 'ราคา iPhone', 'relevant_ids' => [1, 5, 8]],
    ['query' => 'วิธีสั่งซื้อ', 'relevant_ids' => [12, 15]],
    // ...
];
```

### Step 3: Grid Search

```php
$thresholds = [0.65, 0.70, 0.75, 0.80, 0.85];
$results = [];

foreach ($thresholds as $threshold) {
    $metrics = $this->evaluate($testSet, $threshold);
    $results[$threshold] = [
        'precision' => $metrics['precision'],
        'recall' => $metrics['recall'],
        'f1' => 2 * ($metrics['precision'] * $metrics['recall'])
                / ($metrics['precision'] + $metrics['recall']),
    ];
}

// Find optimal threshold
$optimal = collect($results)->sortByDesc('f1')->keys()->first();
```

### Step 4: Validate on Hold-out Set

```php
// Test on unseen queries
$holdoutResults = $this->evaluate($holdoutSet, $optimalThreshold);
```

## Thai Language Considerations

### Lower Thresholds for Thai

Thai text often scores lower due to:
- Word segmentation challenges
- Less training data in embedding models
- Character-level variations

**Recommended adjustments:**
```php
$threshold = $this->isThaiQuery($query) ? 0.70 : 0.75;
```

### Handling Mixed Language

```php
public function getMixedLanguageThreshold(string $query): float
{
    $thaiRatio = $this->getThaiCharRatio($query);

    // Interpolate between Thai (0.70) and English (0.75) thresholds
    return 0.70 * $thaiRatio + 0.75 * (1 - $thaiRatio);
}
```

## Monitoring and Alerts

### Track Threshold Effectiveness

```sql
-- Monitor search quality over time
SELECT
    DATE(created_at) as date,
    AVG(top_similarity) as avg_top_sim,
    AVG(result_count) as avg_results,
    COUNT(*) FILTER (WHERE user_clicked) / COUNT(*)::float as ctr
FROM search_logs
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

### Alert Conditions

| Metric | Alert If | Action |
|--------|----------|--------|
| Avg top similarity | < 0.65 | Review embedding model |
| Zero result rate | > 10% | Lower threshold |
| CTR | < 20% | Review result quality |

## Quick Reference

### Threshold Adjustment Guide

| Symptom | Action | Amount |
|---------|--------|--------|
| Too few results | Lower threshold | -0.05 |
| Irrelevant results | Raise threshold | +0.05 |
| Thai queries failing | Lower threshold | -0.05 to -0.10 |
| Short queries failing | Lower threshold | -0.05 |
| High precision needed | Raise threshold | +0.10 |
