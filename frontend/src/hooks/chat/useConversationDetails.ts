/**
 * T018: useConversationDetails hook
 * T041: Cache warming with prefetchConversation for hover prefetch
 *
 * Single conversation query with optimistic updates setup
 */
import { useQuery, useMutation, useQueryClient, type QueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Conversation, ConversationStats, UpdateConversationData } from '@/types/api';
import { conversationKeys, type ConversationsResponse } from './useConversationList';
import { messageKeys, type MessagesResponse } from './messageKeys';

// Query key factory for single conversation
export const conversationDetailKeys = {
  detail: (botId: number, conversationId: number) =>
    ['conversation', botId, conversationId] as const,
  stats: (botId: number) => ['conversation-stats', botId] as const,
};

// Type for infinite conversations data (used in cache updates)
type InfiniteConversationsData = InfiniteData<ConversationsResponse>;

// Response types
interface ConversationResponse {
  data: Conversation;
  message?: string;
}

interface StatsResponse {
  data: ConversationStats;
}

/**
 * Hook to fetch a single conversation with messages
 */
export function useConversationDetails(
  botId: number | undefined,
  conversationId: number | undefined,
  messagesLimit?: number
) {
  return useQuery({
    queryKey:
      botId && conversationId
        ? conversationDetailKeys.detail(botId, conversationId)
        : ['conversation', 'disabled'],
    queryFn: async () => {
      const params = messagesLimit ? `?messages_limit=${messagesLimit}` : '';
      const response = await api.get<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}${params}`
      );
      return response.data.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 10000, // 10 seconds
  });
}

/**
 * T041: Prefetch conversation details on hover
 * Use this in ConversationItem for cache warming
 */
export function usePrefetchConversation(
  botId: number | undefined,
  queryClient?: QueryClient
) {
  const defaultQueryClient = useQueryClient();
  const client = queryClient || defaultQueryClient;

  return {
    /**
     * Prefetch conversation details when hovering
     * Does not refetch if data is already fresh (within staleTime)
     */
    prefetchConversation: (conversationId: number) => {
      if (!botId) return;

      // Prefetch conversation details
      client.prefetchQuery({
        queryKey: conversationDetailKeys.detail(botId, conversationId),
        queryFn: async () => {
          const response = await api.get<ConversationResponse>(
            `/bots/${botId}/conversations/${conversationId}`
          );
          return response.data.data;
        },
        staleTime: 10000, // Match useConversationDetails staleTime
      });
    },

    /**
     * Prefetch messages for a conversation
     * Useful for conversations that are likely to be selected next
     */
    prefetchMessages: (conversationId: number) => {
      if (!botId) return;

      const messageOptions = { order: 'asc' as const, perPage: 100 };
      client.prefetchQuery({
        queryKey: messageKeys.listWithOptions(botId, conversationId, messageOptions),
        queryFn: async () => {
          const params = new URLSearchParams();
          params.append('per_page', '100');
          params.append('order', 'asc');

          const response = await api.get<MessagesResponse>(
            `/bots/${botId}/conversations/${conversationId}/messages?` + params.toString()
          );
          return response.data;
        },
        staleTime: 0,
      });
    },

    /**
     * Prefetch both conversation and messages
     * Good for hover with slight delay
     */
    prefetchAll: (conversationId: number) => {
      if (!botId) return;

      // Run both prefetches in parallel
      Promise.all([
        client.prefetchQuery({
          queryKey: conversationDetailKeys.detail(botId, conversationId),
          queryFn: async () => {
            const response = await api.get<ConversationResponse>(
              `/bots/${botId}/conversations/${conversationId}`
            );
            return response.data.data;
          },
          staleTime: 10000,
        }),
        client.prefetchQuery({
          queryKey: messageKeys.listWithOptions(botId, conversationId, { order: 'asc' as const, perPage: 100 }),
          queryFn: async () => {
            const params = new URLSearchParams();
            params.append('per_page', '100');
            params.append('order', 'asc');

            const response = await api.get<MessagesResponse>(
              `/bots/${botId}/conversations/${conversationId}/messages?` + params.toString()
            );
            return response.data;
          },
          staleTime: 0,
        }),
      ]);
    },
  };
}

/**
 * Hook to fetch conversation statistics for a bot
 */
export function useConversationStats(botId: number | undefined) {
  return useQuery({
    queryKey: botId ? conversationDetailKeys.stats(botId) : ['conversation-stats', 'disabled'],
    queryFn: async () => {
      const response = await api.get<StatsResponse>(`/bots/${botId}/conversations/stats`);
      return response.data.data;
    },
    enabled: !!botId,
    staleTime: 60000, // 1 minute
  });
}

/**
 * Hook to update a conversation
 */
export function useUpdateConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: UpdateConversationData;
    }) => {
      const response = await api.put<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}`,
        data
      );
      return response.data;
    },
    onSuccess: (_, { conversationId }) => {
      if (!botId) return;
      queryClient.invalidateQueries({ queryKey: conversationKeys.lists() });
      queryClient.invalidateQueries({
        queryKey: conversationDetailKeys.detail(botId, conversationId),
      });
      queryClient.invalidateQueries({ queryKey: conversationDetailKeys.stats(botId) });
    },
  });
}

/**
 * Hook to mark conversation as read
 * T040: Includes optimistic updates for unread count
 * Uses setQueriesData with predicate to handle query keys with filters
 */
export function useMarkAsRead(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/mark-as-read`
      );
      return response.data;
    },
    onMutate: async (conversationId) => {
      if (!botId) return;

      // Cancel any outgoing refetches to prevent overwriting optimistic update
      // Use predicate to match all conversation-infinite queries for this bot (regardless of filters)
      await queryClient.cancelQueries({
        predicate: (query) => {
          const key = query.queryKey;
          return Array.isArray(key) &&
            key[0] === 'conversations-infinite' &&
            key[1] === botId;
        },
      });

      // Snapshot previous data for rollback
      const previousData = queryClient.getQueriesData<InfiniteConversationsData>({
        predicate: (query) => {
          const key = query.queryKey;
          return Array.isArray(key) &&
            key[0] === 'conversations-infinite' &&
            key[1] === botId;
        },
      });

      // Optimistically update unread count to 0 in all matching queries
      queryClient.setQueriesData<InfiniteConversationsData>(
        {
          predicate: (query) => {
            const key = query.queryKey;
            return Array.isArray(key) &&
              key[0] === 'conversations-infinite' &&
              key[1] === botId;
          },
        },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, unread_count: 0 } : conv
              ),
            })),
          };
        }
      );

      return { previousData };
    },
    onError: (_err, _conversationId, context) => {
      // Rollback on error
      if (context?.previousData) {
        context.previousData.forEach(([queryKey, data]) => {
          if (data) {
            queryClient.setQueryData(queryKey, data);
          }
        });
      }
    },
    onSuccess: (_data, conversationId) => {
      if (!botId) return;

      // Re-apply cache update to fix race condition
      // If a refetch happened during mutation, it may have overwritten optimistic update
      queryClient.setQueriesData<InfiniteConversationsData>(
        {
          predicate: (query) => {
            const key = query.queryKey;
            return Array.isArray(key) &&
              key[0] === 'conversations-infinite' &&
              key[1] === botId;
          },
        },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, unread_count: 0 } : conv
              ),
            })),
          };
        }
      );

      // Refetch stats to get accurate unread counts
      queryClient.invalidateQueries({ queryKey: conversationDetailKeys.stats(botId) });
    },
  });
}
