/**
 * T016: useMessages hook
 * T039: Optimized with cursor-based pagination via useInfiniteQuery
 * T040: Optimistic updates for sendMessage and markAsRead
 *
 * Extract message queries from useConversations
 * Includes messageKeys factory pattern
 */
import {
  useQuery,
  useMutation,
  useQueryClient,
  useInfiniteQuery,
  type InfiniteData,
} from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useConnectionStore } from '@/stores/connectionStore';
import type { Message, PaginationMeta } from '@/types/api';

// Fallback polling interval when WebSocket is disconnected (10 seconds)
const FALLBACK_POLLING_INTERVAL = 10000;

// Default page size for messages
const DEFAULT_PAGE_SIZE = 50;

// Query key factory for messages
export const messageKeys = {
  all: ['messages'] as const,
  list: (botId: number, conversationId: number) =>
    [...messageKeys.all, 'list', botId, conversationId] as const,
  listWithOptions: (
    botId: number,
    conversationId: number,
    options: MessagesOptions
  ) => [...messageKeys.list(botId, conversationId), options] as const,
  infinite: (botId: number, conversationId: number) =>
    [...messageKeys.all, 'infinite', botId, conversationId] as const,
};

// Types
export interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

export interface MessagesOptions {
  page?: number;
  perPage?: number;
  order?: 'asc' | 'desc';
}

interface SendMessageData {
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
 * Hook to fetch messages for a conversation (simple query)
 * Use this for conversations with less than 100 messages
 */
export function useMessages(
  botId: number | undefined,
  conversationId: number | undefined,
  options: MessagesOptions = { order: 'asc', perPage: 100 }
) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useQuery({
    queryKey:
      botId && conversationId
        ? messageKeys.listWithOptions(botId, conversationId, options)
        : ['messages', 'disabled'],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (options.page) params.append('page', String(options.page));
      if (options.perPage) params.append('per_page', String(options.perPage));
      if (options.order) params.append('order', options.order);

      const response = await api.get<MessagesResponse>(
        `/bots/${botId}/conversations/${conversationId}/messages?` + params.toString()
      );
      return response.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * T039: Hook to fetch messages with infinite scroll for large conversations
 * Loads messages in pages, with "load more" for older messages
 * Order is descending (newest first) for cursor-based pagination
 */
export function useInfiniteMessages(
  botId: number | undefined,
  conversationId: number | undefined,
  pageSize: number = DEFAULT_PAGE_SIZE
) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useInfiniteQuery({
    queryKey:
      botId && conversationId
        ? messageKeys.infinite(botId, conversationId)
        : ['messages', 'infinite', 'disabled'],
    queryFn: async ({ pageParam = 1 }) => {
      const params = new URLSearchParams();
      params.append('page', String(pageParam));
      params.append('per_page', String(pageSize));
      // Descending order for cursor pagination (newest first, load older on scroll up)
      params.append('order', 'desc');

      const response = await api.get<MessagesResponse>(
        `/bots/${botId}/conversations/${conversationId}/messages?` + params.toString()
      );
      return response.data;
    },
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      // Load older messages (next page in descending order)
      const { current_page, last_page } = lastPage.meta;
      return current_page < last_page ? current_page + 1 : undefined;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Helper to flatten infinite messages and reverse for display (oldest first)
 */
export function flattenInfiniteMessages(
  data: InfiniteData<MessagesResponse> | undefined
): Message[] {
  if (!data) return [];
  // Flatten all pages and reverse to show oldest first
  return data.pages.flatMap((page) => page.data).reverse();
}

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

      const optimisticId = Date.now();
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
