---
id: perf-004-batch-insert
title: Efficient Batch Inserts
impact: MEDIUM
impactDescription: "Single-row inserts are 10-100x slower than batched inserts"
category: perf
tags: [performance, insert, batch, bulk]
relatedRules: [migration-008-large-table-batch]
---

## Why This Matters

Each INSERT statement has overhead: parse, plan, execute, commit. Inserting 1000 rows one-by-one means 1000x that overhead. Batching reduces this dramatically.

## Bad Example

```php
// 1000 separate inserts - SLOW
foreach ($documents as $doc) {
    KnowledgeChunk::create([
        'content' => $doc['content'],
        'embedding' => $doc['embedding'],
    ]);
}
// ~10 seconds for 1000 rows
```

**Why it's wrong:**
- 1000 separate transactions
- 1000 round trips to database
- Very slow

## Good Example

```php
// Batch insert - FAST
$chunks = collect($documents)->map(fn($doc) => [
    'knowledge_base_id' => $kbId,
    'content' => $doc['content'],
    'embedding' => DB::raw("'" . $this->vectorToString($doc['embedding']) . "'"),
    'created_at' => now(),
    'updated_at' => now(),
]);

// Insert in batches of 100
$chunks->chunk(100)->each(function ($batch) {
    DB::table('knowledge_chunks')->insert($batch->toArray());
});
// ~0.5 seconds for 1000 rows
```

**Why it's better:**
- Fewer transactions
- Fewer round trips
- 10-100x faster

## Project-Specific Notes

**BotFacebook Batch Sizes:**

| Operation | Batch Size |
|-----------|------------|
| Regular insert | 100 |
| With vectors | 50 |
| Large rows | 25 |

```php
// In DocumentService
public function bulkCreateChunks(int $kbId, array $chunks): void
{
    collect($chunks)
        ->chunk(50)
        ->each(function ($batch) use ($kbId) {
            $rows = $batch->map(fn($c) => [
                'knowledge_base_id' => $kbId,
                'content' => $c['content'],
                'embedding' => DB::raw("'" . $this->vectorToString($c['embedding']) . "'"),
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            DB::table('knowledge_chunks')->insert($rows);
        });
}
```
