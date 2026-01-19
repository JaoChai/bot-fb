---
id: query-001-query-keys-factory
title: Query Keys Factory Pattern
impact: CRITICAL
impactDescription: "Prevents cache misses and ensures consistent cache invalidation"
category: query
tags: [react-query, cache, invalidation, keys]
relatedRules: [query-004-cache-invalidation, gotcha-001-response-data-access]
---

## Why This Matters

Query keys determine cache identity in React Query. Inconsistent keys cause data not to update, stale data showing, or unnecessary refetches. A centralized query keys factory ensures consistency across the codebase.

Without a factory, it's easy to have `['bots']` in one place and `['bot', 'list']` in another - breaking cache sharing.

## Bad Example

```tsx
// Problem 1: Inconsistent key formats
// In BotList.tsx
const { data } = useQuery({
  queryKey: ['bots'],
  queryFn: fetchBots,
});

// In BotGrid.tsx - different key format!
const { data } = useQuery({
  queryKey: ['bot', 'list'],
  queryFn: fetchBots,
});

// In useBots.ts - yet another format!
const { data } = useQuery({
  queryKey: ['allBots'],
  queryFn: fetchBots,
});

// Problem 2: Inline key arrays
const { data } = useQuery({
  queryKey: ['bots', { status: 'active', page: 1 }], // Object order matters!
  queryFn: () => fetchBots({ status: 'active', page: 1 }),
});

// Elsewhere - same data but cache miss!
const { data } = useQuery({
  queryKey: ['bots', { page: 1, status: 'active' }], // Different order = different key
  queryFn: () => fetchBots({ status: 'active', page: 1 }),
});

// Problem 3: Typos in invalidation
queryClient.invalidateQueries({ queryKey: ['bot'] }); // Missing 's' - doesn't invalidate!
```

**Why it's wrong:**
- Different keys = different cache entries = duplicated data fetching
- Object key order matters in arrays
- Typos in keys cause silent cache invalidation failures
- No single source of truth for key structure

## Good Example

```tsx
// Solution: Centralized query keys factory
// src/lib/queryKeys.ts
export const queryKeys = {
  bots: {
    all: ['bots'] as const,
    lists: () => [...queryKeys.bots.all, 'list'] as const,
    list: (filters?: BotFilters) => [...queryKeys.bots.lists(), filters] as const,
    details: () => [...queryKeys.bots.all, 'detail'] as const,
    detail: (id: string) => [...queryKeys.bots.details(), id] as const,
    settings: (id: string) => [...queryKeys.bots.detail(id), 'settings'] as const,
  },
  conversations: {
    all: ['conversations'] as const,
    lists: () => [...queryKeys.conversations.all, 'list'] as const,
    list: (botId: string, filters?: ConvoFilters) =>
      [...queryKeys.conversations.lists(), botId, filters] as const,
    details: () => [...queryKeys.conversations.all, 'detail'] as const,
    detail: (id: string) => [...queryKeys.conversations.details(), id] as const,
    messages: (conversationId: string) =>
      [...queryKeys.conversations.detail(conversationId), 'messages'] as const,
  },
  users: {
    all: ['users'] as const,
    current: () => [...queryKeys.users.all, 'current'] as const,
    detail: (id: string) => [...queryKeys.users.all, id] as const,
  },
} as const;

// Usage in queries
function useBots(filters?: BotFilters) {
  return useQuery({
    queryKey: queryKeys.bots.list(filters), // Consistent!
    queryFn: () => api.get('/api/v1/bots', { params: filters }),
  });
}

function useBot(id: string) {
  return useQuery({
    queryKey: queryKeys.bots.detail(id),
    queryFn: () => api.get(`/api/v1/bots/${id}`),
    enabled: !!id,
  });
}

// Invalidation with type safety
function useUpdateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: UpdateBotArgs) =>
      api.put(`/api/v1/bots/${id}`, data),
    onSuccess: (_, { id }) => {
      // Invalidate specific bot
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.detail(id) });
      // Invalidate all lists (might contain this bot)
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.lists() });
    },
  });
}

// Hierarchical invalidation
function useDeleteBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => api.delete(`/api/v1/bots/${id}`),
    onSuccess: () => {
      // Invalidate everything under 'bots'
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.all });
    },
  });
}
```

**Why it's better:**
- Single source of truth for all query keys
- `as const` provides TypeScript type safety
- Hierarchical structure enables granular invalidation
- Factory functions ensure consistent filter ordering
- Autocomplete helps prevent typos

## Project-Specific Notes

**BotFacebook Query Keys:**
```tsx
// Located at: frontend/src/lib/query.ts
// Key structure mirrors API endpoints

queryKeys.bots.list({ status: 'active' })
// → ['bots', 'list', { status: 'active' }]

queryKeys.conversations.messages('conv-123')
// → ['conversations', 'detail', 'conv-123', 'messages']
```

**Key Hierarchy:**
```
bots
├── all          → ['bots']
├── lists        → ['bots', 'list']
│   └── list     → ['bots', 'list', filters]
├── details      → ['bots', 'detail']
│   └── detail   → ['bots', 'detail', id]
│       └── settings → ['bots', 'detail', id, 'settings']
```

**Invalidation Patterns:**
| Scenario | Invalidation Key |
|----------|------------------|
| Bot updated | `queryKeys.bots.detail(id)` |
| Any bot changed | `queryKeys.bots.lists()` |
| Everything changed | `queryKeys.bots.all` |

## References

- [TanStack Query Keys](https://tanstack.com/query/latest/docs/framework/react/guides/query-keys)
- [Effective Query Keys](https://tkdodo.eu/blog/effective-react-query-keys)
