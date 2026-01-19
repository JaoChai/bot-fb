---
id: vector-002-choose-dimension
title: Vector Dimension Must Match Embedding Model
impact: HIGH
impactDescription: "Mismatched dimensions cause insertion errors or corrupt searches"
category: vector
tags: [vector, dimension, embedding, model]
relatedRules: [vector-001-create-extension, vector-003-model-consistency]
---

## Why This Matters

Each embedding model produces vectors of a specific dimension. OpenAI's text-embedding-3-small produces 1536 dimensions, while other models differ. If your column dimension doesn't match, inserts fail or searches return wrong results.

## Bad Example

```php
// Problem: Wrong dimension for the model being used
Schema::create('embeddings', function (Blueprint $table) {
    $table->vector('embedding', 768); // BGE model dimension
});

// But code uses OpenAI which produces 1536 dimensions
$embedding = $openAI->embed($text); // Returns 1536-dim vector
DB::table('embeddings')->insert([
    'embedding' => $embedding, // ERROR: dimension mismatch
]);
```

**Why it's wrong:**
- Insert fails with dimension error
- Or worse: silent truncation
- Searches return garbage

## Good Example

```php
// Match dimension to your embedding model
Schema::create('embeddings', function (Blueprint $table) {
    $table->id();
    $table->text('content');

    // OpenAI text-embedding-3-small: 1536
    $table->vector('embedding', 1536);

    // Or for text-embedding-3-large: 3072
    // $table->vector('embedding', 3072);

    // Or for BGE/Cohere: 768
    // $table->vector('embedding', 768);

    $table->timestamps();
});
```

**Why it's better:**
- Dimension matches model output
- Inserts succeed
- Searches work correctly

## Project-Specific Notes

**BotFacebook Embedding Models:**

| Model | Dimension | Use Case |
|-------|-----------|----------|
| text-embedding-3-small | 1536 | Default, good quality/cost |
| text-embedding-3-large | 3072 | High accuracy needs |
| text-embedding-ada-002 | 1536 | Legacy |

**Config Pattern:**
```php
// config/rag.php
return [
    'embedding_model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
    'embedding_dimension' => env('EMBEDDING_DIMENSION', 1536),
];

// In migration
$dimension = config('rag.embedding_dimension');
$table->vector('embedding', $dimension);
```

**Check Current Model:**
```php
// EmbeddingService
public function getDimension(): int
{
    return match($this->model) {
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
        default => throw new \InvalidArgumentException("Unknown model: {$this->model}"),
    };
}
```
