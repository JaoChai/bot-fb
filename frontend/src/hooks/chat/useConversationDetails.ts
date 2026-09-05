/**
 * Conversation detail cache helpers
 * - conversationDetailKeys: query key factory for single conversation
 * - useMarkAsRead: mark-as-read mutation with optimistic unread count update
 */
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Conversation } from '@/types/api';
import type { ConversationsResponse } from './useConversationList';

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
