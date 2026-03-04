---
name: database-ops
description: |
  Database operations specialist for PostgreSQL/Neon with pgvector extension. Handles migrations, schema design, query optimization, vector operations, semantic search.
  Triggers: 'migration', 'database', 'schema', 'query', 'index', 'pgvector', 'embedding'.
  Use when: creating migrations, optimizing slow queries, working with embeddings, designing schema.
allowed-tools:
  - Bash(php artisan migrate*)
  - Bash(php artisan make:migration*)
  - Bash(python3 *.py*)
  - Read
  - Grep
context:
  - path: database/migrations/
  - path: config/database.php
---

# Database Operations

PostgreSQL + Neon + pgvector specialist for BotFacebook.

## Quick Start

```bash
# Create migration
php artisan make:migration add_column_to_table

# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback
```

## MCP Tools Available

- **neon**: Full database access
  - `run_sql` - Execute SQL queries
  - `run_sql_transaction` - Multi-statement transactions
  - `prepare_database_migration` - Test migrations safely
  - `complete_database_migration` - Apply to main branch
  - `prepare_query_tuning` - Analyze slow queries
  - `explain_sql_statement` - Query execution plans
  - `list_slow_queries` - Find performance issues
  - `describe_table_schema` - Table structure
  - `get_database_tables` - List all tables
- **claude-mem**: `search`, `get_observations` - Search past migrations

## Memory Search (Before Starting)

**Always search memory first** to find past migrations and query optimizations.

### Recommended Searches

```
# Search for past migrations
search(query="migration schema", project="bot-fb", type="feature", limit=5)

# Find query optimizations
search(query="query optimization index", project="bot-fb", concepts=["pattern"], limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Creating migration | `search(query="migration", project="bot-fb", type="feature", limit=5)` |
| Adding index | `search(query="index optimization", project="bot-fb", concepts=["pattern"], limit=5)` |
| Vector operations | `search(query="pgvector embedding", project="bot-fb", type="feature", limit=5)` |

## Migration Best Practices

### Safe Migration Pattern

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Always make new columns nullable or have defaults
        $table->string('new_column')->nullable();

        // Add index for frequently queried columns
        $table->index('new_column');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('new_column');
    });
}
```

### Breaking Change Checklist

Before running migration:
- [ ] New columns have defaults or are nullable
- [ ] No column renames without data migration
- [ ] No column type changes that lose data
- [ ] Foreign keys have proper ON DELETE handling
- [ ] Indexes added for new columns used in WHERE

## pgvector Operations

### Create Vector Column

```php
// In migration
$table->vector('embedding', 1536); // OpenAI dimension

// Add index for similarity search
DB::statement('CREATE INDEX idx_embedding ON documents USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
```

### Semantic Search Query

```sql
SELECT id, content,
       1 - (embedding <=> $1::vector) as similarity
FROM documents
WHERE 1 - (embedding <=> $1::vector) > 0.7
ORDER BY embedding <=> $1::vector
LIMIT 10;
```

## Query Optimization

### Use EXPLAIN ANALYZE

```sql
EXPLAIN ANALYZE
SELECT * FROM conversations
WHERE bot_id = 123
AND created_at > NOW() - INTERVAL '7 days';
```

### Common Optimizations

| Issue | Solution |
|-------|----------|
| Sequential Scan | Add index on filtered columns |
| Nested Loop (slow) | Add composite index |
| High cost | Use covering index |
| Too many rows | Add pagination |

### Index Strategies

```sql
-- Single column
CREATE INDEX idx_bot_id ON conversations(bot_id);

-- Composite (order matters!)
CREATE INDEX idx_bot_date ON conversations(bot_id, created_at DESC);

-- Partial index
CREATE INDEX idx_active_bots ON bots(id) WHERE is_active = true;

-- GIN for JSONB
CREATE INDEX idx_metadata ON messages USING GIN(metadata);
```

## Detailed Guides

- **Migration Guide**: See [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)
- **pgvector Guide**: See [PGVECTOR_GUIDE.md](PGVECTOR_GUIDE.md)

## Key Tables

| Table | Purpose |
|-------|---------|
| `bots` | Bot configurations |
| `flows` | Conversation flows |
| `messages` | Chat messages |
| `conversations` | Conversation sessions |
| `documents` | RAG documents (model: Document) |
| `document_chunks` | Chunked document content (model: DocumentChunk) |
| `embeddings` | Vector embeddings |
| `agent_cost_usages` | AI cost tracking (model: AgentCostUsage) |
| `second_ai_logs` | Second AI audit log (model: SecondAILog) |
| `injection_attempt_logs` | Security monitoring (model: InjectionAttemptLog) |
| `lead_recovery_logs` | Lead recovery tracking (model: LeadRecoveryLog) |
| `rag_caches` | Semantic cache (model: RagCache) |
| `activity_logs` | General activity (model: ActivityLog) |

## Neon-Specific Features

### Branching
```bash
# Create branch for testing migrations
neon branches create --name test-migration

# Test migration on branch
php artisan migrate --database=neon-branch

# If successful, apply to main
neon branches delete test-migration
```

### Connection Pooling
- Use `?pooler=true` for serverless
- Connection limit: 100 (pooled)
- Timeout: 5 seconds for queries

## Common Tasks

### Create Safe Migration

```markdown
1. Check existing schema: `php artisan schema:dump`
2. Create migration: `php artisan make:migration name`
3. Add nullable columns or defaults
4. Add indexes for WHERE columns
5. Test on Neon branch first
6. Run: `php artisan migrate`
```

### Optimize Slow Query

```markdown
1. Get query from logs/Sentry
2. Use `explain_sql_statement` MCP tool
3. Identify sequential scans
4. Add appropriate index
5. Test query again
6. Create migration for index
```

### Add Vector Search

```markdown
1. Add vector column: `$table->vector('embedding', 1536)`
2. Create ivfflat index
3. Implement search query with cosine similarity
4. Set similarity threshold (0.7 default)
5. Test with sample queries
```

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| Migration fails on production | Column not nullable | Add `->nullable()` or `->default()` |
| Slow vector search | Missing ivfflat index | Create index with proper lists count |
| Connection timeout | Pool exhausted | Use `?pooler=true`, check connection leaks |
| Foreign key error | Dependent data exists | Add `ON DELETE CASCADE` or handle in code |
| Enum change fails | PostgreSQL enum immutable | Create new enum type, migrate data |
| Deadlock | Concurrent updates | Use `lockForUpdate()` or queue jobs |
| Decimal precision loss | Wrong column type | Use `decimal(19,4)` not `float` |

## Recent Schema Changes

| Date | Change |
|------|--------|
| 2026-02-18 | Evaluation/QA tables dropped (no longer in schema) |
| 2026-02-24 | `model`, `fallback_model`, `decision_model`, `fallback_decision_model` columns dropped from `flows` table |
