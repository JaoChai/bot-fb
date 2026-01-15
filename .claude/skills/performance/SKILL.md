---
name: performance
description: Performance optimization specialist for backend and frontend. Identifies N+1 queries, slow database operations, bundle size issues, Core Web Vitals problems, API response time. Use when the app is slow, queries take too long, pages load slowly, or when optimizing for production.
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

## Performance Targets

| Metric | Target | Tool |
|--------|--------|------|
| API response | < 500ms | Sentry traces |
| AI evaluation | < 1.5s | Logs |
| Page load (LCP) | < 2.5s | Lighthouse |
| Database query | < 100ms | Neon |
| Bundle size | < 500KB gzipped | Vite |

## Backend Optimization

### Find N+1 Queries

```php
// Bad: N+1 problem
$bots = Bot::all();
foreach ($bots as $bot) {
    echo $bot->user->name; // Query per bot!
}

// Good: Eager loading
$bots = Bot::with('user')->get();
```

### Use EXPLAIN ANALYZE

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM messages
WHERE conversation_id = 123
ORDER BY created_at DESC;
```

### Common Query Optimizations

| Issue | Fix |
|-------|-----|
| Sequential Scan | Add index |
| High rows examined | Add WHERE conditions |
| Slow ORDER BY | Add sorted index |
| JSONB slow | Add GIN index |

### Query Caching

```php
// Cache expensive queries
$stats = Cache::remember('bot.stats.'.$botId, 3600, function () use ($botId) {
    return Bot::find($botId)->calculateStats();
});
```

## Frontend Optimization

### Bundle Analysis

```bash
# Analyze bundle
npm run build -- --report

# Check for large dependencies
npx source-map-explorer dist/assets/*.js
```

### Code Splitting

```typescript
// Lazy load routes
const Dashboard = lazy(() => import('./pages/Dashboard'));

// Wrap with Suspense
<Suspense fallback={<Loading />}>
  <Dashboard />
</Suspense>
```

### React Performance

```typescript
// Memoize expensive computations
const expensiveResult = useMemo(() =>
  computeExpensiveValue(data), [data]
);

// Memoize callbacks
const handleClick = useCallback(() => {
  doSomething(id);
}, [id]);

// Memoize components
const MemoizedComponent = memo(Component);
```

### React Query Optimization

```typescript
// Stale-while-revalidate pattern
useQuery({
  queryKey: ['data'],
  queryFn: fetchData,
  staleTime: 5 * 60 * 1000,    // 5 minutes
  cacheTime: 30 * 60 * 1000,   // 30 minutes
});

// Prefetch on hover
const prefetchData = () => {
  queryClient.prefetchQuery(['data', id], () => fetchData(id));
};
```

## Core Web Vitals

### LCP (Largest Contentful Paint) < 2.5s
- Optimize images (WebP, lazy loading)
- Preload critical resources
- Use CDN

### FID (First Input Delay) < 100ms
- Split long tasks
- Defer non-critical JavaScript
- Use web workers

### CLS (Cumulative Layout Shift) < 0.1
- Set image dimensions
- Reserve space for dynamic content
- Avoid inserting content above existing

## Detailed Guides

- **Query Optimization**: See [QUERY_OPTIMIZATION.md](QUERY_OPTIMIZATION.md)
- **Frontend Performance**: See [FRONTEND_PERF.md](FRONTEND_PERF.md)

## Key Commands

```bash
# Find slow queries in logs
grep -i "slow query" storage/logs/laravel.log

# Run Lighthouse
npx lighthouse https://www.botjao.com --view

# Check bundle size
npm run build && du -sh dist/
```

## Performance Debug Output

```
⚡ Performance Report
━━━━━━━━━━━━━━━━━━━━━━━
Target: [endpoint/page]

📊 Backend:
- Response time: XXXms (target: <500ms)
- Queries: XX (N+1 detected: Yes/No)
- Slowest query: XXXms

📊 Frontend:
- Bundle size: XXX KB
- LCP: X.Xs
- FID: XXms
- CLS: 0.XX

🎯 Bottleneck: [identified issue]

💡 Optimizations:
1. [specific fix]
2. [specific fix]
```

## Utility Scripts

- `scripts/find_n1_queries.php` - Detect N+1 patterns
- `scripts/analyze_bundle.sh` - Bundle size analysis
