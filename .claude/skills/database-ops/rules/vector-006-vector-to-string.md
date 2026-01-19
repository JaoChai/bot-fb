---
id: vector-006-vector-to-string
title: Vector Format for SQL Queries
impact: MEDIUM
impactDescription: "Incorrect vector format causes query errors"
category: vector
tags: [vector, format, sql, binding]
relatedRules: [vector-001-create-extension]
---

## Why This Matters

pgvector expects vectors in a specific string format: `[0.1, 0.2, 0.3, ...]`. PHP arrays must be converted before use in queries. Using wrong format causes "invalid input syntax for type vector" errors.

## Bad Example

```php
// Problem: Passing PHP array directly
$embedding = [0.1, 0.2, 0.3];
DB::select("SELECT * FROM docs ORDER BY embedding <=> ?", [$embedding]);
// ERROR: invalid input syntax for type vector

// Problem: JSON encoding
$json = json_encode($embedding);
DB::select("SELECT * FROM docs ORDER BY embedding <=> ?", [$json]);
// ERROR: expecting array format, not JSON
```

**Why it's wrong:**
- PHP arrays aren't SQL vectors
- JSON format not accepted
- Query fails

## Good Example

```php
class VectorHelper
{
    public static function toString(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    public static function fromString(string $vector): array
    {
        // Parse "[0.1,0.2,0.3]" format
        $trimmed = trim($vector, '[]');
        return array_map('floatval', explode(',', $trimmed));
    }
}

// Usage in queries
$embedding = [0.1, 0.2, 0.3];
$vectorString = VectorHelper::toString($embedding);

DB::select("
    SELECT * FROM documents
    ORDER BY embedding <=> ?::vector
", [$vectorString]);

// For inserts
DB::table('documents')->insert([
    'content' => 'text',
    'embedding' => DB::raw("'" . $vectorString . "'::vector"),
]);
```

**Why it's better:**
- Correct format for pgvector
- Explicit type casting
- Reusable helper

## Project-Specific Notes

**BotFacebook Pattern:**

```php
// In EmbeddingService
protected function vectorToString(array $vector): string
{
    return '[' . implode(',', array_map(fn($v) => number_format($v, 8, '.', ''), $vector)) . ']';
}

// Query usage
$embeddingStr = $this->vectorToString($queryEmbedding);
$results = DB::select("
    SELECT *, 1 - (embedding <=> ?) as similarity
    FROM knowledge_chunks
    WHERE 1 - (embedding <=> ?) > ?
    ORDER BY embedding <=> ?
    LIMIT ?
", [$embeddingStr, $embeddingStr, $threshold, $embeddingStr, $limit]);
```

**Laravel Model Cast:**
```php
use Pgvector\Laravel\Vector;

class KnowledgeChunk extends Model
{
    protected $casts = [
        'embedding' => Vector::class,
    ];
}
```
