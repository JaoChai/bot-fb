import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '@/hooks/useMutationWithToast';
import type { Conversation } from '@/types/api';
import type { ConversationsResponse } from './useConversationList';

interface ConversationResponse { data: Conversation; message?: string }

interface ClearContextAllResponse {
  data: { updated_count: number };
  message: string;
}

// Kept as manual useMutation: writes the cache directly (not just invalidate),
// which useMutationWithToast does not support.
export function useToggleHandover(botId: number | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      conversationId,
      unassign = false,
      autoEnableMinutes = 0,
    }: {
      conversationId: number;
      unassign?: boolean;
      autoEnableMinutes?: number;
    }) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/toggle-handover`,
        { unassign, auto_enable_minutes: autoEnableMinutes }
      );
      return response.data;
    },
    onSuccess: (result, { conversationId }) => {
      const updatedConversation = result.data;
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { queryKey: ['conversations-infinite', botId] },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, ...updatedConversation } : conv
              ),
            })),
          };
        }
      );
      queryClient.setQueryData<ConversationResponse>(
        ['conversation', botId, conversationId],
        (old) => (old ? { ...old, data: { ...old.data, ...updatedConversation } } : old)
      );
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
