# pgvector Guide

## Setup

### Enable Extension

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

### Migration

```php
public function up(): void
{
    // Enable extension
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

    Schema::create('knowledge_chunks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
        $table->text('content');
        $table->vector('embedding', 1536); // OpenAI dimension
        $table->jsonb('metadata')->nullable();
        $table->timestamps();
    });

    // Create HNSW index for fast approximate search
    DB::statement("
        CREATE INDEX knowledge_chunks_embedding_idx
        ON knowledge_chunks
        USING hnsw (embedding vector_cosine_ops)
        WITH (m = 16, ef_construction = 64)
    ");
}
```

## Vector Operations

### Distance Functions

| Function | Operator | Use Case |
|----------|----------|----------|
| L2 (Euclidean) | `<->` | General similarity |
| Inner Product | `<#>` | When vectors are normalized |
| Cosine | `<=>` | Text embeddings (recommended) |

### Basic Search

```sql
-- Find 10 most similar chunks using cosine distance
SELECT id, content,
       1 - (embedding <=> $1) as similarity
FROM knowledge_chunks
WHERE knowledge_base_id = $2
ORDER BY embedding <=> $1
LIMIT 10;
```

### Laravel Query

```php
// Using raw query with parameter binding
$embedding = $this->getEmbedding($query);

$chunks = DB::select("
    SELECT id, content,
           1 - (embedding <=> ?) as similarity
    FROM knowledge_chunks
    WHERE knowledge_base_id = ?
      AND 1 - (embedding <=> ?) > 0.7
    ORDER BY embedding <=> ?
    LIMIT 10
", [$embedding, $kbId, $embedding, $embedding]);
```

### Model with Vector

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\Vector;

class KnowledgeChunk extends Model
{
    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
    ];

    public function scopeSimilarTo($query, array $embedding, float $threshold = 0.7)
    {
        $embeddingString = '[' . implode(',', $embedding) . ']';

        return $query
            ->selectRaw("*, 1 - (embedding <=> ?) as similarity", [$embeddingString])
            ->whereRaw("1 - (embedding <=> ?) > ?", [$embeddingString, $threshold])
            ->orderByRaw("embedding <=> ?", [$embeddingString]);
    }
}

// Usage
$results = KnowledgeChunk::query()
    ->where('knowledge_base_id', $kbId)
    ->similarTo($queryEmbedding, 0.7)
    ->limit(10)
    ->get();
```

## Index Types

### IVFFlat (Faster Build, Slower Query)

```sql
CREATE INDEX ON knowledge_chunks
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```

### HNSW (Slower Build, Faster Query)

```sql
CREATE INDEX ON knowledge_chunks
USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);
```

### Index Selection

| Records | Index | Parameters |
|---------|-------|------------|
| < 10K | None | Linear scan is fine |
| 10K-100K | IVFFlat | lists = sqrt(n) |
| 100K-1M | HNSW | m=16, ef_construction=64 |
| > 1M | HNSW | m=32, ef_construction=128 |

## Hybrid Search

### Combining Semantic + Keyword

```sql
-- Hybrid search with keyword boost
WITH semantic AS (
    SELECT id, content,
           1 - (embedding <=> $1) as semantic_score
    FROM knowledge_chunks
    WHERE knowledge_base_id = $2
    ORDER BY embedding <=> $1
    LIMIT 50
),
keyword AS (
    SELECT id,
           ts_rank(to_tsvector('thai', content),
                   plainto_tsquery('thai', $3)) as keyword_score
    FROM knowledge_chunks
    WHERE knowledge_base_id = $2
      AND to_tsvector('thai', content) @@ plainto_tsquery('thai', $3)
)
SELECT s.id, s.content,
       (0.7 * s.semantic_score + 0.3 * COALESCE(k.keyword_score, 0)) as score
FROM semantic s
LEFT JOIN keyword k ON s.id = k.id
ORDER BY score DESC
LIMIT 10;
```

### Laravel Implementation

```php
public function hybridSearch(int $kbId, string $query, array $embedding): Collection
{
    $results = DB::select("
        WITH semantic AS (
            SELECT id, content,
                   1 - (embedding <=> ?) as semantic_score
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
            ORDER BY embedding <=> ?
            LIMIT 50
        ),
        keyword AS (
            SELECT id,
                   ts_rank(to_tsvector('simple', content),
                           plainto_tsquery('simple', ?)) as keyword_score
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
        )
        SELECT s.id, s.content,
               s.semantic_score,
               COALESCE(k.keyword_score, 0) as keyword_score,
               (0.7 * s.semantic_score + 0.3 * COALESCE(k.keyword_score, 0)) as score
        FROM semantic s
        LEFT JOIN keyword k ON s.id = k.id
        WHERE s.semantic_score > 0.5
        ORDER BY score DESC
        LIMIT 10
    ", [
        $this->vectorToString($embedding),
        $kbId,
        $this->vectorToString($embedding),
        $query,
        $kbId,
    ]);

    return collect($results);
}
```

## Performance Optimization

### Query Tuning

```sql
-- Set probes for IVFFlat (higher = more accurate, slower)
SET ivfflat.probes = 10;

-- Set ef_search for HNSW (higher = more accurate, slower)
SET hnsw.ef_search = 100;
```

### Batch Insert

```php
// Efficient batch insert with vectors
$chunks = collect($documents)->map(fn($doc) => [
    'knowledge_base_id' => $kbId,
    'content' => $doc['content'],
    'embedding' => DB::raw("'[" . implode(',', $doc['embedding']) . "]'"),
    'created_at' => now(),
    'updated_at' => now(),
]);

// Insert in batches
$chunks->chunk(100)->each(function ($batch) {
    DB::table('knowledge_chunks')->insert($batch->toArray());
});
```

### Filtering Before Search

```sql
-- Filter first, then search (more efficient)
SELECT id, content
FROM knowledge_chunks
WHERE knowledge_base_id = $1
  AND metadata->>'category' = 'product'
ORDER BY embedding <=> $2
LIMIT 10;
```

## Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| Slow search | No index | Create HNSW index |
| Low recall | Threshold too high | Lower similarity threshold |
| Memory error | Too many dimensions | Reduce embedding size |
| Wrong results | Different embedding model | Ensure consistent model |

## Debugging

### Check Index Usage

```sql
EXPLAIN ANALYZE
SELECT * FROM knowledge_chunks
ORDER BY embedding <=> '[0.1, 0.2, ...]'
LIMIT 10;
```

### Vector Statistics

```sql
-- Average embedding magnitude
SELECT AVG(vector_norm(embedding)) FROM knowledge_chunks;

-- Distribution of similarities
SELECT
    COUNT(*) FILTER (WHERE similarity > 0.9) as high,
    COUNT(*) FILTER (WHERE similarity BETWEEN 0.7 AND 0.9) as medium,
    COUNT(*) FILTER (WHERE similarity < 0.7) as low
FROM (
    SELECT 1 - (embedding <=> $1) as similarity
    FROM knowledge_chunks
) subq;
```
