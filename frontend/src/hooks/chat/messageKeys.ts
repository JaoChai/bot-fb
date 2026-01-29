/**
 * Message query keys and types
 *
 * Extracted from useMessages.ts for better organization.
 * Contains query key factory pattern and shared types.
 */
import type { Message, PaginationMeta } from '@/types/api';

// Fallback polling interval when WebSocket is disconnected (5 seconds for faster recovery)
export const FALLBACK_POLLING_INTERVAL = 5000;

// Heartbeat refresh interval when connected (30 seconds)
// This ensures data stays fresh even if WebSocket events are missed
export const HEARTBEAT_INTERVAL = 30000;

// Default page size for messages
export const DEFAULT_PAGE_SIZE = 50;

/**
 * Query key factory for messages
 *
 * Ensures consistent query keys across all message-related hooks.
 * Used by WebSocket updates to invalidate correct caches.
 */
export const messageKeys = {
  all: ['messages'] as const,
  list: (botId: number, conversationId: number) =>
    [...messageKeys.all, 'list', botId, conversationId] as const,
  listWithOptions: (
    botId: number,
    conversationId: number,
    options: MessagesOptions
  ) => [...messageKeys.list(botId, conversationId), options] as const,
  infinite: (botId: number, conversationId: number) =>
    [...messageKeys.all, 'infinite', botId, conversationId] as const,
};

// Types
export interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

export interface MessagesOptions {
  page?: number;
  perPage?: number;
  order?: 'asc' | 'desc';
}

export interface SendMessageData {
  content: string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'file';
  media_url?: string;
}

export interface AgentMessageResponse {
  message: string;
  data: Message;
  delivery_error?: string | null;
}
