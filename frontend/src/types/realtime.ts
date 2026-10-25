/**
 * Real-time event types for Laravel Echo / Reverb
 *
 * MessageSentEvent and ConversationUpdatedEvent are the contract broadcast
 * payloads (schemas MessageSentPayload / ConversationUpdatedPayload in
 * contracts/openapi.yaml; regenerate with `npm run contract:check`). The
 * other events are not contract-covered yet and stay hand-declared.
 */
import type { components } from './generated/auth-chat';

/** Broadcast of App\Events\MessageSent — event `message.sent`, private `bot.{bot_id}`. */
export type MessageSentEvent = components['schemas']['MessageSentPayload'];

/** Broadcast of App\Events\ConversationUpdated — event `conversation.{update_type}`. */
export type ConversationUpdatedEvent = components['schemas']['ConversationUpdatedPayload'];

export interface DocumentStatusUpdatedEvent {
  id: number;
  name: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  chunk_count: number | null;
  error_message: string | null;
  updated_at: string | null;
}

export interface BotSettingsUpdatedEvent {
  bot_id: number;
  setting_type: 'qa_inspector' | 'general';
  qa_inspector_enabled: boolean;
  updated_at: string;
}

/**
 * Channel names for subscription
 */
export const CHANNELS = {
  // Note: echo.private() and echo.join() automatically add 'private-' and 'presence-' prefixes
  // So we only need the base channel name here
  bot: (id: number) => `bot.${id}`,
  botPresence: (id: number) => `bot.${id}.presence`,
  userNotifications: (id: number) => `user.${id}.notifications`,
  knowledgeBase: (id: number) => `knowledge-base.${id}`,
} as const;

/**
 * Event names for listening
 */
export const EVENTS = {
  messageSent: 'message.sent',
  conversationCreated: 'conversation.created',
  conversationUpdated: 'conversation.updated',
  conversationMessageReceived: 'conversation.message_received',
  conversationHandover: 'conversation.handover',
  conversationClosed: 'conversation.closed',
  notification: 'notification',
  documentStatusUpdated: 'document.status_updated',
  settingsUpdated: 'settings.updated',
} as const;
