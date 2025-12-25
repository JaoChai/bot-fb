import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Stale time - data considered fresh for 5 minutes
      staleTime: 5 * 60 * 1000,
      // Cache time - keep in cache for 30 minutes
      gcTime: 30 * 60 * 1000,
      // Retry failed requests 3 times with exponential backoff
      retry: (failureCount, error) => {
        // Check if error has status property (ApiError from our interceptor)
        const status = (error as { status?: number })?.status;
        // Don't retry on auth errors
        if (status === 401 || status === 403) {
          return false;
        }
        return failureCount < 3;
      },
      // Refetch on window focus (useful for real-time data)
      refetchOnWindowFocus: false,
      // Don't refetch on mount if data is fresh
      refetchOnMount: false,
    },
    mutations: {
      // Retry mutations once on failure
      retry: 1,
    },
  },
});

// Query Keys factory - keeps keys consistent across the app
export const queryKeys = {
  // Auth
  auth: {
    all: ['auth'] as const,
    user: () => [...queryKeys.auth.all, 'user'] as const,
  },
  // Bots
  bots: {
    all: ['bots'] as const,
    lists: () => [...queryKeys.bots.all, 'list'] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.bots.lists(), filters] as const,
    details: () => [...queryKeys.bots.all, 'detail'] as const,
    detail: (id: number) => [...queryKeys.bots.details(), id] as const,
    settings: (id: number) => [...queryKeys.bots.detail(id), 'settings'] as const,
  },
  // Conversations
  conversations: {
    all: ['conversations'] as const,
    lists: () => [...queryKeys.conversations.all, 'list'] as const,
    list: (botId: number, filters?: Record<string, unknown>) =>
      [...queryKeys.conversations.lists(), botId, filters] as const,
    details: () => [...queryKeys.conversations.all, 'detail'] as const,
    detail: (id: number) => [...queryKeys.conversations.details(), id] as const,
    messages: (conversationId: number) =>
      [...queryKeys.conversations.detail(conversationId), 'messages'] as const,
  },
  // Knowledge Base
  knowledgeBase: {
    all: ['knowledgeBase'] as const,
    lists: () => [...queryKeys.knowledgeBase.all, 'list'] as const,
    list: (botId: number) => [...queryKeys.knowledgeBase.lists(), botId] as const,
    detail: (id: number) => [...queryKeys.knowledgeBase.all, 'detail', id] as const,
  },
  // Flows
  flows: {
    all: ['flows'] as const,
    lists: () => [...queryKeys.flows.all, 'list'] as const,
    list: (botId: number) => [...queryKeys.flows.lists(), botId] as const,
    detail: (botId: number, flowId: number) => [...queryKeys.flows.all, 'detail', botId, flowId] as const,
    templates: () => [...queryKeys.flows.all, 'templates'] as const,
  },
} as const;
