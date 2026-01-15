# React Query v5 Patterns

## Basic Query

```tsx
import { useQuery } from '@tanstack/react-query';

function BotList() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['bots'],
    queryFn: () => api.get('/api/v1/bots').then(r => r.data),
  });

  if (isLoading) return <Spinner />;
  if (error) return <Error message={error.message} />;

  return <BotGrid bots={data.data} />;
}
```

## Query with Parameters

```tsx
function BotDetail({ botId }: { botId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['bots', botId],
    queryFn: () => api.get(`/api/v1/bots/${botId}`).then(r => r.data),
    enabled: !!botId, // Only fetch when botId exists
  });

  // ...
}
```

## Mutation

```tsx
import { useMutation, useQueryClient } from '@tanstack/react-query';

function CreateBotForm() {
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: (data: CreateBotDTO) =>
      api.post('/api/v1/bots', data).then(r => r.data),
    onSuccess: () => {
      // Invalidate and refetch
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
  });

  const handleSubmit = (data: CreateBotDTO) => {
    mutation.mutate(data);
  };

  return (
    <form onSubmit={handleSubmit}>
      {/* form fields */}
      <button disabled={mutation.isPending}>
        {mutation.isPending ? 'Creating...' : 'Create Bot'}
      </button>
    </form>
  );
}
```

## Optimistic Updates

```tsx
const toggleMutation = useMutation({
  mutationFn: (botId: string) =>
    api.patch(`/api/v1/bots/${botId}/toggle`),

  onMutate: async (botId) => {
    // Cancel outgoing refetches
    await queryClient.cancelQueries({ queryKey: ['bots'] });

    // Snapshot previous value
    const previousBots = queryClient.getQueryData(['bots']);

    // Optimistically update
    queryClient.setQueryData(['bots'], (old: Bot[]) =>
      old.map(bot =>
        bot.id === botId ? { ...bot, active: !bot.active } : bot
      )
    );

    return { previousBots };
  },

  onError: (err, botId, context) => {
    // Rollback on error
    queryClient.setQueryData(['bots'], context?.previousBots);
  },

  onSettled: () => {
    // Always refetch after error or success
    queryClient.invalidateQueries({ queryKey: ['bots'] });
  },
});
```

## Infinite Query (Pagination)

```tsx
import { useInfiniteQuery } from '@tanstack/react-query';

function MessageList({ conversationId }: { conversationId: string }) {
  const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useInfiniteQuery({
    queryKey: ['messages', conversationId],
    queryFn: ({ pageParam = 1 }) =>
      api.get(`/api/v1/conversations/${conversationId}/messages`, {
        params: { page: pageParam },
      }).then(r => r.data),
    getNextPageParam: (lastPage) => lastPage.meta.next_page,
    initialPageParam: 1,
  });

  const messages = data?.pages.flatMap(page => page.data) ?? [];

  return (
    <>
      {messages.map(msg => <Message key={msg.id} {...msg} />)}
      {hasNextPage && (
        <button
          onClick={() => fetchNextPage()}
          disabled={isFetchingNextPage}
        >
          {isFetchingNextPage ? 'Loading...' : 'Load More'}
        </button>
      )}
    </>
  );
}
```

## Prefetching

```tsx
// Prefetch on hover
function BotCard({ bot }: { bot: Bot }) {
  const queryClient = useQueryClient();

  const prefetchBot = () => {
    queryClient.prefetchQuery({
      queryKey: ['bots', bot.id],
      queryFn: () => api.get(`/api/v1/bots/${bot.id}`).then(r => r.data),
    });
  };

  return (
    <Link
      to={`/bots/${bot.id}`}
      onMouseEnter={prefetchBot}
    >
      {bot.name}
    </Link>
  );
}
```

## Dependent Queries

```tsx
function BotAnalytics({ botId }: { botId: string }) {
  // First query
  const botQuery = useQuery({
    queryKey: ['bots', botId],
    queryFn: () => api.get(`/api/v1/bots/${botId}`).then(r => r.data),
  });

  // Dependent query - only runs when bot data is available
  const analyticsQuery = useQuery({
    queryKey: ['analytics', botId],
    queryFn: () => api.get(`/api/v1/bots/${botId}/analytics`).then(r => r.data),
    enabled: !!botQuery.data, // Wait for bot to load
  });

  // ...
}
```

## Polling / Real-time Updates

```tsx
// Poll every 5 seconds
const { data } = useQuery({
  queryKey: ['notifications'],
  queryFn: fetchNotifications,
  refetchInterval: 5000,
  refetchIntervalInBackground: false, // Stop polling when tab is hidden
});

// Refetch on window focus
const { data } = useQuery({
  queryKey: ['messages'],
  queryFn: fetchMessages,
  refetchOnWindowFocus: true,
});
```

## Query Cancellation

```tsx
const { data } = useQuery({
  queryKey: ['search', searchTerm],
  queryFn: async ({ signal }) => {
    const response = await api.get('/api/v1/search', {
      params: { q: searchTerm },
      signal, // Pass AbortSignal for cancellation
    });
    return response.data;
  },
});
```

## Custom Hooks Pattern

```tsx
// hooks/useBots.ts
export function useBots() {
  return useQuery({
    queryKey: ['bots'],
    queryFn: () => api.get('/api/v1/bots').then(r => r.data),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
}

export function useBot(id: string) {
  return useQuery({
    queryKey: ['bots', id],
    queryFn: () => api.get(`/api/v1/bots/${id}`).then(r => r.data),
    enabled: !!id,
  });
}

export function useCreateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateBotDTO) =>
      api.post('/api/v1/bots', data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
  });
}

export function useUpdateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateBotDTO }) =>
      api.put(`/api/v1/bots/${id}`, data).then(r => r.data),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: ['bots', id] });
      queryClient.invalidateQueries({ queryKey: ['bots'] });
    },
  });
}
```

## Query Configuration

```tsx
// lib/queryClient.ts
import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      gcTime: 1000 * 60 * 30, // 30 minutes (formerly cacheTime)
      retry: 3,
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: 1,
    },
  },
});
```

## Error Handling

```tsx
const { data, error, isError } = useQuery({
  queryKey: ['bots'],
  queryFn: async () => {
    const response = await api.get('/api/v1/bots');
    if (!response.ok) {
      throw new Error('Failed to fetch bots');
    }
    return response.data;
  },
  // Custom error handling
  throwOnError: false, // Don't throw, handle in component
});

// Global error handling
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      throwOnError: (error) => {
        // Only throw for server errors
        return error.status >= 500;
      },
    },
    mutations: {
      onError: (error) => {
        toast.error(error.message);
      },
    },
  },
});
```
