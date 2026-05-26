import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import { isInfiniteConversationsQuery } from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  PaginationMeta,
} from '@/types/api';

interface ConversationResponse { data: Conversation; message?: string }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

interface ClearContextAllResponse {
  data: { updated_count: number };
  message: string;
}

/**
 * Hook to mark conversation as read
 * Uses optimistic update for instant UI feedback (LINE OA style)
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
      // Cancel outgoing refetches to prevent overwriting optimistic update
      await queryClient.cancelQueries({ queryKey: ['conversations-infinite', botId] });

      // Snapshot for rollback
      const previousData = queryClient.getQueriesData<InfiniteData<ConversationsResponse>>({
        predicate: isInfiniteConversationsQuery(botId!),
      });

      // Optimistically set unread_count = 0 (instant UI update)
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, unread_count: 0 }
                  : conv
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
      // Re-apply cache update to fix race condition
      // If a refetch happened during mutation, it may have overwritten optimistic update
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, unread_count: 0 }
                  : conv
              ),
            })),
          };
        }
      );
    },
    onSettled: () => {
      // Invalidate stats (lightweight query for sidebar counts)
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to clear bot context for a conversation
 * Bot will not reference messages before the cleared timestamp
 */
export function useClearContext(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/clear-context`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation', botId],
      ['conversation-stats', botId],
    ],
  });
}

/**
 * Hook to clear bot context for ALL active/handover conversations
 * Bot will start fresh with all open conversations
 */
export function useClearContextAll(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async () => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await api.post<ClearContextAllResponse>(
        `/bots/${botId}/conversations/clear-context-all`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation-stats', botId],
    ],
  });
}
