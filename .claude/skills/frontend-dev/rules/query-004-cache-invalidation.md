---
id: query-004-cache-invalidation
title: Cache Invalidation Strategies
impact: HIGH
impactDescription: "Ensures UI shows fresh data after mutations"
category: query
tags: [react-query, cache, invalidation, refetch]
relatedRules: [query-001-query-keys-factory, query-003-optimistic-updates]
---

## Why This Matters

After a mutation, the cached data is stale. Without proper invalidation, the UI shows outdated information. Too aggressive invalidation wastes API calls; too conservative means stale data.

Finding the right balance requires understanding React Query's cache hierarchy and invalidation patterns.

## Bad Example

```tsx
// Problem 1: Forgetting to invalidate
const mutation = useMutation({
  mutationFn: updateBot,
  // No onSuccess - cache stays stale forever!
});

// Problem 2: Over-invalidating
const mutation = useMutation({
  mutationFn: updateBot,
  onSuccess: () => {
    // Invalidates EVERYTHING - wasteful
    queryClient.invalidateQueries();
  },
});

// Problem 3: Wrong key - invalidation silently fails
const mutation = useMutation({
  mutationFn: (id) => api.delete(`/api/v1/bots/${id}`),
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['bot'] }); // Wrong! Should be 'bots'
    // Nothing invalidated, deleted bot still shows
  },
});

// Problem 4: Not invalidating related data
const mutation = useMutation({
  mutationFn: deleteBot,
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['bots', 'list'] });
    // What about bot detail pages? Statistics? Conversations?
  },
});
```

**Why it's wrong:**
- Missing invalidation leaves stale data in UI
- Invalidating everything causes unnecessary refetches
- Typos in keys cause silent failures
- Forgetting related data causes inconsistent UI

## Good Example

```tsx
// Solution: Strategic invalidation with query key factory
function useDeleteBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (botId: string) =>
      api.delete(`/api/v1/bots/${botId}`),

    onSuccess: (_, botId) => {
      // Remove specific bot from cache immediately
      queryClient.removeQueries({ queryKey: queryKeys.bots.detail(botId) });

      // Invalidate lists (will refetch)
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.lists() });

      // Also invalidate related data
      queryClient.invalidateQueries({
        queryKey: queryKeys.conversations.list(botId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.stats.bot(botId),
      });
    },
  });
}

// Granular invalidation based on what changed
function useUpdateBotSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ botId, settings }: UpdateSettingsArgs) =>
      api.put(`/api/v1/bots/${botId}/settings`, settings),

    onSuccess: (newSettings, { botId }) => {
      // Update specific cache entry (no refetch)
      queryClient.setQueryData(
        queryKeys.bots.settings(botId),
        newSettings
      );

      // Invalidate bot detail (has settings embedded)
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.detail(botId),
      });

      // Don't invalidate lists - settings not shown there
    },
  });
}

// Conditional invalidation based on mutation result
function useToggleBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (botId: string) =>
      api.patch<Bot>(`/api/v1/bots/${botId}/toggle`),

    onSuccess: (updatedBot) => {
      // Update detail cache with server response
      queryClient.setQueryData(
        queryKeys.bots.detail(updatedBot.id),
        updatedBot
      );

      // Invalidate lists (active status affects filtering)
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.lists(),
      });
    },
  });
}

// Predicate-based invalidation
function useGlobalRefresh() {
  const queryClient = useQueryClient();

  return () => {
    // Invalidate all queries that start with 'bots'
    queryClient.invalidateQueries({
      predicate: (query) =>
        query.queryKey[0] === 'bots' ||
        query.queryKey[0] === 'conversations',
    });
  };
}

// Exact vs fuzzy matching
function invalidationExamples() {
  // Fuzzy (default) - invalidates all matching prefixes
  queryClient.invalidateQueries({ queryKey: ['bots'] });
  // Matches: ['bots'], ['bots', 'list'], ['bots', 'detail', '123']

  // Exact - only invalidates exact match
  queryClient.invalidateQueries({
    queryKey: ['bots', 'list'],
    exact: true,
  });
  // Only matches: ['bots', 'list']
}
```

**Why it's better:**
- Query key factory prevents typos
- Granular invalidation minimizes API calls
- `setQueryData` for instant updates when you have the data
- `removeQueries` for deleted items
- Clear hierarchy of what gets invalidated

## Project-Specific Notes

**BotFacebook Invalidation Patterns:**

| Mutation | Invalidate |
|----------|------------|
| Create bot | `bots.lists()` |
| Update bot | `bots.detail(id)`, `bots.lists()` |
| Delete bot | `remove bots.detail(id)`, `bots.lists()`, `conversations.list(botId)` |
| Send message | `conversations.messages(convId)` |
| Read conversation | `conversations.detail(convId)`, `conversations.lists()` |

**Invalidation Decision Tree:**
```
Did the mutation change list membership?
├── Yes → invalidateQueries(lists)
└── No → setQueryData(detail) only

Does the mutation affect related entities?
├── Yes → invalidateQueries(related)
└── No → Skip

Is the entity deleted?
├── Yes → removeQueries(detail)
└── No → invalidateQueries or setQueryData
```

## References

- [TanStack Query Invalidation](https://tanstack.com/query/latest/docs/framework/react/guides/query-invalidation)
- [Query Filters](https://tanstack.com/query/latest/docs/framework/react/guides/filters)
