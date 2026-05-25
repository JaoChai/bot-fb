import { useMutation, useQuery, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { messageKeys, isInfiniteConversationsQuery, type MessagesOptions } from '@/hooks/chat';
import { useMutationWithToast } from './useMutationWithToast';
import type {
  AddTagsData,
  BulkTagsData,
  Conversation,
  ConversationNote,
  ConversationStatusCounts,
  CreateNoteData,
  Message,
  PaginationMeta,
  UpdateConversationData,
  UpdateNoteData,
} from '@/types/api';

// Re-exports during the Sprint 3 split. Direct imports from these domain
// files are also supported via @/hooks/conversations.
export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
} from './conversations';

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

interface ConversationResponse {
  data: Conversation;
  message?: string;
}

interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

/**
 * Hook to update a conversation
 */
export function useUpdateConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: UpdateConversationData;
    }) => {
      const response = await api.put<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}`,
        data
      );
      return response.data;
    },
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to close a conversation
 */
export function useCloseConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/close`
      );
      return response.data;
    },
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to reopen a conversation
 */
export function useReopenConversation(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/reopen`
      );
      return response.data;
    },
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to toggle handover mode with auto-enable timer
 */
export function useToggleHandover(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      unassign = false,
      autoEnableMinutes = 0,
    }: {
      conversationId: number;
      unassign?: boolean;
      autoEnableMinutes?: number;
    }) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/toggle-handover`,
        { unassign, auto_enable_minutes: autoEnableMinutes }
      );
      return response.data;
    },
    onSuccess: (result, { conversationId }) => {
      const updatedConversation = result.data;

      // Immediately update cache with response data (fixes UI not updating)
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { queryKey: ['conversations-infinite', botId] },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, ...updatedConversation } : conv
              ),
            })),
          };
        }
      );

      // Also update single conversation query if it exists
      queryClient.setQueryData<ConversationResponse>(
        ['conversation', botId, conversationId],
        (old) => old ? { ...old, data: { ...old.data, ...updatedConversation } } : old
      );

      // Invalidate stats (status changed)
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to mark conversation as read
 * Uses optimistic update for instant UI feedback (LINE OA style)
 */
export function useMarkAsRead(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/mark-as-read`
      );
      return response.data;
    },
    onMutate: async (conversationId) => {
      // Cancel outgoing refetches to prevent overwriting optimistic update
      await queryClient.cancelQueries({ queryKey: ['conversations-infinite', botId] });

      // Snapshot for rollback
      const previousData = queryClient.getQueriesData<InfiniteData<ConversationsResponse>>({
        predicate: isInfiniteConversationsQuery(botId!),
      });

      // Optimistically set unread_count = 0 (instant UI update)
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, unread_count: 0 }
                  : conv
              ),
            })),
          };
        }
      );

      return { previousData };
    },
    onError: (_err, _conversationId, context) => {
      // Rollback on error
      if (context?.previousData) {
        context.previousData.forEach(([queryKey, data]) => {
          if (data) {
            queryClient.setQueryData(queryKey, data);
          }
        });
      }
    },
    onSuccess: (_data, conversationId) => {
      // Re-apply cache update to fix race condition
      // If a refetch happened during mutation, it may have overwritten optimistic update
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? { ...conv, unread_count: 0 }
                  : conv
              ),
            })),
          };
        }
      );
    },
    onSettled: () => {
      // Invalidate stats (lightweight query for sidebar counts)
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to clear bot context for a conversation
 * Bot will not reference messages before the cleared timestamp
 */
export function useClearContext(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/clear-context`
      );
      return response.data;
    },
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      // Also invalidate stats to reflect context clear
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

interface ClearContextAllResponse {
  data: { updated_count: number };
  message: string;
}

/**
 * Hook to clear bot context for ALL active/handover conversations
 * Bot will start fresh with all open conversations
 */
