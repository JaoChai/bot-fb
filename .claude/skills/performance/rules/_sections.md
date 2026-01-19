# Performance Decision Trees and Guides

## Decision Tree 1: Slow Response Diagnosis

```
Response slow?
├── Which phase?
│   ├── Database (TTFB high)
│   │   ├── Single slow query → Optimize query, add index
│   │   ├── Many queries (N+1) → Add eager loading
│   │   └── Connection pool → Increase pool size
│   │
│   ├── Application (processing)
│   │   ├── Computation heavy → Cache results
│   │   ├── External API call → Async/queue
│   │   └── Memory issues → Optimize memory usage
│   │
│   └── Network (latency)
│       ├── Large payload → Compress, paginate
│       ├── No caching → Add Cache-Control headers
│       └── CDN not used → Enable CDN
│
└── Full investigation needed
    ├── Profile with Xdebug/Blackfire
    ├── Check Sentry traces
    └── Enable query logging
```

## Decision Tree 2: N+1 Query Detection

```
N+1 Query?
├── How to detect
│   ├── Laravel Debugbar shows many queries
│   ├── Log shows repeated similar queries
│   └── Sentry shows high query count
│
├── Where it occurs
│   ├── Loop accessing relationship
│   │   └── Fix: ->with('relation')
│   ├── Blade template accessing relationship
│   │   └── Fix: Eager load in controller
│   └── API resource accessing relationship
│       └── Fix: whenLoaded() or eager load
│
└── Prevention
    ├── Enable preventLazyLoading() in dev
    ├── Review queries in code review
    └── Set up N+1 detection alerts
```

## Decision Tree 3: Frontend Performance

```
Frontend slow?
├── Initial load slow
│   ├── Large bundle
│   │   ├── Too many dependencies → Audit, remove unused
│   │   ├── No code splitting → Implement lazy routes
│   │   └── Large images → Compress, use WebP
│   │
│   └── Server response slow
│       ├── No SSR/SSG → Consider if needed
│       └── API data blocking → Prefetch/parallel
│
├── Interaction slow
│   ├── JavaScript blocking
│   │   ├── Long tasks → Split into smaller chunks
│   │   └── Heavy computation → Use Web Worker
│   │
│   └── React re-renders
│       ├── Missing memoization → useMemo/useCallback
│       ├── State updates in loop → Batch updates
│       └── Prop drilling → Use context/store
│
└── Layout shifts
    ├── Images without dimensions → Add width/height
    ├── Dynamic content → Reserve space
    └── Font loading → Use font-display: swap
```

## Decision Tree 4: Caching Strategy

```
What to cache?
├── Database query results
│   ├── Expensive aggregations → Cache 5-15 min
│   ├── Rarely changing data → Cache 1+ hour
│   └── User-specific data → Cache per user key
│
├── API responses
│   ├── Public data → HTTP cache headers
│   ├── Semi-static → Stale-while-revalidate
│   └── Real-time needed → Don't cache
│
├── Computed values
│   ├── Heavy calculations → Cache result
│   ├── Repeated transforms → Memoize
│   └── Config values → Cache on boot
│
└── Frontend
    ├── React Query → staleTime for data freshness
    ├── Static assets → Long cache + hash
    └── API data → IndexedDB for offline
```

## Decision Tree 5: Index Selection

```
Need index?
├── Query pattern
│   ├── Equality (=)
│   │   └── B-tree index (default)
│   │
│   ├── Range (<, >, BETWEEN)
│   │   └── B-tree index
│   │
│   ├── Text search (LIKE 'x%')
│   │   └── B-tree (prefix only) or GIN
│   │
│   ├── Full-text search
│   │   └── GIN with tsvector
│   │
│   ├── JSONB operations
│   │   └── GIN index
│   │
│   └── Vector similarity
│       └── HNSW or IVFFlat
│
└── Multi-column?
    ├── Multiple WHERE conditions → Composite index
    ├── ORDER BY included → Include in index
    └── Covering index → Include all SELECT columns
```

## Performance Targets Reference

| Metric | Target | Action Required |
|--------|--------|-----------------|
| API Response | < 500ms | Optimize DB/logic |
| AI Evaluation | < 1.5s | Acceptable |
| Page Load (LCP) | < 2.5s | Optimize assets |
| First Input Delay | < 100ms | Reduce JS |
| Cumulative Layout Shift | < 0.1 | Fix layout |
| DB Query | < 100ms | Add index |
| Bundle Size (gzipped) | < 500KB | Code split |

## Measurement Tools

### Backend
- **Query Time**: Laravel query log, Neon MCP
- **API Time**: Sentry traces, response headers
- **Memory**: `memory_get_peak_usage()`

### Frontend
- **Lighthouse**: Core Web Vitals
- **Chrome DevTools**: Performance tab
- **Bundle**: `npm run build -- --report`
- **React**: React DevTools Profiler

## Common Patterns

### Query Optimization Pattern
```php
// Before: Unoptimized
$users = User::all();
foreach ($users as $user) {
    $postCount = $user->posts()->count();
}

// After: Optimized
$users = User::withCount('posts')->get();
foreach ($users as $user) {
    $postCount = $user->posts_count;
}
```

### Caching Pattern
```php
// Standard caching pattern
$result = Cache::remember(
    "key:{$id}",
    now()->addHours(1),
    fn() => $this->expensiveOperation($id)
);

// With tags for invalidation
$result = Cache::tags(['users', 'stats'])
    ->remember("user:{$id}:stats", 3600, fn() => ...);

// Invalidate
Cache::tags(['users'])->flush();
```

### React Memoization Pattern
```typescript
// Memoize expensive computation
const processed = useMemo(() =>
  heavyProcess(data),
  [data]
);

// Memoize callback
const handleClick = useCallback((id: string) => {
  doSomething(id);
}, [doSomething]);

// Memoize component
const MemoizedList = memo(List, (prev, next) =>
  prev.items.length === next.items.length
);
```
