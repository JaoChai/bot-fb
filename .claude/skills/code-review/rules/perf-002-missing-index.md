---
id: perf-002-missing-index
title: Missing Database Indexes
impact: MEDIUM
impactDescription: "Missing indexes cause slow queries as data grows"
category: perf
tags: [performance, database, index, query]
relatedRules: [perf-001-n-plus-one]
---

## Why This Matters

Indexes speed up queries dramatically. Without them, the database scans entire tables. A query taking 1ms with an index might take 10 seconds without one.

## Bad Example

```php
// Migration without indexes
Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bot_id'); // No index!
    $table->foreignId('user_id'); // No index!
    $table->string('status');
    $table->timestamps();
});

// Queries that will be slow
Conversation::where('bot_id', $botId)->get(); // Full table scan
Conversation::where('status', 'active')->get(); // Full table scan
Conversation::where('bot_id', $botId)
    ->where('status', 'active')
    ->get(); // Still slow
```

**Why it's wrong:**
- Full table scans
- Gets slower as data grows
- High CPU and I/O
- Timeouts in production

## Good Example

```php
// Migration with proper indexes
Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('bot_id')->constrained()->index();
    $table->foreignId('user_id')->constrained()->index();
    $table->string('status');
    $table->timestamps();

    // Composite index for common query
    $table->index(['bot_id', 'status']);
});

// Adding index to existing table
Schema::table('conversations', function (Blueprint $table) {
    $table->index(['bot_id', 'status', 'created_at']);
});

// Check query performance
// Use EXPLAIN to verify index usage
DB::select('EXPLAIN SELECT * FROM conversations WHERE bot_id = ?', [1]);
```

**Why it's better:**
- Index lookups are O(log n)
- Scales to millions of rows
- Low CPU usage
- Fast queries

## Review Checklist

- [ ] Foreign keys have indexes
- [ ] Columns in WHERE clauses indexed
- [ ] Columns in ORDER BY indexed
- [ ] Composite indexes for common query patterns
- [ ] No redundant indexes

## Detection

```sql
-- Find missing indexes (PostgreSQL)
SELECT
    relname as table,
    seq_scan - idx_scan as too_much_seq,
    CASE WHEN seq_scan - idx_scan > 0 THEN 'Missing Index?' ELSE 'OK' END as status
FROM pg_stat_user_tables
WHERE seq_scan > 0
ORDER BY too_much_seq DESC;

-- Slow queries
SELECT query, calls, mean_time
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 10;
```

## Project-Specific Notes

**BotFacebook Index Strategy:**

```php
// conversations table
$table->index(['bot_id', 'status']);           // Bot's active conversations
$table->index(['bot_id', 'updated_at']);       // Recent conversations
$table->index(['platform_user_id', 'bot_id']); // Find by LINE/Telegram ID

// messages table
$table->index(['conversation_id', 'created_at']); // Message history
$table->index(['bot_id', 'created_at']);          // Bot analytics

// knowledge_documents table
$table->index(['bot_id', 'status']);              // Active documents
// Note: Vector index created separately with pgvector

-- Create vector index
CREATE INDEX knowledge_embedding_idx
ON knowledge_documents
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```