export function useClearContextAll(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await api.post<ClearContextAllResponse>(
        `/bots/${botId}/conversations/clear-context-all`
      );
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

// =====================
// Notes/Memory Hooks
// =====================

interface NotesResponse {
  data: ConversationNote[];
}

interface NoteResponse {
  data: ConversationNote;
  message: string;
}

/**
 * Hook to fetch notes for a conversation
 */
export function useConversationNotes(botId: number | undefined, conversationId: number | undefined) {
  return useQuery({
    queryKey: ['conversation-notes', botId, conversationId],
    queryFn: async () => {
      const response = await api.get<NotesResponse>(
        `/bots/${botId}/conversations/${conversationId}/notes`
      );
      return response.data.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 30000,
  });
}

/**
 * Hook to add a note to a conversation
 */
export function useAddNote(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutationWithToast({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: CreateNoteData;
    }) => {
      const response = await api.post<NoteResponse>(
        `/bots/${botId}/conversations/${conversationId}/notes`,
        data
      );
      return response.data;
    },
    successMessage: 'บันทึก Note สำเร็จ',
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversation-notes', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
    },
  });
}

/**
 * Hook to update a note in a conversation
 */
export function useUpdateNote(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutationWithToast({
    mutationFn: async ({
      conversationId,
      noteId,
      data,
    }: {
      conversationId: number;
      noteId: string;
      data: UpdateNoteData;
    }) => {
      const response = await api.put<NoteResponse>(
        `/bots/${botId}/conversations/${conversationId}/notes/${noteId}`,
        data
      );
      return response.data;
    },
    successMessage: 'แก้ไข Note สำเร็จ',
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversation-notes', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
    },
  });
}

/**
 * Hook to delete a note from a conversation
 */
export function useDeleteNote(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      noteId,
    }: {
      conversationId: number;
      noteId: string;
    }) => {
      await api.delete(`/bots/${botId}/conversations/${conversationId}/notes/${noteId}`);
    },
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversation-notes', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
    },
  });
}

// =====================
// Tags Hooks
// =====================

interface TagsResponse {
  data: string[];
}

interface TagOperationResponse {
  data: { tags: string[] };
  message: string;
}

interface BulkTagsResponse {
  data: { updated_count: number };
  message: string;
}

/**
 * Hook to fetch all unique tags used in bot conversations
 */
export function useBotTags(botId: number | undefined) {
  return useQuery({
    queryKey: ['bot-tags', botId],
    queryFn: async () => {
      const response = await api.get<TagsResponse>(`/bots/${botId}/conversations/tags`);
      return response.data.data;
    },
    enabled: !!botId,
    staleTime: 60000, // 1 minute
  });
}

/**
 * Hook to add tags to a conversation
 */
export function useAddTags(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: AddTagsData;
    }) => {
      const response = await api.post<TagOperationResponse>(
        `/bots/${botId}/conversations/${conversationId}/tags`,
        data
      );
      return response.data;
    },
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['bot-tags', botId] });
    },
  });
}

/**
 * Hook to remove a tag from a conversation
 */
export function useRemoveTag(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      tag,
    }: {
      conversationId: number;
      tag: string;
    }) => {
      const response = await api.delete<TagOperationResponse>(
        `/bots/${botId}/conversations/${conversationId}/tags/${encodeURIComponent(tag)}`
      );
      return response.data;
    },
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['bot-tags', botId] });
    },
  });
}

/**
 * Hook to bulk add tags to multiple conversations
 */
export function useBulkAddTags(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: BulkTagsData) => {
      const response = await api.post<BulkTagsResponse>(
        `/bots/${botId}/conversations/bulk-tags`,
        data
      );
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['bot-tags', botId] });
    },
  });
}

// =====================
// HITL Agent Message Hooks
// =====================

interface SendAgentMessageData {
  content: string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'file';
  media_url?: string;
}

interface AgentMessageResponse {
  message: string;
  data: Message;
  delivery_error?: string | null;
}

/**
 * Hook to send a message from agent to customer (HITL mode)
 * Includes optimistic updates for instant UI feedback
 */
