/**
 * Real-time event types for Laravel Echo / Reverb
 */

export interface MessageSentEvent {
  id: number;
  conversation_id: number;
  sender: 'user' | 'bot' | 'agent';
  content: string;
  type: 'text' | 'image' | 'video' | 'audio' | 'file' | 'sticker' | 'location';
  media_url: string | null;
  media_type: string | null;
  created_at: string;
}

export interface ConversationUpdatedEvent {
  id: number;
  bot_id: number;
  status: 'active' | 'closed' | 'pending';
  is_handover: boolean;
  assigned_user_id: number | null;
  message_count: number;
  last_message_at: string | null;
  update_type: 'created' | 'updated' | 'message_received' | 'handover' | 'closed';
  updated_at: string;
}

export interface AdminNotificationEvent {
  type: 'handover_request' | 'new_conversation' | 'system' | 'info' | 'warning' | 'error';
  title: string;
  message: string;
  data: Record<string, unknown>;
  timestamp: string;
}

/**
 * Channel names for subscription
 */
export const CHANNELS = {
  conversation: (id: number) => `private-conversation.${id}`,
  bot: (id: number) => `private-bot.${id}`,
  botPresence: (id: number) => `presence-bot.${id}.presence`,
  userNotifications: (id: number) => `private-user.${id}.notifications`,
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
} as const;
