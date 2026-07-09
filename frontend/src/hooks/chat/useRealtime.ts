/**
 * T019: useRealtime hook
 * T042: Prevent unnecessary re-renders with useRef for callbacks
 * T043: WebSocket reconnection handling with automatic reconnect
 *
 * Extract Echo/WebSocket subscriptions for chat
 * Listen for MessageReceived events and invalidate cache
 */
import { useEffect, useRef, useCallback } from 'react';
import { useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { useBotChannel } from '@/hooks/useEcho';
import { messageKeys } from './messageKeys';
import {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  type InfiniteMessages,
} from './infiniteMessageCache';
import { conversationKeys, type ConversationsResponse } from './useConversationList';
import { conversationDetailKeys } from './useConversationDetails';
import { updateConversationInList, createMessageFromEvent, isInfiniteConversationsQuery } from './realtimeUtils';
import { showBrowserNotification, playPing, setUnreadBadge } from '@/lib/notifications';
import { syncBot } from '@/lib/syncEngine';
import { useUIStore } from '@/stores/uiStore';
import type { Conversation, ConversationFilters } from '@/types/api';
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

  // Notification state refs
  const unreadCountRef = useRef(0);
  const audioEnabled = useUIStore((s) => s.audioEnabled);
  const notificationEnabled = useUIStore((s) => s.notificationEnabled);
  const audioEnabledRef = useRef(audioEnabled);
  const notificationEnabledRef = useRef(notificationEnabled);

  // Keep refs updated
  useEffect(() => {
    botIdRef.current = botId;
    filtersRef.current = filters;
    selectedConversationIdRef.current = selectedConversationId;
    audioEnabledRef.current = audioEnabled;
    notificationEnabledRef.current = notificationEnabled;
  }, [botId, filters, selectedConversationId, audioEnabled, notificationEnabled]);

  // T042: Stable callback that reads from refs
  const handleRealtimeMessage = useCallback(
    (event: MessageSentEvent) => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      const infiniteKey = messageKeys.infinite(currentBotId, event.conversation_id);

      // Check if message already exists to avoid duplicate updates
      const existingMessages = queryClient.getQueryData<InfiniteMessages>(infiniteKey);
      if (messageExistsInInfinite(existingMessages, event.id)) {
        // Message already exists, skip update
        return;
      }

      // Add message to cache (newest-first: prepend to first page)
      queryClient.setQueryData<InfiniteMessages>(infiniteKey, (old) => {
        if (!old) return old;
        return prependMessagesToInfinite(old, [createMessageFromEvent(event)]);
      });

      // Update conversation in list — filter-agnostic, refs supply selection state
      updateConversationInList(
        queryClient,
        currentBotId,
        event.conversation_id,
        selectedConversationIdRef.current,
        event
      );

      // Notify when tab is hidden and message is from user (not bot/agent)
      if (document.visibilityState === 'hidden' && event.sender === 'user') {
        unreadCountRef.current++;
        setUnreadBadge(unreadCountRef.current);

        if (audioEnabledRef.current) {
          playPing();
        }
        if (notificationEnabledRef.current) {
          showBrowserNotification('ข้อความใหม่', {
            body: event.content?.substring(0, 100) || 'มีข้อความใหม่เข้ามา',
          });
        }
      }
    },
    [queryClient] // Only queryClient as dependency since we use refs
  );

  // T042: Stable callback for conversation updates
  // T045: Update both infinite list AND detail query for bot auto-enable sync
  const handleConversationUpdate = useCallback(
    (event: ConversationUpdatedEvent) => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(currentBotId) },
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

      // T045: Also update conversation detail query for bot auto-enable toggle sync
      // This ensures BotControl component receives updated is_handover state
      queryClient.setQueryData<Conversation>(
        conversationDetailKeys.detail(currentBotId, event.id),
        (old) => {
          if (!old) return old;

          const isSelected = event.id === selectedConversationIdRef.current;
          return {
            ...old,
            status: event.status,
            is_handover: event.is_handover,
            assigned_user_id: event.assigned_user_id,
            message_count: event.message_count,
            last_message_at: event.last_message_at,
            needs_response: event.needs_response,
            unread_count: isSelected ? 0 : (event.unread_count ?? old.unread_count),
            bot_auto_enable_at: event.bot_auto_enable_at,
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

  // Reset badge when tab becomes visible
  useEffect(() => {
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        unreadCountRef.current = 0;
        setUnreadBadge(0);
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);
    return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
  }, []);

  // Subscribe to bot channel
  useBotChannel(botId, {
    onMessage: handleRealtimeMessage,
    onConversationUpdate: handleConversationUpdate,
    onNewConversation: handleNewConversation,
  });

  // T043: Handle reconnection — delta sync or full invalidation
  const useDeltaSync = import.meta.env.VITE_FEATURE_DELTA_SYNC === 'true';

  useEffect(() => {
    const handleReconnect = () => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      if (useDeltaSync) {
        syncBot(currentBotId, queryClient, selectedConversationIdRef.current);
      } else {
        queryClient.invalidateQueries({
          predicate: isInfiniteConversationsQuery(currentBotId),
        });

        const currentSelectedId = selectedConversationIdRef.current;
        if (currentSelectedId) {
          queryClient.invalidateQueries({
            queryKey: messageKeys.infinite(currentBotId, currentSelectedId),
          });
          queryClient.invalidateQueries({
            queryKey: conversationDetailKeys.detail(currentBotId, currentSelectedId),
          });
        }
      }
    };

    window.addEventListener('echo:reconnected', handleReconnect);
    return () => window.removeEventListener('echo:reconnected', handleReconnect);
  }, [queryClient, useDeltaSync]);
}
