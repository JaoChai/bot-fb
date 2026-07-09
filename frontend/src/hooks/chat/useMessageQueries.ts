/**
 * Message query hooks
 *
 * T039: Cursor-based pagination via useInfiniteQuery (newest first).
 * The legacy page-1-ascending useMessages hook was removed — it could never
 * surface new messages in conversations with more than one page.
 */
import {
  useInfiniteQuery,
  type InfiniteData,
} from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useConnectionStore } from '@/stores/connectionStore';
import type { Message } from '@/types/api';
import {
  messageKeys,
  FALLBACK_POLLING_INTERVAL,
  DEFAULT_PAGE_SIZE,
  type MessagesResponse,
} from './messageKeys';

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
    // Infinite query: only poll when disconnected to avoid refetching all loaded pages
    // When connected, WebSocket events keep the cache fresh (reconnect sync covers gaps)
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