export function useSendAgentMessage(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: SendAgentMessageData;
    }) => {
      const response = await api.post<AgentMessageResponse>(
        `/bots/${botId}/conversations/${conversationId}/agent-message`,
        data,
        { headers: { 'Idempotency-Key': crypto.randomUUID() } }
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      const messageOptions: MessagesOptions = { order: 'asc', perPage: 100 };

      // Cancel any outgoing refetches to avoid overwriting optimistic update
      await queryClient.cancelQueries({
        queryKey: messageKeys.listWithOptions(botId, conversationId, messageOptions),
      });

      // Snapshot previous messages
      const previousMessages = queryClient.getQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions)
      );

      // Use negative timestamp to guarantee no collision with DB IDs (always positive)
      const optimisticId = -Date.now();

      // Optimistically add the new message
      if (previousMessages) {
        const optimisticMessage: Message = {
          id: optimisticId, // Use the generated ID
          conversation_id: conversationId,
          sender: 'agent',
          content: data.content,
          type: data.type || 'text',
          media_url: data.media_url || null,
          media_type: null,
          media_metadata: null,
          model_used: null,
          prompt_tokens: null,
          completion_tokens: null,
          cost: null,
          external_message_id: null,
          reply_to_message_id: null,
          sentiment: null,
          intents: null,
          created_at: new Date().toISOString(),
          updated_at: new Date().toISOString(),
        };

        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, messageOptions),
          {
            ...previousMessages,
            data: [...previousMessages.data, optimisticMessage],
          }
        );
      }

      return { previousMessages, optimisticId, messageOptions };
    },
    onError: (_err, { conversationId }, context) => {
      if (!botId) return;

      // Rollback: remove only the failed optimistic message
      if (context?.optimisticId && context?.messageOptions) {
        queryClient.setQueryData<MessagesResponse>(
          messageKeys.listWithOptions(botId, conversationId, context.messageOptions),
          (old) => {
            if (!old) return old;
            return {
              ...old,
              data: old.data.filter((m) => m.id !== context.optimisticId),
            };
          }
        );
      }
    },
    onSuccess: (response, { conversationId }, context) => {
      if (!botId) return;

      const messageOptions: MessagesOptions = context?.messageOptions ?? { order: 'asc', perPage: 100 };
      const optimisticId = context?.optimisticId;

      // Replace only the specific optimistic message with real message from server
      // This avoids invalidateQueries which causes race conditions with WebSocket updates
      queryClient.setQueryData<MessagesResponse>(
        messageKeys.listWithOptions(botId, conversationId, messageOptions),
        (old) => {
          if (!old) return old;

          // CRITICAL: Check if real message already exists first (from WebSocket)
          // This prevents duplicates when WebSocket arrives before API response
          const realMessageExists = old.data.some((m) => m.id === response.data.id);
          const hasOptimistic = optimisticId && old.data.some((m) => m.id === optimisticId);

          if (realMessageExists && hasOptimistic) {
            // WebSocket came first - just remove the optimistic message
            return {
              ...old,
              data: old.data.filter((m) => m.id !== optimisticId),
            };
          }

          if (realMessageExists) {
            // Real message exists, no optimistic - nothing to do
            return old;
          }

          if (hasOptimistic) {
            // Normal case: replace optimistic with real
            return {
              ...old,
              data: old.data.map((m) =>
                m.id === optimisticId ? response.data : m
              ),
            };
          }

          // No optimistic, no real message - add it
          return {
            ...old,
            data: [...old.data, response.data],
          };
        }
      );

      // WebSocket uses toOthers() and doesn't echo back to the sender, so we must
      // patch needs_response = false locally for the agent who just replied.
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? {
                      ...conv,
                      needs_response: false, // Agent replied = no longer needs response
                      last_message_at: new Date().toISOString(),
                      message_count: conv.message_count + 1,
                    }
                  : conv
              ),
            })),
          };
        }
      );

      // Note: We intentionally do NOT invalidate queries here to prevent race conditions
      // WebSocket handles real-time updates, and refetch happens on reconnect
    },
  });
}
