---
id: async-001-parallel-fetching
title: Parallel Data Fetching with Promise.all
impact: CRITICAL
impactDescription: "Eliminates waterfall delays - can reduce load time by 50-80%"
category: async
tags: [async, performance, data-fetching, waterfall]
relatedRules: [async-002-avoid-sequential-awaits, query-001-query-keys-factory]
---

## Why This Matters

Waterfalls are the #1 performance killer in web applications. When you fetch data sequentially, each request waits for the previous one to complete. If each request takes 200ms, three sequential requests take 600ms. With parallel fetching, they complete in ~200ms total.

## Bad Example

```tsx
// Problem: Sequential fetching creates waterfall
async function loadDashboardData(userId: string) {
  const user = await api.getUser(userId);           // 200ms
  const bots = await api.getBots(userId);           // +200ms (waits for user)
  const conversations = await api.getConversations(); // +200ms (waits for bots)

  return { user, bots, conversations };
  // Total: 600ms
}
```

**Why it's wrong:**
- Each request waits for the previous one to complete
- Total time = sum of all request times
- User sees loading state for much longer than necessary

## Good Example

```tsx
// Solution: Parallel fetching with Promise.all
async function loadDashboardData(userId: string) {
  const [user, bots, conversations] = await Promise.all([
    api.getUser(userId),
    api.getBots(userId),
    api.getConversations(),
  ]);

  return { user, bots, conversations };
  // Total: ~200ms (max of all requests)
}
```

**Why it's better:**
- All requests start simultaneously
- Total time = longest single request
- 3x faster in this example

## With React Query

```tsx
// Parallel queries in component
function Dashboard({ userId }: { userId: string }) {
  // These run in parallel automatically
  const userQuery = useQuery({
    queryKey: queryKeys.users.detail(userId),
    queryFn: () => api.getUser(userId),
  });

  const botsQuery = useQuery({
    queryKey: queryKeys.bots.list(userId),
    queryFn: () => api.getBots(userId),
  });

  // Or use useQueries for dynamic parallel queries
  const queries = useQueries({
    queries: botIds.map(id => ({
      queryKey: queryKeys.bots.detail(id),
      queryFn: () => api.getBot(id),
    })),
  });
}
```

## Project-Specific Notes

In BotFacebook, common parallel fetch opportunities:
- Dashboard: user + bots + recent conversations
- Bot detail: bot info + conversations + knowledge bases
- Chat: messages + conversation info + bot settings

## References

- [Promise.all() - MDN](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Promise/all)
- [React Query useQueries](https://tanstack.com/query/latest/docs/react/reference/useQueries)
