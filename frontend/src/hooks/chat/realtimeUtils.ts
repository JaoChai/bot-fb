import { type Query, type QueryClient, type InfiniteData } from '@tanstack/react-query';
import { type ConversationsResponse } from './useConversationList';
import type { Conversation, Message } from '@/types/api';
import type { MessageSentEvent } from '@/types/realtime';

/**
 * Matches every `useInfiniteConversationList` cache entry for a bot, regardless of
 * filters. Use whenever filter object identity may shift between query registration
 * and cache write (Echo handlers, mutation onSuccess callbacks).
 */
export const isInfiniteConversationsQuery =
  (botId: number) =>
  (query: Query): boolean => {
    const key = query.queryKey;
    return Array.isArray(key)
      && key[0] === 'conversations-infinite'
      && key[1] === botId;
  };

export function createMessageFromEvent(event: MessageSentEvent): Message {
  return {
    id: event.id,
    conversation_id: event.conversation_id,
    sender: event.sender,
    content: event.content,
    type: event.type,
    media_url: event.media_url,
    media_type: event.media_type,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: event.created_at,
    updated_at: event.created_at,
  };
}

export function updateConversationInList(
  queryClient: QueryClient,
  botId: number,
  conversationId: number,
  selectedConversationId: number | null | undefined,
  event: MessageSentEvent
) {
  queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
    { predicate: isInfiniteConversationsQuery(botId) },
    (old) => {
      if (!old) return old;

      const nowNeedsResponse = event.sender === 'user';

      let targetPageIdx = -1;
      let targetItemIdx = -1;
      for (let p = 0; p < old.pages.length; p++) {
        const idx = old.pages[p].data.findIndex((c) => c.id === conversationId);
        if (idx !== -1) {
          targetPageIdx = p;
          targetItemIdx = idx;
          break;
        }
      }

      if (targetPageIdx === -1) return old;

      const existingConv = old.pages[targetPageIdx].data[targetItemIdx];
      const newMessage = createMessageFromEvent(event);
      const updatedConv: Conversation = {
        ...existingConv,
        last_message_at: event.conversation?.last_message_at ?? event.created_at,
        message_count: event.conversation?.message_count ?? existingConv.message_count + 1,
        unread_count:
          existingConv.id === selectedConversationId
            ? 0
            : (event.conversation?.unread_count ?? existingConv.unread_count + 1),
        needs_response: nowNeedsResponse,
        last_message: newMessage,
      };

      // Build new pages: remove from old position, prepend to first page
      const newPages = old.pages.map((page, i) => {
        const filteredData = i === targetPageIdx
          ? page.data.filter((_, j) => j !== targetItemIdx)
          : page.data;

        if (i === 0) {
          return { ...page, data: [updatedConv, ...filteredData] };
        }
        return { ...page, data: filteredData };
      });

      return { ...old, pages: newPages };
    }
  );
}
