---
id: query-006-prefetching
title: Prefetching for Instant Navigation
impact: MEDIUM
impactDescription: "Eliminates loading states for predictable user navigation"
category: query
tags: [react-query, prefetch, performance, ux]
relatedRules: [perf-001-memoization]
---

## Why This Matters

Prefetching loads data before the user navigates, making page transitions feel instant. By anticipating where users will go (hover on links, visible items), you can eliminate loading spinners entirely.

This creates a perception of a much faster application.

## Bad Example

```tsx
// Problem: No prefetching - always shows loading
function BotCard({ bot }) {
  return (
    <Link to={`/bots/${bot.id}`}>
      <h3>{bot.name}</h3>
    </Link>
    // User clicks → sees loading spinner → waits for data
  );
}

// Problem: Prefetching on every render
function BotList({ bots }) {
  const queryClient = useQueryClient();

  // This runs on every render!
  bots.forEach(bot => {
    queryClient.prefetchQuery({
      queryKey: ['bots', bot.id],
      queryFn: () => fetchBot(bot.id),
    });
  });

  return <ul>{/* ... */}</ul>;
}

// Problem: Prefetching too eagerly
function SearchResults({ results }) {
  const queryClient = useQueryClient();

  useEffect(() => {
    // Prefetches ALL results - 50+ API calls!
    results.forEach(result => {
      queryClient.prefetchQuery({
        queryKey: ['items', result.id],
        queryFn: () => fetchItem(result.id),
      });
    });
  }, [results]);
}
```

**Why it's wrong:**
- No prefetching means users always wait
- Prefetching on render wastes resources
- Prefetching all items makes too many requests
- No consideration of user intent

## Good Example

```tsx
// Solution 1: Prefetch on hover
function BotCard({ bot }: { bot: Bot }) {
  const queryClient = useQueryClient();

  const handleMouseEnter = () => {
    queryClient.prefetchQuery({
      queryKey: queryKeys.bots.detail(bot.id),
      queryFn: () => api.get(`/api/v1/bots/${bot.id}`).then(r => r.data.data),
      staleTime: 60 * 1000, // Don't refetch if less than 1 min old
    });
  };

  return (
    <Link
      to={`/bots/${bot.id}`}
      onMouseEnter={handleMouseEnter}
      className="block rounded-lg border p-4 hover:bg-muted"
    >
      <h3 className="font-semibold">{bot.name}</h3>
      <p className="text-sm text-muted-foreground">{bot.description}</p>
    </Link>
  );
}

// Solution 2: Prefetch visible items only
function ConversationList({ conversations }: { conversations: Conversation[] }) {
  const queryClient = useQueryClient();
  const observerRef = useRef<IntersectionObserver | null>(null);

  useEffect(() => {
    observerRef.current = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const conversationId = entry.target.getAttribute('data-id');
            if (conversationId) {
              // Prefetch when item becomes visible
              queryClient.prefetchQuery({
                queryKey: queryKeys.conversations.detail(conversationId),
                queryFn: () => fetchConversation(conversationId),
                staleTime: 30 * 1000,
              });
            }
          }
        });
      },
      { rootMargin: '100px' } // Start prefetching slightly before visible
    );

    return () => observerRef.current?.disconnect();
  }, [queryClient]);

  return (
    <ul>
      {conversations.map((convo) => (
        <li
          key={convo.id}
          data-id={convo.id}
          ref={(el) => el && observerRef.current?.observe(el)}
        >
          <ConversationCard conversation={convo} />
        </li>
      ))}
    </ul>
  );
}

// Solution 3: Prefetch on focus (keyboard navigation)
function NavigationItem({ item }: { item: NavItem }) {
  const queryClient = useQueryClient();

  const prefetchData = useCallback(() => {
    queryClient.prefetchQuery({
      queryKey: queryKeys.pages.content(item.path),
      queryFn: () => fetchPageContent(item.path),
    });
  }, [item.path, queryClient]);

  return (
    <Link
      to={item.path}
      onMouseEnter={prefetchData}
      onFocus={prefetchData} // Also prefetch on keyboard focus
      className="nav-link"
    >
      {item.label}
    </Link>
  );
}

// Solution 4: Prefetch in route loader
// router.tsx
const router = createBrowserRouter([
  {
    path: '/bots/:id',
    element: <BotDetail />,
    loader: async ({ params }) => {
      // Prefetch while route is loading
      queryClient.prefetchQuery({
        queryKey: queryKeys.bots.detail(params.id!),
        queryFn: () => fetchBot(params.id!),
      });
      return null;
    },
  },
]);

// Solution 5: Prefetch related data after main query
function useBotWithRelated(botId: string) {
  const queryClient = useQueryClient();

  const botQuery = useQuery({
    queryKey: queryKeys.bots.detail(botId),
    queryFn: () => fetchBot(botId),
  });

  // Prefetch related data when bot loads
  useEffect(() => {
    if (botQuery.data) {
      // User likely wants to see conversations next
      queryClient.prefetchQuery({
        queryKey: queryKeys.conversations.list(botId),
        queryFn: () => fetchConversations(botId),
      });

      // And maybe settings
      queryClient.prefetchQuery({
        queryKey: queryKeys.bots.settings(botId),
        queryFn: () => fetchBotSettings(botId),
      });
    }
  }, [botQuery.data, botId, queryClient]);

  return botQuery;
}
```

**Why it's better:**
- Hover prefetch anticipates user intent
- Intersection observer limits requests to visible items
- staleTime prevents unnecessary refetches
- Focus prefetch supports keyboard users
- Route loaders prefetch during navigation

## Project-Specific Notes

**BotFacebook Prefetch Patterns:**

| Trigger | Data to Prefetch |
|---------|------------------|
| Hover on bot card | Bot detail |
| View conversation list | First few conversations |
| Open bot settings | Available models, pricing |
| Navigate to dashboard | Stats, recent activity |

**Prefetch Configuration:**
```tsx
// Common staleTime settings
queryClient.prefetchQuery({
  queryKey: key,
  queryFn: fn,
  staleTime: 5 * 60 * 1000, // 5 minutes for stable data
  // OR
  staleTime: 30 * 1000, // 30 seconds for dynamic data
});
```

## References

- [TanStack Query Prefetching](https://tanstack.com/query/latest/docs/framework/react/guides/prefetching)
- [Optimistic Prefetching](https://tkdodo.eu/blog/seeding-the-query-cache)
