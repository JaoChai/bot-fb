---
id: index-004-probes-and-ef
title: Query-Time Index Tuning (probes/ef_search)
impact: MEDIUM
impactDescription: "Query parameters trade speed for accuracy"
category: index
tags: [index, probes, ef_search, tuning, query]
relatedRules: [index-002-hnsw-params, index-003-ivfflat-lists]
---

## Why This Matters

Vector indexes have query-time parameters that affect search quality. Higher values = better recall but slower queries. Tune these based on your accuracy requirements.

## Parameters

| Index | Parameter | Default | Effect |
|-------|-----------|---------|--------|
| IVFFlat | probes | 1 | Lists to search |
| HNSW | ef_search | 40 | Candidate pool size |

## Bad Example

```php
// Using defaults - may miss relevant results
$results = DB::select("
    SELECT * FROM docs ORDER BY embedding <=> ? LIMIT 10
", [$embedding]);
// IVFFlat searches only 1 list by default!
```

**Why it's wrong:**
- Default probes=1 misses many results
- Default ef_search may be insufficient

## Good Example

```php
// Set parameters before search
DB::statement('SET ivfflat.probes = 10');  // For IVFFlat
DB::statement('SET hnsw.ef_search = 100'); // For HNSW

$results = DB::select("
    SELECT * FROM docs
    ORDER BY embedding <=> ?
    LIMIT 10
", [$embedding]);

// Or per-session configuration
// In AppServiceProvider
DB::listen(function ($query) {
    if (str_contains($query->sql, '<=>')) {
        DB::statement('SET hnsw.ef_search = 100');
    }
});
```

**Why it's better:**
- Explicit quality control
- Better recall for searches

## Project-Specific Notes

**BotFacebook Query Settings:**

```php
// In SemanticSearchService
public function search(array $embedding): Collection
{
    // Tune based on table index type
    DB::statement('SET hnsw.ef_search = 100');

    return DB::select("
        SELECT *, 1 - (embedding <=> ?) as similarity
        FROM knowledge_chunks
        ORDER BY embedding <=> ?
        LIMIT 10
    ", [$this->toString($embedding), $this->toString($embedding)]);
}
```

**Tuning Guide:**
| Need | probes | ef_search |
|------|--------|-----------|
| Fast, ok accuracy | 5 | 40 |
| Balanced | 10 | 100 |
| High accuracy | 20+ | 200+ |
