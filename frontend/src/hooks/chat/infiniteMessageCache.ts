/**
 * Pure helpers for the infinite messages cache.
 *
 * The cache (`messageKeys.infinite`) stores pages newest→oldest as returned
 * by the API with order=desc: pages[0].data[0] is the newest message.
 * All realtime / optimistic / sync write paths go through these helpers so
 * dedup and ordering rules live in exactly one place.
 */
import type { InfiniteData } from '@tanstack/react-query';
import type { Message } from '@/types/api';
import type { MessagesResponse } from './messageKeys';

export type InfiniteMessages = InfiniteData<MessagesResponse>;

export function messageExistsInInfinite(
  data: InfiniteMessages | undefined,
  messageId: number
): boolean {
  if (!data) return false;
  return data.pages.some((page) => page.data.some((m) => m.id === messageId));
}

/**
 * Dedup against every page, then insert the remaining messages newest-first
 * at the front of the first page. Returns the input object unchanged when
 * there is nothing to insert (lets React Query skip the update).
 */
export function prependMessagesToInfinite(
  data: InfiniteMessages,
  messages: Message[]
): InfiniteMessages {
  if (data.pages.length === 0) return data;

  const existingIds = new Set(data.pages.flatMap((page) => page.data.map((m) => m.id)));
  const fresh = messages.filter((m) => !existingIds.has(m.id));
  if (fresh.length === 0) return data;

  const freshDesc = [...fresh].sort(
    (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
  );

  return {
    ...data,
    pages: data.pages.map((page, i) =>
      i === 0 ? { ...page, data: [...freshDesc, ...page.data] } : page
    ),
  };
}

/**
 * Replace the message with id `matchId` (usually a negative optimistic id)
 * with `replacement`. If `replacement.id` already exists elsewhere (WebSocket
 * echoed the real message before the API response), remove the matchId entry
 * instead of creating a duplicate.
 */
export function replaceMessageInInfinite(
  data: InfiniteMessages,
  matchId: number,
  replacement: Message
): InfiniteMessages {
  const replacementExists =
    replacement.id !== matchId && messageExistsInInfinite(data, replacement.id);

  // Only clone pages that actually contain matchId; untouched pages keep
  // their reference so memoized consumers can skip re-rendering them.
  return {
    ...data,
    pages: data.pages.map((page) => {
      if (!page.data.some((m) => m.id === matchId)) return page;
      return {
        ...page,
        data: replacementExists
          ? page.data.filter((m) => m.id !== matchId)
          : page.data.map((m) => (m.id === matchId ? replacement : m)),
      };
    }),
  };
}

export function removeMessageFromInfinite(
  data: InfiniteMessages,
  messageId: number
): InfiniteMessages {
  return {
    ...data,
    pages: data.pages.map((page) =>
      page.data.some((m) => m.id === messageId)
        ? { ...page, data: page.data.filter((m) => m.id !== messageId) }
        : page
    ),
  };
}
