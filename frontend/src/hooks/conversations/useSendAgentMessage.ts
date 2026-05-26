import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { messageKeys, isInfiniteConversationsQuery, type MessagesOptions } from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

interface MessagesResponse { data: Message[]; meta: PaginationMeta }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

interface SendAgentMessageData {
  content: string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'file';
  media_url?: string;
}

interface AgentMessageResponse {
  message: string;
  data: Message;
  delivery_error?: string | null;
}

/**
 * Hook to send a message from agent to customer (HITL mode)
 * Includes optimistic updates for instant UI feedback
 */
export function useSendAgentMessage(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: SendAgentMessageData;
    }) => {
      const response = await api.post<AgentMessageResponse>(
        `/bots/${botId}/conversations/${conversationId}/agent-message`,
        data,
        { headers: { 'Idempotency-Key': crypto.randomUUID() } }
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      const messageOptions: MessagesOptions = { order: 'asc', perPage: 100 };

      // Cancel any outgoing refetches to avoid overwriting optimistic update
      await queryClient.cancelQueries({
        queryKey: messageKeys.listWithOptions(botId, conversationId, messageOptions),
      });

      // Snapshot previous messages
      const previousMessages = queryClient.getQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions)
      );

      // Use negative timestamp to guarantee no collision with DB IDs (always positive)
      const optimisticId = -Date.now();

      // Optimistically add the new message
      if (previousMessages) {
        const optimisticMessage: Message = {
          id: optimisticId, // Use the generated ID
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

        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, messageOptions),
          {
            ...previousMessages,
            data: [...previousMessages.data, optimisticMessage],
          }
        );
      }

      return { previousMessages, optimisticId, messageOptions };
    },
    onError: (_err, { conversationId }, context) => {
      if (!botId) return;

      // Rollback: remove only the failed optimistic message
      if (context?.optimisticId && context?.messageOptions) {
        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, context.messageOptions),
          (old) => {
            if (!old) return old;
            return {
              ...old,
              data: old.data.filter((m) => m.id !== context.optimisticId),
            };
          }
        );
      }
    },
    onSuccess: (response, { conversationId }, context) => {
      if (!botId) return;

      const messageOptions: MessagesOptions = context?.messageOptions ?? { order: 'asc', perPage: 100 };
      const optimisticId = context?.optimisticId;

      // Replace only the specific optimistic message with real message from server
      // This avoids invalidateQueries which causes race conditions with WebSocket updates
      queryClient.setQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions),
        (old) => {
          if (!old) return old;

          // CRITICAL: Check if real message already exists first (from WebSocket)
          // This prevents duplicates when WebSocket arrives before API response
          const realMessageExists = old.data.some((m) => m.id === response.data.id);
          const hasOptimistic = optimisticId && old.data.some((m) => m.id === optimisticId);

          if (realMessageExists && hasOptimistic) {
            // WebSocket came first - just remove the optimistic message
            return {
              ...old,
              data: old.data.filter((m) => m.id !== optimisticId),
            };
          }

          if (realMessageExists) {
            // Real message exists, no optimistic - nothing to do
            return old;
          }

          if (hasOptimistic) {
            // Normal case: replace optimistic with real
            return {
              ...old,
              data: old.data.map((m) =>
                m.id === optimisticId ? response.data : m
              ),
            };
          }

          // No optimistic, no real message - add it
          return {
            ...old,
            data: [...old.data, response.data],
          };
        }
      );

      // WebSocket uses toOthers() and doesn't echo back to the sender, so we must
      // patch needs_response = false locally for the agent who just replied.
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
                  ? {
                      ...conv,
                      needs_response: false, // Agent replied = no longer needs response
                      last_message_at: new Date().toISOString(),
                      message_count: conv.message_count + 1,
                    }
                  : conv
              ),
            })),
          };
        }
      );

      // Note: We intentionally do NOT invalidate queries here to prevent race conditions
      // WebSocket handles real-time updates, and refetch happens on reconnect
    },
  });
}
