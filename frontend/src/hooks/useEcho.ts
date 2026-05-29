import { useEffect, useRef } from 'react';
import type { Channel } from 'laravel-echo';
import { getEcho } from '@/lib/echo';
import type {
  MessageSentEvent,
  ConversationUpdatedEvent,
  DocumentStatusUpdatedEvent,
  BotSettingsUpdatedEvent,
} from '@/types/realtime';
import { CHANNELS, EVENTS } from '@/types/realtime';

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
    onSettingsUpdate?: (event: BotSettingsUpdatedEvent) => void;
  }
) {
  const channelRef = useRef<Channel | null>(null);

  // Store callbacks in refs to prevent effect re-runs
  const callbacksRef = useRef(callbacks);
  callbacksRef.current = callbacks;

  useEffect(() => {
    if (!botId) {
      return;
    }

    const echo = getEcho();
    const channelName = CHANNELS.bot(botId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.messageSent}`, (event: MessageSentEvent) => {
        callbacksRef.current.onMessage?.(event);
      })
      .listen(`.${EVENTS.conversationCreated}`, (event: ConversationUpdatedEvent) => {
        callbacksRef.current.onNewConversation?.(event);
      })
      .listen(`.${EVENTS.conversationUpdated}`, (event: ConversationUpdatedEvent) => {
        callbacksRef.current.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.conversationMessageReceived}`, (event: ConversationUpdatedEvent) => {
        callbacksRef.current.onConversationUpdate?.(event);
      })
      .listen(`.${EVENTS.settingsUpdated}`, (event: BotSettingsUpdatedEvent) => {
        callbacksRef.current.onSettingsUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [botId]); // Only re-subscribe when botId changes

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
  const callbacksRef = useRef(callbacks);
  callbacksRef.current = callbacks;

  useEffect(() => {
    if (!knowledgeBaseId) return;

    const echo = getEcho();
    const channelName = CHANNELS.knowledgeBase(knowledgeBaseId);

    channelRef.current = echo.private(channelName)
      .listen(`.${EVENTS.documentStatusUpdated}`, (event: DocumentStatusUpdatedEvent) => {
        callbacksRef.current.onDocumentStatusUpdate?.(event);
      });

    return () => {
      if (channelRef.current) {
        echo.leave(channelName);
        channelRef.current = null;
      }
    };
  }, [knowledgeBaseId]);

  return channelRef.current;
}
