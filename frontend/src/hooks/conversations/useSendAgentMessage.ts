import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  messageKeys,
  isInfiniteConversationsQuery,
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
  type InfiniteMessages,
} from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

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

      const infiniteKey = messageKeys.infinite(botId, conversationId);

      // Cancel any outgoing refetches to avoid overwriting optimistic update
      await queryClient.cancelQueries({ queryKey: infiniteKey });

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

      queryClient.setQueryData<InfiniteMessages>(infiniteKey, (old) => {
        if (!old) return old;
        return prependMessagesToInfinite(old, [optimisticMessage]);
      });

      return { optimisticId };
    },
    onError: (_err, { conversationId }, context) => {
      if (!botId || !context?.optimisticId) return;

      // Rollback: remove only the failed optimistic message
      queryClient.setQueryData<InfiniteMessages>(
        messageKeys.infinite(botId, conversationId),
        (old) => (old ? removeMessageFromInfinite(old, context.optimisticId) : old)
      );
    },
    onSuccess: (response, { conversationId }, context) => {
      if (!botId) return;

      const optimisticId = context?.optimisticId;

      // Replace the optimistic message with the real one. The helpers handle
      // the WebSocket-arrived-first case (real id already cached) without
      // duplicating, which avoids invalidateQueries race conditions.
      queryClient.setQueryData<InfiniteMessages>(
        messageKeys.infinite(botId, conversationId),
        (old) => {
          if (!old) return old;
          if (optimisticId && messageExistsInInfinite(old, optimisticId)) {
            return replaceMessageInInfinite(old, optimisticId, response.data);
          }
          return prependMessagesToInfinite(old, [response.data]);
        }
      );

      // WebSocket uses toOthers() and doesn't echo back to the sender, so we must
      // patch needs_response = false locally for the agent who just replied.
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId) },
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
