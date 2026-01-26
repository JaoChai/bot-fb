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
