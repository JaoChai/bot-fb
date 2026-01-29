/**
 * Message query hooks
 *
 * T016: Original useMessages hook
 * T039: Optimized with cursor-based pagination via useInfiniteQuery
 *
 * Extracted from useMessages.ts for single responsibility.
 */
import {
  useQuery,
  useInfiniteQuery,
  type InfiniteData,
} from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildFilterParams } from '@/lib/params';
import { useConnectionStore } from '@/stores/connectionStore';
import type { Message } from '@/types/api';
import {
  messageKeys,
  FALLBACK_POLLING_INTERVAL,
  HEARTBEAT_INTERVAL,
  DEFAULT_PAGE_SIZE,
  type MessagesResponse,
  type MessagesOptions,
} from './messageKeys';

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
      const params = buildFilterParams({
        page: options.page,
        per_page: options.perPage,
        order: options.order,
      });

      const response = await api.get<MessagesResponse>(
        `/bots/${botId}/conversations/${conversationId}/messages?` + params.toString()
      );
      return response.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 0,
    // When connected: heartbeat refresh every 30s to catch missed events
    // When disconnected: fast polling every 5s for quick recovery
    refetchInterval: isConnected ? HEARTBEAT_INTERVAL : FALLBACK_POLLING_INTERVAL,
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
    // When connected: heartbeat refresh every 30s to catch missed events
    // When disconnected: fast polling every 5s for quick recovery
    refetchInterval: isConnected ? HEARTBEAT_INTERVAL : FALLBACK_POLLING_INTERVAL,
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
