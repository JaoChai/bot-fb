import { QueryClient } from '@tanstack/react-query';
import { createSyncStoragePersister } from '@tanstack/query-sync-storage-persister';

// Query keys that should NOT be persisted (real-time data)
// Note: 'conversation-messages' removed - now unified under 'messages' via messageKeys
const NON_PERSISTENT_KEYS = [
  'bots',
  'conversations',
  'conversations-infinite',
  'conversation',
  'conversation-stats',
  'conversation-notes',
  'messages', // Unified message cache (includes old 'conversation-messages')
];

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Stale time - data considered fresh for 5 minutes
      staleTime: 5 * 60 * 1000,
      // Cache time - keep in cache for 24 hours (required for persistence)
      gcTime: 1000 * 60 * 60 * 24,
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
      // Refetch on mount if data is stale (e.g., after navigation back from create)
      refetchOnMount: true,
    },
    mutations: {
      // Retry mutations once on failure
      retry: 1,
    },
  },
});

/**
 * Check if a query should be persisted to localStorage
 * Real-time data (conversations, messages) should NOT be persisted
 * to ensure fresh data is always fetched from server
 */
export function shouldDehydrateQuery(query: { queryKey: readonly unknown[] }): boolean {
  const firstKey = query.queryKey[0];
  if (typeof firstKey === 'string' && NON_PERSISTENT_KEYS.includes(firstKey)) {
    return false; // Don't persist real-time conversation data
  }
  return true; // Persist other queries (bots, settings, etc.)
}

// Persister for localStorage - keeps cache across page refreshes
export const persister = createSyncStoragePersister({
  storage: window.localStorage,
  key: 'BOTJAO_QUERY_CACHE',
});

// Query Keys factory - keeps keys consistent across the app
export const queryKeys = {
  // Auth
  auth: {
    all: ['auth'] as const,
    user: () => [...queryKeys.auth.all, 'user'] as const,
  },
  // User Settings
  settings: {
    all: ['settings'] as const,
    user: () => [...queryKeys.settings.all, 'user'] as const,
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
  // Quick Replies
  quickReplies: {
    all: ['quickReplies'] as const,
    lists: () => [...queryKeys.quickReplies.all, 'list'] as const,
    list: (filters?: Record<string, unknown>) =>
      [...queryKeys.quickReplies.lists(), filters] as const,
    details: () => [...queryKeys.quickReplies.all, 'detail'] as const,
    detail: (id: number) => [...queryKeys.quickReplies.details(), id] as const,
    search: (query: string) => [...queryKeys.quickReplies.all, 'search', query] as const,
  },
} as const;
