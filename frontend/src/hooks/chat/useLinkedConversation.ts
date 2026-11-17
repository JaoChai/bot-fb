/**
 * F1b: deep-link room fetch for /chat?botId=<b>&conversationId=<c>
 *
 * The Telegram follow-up card carries a link to a conversation that may sit
 * outside the loaded list pages. This hook fetches exactly that room with
 * `?messages_limit=1` (without the limit the endpoint loads every message of
 * the room — ConversationQueryService) and caches it as a BARE Conversation
 * under conversationDetailKeys.detail(botId, conversationId) — the same shape
 * useRealtime writes into that key. The page never reads `messages` from this
 * copy.
 *
 * While mounted and enabled it also refetches the key after EVERY successful
 * mutation in the app: handover, tags and mark-as-read write only the list
 * cache, and for a room outside the loaded list pages the header, BotControl
 * and the info panel would otherwise show stale state (D133).
 */
import { useEffect, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import type { Conversation } from '@/types/api';
import { conversationDetailKeys } from './useConversationDetails';

interface ConversationEnvelope {
  data: Conversation;
  message?: string;
}

/**
 * A link id is valid only when the raw text is a plain positive decimal
 * integer (^[1-9][0-9]*$) AND the value fits JS' safe integer range.
 * parseInt alone is not a check: it accepts '12abc' and '1e3' and truncates
 * '1.5' (D132). A missing raw (null) is not a bad link — the link is absent.
 */
export function parseConversationLinkParam(raw: string | null): number | null {
  if (raw === null) return null;
  if (!/^[1-9][0-9]*$/.test(raw)) return null;
  const value = Number(raw);
  return Number.isSafeInteger(value) ? value : null;
}

function isLinkedId(value: number | null): value is number {
  return value !== null && Number.isSafeInteger(value) && value > 0;
}

export function useLinkedConversation(
  botId: number | null,
  conversationId: number | null
) {
  const queryClient = useQueryClient();
  const enabled = isLinkedId(botId) && isLinkedId(conversationId);
  const detailKey = useMemo(
    () => conversationDetailKeys.detail(botId ?? 0, conversationId ?? 0),
    [botId, conversationId]
  );

  const query = useQuery({
    queryKey: detailKey,
    queryFn: async (): Promise<Conversation> => {
      const envelope = await apiGet<ConversationEnvelope>(
        `/bots/${botId}/conversations/${conversationId}?messages_limit=1`
      );
      // Cached value is the bare Conversation — never the { data } envelope.
      return envelope.data;
    },
    enabled,
    retry: false,
  });

  // Refetch after every successful mutation anywhere in the app while this
  // hook is mounted (subscription removed on unmount). Idempotent per
  // mutation via mutationId so duplicate cache events cannot double-fire.
  useEffect(() => {
    if (!enabled) return;
    const mutationCache = queryClient.getMutationCache();
    const handled = new Set<number>();
    const unsubscribe = mutationCache.subscribe((event) => {
      if (event.type !== 'updated') return;
      if (event.mutation.state.status !== 'success') return;
      if (handled.has(event.mutation.mutationId)) return;
      handled.add(event.mutation.mutationId);
      void queryClient.invalidateQueries({ queryKey: detailKey });
    });
    return () => unsubscribe();
  }, [enabled, queryClient, detailKey]);

  return query;
}
