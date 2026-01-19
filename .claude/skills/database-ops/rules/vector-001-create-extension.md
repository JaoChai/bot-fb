---
id: vector-001-create-extension
title: Enable pgvector Extension Before Use
impact: CRITICAL
impactDescription: "Using vector type without extension causes 'type vector does not exist' error"
category: vector
tags: [vector, pgvector, extension, setup]
relatedRules: [vector-002-choose-dimension]
---

## Why This Matters

The `vector` type is not built into PostgreSQL - it comes from the pgvector extension. Trying to create a vector column without enabling the extension first will fail with "type vector does not exist". This breaks migrations and deployments.

## Bad Example

```php
// Problem: Using vector without enabling extension
public function up(): void
{
    Schema::create('embeddings', function (Blueprint $table) {
        $table->id();
        $table->vector('embedding', 1536); // ERROR: type "vector" does not exist
    });
}
```

**Why it's wrong:**
- Migration fails immediately
- Error message can be confusing
- Blocks all subsequent migrations

## Good Example

```php
public function up(): void
{
    // Step 1: Enable extension (idempotent)
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

    // Step 2: Create table with vector column
    Schema::create('embeddings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('document_id')->constrained()->cascadeOnDelete();
        $table->vector('embedding', 1536);
        $table->timestamps();
    });

    // Step 3: Create index for similarity search
    DB::statement("
        CREATE INDEX embeddings_vector_idx
        ON embeddings
        USING hnsw (embedding vector_cosine_ops)
        WITH (m = 16, ef_construction = 64)
    ");
}

public function down(): void
{
    Schema::dropIfExists('embeddings');
    // Note: Don't drop extension - other tables may use it
}
```

**Why it's better:**
- Extension enabled before use
- `IF NOT EXISTS` is idempotent
- Index created for performance

## Project-Specific Notes

**BotFacebook Vector Setup:**

```php
// In first migration using vectors
public function up(): void
{
    // Neon already has pgvector, but this ensures it
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

    Schema::create('knowledge_chunks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
        $table->text('content');
        $table->vector('embedding', 1536); // OpenAI dimension
        $table->jsonb('metadata')->nullable();
        $table->timestamps();
    });
}
```

**Check Extension Status:**
```sql
-- Verify extension is installed
SELECT * FROM pg_extension WHERE extname = 'vector';

-- Check version
SELECT extversion FROM pg_extension WHERE extname = 'vector';
```

## MCP Tools

```
# Enable extension via Neon MCP
mcp__neon__run_sql(
    projectId="your-project",
    sql="CREATE EXTENSION IF NOT EXISTS vector"
)

# Verify installation
mcp__neon__run_sql(
    projectId="your-project",
    sql="SELECT extversion FROM pg_extension WHERE extname = 'vector'"
)
```

## References

- [pgvector Installation](https://github.com/pgvector/pgvector#installation)
- [Neon pgvector](https://neon.tech/docs/extensions/pgvector)
