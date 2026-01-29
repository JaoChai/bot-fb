/**
 * T017: useConversationList hook
 * Extract conversation list query with infinite scroll support
 * Includes conversationKeys factory pattern
 */
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildConversationFilterParams } from '@/lib/params';
import { useConnectionStore } from '@/stores/connectionStore';
import type {
  Conversation,
  ConversationFilters,
  ConversationStatusCounts,
  PaginationMeta,
} from '@/types/api';

// Fallback polling interval when WebSocket is disconnected (5 seconds for faster recovery)
const FALLBACK_POLLING_INTERVAL = 5000;

// Heartbeat refresh interval when connected (30 seconds)
// This ensures data stays fresh even if WebSocket events are missed during reconnection gaps
// Similar to how LINE OA maintains data sync with periodic refreshes
const HEARTBEAT_INTERVAL = 30000;

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
      const params = buildConversationFilterParams(filters);
      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    staleTime: 0,
    // When connected: heartbeat refresh every 30s to catch missed events
    // When disconnected: fast polling every 5s for quick recovery
    refetchInterval: isConnected ? HEARTBEAT_INTERVAL : FALLBACK_POLLING_INTERVAL,
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
    // When connected: heartbeat refresh every 30s to catch missed events
    // When disconnected: fast polling every 5s for quick recovery
    refetchInterval: isConnected ? HEARTBEAT_INTERVAL : FALLBACK_POLLING_INTERVAL,
  });
}

