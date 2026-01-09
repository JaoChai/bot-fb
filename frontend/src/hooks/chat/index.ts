/**
 * Chat hooks - extracted from useConversations.ts
 * These hooks can be used alongside the existing monolithic hook
 *
 * Phase 5 Optimizations (T039-T045):
 * - T039: useInfiniteMessages for cursor-based pagination
 * - T040: Optimistic updates in useSendMessage and useMarkAsRead
 * - T041: usePrefetchConversation for cache warming
 * - T042: useRealtime with useRef to prevent re-renders
 * - T043: useConnectionStatus for WebSocket status indicator
 */

// Messages (T039, T040)
export {
  useMessages,
  useInfiniteMessages,
  useSendMessage,
  messageKeys,
  flattenInfiniteMessages,
} from './useMessages';
export type { MessagesOptions, MessagesResponse } from './useMessages';

// Conversation List
export {
  useConversationList,
  useInfiniteConversationList,
  conversationKeys,
} from './useConversationList';
export type { ConversationsResponse } from './useConversationList';

// Conversation Details (T041)
export {
  useConversationDetails,
  useConversationStats,
  useUpdateConversation,
  useMarkAsRead,
  usePrefetchConversation,
  conversationDetailKeys,
} from './useConversationDetails';

// Notes (T034)
export { useNotes, useAddNote, useUpdateNote, useDeleteNote, notesKeys } from './useNotes';

// Tags (T035)
export { useBotTags, useAddTags, useRemoveTag, tagsKeys } from './useTags';

// Real-time (T042, T043)
export { useRealtime, useConnectionStatus } from './useRealtime';
