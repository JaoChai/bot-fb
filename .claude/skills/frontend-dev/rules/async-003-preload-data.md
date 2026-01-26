---
id: async-003-preload-data
title: Preload Data Before Navigation
impact: HIGH
impactDescription: "Eliminates loading states on navigation - instant page transitions"
category: async
tags: [async, prefetch, navigation, ux]
relatedRules: [query-006-prefetching]
---

## Why This Matters

Users perceive navigation as slow when they see loading spinners after clicking a link. By preloading data on hover or focus, the data is already cached when they navigate, making the app feel instant.

## Bad Example

```tsx
// Problem: Data loads only after navigation
function BotCard({ bot }: { bot: Bot }) {
  return (
    <Link to={`/bots/${bot.id}`}>
      <Card>
        <h3>{bot.name}</h3>
      </Card>
    </Link>
    // User clicks → navigates → sees loading spinner → data loads
  );
}
```

**Why it's wrong:**
- User must wait for data after clicking
- Shows loading state on every navigation
- App feels slow and unresponsive

## Good Example

```tsx
// Solution: Prefetch on hover
function BotCard({ bot }: { bot: Bot }) {
  const queryClient = useQueryClient();

  const prefetchBot = () => {
    queryClient.prefetchQuery({
      queryKey: queryKeys.bots.detail(bot.id),
      queryFn: () => api.getBot(bot.id),
      staleTime: 60 * 1000, // Don't refetch if less than 1 min old
    });
  };

  return (
    <Link
      to={`/bots/${bot.id}`}
      onMouseEnter={prefetchBot}
      onFocus={prefetchBot}
    >
      <Card>
        <h3>{bot.name}</h3>
      </Card>
    </Link>
    // User hovers → data prefetches → user clicks → instant navigation
  );
}
```

**Why it's better:**
- Data loads while user is deciding to click
- Navigation feels instant
- No loading spinner on destination page

## Custom Hook Pattern

```tsx
// Reusable prefetch hook
function usePrefetchBot(botId: string) {
  const queryClient = useQueryClient();

  return useCallback(() => {
    queryClient.prefetchQuery({
      queryKey: queryKeys.bots.detail(botId),
      queryFn: () => api.getBot(botId),
      staleTime: 60 * 1000,
    });
  }, [queryClient, botId]);
}

// Usage
function BotCard({ bot }: { bot: Bot }) {
  const prefetch = usePrefetchBot(bot.id);

  return (
    <Link
      to={`/bots/${bot.id}`}
      onMouseEnter={prefetch}
      onFocus={prefetch}
    >
      ...
    </Link>
  );
}
```

## Prefetch Related Data

```tsx
// Prefetch multiple related queries
const prefetchBotWithConversations = () => {
  // Prefetch bot detail
  queryClient.prefetchQuery({
    queryKey: queryKeys.bots.detail(bot.id),
    queryFn: () => api.getBot(bot.id),
  });

  // Also prefetch recent conversations
  queryClient.prefetchQuery({
    queryKey: queryKeys.conversations.list({ botId: bot.id }),
    queryFn: () => api.getConversations(bot.id),
  });
};
```

## Project-Specific Notes

BotFacebook prefetch opportunities:
- Bot cards → bot detail + conversations
- Conversation list → message history
- Dashboard cards → detail pages

## References

- [React Query Prefetching](https://tanstack.com/query/latest/docs/react/guides/prefetching)
- Related rule: [query-006-prefetching](query-006-prefetching.md)
