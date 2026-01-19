---
id: query-005-infinite-queries
title: Infinite Queries for Pagination
impact: MEDIUM
impactDescription: "Enables efficient loading of large datasets with pagination"
category: query
tags: [react-query, pagination, infinite-scroll, performance]
relatedRules: [perf-003-virtualization]
---

## Why This Matters

When displaying lists with many items (messages, conversations, logs), loading everything at once is slow and memory-intensive. `useInfiniteQuery` loads data in pages, appending new pages as the user scrolls or clicks "load more".

This improves initial load time and reduces memory usage for large datasets.

## Bad Example

```tsx
// Problem 1: Loading all data at once
function ConversationList({ botId }) {
  const { data } = useQuery({
    queryKey: ['conversations', botId],
    queryFn: () => api.get(`/api/v1/bots/${botId}/conversations`),
    // Loads ALL conversations - could be thousands!
  });

  return <List items={data?.data} />;
}

// Problem 2: Manual pagination state
function MessageList({ conversationId }) {
  const [page, setPage] = useState(1);
  const [allMessages, setAllMessages] = useState([]);

  const { data, isLoading } = useQuery({
    queryKey: ['messages', conversationId, page],
    queryFn: () => fetchMessages(conversationId, page),
  });

  useEffect(() => {
    if (data) {
      setAllMessages(prev => [...prev, ...data.data]); // Duplicates on refetch!
    }
  }, [data]);

  // This approach has many bugs:
  // - Duplicates when refetching
  // - No automatic cache management
  // - Manual tracking of hasMore
  // - State gets stale
}

// Problem 3: Wrong pageParam handling
const { data, fetchNextPage } = useInfiniteQuery({
  queryKey: ['items'],
  queryFn: ({ pageParam }) => fetchItems(pageParam),
  getNextPageParam: (lastPage) => lastPage.nextPage, // Might be undefined
  // Missing initialPageParam!
});
```

**Why it's wrong:**
- Loading all data wastes bandwidth and memory
- Manual pagination has edge cases (duplicates, stale state)
- Missing `initialPageParam` causes errors in React Query v5
- Not handling undefined `nextPage` causes infinite fetching

## Good Example

```tsx
// Solution: useInfiniteQuery with proper configuration
function MessageList({ conversationId }: { conversationId: string }) {
  const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
  } = useInfiniteQuery({
    queryKey: queryKeys.conversations.messages(conversationId),
    queryFn: async ({ pageParam }) => {
      const response = await api.get(
        `/api/v1/conversations/${conversationId}/messages`,
        { params: { page: pageParam, per_page: 20 } }
      );
      return response.data;
    },
    initialPageParam: 1, // Required in v5!
    getNextPageParam: (lastPage) => {
      // Return undefined when no more pages
      if (lastPage.meta.current_page >= lastPage.meta.last_page) {
        return undefined;
      }
      return lastPage.meta.current_page + 1;
    },
  });

  // Flatten pages into single array
  const messages = data?.pages.flatMap((page) => page.data) ?? [];

  if (isLoading) return <MessagesSkeleton />;

  return (
    <div className="flex flex-col gap-2">
      {messages.map((message) => (
        <Message key={message.id} message={message} />
      ))}

      {hasNextPage && (
        <button
          onClick={() => fetchNextPage()}
          disabled={isFetchingNextPage}
          className="self-center rounded bg-muted px-4 py-2"
        >
          {isFetchingNextPage ? 'Loading...' : 'Load More'}
        </button>
      )}
    </div>
  );
}

// With intersection observer for infinite scroll
function InfiniteConversationList({ botId }: { botId: string }) {
  const loadMoreRef = useRef<HTMLDivElement>(null);

  const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useInfiniteQuery({
    queryKey: queryKeys.conversations.list(botId),
    queryFn: ({ pageParam }) =>
      api.get(`/api/v1/bots/${botId}/conversations`, {
        params: { page: pageParam },
      }).then(r => r.data),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
  });

  // Auto-load when user scrolls to bottom
  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && hasNextPage && !isFetchingNextPage) {
          fetchNextPage();
        }
      },
      { threshold: 0.1 }
    );

    if (loadMoreRef.current) {
      observer.observe(loadMoreRef.current);
    }

    return () => observer.disconnect();
  }, [fetchNextPage, hasNextPage, isFetchingNextPage]);

  const conversations = data?.pages.flatMap((page) => page.data) ?? [];

  return (
    <div className="space-y-2">
      {conversations.map((convo) => (
        <ConversationCard key={convo.id} conversation={convo} />
      ))}

      {/* Invisible element to trigger loading */}
      <div ref={loadMoreRef} className="h-4" />

      {isFetchingNextPage && <LoadingSpinner />}
    </div>
  );
}

// Bidirectional infinite query (load older and newer)
function ChatMessages({ conversationId }: { conversationId: string }) {
  const {
    data,
    fetchNextPage, // Older messages
    fetchPreviousPage, // Newer messages
    hasNextPage,
    hasPreviousPage,
  } = useInfiniteQuery({
    queryKey: queryKeys.conversations.messages(conversationId),
    queryFn: ({ pageParam }) =>
      fetchMessages(conversationId, pageParam),
    initialPageParam: { cursor: null, direction: 'older' },
    getNextPageParam: (lastPage) =>
      lastPage.hasMore ? { cursor: lastPage.oldestId, direction: 'older' } : undefined,
    getPreviousPageParam: (firstPage) =>
      firstPage.hasNewer ? { cursor: firstPage.newestId, direction: 'newer' } : undefined,
  });

  // ...
}
```

**Why it's better:**
- Automatic page management
- No duplicate data on refetch
- Clear `hasNextPage` state
- Easy integration with intersection observer
- Proper cache invalidation

## Project-Specific Notes

**BotFacebook Pagination Format:**
```typescript
// Laravel pagination response
interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
```

**Common Infinite Queries:**
- Messages in conversation
- Conversation list per bot
- Activity logs
- Search results

**Performance Tip:** Combine with virtualization for very long lists (see perf-003).

## References

- [TanStack Query Infinite Queries](https://tanstack.com/query/latest/docs/framework/react/guides/infinite-queries)
- Related rule: perf-003-virtualization
