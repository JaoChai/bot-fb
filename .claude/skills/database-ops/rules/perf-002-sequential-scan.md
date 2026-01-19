---
id: perf-002-sequential-scan
title: Avoid Sequential Scans on Large Tables
impact: HIGH
impactDescription: "Sequential scan reads every row - O(n) instead of O(log n)"
category: perf
tags: [performance, sequential-scan, index, optimization]
relatedRules: [perf-001-explain-analyze, migration-005-add-index-concurrent]
---

## Why This Matters

Sequential scans read every row in a table. On 1M rows, this means reading 1M rows even to find 10 results. Indexes reduce this to ~10 reads. The difference is seconds vs milliseconds.

## Bad Example

```sql
-- No index on bot_id - Sequential Scan
SELECT * FROM messages
WHERE bot_id = 123
ORDER BY created_at DESC
LIMIT 10;

-- EXPLAIN shows:
-- Seq Scan on messages (rows=1000000, time=2500ms)
```

**Why it's wrong:**
- Reads entire table
- Slow as table grows
- Wastes I/O

## Good Example

```sql
-- Add composite index
CREATE INDEX CONCURRENTLY idx_messages_bot_date
ON messages (bot_id, created_at DESC);

-- Same query now uses Index Scan
SELECT * FROM messages
WHERE bot_id = 123
ORDER BY created_at DESC
LIMIT 10;

-- EXPLAIN shows:
-- Index Scan using idx_messages_bot_date (rows=10, time=2ms)
```

**Why it's better:**
- Index-only scan
- O(log n) performance
- Scales with data

## Project-Specific Notes

**BotFacebook Common Indexes:**

```sql
-- Messages by bot and date
CREATE INDEX idx_messages_bot_date ON messages (bot_id, created_at DESC);

-- Conversations by bot
CREATE INDEX idx_conversations_bot ON conversations (bot_id);

-- Knowledge chunks by KB
CREATE INDEX idx_chunks_kb ON knowledge_chunks (knowledge_base_id);

-- Users by email (unique)
CREATE UNIQUE INDEX idx_users_email ON users (email);
```

**When Seq Scan is OK:**
- Tables <1000 rows
- Selecting >10% of table
- No suitable index exists
