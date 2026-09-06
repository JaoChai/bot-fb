/**
 * Chat hooks - extracted from useConversations.ts
 * These hooks can be used alongside the existing monolithic hook
 *
 * Phase 5 Optimizations (T039-T045):
 * - T039: useInfiniteMessages for cursor-based pagination
 * - T040: Optimistic updates in useMarkAsRead
 * - T042: useRealtime with useRef to prevent re-renders
 */

// Message Keys and Types
export { messageKeys } from './messageKeys';

// Pure cache helpers
export { type InfiniteMessages } from './infiniteMessageCache';

// Message Queries (T039)
export {
  useInfiniteMessages,
  flattenInfiniteMessages,
} from './useMessageQueries';

// Conversation List
export { useInfiniteConversationList } from './useConversationList';

// Conversation Details (T041)
export { useMarkAsRead } from './useConversationDetails';

// Notes (T034)
export { useNotes, useAddNote, useUpdateNote, useDeleteNote } from './useNotes';

// Tags (T035)
export { useBotTags, useAddTags, useRemoveTag } from './useTags';

// Conversation actions (moved from the legacy conversation hooks in Track 1)
export { useToggleHandover, useClearContext, useClearContextAll } from './useConversationActions';
export { useSendAgentMessage } from './useSendAgentMessage';

// Real-time (T042, T043)
export { useRealtime } from './useRealtime';
