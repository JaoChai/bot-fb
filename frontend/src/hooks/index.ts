export {
  useUser,
  useLogin,
  useRegister,
  useLogout,
  useAuth,
} from './useAuth';

export {
  useConversationChannel,
  useBotChannel,
  useNotifications,
  useEchoConnection,
  useBotPresence,
} from './useEcho';

export { useConnectionStatus } from './useConnectionStatus';

export {
  useBotSettings,
  useUpdateBotSettings,
  useBotSettingsOperations,
} from './useBotSettings';

export {
  useFlows,
  useFlow,
  useFlowTemplates,
  useCreateFlow,
  useUpdateFlow,
  useDeleteFlow,
  useDuplicateFlow,
  useSetDefaultFlow,
  useFlowOperations,
} from './useFlows';

export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
  useUpdateConversation,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
  // Notes/Memory hooks
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
  // Tags hooks
  useBotTags,
  useAddTags,
  useRemoveTag,
  useBulkAddTags,
  // HITL Agent hooks
  useSendAgentMessage,
} from './useConversations';

export {
  useUserSettings,
  useUserSettingsOperations,
} from './useUserSettings';

export {
  useConnection,
  useCreateConnection,
  useUpdateConnection,
  useDeleteConnection,
  useConnectionOperations,
} from './useConnections';

export {
  useImprovementSession,
  useImprovementSuggestions,
  useStartImprovement,
  useToggleSuggestion,
  useApplyImprovements,
  useCancelImprovement,
  useImprovementOperations,
  isSessionRunning,
  canCancelSession,
} from './useImprovement';

export {
  useDashboardSummary,
  hasActiveAlerts,
  getTotalAlertCount,
} from './useDashboard';

export {
  useMediaQuery,
  useIsMobile,
  useIsTablet,
  useIsDesktop,
} from './useMediaQuery';

export {
  useQuickReplies,
  useActiveQuickReplies,
  useQuickReplySearch,
  useQuickReply,
  useCreateQuickReply,
  useUpdateQuickReply,
  useDeleteQuickReply,
  useToggleQuickReply,
  useReorderQuickReplies,
  useQuickReplyOperations,
} from './useQuickReplies';
