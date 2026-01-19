---
id: query-002-enabled-option
title: Conditional Fetching with enabled Option
impact: HIGH
impactDescription: "Prevents unnecessary API calls and errors from missing dependencies"
category: query
tags: [react-query, conditional, enabled, dependent-queries]
relatedRules: [query-001-query-keys-factory]
---

## Why This Matters

The `enabled` option controls when a query should fetch. Without it, queries run immediately on component mount, even when required data (like an ID from URL params) isn't available yet. This causes unnecessary API errors and wasted requests.

## Bad Example

```tsx
// Problem 1: Query runs before ID is available
function BotDetail() {
  const { id } = useParams(); // Initially undefined during first render

  const { data } = useQuery({
    queryKey: ['bots', id],
    queryFn: () => api.get(`/api/v1/bots/${id}`), // Fetches with undefined ID!
  });

  // API call goes to /api/v1/bots/undefined → 404 error
}

// Problem 2: Dependent query runs too early
function BotAnalytics({ botId }) {
  const { data: bot } = useQuery({
    queryKey: ['bots', botId],
    queryFn: () => fetchBot(botId),
  });

  // Runs immediately, but bot.settings might be needed
  const { data: analytics } = useQuery({
    queryKey: ['analytics', botId],
    queryFn: () => fetchAnalytics(botId, bot.settings.timezone), // Error! bot is undefined
  });
}

// Problem 3: Checking inside queryFn instead of using enabled
function SearchResults({ query }) {
  const { data } = useQuery({
    queryKey: ['search', query],
    queryFn: async () => {
      if (!query) return []; // Still creates cache entry for empty query
      return api.get('/api/v1/search', { params: { q: query } });
    },
  });
}
```

**Why it's wrong:**
- Queries with undefined params cause 404 errors
- Dependent queries error when accessing undefined data
- Checking inside queryFn still creates unnecessary cache entries
- Wasted API calls and poor UX (error flashes)

## Good Example

```tsx
// Solution 1: Disable until ID is available
function BotDetail() {
  const { id } = useParams<{ id: string }>();

  const { data, isLoading } = useQuery({
    queryKey: queryKeys.bots.detail(id!),
    queryFn: () => api.get(`/api/v1/bots/${id}`),
    enabled: !!id, // Only fetch when id exists
  });

  // Query stays in 'pending' state until enabled
  if (!id) return <p>No bot selected</p>;
  if (isLoading) return <Spinner />;

  return <BotView bot={data} />;
}

// Solution 2: Dependent queries with proper chaining
function BotAnalytics({ botId }) {
  const { data: bot, isSuccess: botLoaded } = useQuery({
    queryKey: queryKeys.bots.detail(botId),
    queryFn: () => fetchBot(botId),
    enabled: !!botId,
  });

  // Only runs after bot is successfully loaded
  const { data: analytics } = useQuery({
    queryKey: queryKeys.analytics.bot(botId),
    queryFn: () => fetchAnalytics(botId, bot!.settings.timezone),
    enabled: botLoaded && !!bot?.settings?.timezone, // Dependent on bot data
  });

  return (/* ... */);
}

// Solution 3: Search with minimum query length
function SearchResults({ query }) {
  const debouncedQuery = useDebounce(query, 300);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: queryKeys.search.results(debouncedQuery),
    queryFn: () => api.get('/api/v1/search', { params: { q: debouncedQuery } }),
    enabled: debouncedQuery.length >= 2, // Only search with 2+ characters
  });

  if (!debouncedQuery) return <p>Enter search term</p>;
  if (debouncedQuery.length < 2) return <p>Type at least 2 characters</p>;
  if (isLoading) return <Spinner />;

  return <SearchList results={data} />;
}

// Solution 4: Feature flag or permission check
function AdminDashboard() {
  const { user } = useAuth();

  const { data: adminStats } = useQuery({
    queryKey: ['admin', 'stats'],
    queryFn: fetchAdminStats,
    enabled: user?.role === 'admin', // Only admins can access
  });

  if (!user || user.role !== 'admin') {
    return <Unauthorized />;
  }

  return <StatsDisplay stats={adminStats} />;
}
```

**Why it's better:**
- No API calls until data is ready
- No error states from undefined params
- Clean dependency chain between queries
- Efficient - no unnecessary cache entries
- Better UX - appropriate loading states

## Project-Specific Notes

**Common BotFacebook Patterns:**
```tsx
// Bot detail pages - wait for route param
const { id } = useParams();
enabled: !!id

// Conversation messages - wait for conversation selection
enabled: !!selectedConversationId

// User-specific data - wait for auth
const { user } = useAuthStore();
enabled: !!user?.id

// Filtered lists - wait for filter selection
enabled: filters.botId !== undefined
```

**Handling Loading States:**
```tsx
// isPending = true when enabled is false
// isLoading = isPending && isFetching (actually loading)

const { data, isPending, isLoading, isFetching } = useQuery({
  queryKey: ['data', id],
  queryFn: fetchData,
  enabled: !!id,
});

// isPending: true (waiting for enabled)
// isLoading: false (not actually fetching)
// isFetching: false

// After id becomes truthy:
// isPending: true → false
// isLoading: true → false
// isFetching: true → false
```

## References

- [TanStack Query Dependent Queries](https://tanstack.com/query/latest/docs/framework/react/guides/dependent-queries)
- [Disabling Queries](https://tanstack.com/query/latest/docs/framework/react/guides/disabling-queries)
