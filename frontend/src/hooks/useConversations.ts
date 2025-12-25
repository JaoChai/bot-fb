import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  Conversation,
  ConversationFilters,
  ConversationStats,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
  UpdateConversationData,
} from '@/types/api';

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & {
    status_counts: ConversationStatusCounts;
  };
}

interface ConversationResponse {
  data: Conversation;
  message?: string;
}

interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

interface StatsResponse {
  data: ConversationStats;
}

/**
 * Hook to fetch conversations for a bot with filters and pagination
 */
export function useConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  return useQuery({
    queryKey: ['conversations', botId, filters],
    queryFn: async () => {
      const params = new URLSearchParams();

      if (filters.status) {
        params.append('status', Array.isArray(filters.status) ? filters.status.join(',') : filters.status);
      }
      if (filters.channel_type) params.append('channel_type', filters.channel_type);
      if (filters.is_handover !== undefined) params.append('is_handover', String(filters.is_handover));
      if (filters.assigned_user_id) params.append('assigned_user_id', String(filters.assigned_user_id));
      if (filters.tags?.length) params.append('tags', filters.tags.join(','));
      if (filters.search) params.append('search', filters.search);
      if (filters.from_date) params.append('from_date', filters.from_date);
      if (filters.to_date) params.append('to_date', filters.to_date);
      if (filters.sort_by) params.append('sort_by', filters.sort_by);
      if (filters.sort_direction) params.append('sort_direction', filters.sort_direction);
      if (filters.per_page) params.append('per_page', String(filters.per_page));
      if (filters.page) params.append('page', String(filters.page));

      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    staleTime: 30000, // 30 seconds
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
  options: { page?: number; perPage?: number; order?: 'asc' | 'desc' } = {}
) {
  return useQuery({
    queryKey: ['conversation-messages', botId, conversationId, options],
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
    staleTime: 10000,
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
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to close a conversation
 */
export function useCloseConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/close`
      );
      return response.data;
    },
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to reopen a conversation
 */
export function useReopenConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/reopen`
      );
      return response.data;
    },
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to toggle handover mode
 */
export function useToggleHandover(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      unassign = false,
    }: {
      conversationId: number;
      unassign?: boolean;
    }) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/toggle-handover`,
        { unassign }
      );
      return response.data;
    },
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}
