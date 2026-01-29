/**
 * Message mutation hooks
 *
 * T040: Includes optimistic updates for sendMessage
 *
 * Extracted from useMessages.ts for single responsibility.
 */
import {
  useMutation,
  useQueryClient,
  type InfiniteData,
} from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Message } from '@/types/api';
import {
  messageKeys,
  type MessagesResponse,
  type SendMessageData,
  type AgentMessageResponse,
} from './messageKeys';

/**
 * Hook to send a message from agent to customer (HITL mode)
 * T040: Includes optimistic updates
 */
export function useSendMessage(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: SendMessageData;
    }) => {
      const response = await api.post<AgentMessageResponse>(
        `/bots/${botId}/conversations/${conversationId}/agent-message`,
        data
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      const messageOptions = { order: 'asc' as const, perPage: 100 };

      // Cancel any outgoing refetches
      await Promise.all([
        queryClient.cancelQueries({
          queryKey: messageKeys.listWithOptions(botId, conversationId, messageOptions),
        }),
        queryClient.cancelQueries({
          queryKey: messageKeys.infinite(botId, conversationId),
        }),
      ]);

      // Snapshot previous values
      const previousMessages = queryClient.getQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions)
      );
      const previousInfiniteMessages = queryClient.getQueryData<InfiniteData<MessagesResponse>>(
        messageKeys.infinite(botId, conversationId)
      );

      // Use negative timestamp to guarantee no collision with DB IDs (always positive)
      const optimisticId = -Date.now();
      const optimisticMessage: Message = {
        id: optimisticId,
        conversation_id: conversationId,
        sender: 'agent',
        content: data.content,
        type: data.type || 'text',
        media_url: data.media_url || null,
        media_type: null,
        media_metadata: null,
        model_used: null,
        prompt_tokens: null,
        completion_tokens: null,
        cost: null,
        external_message_id: null,
        reply_to_message_id: null,
        sentiment: null,
        intents: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };

      // Optimistically update simple query
      if (previousMessages) {
        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, messageOptions),
          {
            ...previousMessages,
            data: [...previousMessages.data, optimisticMessage],
          }
        );
      }

      // Optimistically update infinite query (add to first page since it's newest)
      if (previousInfiniteMessages) {
        queryClient.setQueryData<InfiniteData<MessagesResponse>>(
          messageKeys.infinite(botId, conversationId),
          {
            ...previousInfiniteMessages,
            pages: previousInfiniteMessages.pages.map((page, index) => {
              // Add to first page (newest messages)
              if (index === 0) {
                return {
                  ...page,
                  data: [optimisticMessage, ...page.data],
                };
              }
              return page;
            }),
          }
        );
      }

      return { previousMessages, previousInfiniteMessages, optimisticId };
    },
    onError: (_err, { conversationId }, context) => {
      if (!botId) return;

      const messageOptions = { order: 'asc' as const, perPage: 100 };

      // Rollback simple query
      if (context?.previousMessages) {
        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, messageOptions),
          context.previousMessages
        );
      }

      // Rollback infinite query
      if (context?.previousInfiniteMessages) {
        queryClient.setQueryData<InfiniteData<MessagesResponse>>(
          messageKeys.infinite(botId, conversationId),
          context.previousInfiniteMessages
        );
      }
    },
    onSuccess: (response, { conversationId }, context) => {
      if (!botId) return;

      const messageOptions = { order: 'asc' as const, perPage: 100 };
      const optimisticId = context?.optimisticId;

      // Replace optimistic message with real one in simple query
      queryClient.setQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions),
        (old) => {
          if (!old) return old;

          const realMessageExists = old.data.some((m) => m.id === response.data.id);
          const hasOptimistic = optimisticId && old.data.some((m) => m.id === optimisticId);

          if (realMessageExists && hasOptimistic) {
            return {
              ...old,
              data: old.data.filter((m) => m.id !== optimisticId),
            };
          }

          if (realMessageExists) return old;

          if (hasOptimistic) {
            return {
              ...old,
              data: old.data.map((m) => (m.id === optimisticId ? response.data : m)),
            };
          }

          return {
            ...old,
            data: [...old.data, response.data],
          };
        }
      );

      // Replace optimistic message with real one in infinite query
      queryClient.setQueryData<InfiniteData<MessagesResponse>>(
        messageKeys.infinite(botId, conversationId),
        (old) => {
          if (!old) return old;

          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((m) =>
                m.id === optimisticId ? response.data : m
              ).filter((m, index, arr) => {
                // Remove duplicates if real message already exists
                if (m.id === response.data.id) {
                  return arr.findIndex((msg) => msg.id === response.data.id) === index;
                }
                return true;
              }),
            })),
          };
        }
      );
    },
  });
}
