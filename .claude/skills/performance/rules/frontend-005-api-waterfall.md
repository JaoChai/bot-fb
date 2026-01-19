---
id: frontend-005-api-waterfall
title: API Request Waterfall
impact: HIGH
impactDescription: "Sequential API calls causing slow data loading"
category: frontend
tags: [api, waterfall, react-query, parallel]
relatedRules: [query-001-n-plus-one, cache-004-react-query-cache]
---

## Symptom

- Data loads in sequence, not parallel
- Long time to complete page load
- Multiple loading spinners appearing one after another
- Network waterfall shows sequential requests

## Root Cause

1. Dependent queries not parallelized
2. Fetching in useEffect chains
3. Not using React Query properly
4. Missing data prefetching
5. Over-fetching (requesting more than needed)

## Diagnosis

### Quick Check

```typescript
// Check Network tab waterfall
// Requests should start at same time, not sequential

// Check for chained useEffects
useEffect(() => {
  fetchUser().then(user => {
    fetchPosts(user.id);  // Waterfall!
  });
}, []);
```

### Detailed Analysis

```typescript
// Look for these patterns
// Bad: Sequential fetching
const user = await fetchUser();
const posts = await fetchPosts(user.id);
const comments = await fetchComments(posts[0].id);

// Good: Parallel when possible
const [user, settings] = await Promise.all([
  fetchUser(),
  fetchSettings(),
]);
```

## Measurement

```
Before: Total load time = sum of all requests
Target: Total load time ≈ longest single request
```

## Solution

### Fix Steps

1. **Parallel queries with React Query**
```typescript
// Use multiple useQuery hooks - they run in parallel
function Dashboard() {
  const userQuery = useQuery({
    queryKey: ['user'],
    queryFn: fetchUser,
  });

  const statsQuery = useQuery({
    queryKey: ['stats'],
    queryFn: fetchStats,
  });

  const botsQuery = useQuery({
    queryKey: ['bots'],
    queryFn: fetchBots,
  });

  // All three run in parallel!
}
```

2. **Use useQueries for dynamic parallel queries**
```typescript
function BotList({ botIds }: { botIds: string[] }) {
  const botQueries = useQueries({
    queries: botIds.map(id => ({
      queryKey: ['bot', id],
      queryFn: () => fetchBot(id),
    })),
  });
}
```

3. **Prefetch on hover/focus**
```typescript
function BotCard({ bot }: { bot: Bot }) {
  const queryClient = useQueryClient();

  const prefetchBot = () => {
    queryClient.prefetchQuery({
      queryKey: ['bot', bot.id, 'details'],
      queryFn: () => fetchBotDetails(bot.id),
      staleTime: 60000,
    });
  };

  return (
    <Link
      to={`/bots/${bot.id}`}
      onMouseEnter={prefetchBot}
      onFocus={prefetchBot}
    >
      {bot.name}
    </Link>
  );
}
```

4. **Prefetch in loader (React Router)**
```typescript
// router.tsx
{
  path: '/bots/:id',
  loader: async ({ params }) => {
    // Prefetch while route loads
    queryClient.prefetchQuery({
      queryKey: ['bot', params.id],
      queryFn: () => fetchBot(params.id),
    });
    return null;
  },
  element: <BotPage />,
}
```

5. **Dependent queries - only when necessary**
```typescript
function UserPosts({ userId }: { userId: string }) {
  // First query
  const userQuery = useQuery({
    queryKey: ['user', userId],
    queryFn: () => fetchUser(userId),
  });

  // Dependent query - runs after user loads
  const postsQuery = useQuery({
    queryKey: ['posts', userQuery.data?.id],
    queryFn: () => fetchPosts(userQuery.data!.id),
    enabled: !!userQuery.data?.id,  // Only run when user loaded
  });
}
```

6. **Batch API endpoint**
```typescript
// Instead of: /api/bots/1, /api/bots/2, /api/bots/3
// Use: /api/bots?ids=1,2,3

const botsQuery = useQuery({
  queryKey: ['bots', botIds],
  queryFn: () => fetchBots(botIds),  // Single request
});
```

### Request Strategy Matrix

| Scenario | Strategy |
|----------|----------|
| Independent data | Parallel useQuery hooks |
| Dynamic list | useQueries |
| Truly dependent | enabled: condition |
| Navigation | Prefetch on hover |
| List items | Batch API endpoint |

## Verification

```typescript
// Check Network tab
// Parallel requests should start at same time

// Add timing logs
console.time('all-data');
await Promise.all([...queries]);
console.timeEnd('all-data');
```

## Prevention

- Default to parallel queries
- Use enabled: only when truly dependent
- Implement prefetching
- Create batch endpoints
- Review Network waterfall regularly

## Project-Specific Notes

**BotFacebook Context:**
- Batch endpoints: `/api/bots?ids=`, `/api/conversations?ids=`
- Prefetch: Bot details on hover
- Query keys: Use `queryKeys` factory from `src/lib/queryKeys.ts`
- Parallel: Dashboard loads bots, stats, activity in parallel
