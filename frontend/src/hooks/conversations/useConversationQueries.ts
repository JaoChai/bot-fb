import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildConversationFilterParams } from '@/lib/params';
import { useConnectionStore } from '@/stores/connectionStore';
import { messageKeys, type MessagesOptions } from '@/hooks/chat';
import type {
  Conversation,
  ConversationFilters,
  ConversationStats,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}
interface ConversationResponse { data: Conversation; message?: string }
interface MessagesResponse { data: Message[]; meta: PaginationMeta }
interface StatsResponse { data: ConversationStats }

// Fallback polling interval when WebSocket is disconnected (10 seconds)
const FALLBACK_POLLING_INTERVAL = 10_000;

/**
 * Hook to fetch conversations for a bot with filters and pagination
 */
export function useConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useQuery({
    queryKey: ['conversations', botId, filters],
    queryFn: async () => {
      const params = buildConversationFilterParams(filters);
      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    // Always refetch on mount - conversations are real-time data
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Hook to fetch conversations with infinite scroll pagination
 */
export function useInfiniteConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useInfiniteQuery({
    queryKey: ['conversations-infinite', botId, filters],
    // Always refetch on mount - conversations are real-time data
    staleTime: 0,
    queryFn: async ({ pageParam = 1 }) => {
      const params = buildConversationFilterParams(filters);
      params.append('per_page', String(filters.per_page || 30));
      params.append('page', String(pageParam));

      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const { current_page, last_page } = lastPage.meta;
      return current_page < last_page ? current_page + 1 : undefined;
    },
    enabled: !!botId,
    // Let global defaults handle caching - WebSocket handles real-time updates
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Hook to fetch a single conversation with messages
 */
export function useConversation(botId: number | undefined, conversationId: number | undefined, messagesLimit?: number) {
  return useQuery({
    queryKey: ['conversation', botId, conversationId],
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
 * Hook to fetch messages for a conversation with pagination
 */
export function useConversationMessages(
  botId: number | undefined,
  conversationId: number | undefined,
  options: MessagesOptions = {}
) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useQuery({
    // Use unified messageKeys for cache consistency with useMessages hook
    queryKey: botId && conversationId
      ? messageKeys.listWithOptions(botId, conversationId, options)
      : ['messages', 'disabled'],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (options.page) params.append('page', String(options.page));
      if (options.perPage) params.append('per_page', String(options.perPage));
      if (options.order) params.append('order', options.order);

      const response = await api.get<MessagesResponse>(
        `/bots/${botId}/conversations/${conversationId}/messages?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId && !!conversationId,
    // Always refetch on mount - messages are real-time data
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Hook to fetch conversation statistics for a bot
 */
export function useConversationStats(botId: number | undefined) {
  return useQuery({
    queryKey: ['conversation-stats', botId],
    queryFn: async () => {
      const response = await api.get<StatsResponse>(`/bots/${botId}/conversations/stats`);
      return response.data.data;
    },
    enabled: !!botId,
    staleTime: 60000, // 1 minute
  });
}
