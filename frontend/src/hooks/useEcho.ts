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
 */
export function useConversationChannel(
  conversationId: number | null,
  callbacks: {
    onMessage?: (event: MessageSentEvent) => void;
    onConversationUpdate?: (event: ConversationUpdatedEvent) => void;
  }
) {
  const channelRef = useRef<Channel | null>(null);

  useEffect(() => {
    if (!conversationId) return;

    const echo = getEcho();
    const channelName = CHANNELS.conversation(conversationId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.messageSent}`, (event: MessageSentEvent) => {
        callbacks.onMessage?.(event);
      })
      .listen(`.${EVENTS.conversationUpdated}`, (event: ConversationUpdatedEvent) => {
        callbacks.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.conversationMessageReceived}`, (event: ConversationUpdatedEvent) => {
        callbacks.onConversationUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [conversationId, callbacks.onMessage, callbacks.onConversationUpdate]);

  return channelRef.current;
}

/**
 * Hook to subscribe to a bot channel for all conversations
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

  useEffect(() => {
    if (!botId) return;

    const echo = getEcho();
    const channelName = CHANNELS.bot(botId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.messageSent}`, (event: MessageSentEvent) => {
        console.log('[useBotChannel] message.sent:', event.conversation_id);
        callbacks.onMessage?.(event);
      })
      .listen(`.${EVENTS.conversationCreated}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.created:', event.id);
        callbacks.onNewConversation?.(event);
      })
      .listen(`.${EVENTS.conversationUpdated}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.updated:', event.id);
        callbacks.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.conversationMessageReceived}`, (event: ConversationUpdatedEvent) => {
        console.log('[useBotChannel] conversation.message_received:', event.id);
        callbacks.onConversationUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [botId, callbacks.onMessage, callbacks.onConversationUpdate, callbacks.onNewConversation]);

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
