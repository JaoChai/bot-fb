---
id: index-006-index-maintenance
title: Vector Index Maintenance After Bulk Operations
impact: MEDIUM
impactDescription: "Bulk inserts degrade IVFFlat index quality - requires reindex"
category: index
tags: [index, maintenance, reindex, bulk]
relatedRules: [index-001-hnsw-vs-ivfflat]
---

## Why This Matters

IVFFlat indexes are built with a specific data distribution. After bulk inserts, new vectors may not fit well into existing clusters, degrading search quality. HNSW handles this better but still benefits from occasional reindex.

## Bad Example

```php
// Bulk insert 50K new documents
foreach ($documents as $doc) {
    KnowledgeChunk::create([
        'content' => $doc['content'],
        'embedding' => $doc['embedding'],
    ]);
}
// Index quality degraded - no reindex
```

**Why it's wrong:**
- IVFFlat clusters become unbalanced
- Search recall decreases
- Performance degrades

## Good Example

```php
// After large bulk insert, reindex
public function bulkInsert(array $documents): void
{
    // Insert in batches
    collect($documents)->chunk(1000)->each(function ($batch) {
        KnowledgeChunk::insert($batch->toArray());
    });

    // Check if reindex needed (>20% growth)
    $currentCount = KnowledgeChunk::count();
    $previousCount = Cache::get('knowledge_chunks_count', 0);

    if ($currentCount > $previousCount * 1.2) {
        $this->reindexVectors();
        Cache::put('knowledge_chunks_count', $currentCount);
    }
}

private function reindexVectors(): void
{
    DB::statement('REINDEX INDEX CONCURRENTLY knowledge_chunks_embedding_idx');
}
```

**Why it's better:**
- Maintains index quality
- Triggered on significant growth
- Uses CONCURRENTLY to avoid locks

## Project-Specific Notes

**BotFacebook Reindex Schedule:**

| Event | Action |
|-------|--------|
| Bulk import (>1K docs) | Reindex after |
| Weekly | Check index health |
| >50% growth | Force reindex |

```sql
-- Check index health
SELECT indexrelname, idx_scan, idx_tup_read, idx_tup_fetch
FROM pg_stat_user_indexes
WHERE indexrelname LIKE '%embedding%';

-- Reindex without lock
REINDEX INDEX CONCURRENTLY knowledge_chunks_embedding_idx;
```
