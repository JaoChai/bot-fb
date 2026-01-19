---
id: cache-004-react-query-cache
title: React Query Cache Management
impact: MEDIUM
impactDescription: "Inefficient client-side caching causing unnecessary refetches"
category: cache
tags: [react-query, cache, frontend, stale-time]
relatedRules: [frontend-005-api-waterfall, cache-003-invalidation]
---

## Symptom

- Data refetched on every component mount
- Flash of loading state on navigation
- Too many API requests in Network tab
- Stale data showing after mutations

## Root Cause

1. staleTime too short (default: 0)
2. Not using query keys properly
3. Missing optimistic updates
4. Over-invalidating after mutations
5. Not configuring gcTime (garbage collection)

## Diagnosis

### Quick Check

```typescript
// Check React Query Devtools
// Install: npm i @tanstack/react-query-devtools

import { ReactQueryDevtools } from '@tanstack/react-query-devtools';

<QueryClientProvider client={queryClient}>
  <App />
  <ReactQueryDevtools initialIsOpen={false} />
</QueryClientProvider>
```

### Detailed Analysis

```typescript
// Check current cache state
const queryClient = useQueryClient();
console.log(queryClient.getQueryCache().getAll());

// Check specific query
const queryState = queryClient.getQueryState(['bots']);
console.log(queryState);
```

## Measurement

```
Before: Refetch on every mount, many duplicate requests
Target: Use cached data, minimal refetches
```

## Solution

### Fix Steps

1. **Configure proper staleTime**
```typescript
// lib/query.ts
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60,  // 1 minute
      gcTime: 1000 * 60 * 5,  // 5 minutes (was cacheTime)
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
});

// Per-query staleTime
const { data } = useQuery({
  queryKey: ['bots'],
  queryFn: fetchBots,
  staleTime: 1000 * 60 * 5,  // 5 minutes for this query
});
```

2. **Use proper query keys**
```typescript
// Query key factory pattern
export const queryKeys = {
  bots: {
    all: ['bots'] as const,
    list: (params: BotListParams) => ['bots', 'list', params] as const,
    detail: (id: string) => ['bots', 'detail', id] as const,
    settings: (id: string) => ['bots', 'settings', id] as const,
  },
  conversations: {
    all: ['conversations'] as const,
    list: (botId: string) => ['conversations', 'list', botId] as const,
    messages: (convId: string) => ['conversations', 'messages', convId] as const,
  },
};

// Usage
useQuery({
  queryKey: queryKeys.bots.detail(botId),
  queryFn: () => fetchBot(botId),
});
```

3. **Optimistic updates for mutations**
```typescript
const updateBotMutation = useMutation({
  mutationFn: (data: UpdateBotData) => api.bots.update(data),
  onMutate: async (newData) => {
    // Cancel in-flight queries
    await queryClient.cancelQueries({
      queryKey: queryKeys.bots.detail(newData.id),
    });

    // Snapshot previous value
    const previous = queryClient.getQueryData(
      queryKeys.bots.detail(newData.id)
    );

    // Optimistically update
    queryClient.setQueryData(
      queryKeys.bots.detail(newData.id),
      (old: Bot) => ({ ...old, ...newData })
    );

    return { previous };
  },
  onError: (err, variables, context) => {
    // Rollback on error
    queryClient.setQueryData(
      queryKeys.bots.detail(variables.id),
      context?.previous
    );
  },
  onSettled: (data, error, variables) => {
    // Always refetch after mutation
    queryClient.invalidateQueries({
      queryKey: queryKeys.bots.detail(variables.id),
    });
  },
});
```

4. **Selective invalidation**
```typescript
// Bad: Invalidate everything
queryClient.invalidateQueries({ queryKey: ['bots'] });

// Good: Invalidate specific queries
queryClient.invalidateQueries({
  queryKey: queryKeys.bots.detail(botId),
});

// Good: Invalidate related but not all
queryClient.invalidateQueries({
  queryKey: queryKeys.bots.list,
  exact: false,
});
```

5. **Prefetch for instant navigation**
```typescript
function BotList() {
  const queryClient = useQueryClient();

  const prefetchBot = (botId: string) => {
    queryClient.prefetchQuery({
      queryKey: queryKeys.bots.detail(botId),
      queryFn: () => fetchBot(botId),
      staleTime: 1000 * 60,
    });
  };

  return (
    <ul>
      {bots.map(bot => (
        <li
          key={bot.id}
          onMouseEnter={() => prefetchBot(bot.id)}
        >
          <Link to={`/bots/${bot.id}`}>{bot.name}</Link>
        </li>
      ))}
    </ul>
  );
}
```

### Cache Time Guidelines

| Data Type | staleTime | gcTime |
|-----------|-----------|--------|
| User profile | 5 min | 30 min |
| Bot list | 1 min | 10 min |
| Bot details | 2 min | 10 min |
| Messages | 30 sec | 5 min |
| Real-time data | 0 | 1 min |
| Static config | 1 hour | 24 hours |

## Verification

```typescript
// Check with React Query Devtools
// Green = fresh, Yellow = stale, Gray = inactive

// Log cache state
useEffect(() => {
  console.log('Cache entries:', queryClient.getQueryCache().getAll().length);
}, []);
```

## Prevention

- Configure global staleTime > 0
- Use query key factory
- Implement optimistic updates
- Review devtools regularly
- Document cache invalidation rules

## Project-Specific Notes

**BotFacebook Context:**
- Query keys: `src/lib/queryKeys.ts`
- Default staleTime: 60 seconds
- Bots/conversations: Optimistic updates
- Real-time messages: WebSocket, minimal caching
- Devtools: Enabled in development
