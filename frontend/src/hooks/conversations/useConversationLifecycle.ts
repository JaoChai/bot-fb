import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import type {
  Conversation,
  ConversationStatusCounts,
  PaginationMeta,
  UpdateConversationData,
} from '@/types/api';

interface ConversationResponse { data: Conversation; message?: string }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

export function useUpdateConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async ({ conversationId, data }: { conversationId: number; data: UpdateConversationData }) => {
      const response = await api.put<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}`,
        data
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

export function useCloseConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/close`
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

export function useReopenConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/reopen`
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
