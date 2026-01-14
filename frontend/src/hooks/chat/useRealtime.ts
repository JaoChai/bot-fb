/**
 * T019: useRealtime hook
 * T042: Prevent unnecessary re-renders with useRef for callbacks
 * T043: WebSocket reconnection handling with automatic reconnect
 *
 * Extract Echo/WebSocket subscriptions for chat
 * Listen for MessageReceived events and invalidate cache
 */
import { useEffect, useRef, useCallback, useState } from 'react';
import { useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { useBotChannel } from '@/hooks/useEcho';
import { messageKeys, type MessagesResponse } from './useMessages';
import { conversationKeys, type ConversationsResponse } from './useConversationList';
import { useConnectionStore } from '@/stores/connectionStore';
import type { Message, Conversation, ConversationFilters } from '@/types/api';
import type { MessageSentEvent, ConversationUpdatedEvent } from '@/types/realtime';

interface UseRealtimeOptions {
  selectedConversationId?: number | null;
  /**
   * Enable polling fallback when WebSocket is disconnected
   * @default true
   */
  enableFallbackPolling?: boolean;
}

/**
 * T043: Hook to track WebSocket connection status
 * Use this to show connection indicators in the UI
 */
export function useConnectionStatus() {
  const isConnected = useConnectionStore((state) => state.isConnected);
  const setConnected = useConnectionStore((state) => state.setConnected);
  const [isReconnecting, setIsReconnecting] = useState(false);

  useEffect(() => {
    const handleConnected = () => {
      setConnected(true);
      setIsReconnecting(false);
    };

    const handleDisconnected = () => {
      setConnected(false);
    };

    const handleReconnecting = () => {
      setIsReconnecting(true);
    };

    // Listen for Echo connection events
    window.addEventListener('echo:connected', handleConnected);
    window.addEventListener('echo:disconnected', handleDisconnected);
    window.addEventListener('echo:reconnected', handleConnected);

    // Check Pusher state changes for more granular status
    const checkPusherState = () => {
      const pusher = (window as unknown as { Echo?: { connector?: { pusher?: { connection?: { state: string } } } } }).Echo?.connector?.pusher;
      if (pusher?.connection?.state === 'connecting') {
        setIsReconnecting(true);
      }
    };

    // Check state periodically when disconnected
    const interval = setInterval(() => {
      if (!isConnected) {
        checkPusherState();
      }
    }, 1000);

    return () => {
      window.removeEventListener('echo:connected', handleConnected);
      window.removeEventListener('echo:disconnected', handleDisconnected);
      window.removeEventListener('echo:reconnected', handleReconnecting);
      clearInterval(interval);
    };
  }, [setConnected, isConnected]);

  return { isConnected, isReconnecting };
}

/**
 * Hook to handle real-time updates for chat
 * T042: Uses useRef to prevent callback re-creation and unnecessary re-renders
 * Surgically updates cache instead of refetching
 */
export function useRealtime(
  botId: number | null,
  filters: ConversationFilters,
  options: UseRealtimeOptions = {}
) {
  const queryClient = useQueryClient();
  const { selectedConversationId } = options;

  // T042: Store values in refs to prevent callback recreation
  const botIdRef = useRef(botId);
  const filtersRef = useRef(filters);
  const selectedConversationIdRef = useRef(selectedConversationId);

  // Keep refs updated
  useEffect(() => {
    botIdRef.current = botId;
    filtersRef.current = filters;
    selectedConversationIdRef.current = selectedConversationId;
  }, [botId, filters, selectedConversationId]);

  // T042: Stable callback that reads from refs
  const handleRealtimeMessage = useCallback(
    (event: MessageSentEvent) => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      // Check if message already exists to avoid duplicate updates
      const messageOptions = { order: 'asc' as const, perPage: 100 };
      const existingMessages = queryClient.getQueryData<MessagesResponse>(
        messageKeys.listWithOptions(currentBotId, event.conversation_id, messageOptions)
      );

      if (existingMessages?.data.some((m) => m.id === event.id)) {
        // Message already exists, skip update
        return;
      }

      // Add message to cache
      queryClient.setQueryData<MessagesResponse>(
        messageKeys.listWithOptions(currentBotId, event.conversation_id, messageOptions),
        (old) => {
          if (!old) return old;
          const exists = old.data.some((m) => m.id === event.id);
          if (exists) return old;

          const newMessage: Message = {
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

          return {
            ...old,
            data: [...old.data, newMessage],
          };
        }
      );

      // Update conversation in list using refs
      updateConversationInList(
        queryClient,
        currentBotId,
        filtersRef.current,
        event.conversation_id,
        selectedConversationIdRef.current,
        event
      );
    },
    [queryClient] // Only queryClient as dependency since we use refs
  );

  // T042: Stable callback for conversation updates
  const handleConversationUpdate = useCallback(
    (event: ConversationUpdatedEvent) => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      // T042: Only update if data actually changed
      queryClient.setQueryData<InfiniteData<ConversationsResponse>>(
        conversationKeys.infinite(currentBotId, filtersRef.current),
        (old) => {
          if (!old) return old;

          // Find if conversation exists and compare values
          let hasChanges = false;
          const updatedPages = old.pages.map((page) => ({
            ...page,
            data: page.data.map((conv) => {
              if (conv.id !== event.id) return conv;

              // Check if any values actually changed
              const needsUpdate =
                conv.status !== event.status ||
                conv.is_handover !== event.is_handover ||
                conv.assigned_user_id !== event.assigned_user_id ||
                conv.message_count !== event.message_count ||
                conv.last_message_at !== event.last_message_at ||
                conv.needs_response !== event.needs_response ||
                conv.unread_count !== event.unread_count ||
                conv.bot_auto_enable_at !== event.bot_auto_enable_at;

              if (!needsUpdate) return conv;

              hasChanges = true;
              // If this conversation is currently selected, keep unread_count at 0
              const isSelected = conv.id === selectedConversationIdRef.current;
              return {
                ...conv,
                status: event.status,
                is_handover: event.is_handover,
                assigned_user_id: event.assigned_user_id,
                message_count: event.message_count,
                last_message_at: event.last_message_at,
                needs_response: event.needs_response,
                unread_count: isSelected ? 0 : (event.unread_count ?? conv.unread_count),
                bot_auto_enable_at: event.bot_auto_enable_at,
              };
            }),
          }));

          // Only return new object if there were actual changes
          if (!hasChanges) return old;

          return {
            ...old,
            pages: updatedPages,
          };
        }
      );
    },
    [queryClient]
  );

  // T042: Stable callback for new conversations
  // T044: Use targeted invalidation instead of full refetch to reduce network load
  const handleNewConversation = useCallback(
    (event: ConversationUpdatedEvent) => {
      const currentBotId = botIdRef.current;
      const currentFilters = filtersRef.current;
      if (!currentBotId) return;

      // Check if conversation already exists in cache (e.g., from message event)
      const existingData = queryClient.getQueryData<InfiniteData<ConversationsResponse>>(
        conversationKeys.infinite(currentBotId, currentFilters)
      );

      if (existingData) {
        const conversationExists = existingData.pages.some((page) =>
          page.data.some((conv) => conv.id === event.id)
        );

        // If conversation already exists, no need to refetch
        if (conversationExists) {
          return;
        }
      }

      // Invalidate only the first page to get new conversation
      // This is more efficient than refetching all pages
      queryClient.invalidateQueries({
        queryKey: conversationKeys.infinite(currentBotId, currentFilters),
        exact: true,
        refetchType: 'active',
      });
    },
    [queryClient]
  );

  // Subscribe to bot channel
  useBotChannel(botId, {
    onMessage: handleRealtimeMessage,
    onConversationUpdate: handleConversationUpdate,
    onNewConversation: handleNewConversation,
  });

  // T043: Handle reconnection with selective invalidation
  useEffect(() => {
    const handleReconnect = () => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      const currentSelectedId = selectedConversationIdRef.current;

      // Only invalidate the currently viewed conversation's messages
      if (currentSelectedId) {
        queryClient.invalidateQueries({
          queryKey: messageKeys.list(currentBotId, currentSelectedId),
        });
      }

      // Invalidate conversation list to get any missed updates
      queryClient.invalidateQueries({
        queryKey: conversationKeys.infinite(currentBotId),
      });
    };

    window.addEventListener('echo:reconnected', handleReconnect);
    return () => window.removeEventListener('echo:reconnected', handleReconnect);
  }, [queryClient]);
}

// Helper to update conversation in infinite list
function updateConversationInList(
  queryClient: ReturnType<typeof useQueryClient>,
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
      const allConversations = old.pages.flatMap((page) => page.data);
      const conversationIndex = allConversations.findIndex(
        (conv) => conv.id === conversationId
      );

      if (conversationIndex === -1) return old;

      const existingConv = allConversations[conversationIndex];
      const updatedConv: Conversation = {
        ...existingConv,
        last_message_at: event.conversation?.last_message_at ?? event.created_at,
        message_count: event.conversation?.message_count ?? existingConv.message_count + 1,
        unread_count:
          existingConv.id === selectedConversationId
            ? 0
            : (event.conversation?.unread_count ?? existingConv.unread_count + 1),
        needs_response: nowNeedsResponse,
        last_message: {
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
        },
      };

      allConversations.splice(conversationIndex, 1);
      allConversations.unshift(updatedConv);

      let offset = 0;
      return {
        ...old,
        pages: old.pages.map((page) => {
          const pageSize = page.data.length;
          const pageData = allConversations.slice(offset, offset + pageSize);
          offset += pageSize;
          return { ...page, data: pageData };
        }),
      };
    }
  );
}
