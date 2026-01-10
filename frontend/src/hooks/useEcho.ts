import { useEffect, useRef, useCallback } from 'react';
import type { Channel, PresenceChannel } from 'laravel-echo';
import { getEcho, reconnectEcho } from '@/lib/echo';
import type {
  MessageSentEvent,
  ConversationUpdatedEvent,
  AdminNotificationEvent,
  DocumentStatusUpdatedEvent,
} from '@/types/realtime';
import { CHANNELS, EVENTS } from '@/types/realtime';

/**
 * Hook to subscribe to a conversation channel for real-time messages
 * Uses refs for callbacks to prevent effect re-runs on callback changes
 */
export function useConversationChannel(
  conversationId: number | null,
  callbacks: {
    onMessage?: (event: MessageSentEvent) => void;
    onConversationUpdate?: (event: ConversationUpdatedEvent) => void;
  }
) {
  const channelRef = useRef<Channel | null>(null);

  // Store callbacks in refs to prevent effect re-runs
  const callbacksRef = useRef(callbacks);
  callbacksRef.current = callbacks;

  useEffect(() => {
    if (!conversationId) return;

    const echo = getEcho();
    const channelName = CHANNELS.conversation(conversationId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.messageSent}`, (event: MessageSentEvent) => {
        callbacksRef.current.onMessage?.(event);
      })
      .listen(`.${EVENTS.conversationUpdated}`, (event: ConversationUpdatedEvent) => {
        callbacksRef.current.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.conversationMessageReceived}`, (event: ConversationUpdatedEvent) => {
        callbacksRef.current.onConversationUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [conversationId]); // Only re-subscribe when conversationId changes

  return channelRef.current;
}

/**
 * Hook to subscribe to a bot channel for all conversations
 * Uses refs for callbacks to prevent effect re-runs on callback changes
 */
export function useBotChannel(
  botId: number | null,
  callbacks: {
    onMessage?: (event: MessageSentEvent) => void;
    onConversationUpdate?: (event: ConversationUpdatedEvent) => void;
    onNewConversation?: (event: ConversationUpdatedEvent) => void;
  }
) {
  const channelRef = useRef<Channel | null>(null);

  // Store callbacks in refs to prevent effect re-runs
  const callbacksRef = useRef(callbacks);
  callbacksRef.current = callbacks;

  useEffect(() => {
    if (!botId) {
      console.log('[useBotChannel] No botId, skipping subscription');
      return;
    }

    const echo = getEcho();
    const channelName = CHANNELS.bot(botId);

    console.log('[useBotChannel] Subscribing to channel:', channelName);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.messageSent}`, (event: MessageSentEvent) => {
        console.log('[useBotChannel] message.sent:', event.conversation_id);
        callbacksRef.current.onMessage?.(event);
      })
      .listen(`.${EVENTS.conversationCreated}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.created:', event.id);
        callbacksRef.current.onNewConversation?.(event);
      })
      .listen(`.${EVENTS.conversationUpdated}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.updated:', event.id);
        callbacksRef.current.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.conversationMessageReceived}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.message_received:', event.id);
        callbacksRef.current.onConversationUpdate?.(event);
      });

    console.log('[useBotChannel] Subscription complete for:', channelName);

    return () => {
      console.log('[useBotChannel] Leaving channel:', channelName);
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [botId]); // Only re-subscribe when botId changes

  return channelRef.current;
}

/**
 * Hook to subscribe to user notifications
 */
export function useNotifications(
  userId: number | null,
  onNotification: (event: AdminNotificationEvent) => void
) {
  const channelRef = useRef<Channel | null>(null);

  useEffect(() => {
    if (!userId) return;

    const echo = getEcho();
    const channelName = CHANNELS.userNotifications(userId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.notification}`, (event: AdminNotificationEvent) => {
        onNotification(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [userId, onNotification]);

  return channelRef.current;
}

/**
 * Hook to manage Echo connection lifecycle
 */
export function useEchoConnection() {
  const reconnect = useCallback(() => {
    reconnectEcho();
  }, []);

  const isConnected = useCallback(() => {
    const echo = getEcho();
    return echo.connector.pusher?.connection?.state === 'connected';
  }, []);

  return { reconnect, isConnected };
}

/**
 * Hook for presence channel (to see who else is viewing)
 */
export function useBotPresence(
  botId: number | null,
  callbacks: {
    onHere?: (members: { id: number; name: string }[]) => void;
    onJoining?: (member: { id: number; name: string }) => void;
    onLeaving?: (member: { id: number; name: string }) => void;
  }
) {
  const channelRef = useRef<PresenceChannel | null>(null);

  useEffect(() => {
    if (!botId) return;

    const echo = getEcho();
    const channelName = `bot.${botId}.presence`;

    channelRef.current = echo.join(channelName)
      .here((members: { id: number; name: string }[]) => {
        callbacks.onHere?.(members);
      })
      .joining((member: { id: number; name: string }) => {
        callbacks.onJoining?.(member);
      })
      .leaving((member: { id: number; name: string }) => {
        callbacks.onLeaving?.(member);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [botId, callbacks.onHere, callbacks.onJoining, callbacks.onLeaving]);

  return channelRef.current;
}

/**
 * Hook to subscribe to knowledge base channel for document status updates
 */
export function useKnowledgeBaseChannel(
  knowledgeBaseId: number | null,
  callbacks: {
    onDocumentStatusUpdate?: (event: DocumentStatusUpdatedEvent) => void;
  }
) {
  const channelRef = useRef<Channel | null>(null);

  useEffect(() => {
    if (!knowledgeBaseId) return;

    const echo = getEcho();
    const channelName = CHANNELS.knowledgeBase(knowledgeBaseId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.documentStatusUpdated}`, (event: DocumentStatusUpdatedEvent) => {
        callbacks.onDocumentStatusUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [knowledgeBaseId, callbacks.onDocumentStatusUpdate]);

  return channelRef.current;
}
