---
id: db-003-add-index
title: Add Database Index
impact: MEDIUM
impactDescription: "Add indexes to improve query performance"
category: db
tags: [index, migration, performance, postgresql]
relatedRules: [db-002-query-optimization, db-004-migration-refactor]
---

## Code Smell

- EXPLAIN shows "Seq Scan" on large table
- WHERE clause on non-indexed column
- ORDER BY without supporting index
- JOIN on non-indexed foreign key
- Slow count queries

## Root Cause

1. Indexes not planned upfront
2. New query patterns emerged
3. Table grew larger
4. Missing foreign key indexes
5. No monitoring for slow queries

## When to Apply

**Apply when:**
- EXPLAIN shows Seq Scan on large table
- Column frequently in WHERE/ORDER BY
- Foreign key used in JOINs
- Text search needed

**Don't apply when:**
- Table is small (< 1000 rows)
- Column rarely queried
- Would slow writes significantly
- Index already exists

## Solution

### Before (No Index)

```php
// Migration - no index
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->constrained();
    $table->text('content');
    $table->string('role');
    $table->timestamps();
});

// Query is slow on large table
Message::where('conversation_id', $id)
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();
// EXPLAIN shows: Seq Scan on messages
```

### After (With Index)

```php
// Migration to add index
Schema::table('messages', function (Blueprint $table) {
    // Composite index for common query pattern
    $table->index(['conversation_id', 'created_at']);
});

// Same query now uses index
Message::where('conversation_id', $id)
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();
// EXPLAIN shows: Index Scan using messages_conversation_id_created_at_index
```

### Index Types & Usage

```php
// 1. B-tree (default) - equality, range, ORDER BY
$table->index('email');  // B-tree
$table->index(['user_id', 'created_at']);  // Composite B-tree

// 2. Unique - for unique constraints
$table->unique('email');
$table->unique(['bot_id', 'platform_id']);  // Composite unique

// 3. GIN - for array/jsonb/full-text
DB::statement('CREATE INDEX idx_messages_metadata ON messages USING GIN (metadata)');

// 4. GiST - for geometric/range data
// 5. BRIN - for naturally ordered data (timestamps)

// For pgvector (BotFacebook)
DB::statement('CREATE INDEX idx_chunks_embedding ON knowledge_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
```

### Common Index Patterns

```php
// 1. Foreign key index (should always add)
$table->foreignId('user_id')->constrained()->index();
// Or add later:
$table->index('user_id');

// 2. Composite index for WHERE + ORDER BY
$table->index(['status', 'created_at']);
// Supports: WHERE status = 'active' ORDER BY created_at

// 3. Partial index for filtered queries
DB::statement("
    CREATE INDEX idx_messages_unread
    ON messages (conversation_id)
    WHERE read_at IS NULL
");

// 4. Index for text search
$table->fullText('content');  // GIN full-text index

// 5. Expression index
DB::statement("
    CREATE INDEX idx_users_email_lower
    ON users (LOWER(email))
");
```

### Step-by-Step

1. **Identify need for index**
   ```sql
   -- Check slow queries
   EXPLAIN ANALYZE SELECT * FROM messages
   WHERE conversation_id = 123
   ORDER BY created_at DESC;

   -- If shows "Seq Scan" → needs index
   ```

2. **Determine index type**
   - WHERE equality/range → B-tree
   - JSON queries → GIN
   - Full-text search → GIN
   - Vector similarity → IVFFlat/HNSW

3. **Create migration**
   ```bash
   php artisan make:migration add_index_to_messages_table
   ```

4. **Add index (with CONCURRENTLY for production)**
   ```php
   public function up(): void
   {
       // For large tables, use raw SQL with CONCURRENTLY
       DB::statement('
           CREATE INDEX CONCURRENTLY idx_messages_conv_created
           ON messages (conversation_id, created_at)
       ');
   }

   public function down(): void
   {
       DB::statement('DROP INDEX CONCURRENTLY idx_messages_conv_created');
   }
   ```

5. **Verify improvement**
   ```sql
   EXPLAIN ANALYZE SELECT * FROM messages
   WHERE conversation_id = 123
   ORDER BY created_at DESC;
   -- Should show "Index Scan" now
   ```

## Verification

```bash
# List existing indexes
\d messages
# Or:
SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'messages';

# Check index usage
SELECT
    schemaname,
    relname,
    indexrelname,
    idx_scan,
    idx_tup_read
FROM pg_stat_user_indexes
WHERE relname = 'messages';
```

## Anti-Patterns

- **Over-indexing**: Too many indexes slow writes
- **Wrong column order**: Composite index order matters
- **Ignoring selectivity**: Index on boolean column rarely helps
- **Not using CONCURRENTLY**: Locks table during index creation

## Index Order Rule

```php
// Column order in composite index matters!
$table->index(['status', 'created_at']);

// ✅ Works: WHERE status = 'x' ORDER BY created_at
// ✅ Works: WHERE status = 'x'
// ❌ Won't use index well: WHERE created_at > '2024-01-01'

// Rule: Put equality columns first, range/ORDER BY columns last
```

## Project-Specific Notes

**BotFacebook Context:**
- Vector indexes: IVFFlat for large datasets
- Use `CONCURRENTLY` for production indexes
- Common indexes needed: conversation_id, bot_id, created_at
- Check with Neon MCP `explain_sql_statement`
