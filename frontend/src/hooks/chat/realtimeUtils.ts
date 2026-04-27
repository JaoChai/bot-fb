import { type QueryClient, type InfiniteData } from '@tanstack/react-query';
import { conversationKeys, type ConversationsResponse } from './useConversationList';
import type { Conversation, ConversationFilters, Message } from '@/types/api';
import type { MessageSentEvent } from '@/types/realtime';

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
  filters: ConversationFilters,
  conversationId: number,
  selectedConversationId: number | null | undefined,
  event: MessageSentEvent
) {
  queryClient.setQueryData<InfiniteData<ConversationsResponse>>(
    conversationKeys.infinite(botId, filters),
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
