// Re-export shim. Each file added in subsequent tasks adds its exports here.
// Consumers should import from '@/hooks/useConversations' (re-export from
// this file lives there). Direct imports from '@/hooks/conversations' are
// also permitted.

export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
} from './useConversationQueries';

export {
  useUpdateConversation,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
} from './useConversationLifecycle';

export {
  useMarkAsRead,
  useClearContext,
  useClearContextAll,
} from './useConversationRead';

export {
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
} from './useConversationNotes';

export {
  useBotTags,
  useAddTags,
  useRemoveTag,
  useBulkAddTags,
} from './useConversationTags';

export { useSendAgentMessage } from './useSendAgentMessage';
