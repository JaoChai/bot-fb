---
name: performance
description: |
  Performance optimization specialist for backend and frontend.
  Triggers: 'slow', 'performance', 'N+1', 'bundle size', 'Core Web Vitals', 'optimize'.
  Use when: app is slow, queries take too long, pages load slowly, optimizing for production.
allowed-tools:
  - Bash(npm run build*)
  - Bash(npx lighthouse*)
  - Bash(python3 *.py*)
  - Read
  - Grep
context:
  - path: config/cache.php
  - path: config/database.php
  - path: vite.config.ts
---

# Performance Optimization

Full-stack performance specialist for BotFacebook.

## Quick Start

เมื่อ app ช้า ให้ตรวจตามลำดับ:
1. **API ช้า?** → Check slow queries, N+1
2. **Frontend ช้า?** → Check bundle size, Core Web Vitals
3. **Real-time ช้า?** → Check WebSocket, queue

## MCP Tools Available

- **neon**: `explain_sql_statement`, `list_slow_queries`, `prepare_query_tuning` - Database analysis
- **chrome**: `screenshot`, `computer` - Frontend profiling
- **sentry**: `search_events`, `get_trace_details` - Performance monitoring
- **claude-mem**: `search`, `get_observations` - Search past optimizations

## Memory Search (Before Starting)

**Always search memory first** to find past optimizations and performance fixes.

### Recommended Searches

```
# Search for performance fixes
search(query="performance optimization", project="bot-fb", type="bugfix", limit=5)

# Find N+1 and query fixes
search(query="N+1 query fix", project="bot-fb", concepts=["problem-solution"], limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Slow API | `search(query="API slow response", project="bot-fb", type="bugfix", limit=5)` |
| Database issues | `search(query="query optimization", project="bot-fb", concepts=["pattern"], limit=5)` |
| Frontend bundle | `search(query="bundle size", project="bot-fb", type="bugfix", limit=5)` |

## Performance Targets

| Metric | Target | Tool |
|--------|--------|------|
| API response | < 500ms | Sentry traces |
| AI evaluation | < 1.5s | Logs |
| Page load (LCP) | < 2.5s | Lighthouse |
| Database query | < 100ms | Neon |
| Bundle size | < 500KB gzipped | Vite |

## Backend Optimization

| Issue | Solution | Guide |
|-------|----------|-------|
| N+1 queries | Eager loading `->with()` | [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md) |
| Slow queries | EXPLAIN ANALYZE + indexes | [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md) |
| No caching | `Cache::remember()` | [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md) |

## Frontend Optimization

| Issue | Solution | Guide |
|-------|----------|-------|
| Large bundle | Code splitting, `lazy()` | [FRONTEND_PERF.md](FRONTEND_PERF.md) |
| Re-renders | `useMemo`, `useCallback` | [FRONTEND_PERF.md](FRONTEND_PERF.md) |
| Slow load | Preload, staleTime | [FRONTEND_PERF.md](FRONTEND_PERF.md) |

## Core Web Vitals Targets

| Metric | Target | Key Fix |
|--------|--------|---------|
| LCP | < 2.5s | Optimize images, preload |
| FID | < 100ms | Split long tasks |
| CLS | < 0.1 | Set image dimensions |

## Quick Commands

```bash
# Backend: Find slow queries
grep -i "slow query" storage/logs/laravel.log

# Frontend: Run Lighthouse
npx lighthouse https://www.botjao.com --view

# Frontend: Check bundle
npm run build && du -sh dist/
```

## Real Case Studies

Actual performance fixes applied to this project, with measured results.

### Dropping 21 Unused Indexes

Queried `pg_stat_user_indexes` to find indexes with `idx_scan = 0`. Dropped in 3 migrations:

| Migration | What | Count |
|-----------|------|-------|
| `2026_02_16_100001_drop_redundant_indexes` | Redundant single-column indexes covered by composites | 4 |
| `2026_02_16_100002_drop_indexes_on_empty_tables` | Indexes on 0-row tables (unused features) | 15 |
| `2026_02_16_100003_drop_unused_indexes_on_active_tables` | Indexes with 0 scans on active tables | 6 |

All use `DROP INDEX CONCURRENTLY` with `$withinTransaction = false` to avoid locking.

### Webhook Index Optimization

Added partial composite index for the hottest query path (webhook conversation lookup):

```sql
-- 2026_02_16_100000_add_webhook_lookup_index_to_conversations
CREATE INDEX CONCURRENTLY idx_conversations_webhook_lookup
ON conversations (bot_id, external_customer_id, channel_type, status)
WHERE deleted_at IS NULL;
```

The `WHERE deleted_at IS NULL` partial index keeps it small and fast since soft-deleted rows are excluded.

### Embedding Chunking (array_chunk with preserve_keys)

`EmbeddingService::generateBatch()` chunks texts into batches of 25:

```php
$chunks = array_chunk($texts, 25, true);  // preserve_keys=true is critical

foreach ($chunks as $chunk) {
    $response = Http::post('/embeddings', ['input' => array_values($chunk)]);
    $offset = array_key_first($chunk);
    foreach ($data as $item) {
        $embeddings[$offset + $item['index']] = $item['embedding'];
    }
}
ksort($embeddings);
```

**Why `preserve_keys=true`**: Without it, each chunk resets keys to 0. The `$offset = array_key_first($chunk)` calculation needs original keys to map API response indexes back to the correct positions. Without preserved keys, embeddings get assigned to wrong texts.

### Message Pagination (DESC-then-reverse)

`ConversationQueryService::getConversation()` loads the latest N messages efficiently:

```php
// Step 1: Fetch latest N in DESC order (uses index, avoids OFFSET)
'messages' => fn($query) => $query->orderBy('created_at', 'desc')->limit($messagesLimit),

// Step 2: Reverse to chronological order for display
$conversation->setRelation('messages', $conversation->messages->reverse()->values());
```

**Why not ASC with OFFSET?** `OFFSET` requires scanning and discarding rows. DESC + LIMIT reads only the rows needed from the index tail, then `->reverse()->values()` flips to chronological in-memory (cheap for small N like 50-100).

### Laravel HTTP Retry Pattern

`OpenRouterService::client()` uses retry with exponential backoff:

```php
Http::baseUrl($this->baseUrl)
    ->timeout($requestTimeout)
    ->retry(3, function (int $attempt) {
        return $attempt * 200;  // 200ms, 400ms, 600ms
    }, throw: false, when: function (\Exception $e, $response) {
        return $response?->status() === 429 || $response?->status() >= 500;
    })
```

**Key: `throw: false`** — Without this, the retry mechanism throws its own exception after exhausting retries, bypassing the existing error handling in `chat()`. With `throw: false`, the failed response is returned normally and handled by the `$response->failed()` check downstream.

## Gotchas

| Problem | Solution |
|---------|----------|
| N+1 not detected | `preventLazyLoading()` in dev |
| Cache not working | Check `CACHE_DRIVER` env |
| Bundle too large | Use `lazy()` for routes |
| LCP slow | WebP + lazy loading |
| Memory spike | Use `chunk()` or `cursor()` |

## Key Files

| File | Purpose |
|------|---------|
| `config/cache.php` | Cache config |
| `config/database.php` | DB settings |
| `vite.config.ts` | Build optimization |
