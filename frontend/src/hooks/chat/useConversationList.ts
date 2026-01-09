/**
 * T017: useConversationList hook
 * Extract conversation list query with infinite scroll support
 * Includes conversationKeys factory pattern
 */
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useConnectionStore } from '@/stores/connectionStore';
import type {
  Conversation,
  ConversationFilters,
  ConversationStatusCounts,
  PaginationMeta,
} from '@/types/api';

// Fallback polling interval when WebSocket is disconnected (10 seconds)
const FALLBACK_POLLING_INTERVAL = 10000;

// Query key factory for conversations
export const conversationKeys = {
  all: ['conversations'] as const,
  lists: () => [...conversationKeys.all, 'list'] as const,
  list: (botId: number, filters?: ConversationFilters) =>
    [...conversationKeys.lists(), botId, filters] as const,
  infinite: (botId: number, filters?: ConversationFilters) =>
    ['conversations-infinite', botId, filters] as const,
  stats: (botId: number) => [...conversationKeys.all, 'stats', botId] as const,
};

// Response types
export interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & {
    status_counts: ConversationStatusCounts;
  };
}

/**
 * Hook to fetch conversations for a bot with filters and pagination
 */
export function useConversationList(
  botId: number | undefined,
  filters: ConversationFilters = {}
) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useQuery({
    queryKey: botId ? conversationKeys.list(botId, filters) : ['conversations', 'disabled'],
    queryFn: async () => {
      const params = buildFilterParams(filters);
      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Hook to fetch conversations with infinite scroll pagination
 */
export function useInfiniteConversationList(
  botId: number | undefined,
  filters: ConversationFilters = {}
) {
  const isConnected = useConnectionStore((state) => state.isConnected);

  return useInfiniteQuery({
    queryKey: botId
      ? conversationKeys.infinite(botId, filters)
      : ['conversations-infinite', 'disabled'],
    staleTime: 0,
    queryFn: async ({ pageParam = 1 }) => {
      const params = buildFilterParams(filters);
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
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

/**
 * Helper to build URLSearchParams from filters
 */
function buildFilterParams(filters: ConversationFilters): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.status) {
    params.append(
      'status',
      Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    );
  }
  if (filters.channel_type) params.append('channel_type', filters.channel_type);
  if (filters.telegram_chat_type) {
    params.append(
      'telegram_chat_type',
      Array.isArray(filters.telegram_chat_type)
        ? filters.telegram_chat_type.join(',')
        : filters.telegram_chat_type
    );
  }
  if (filters.is_handover !== undefined) {
    params.append('is_handover', String(filters.is_handover));
  }
  if (filters.assigned_user_id) {
    params.append('assigned_user_id', String(filters.assigned_user_id));
  }
  if (filters.tags?.length) params.append('tags', filters.tags.join(','));
  if (filters.search) params.append('search', filters.search);
  if (filters.from_date) params.append('from_date', filters.from_date);
  if (filters.to_date) params.append('to_date', filters.to_date);
  if (filters.sort_by) params.append('sort_by', filters.sort_by);
  if (filters.sort_direction) params.append('sort_direction', filters.sort_direction);

  return params;
}
