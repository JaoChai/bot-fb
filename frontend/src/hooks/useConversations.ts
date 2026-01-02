import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  AddTagsData,
  BulkTagsData,
  Conversation,
  ConversationFilters,
  ConversationNote,
  ConversationStats,
  ConversationStatusCounts,
  CreateNoteData,
  Message,
  PaginationMeta,
  UpdateConversationData,
  UpdateNoteData,
} from '@/types/api';

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & {
    status_counts: ConversationStatusCounts;
  };
}

interface ConversationResponse {
  data: Conversation;
  message?: string;
}

interface MessagesResponse {
  data: Message[];
  meta: PaginationMeta;
}

interface StatsResponse {
  data: ConversationStats;
}

/**
 * Hook to fetch conversations for a bot with filters and pagination
 */
export function useConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  return useQuery({
    queryKey: ['conversations', botId, filters],
    queryFn: async () => {
      const params = new URLSearchParams();

      if (filters.status) {
        params.append('status', Array.isArray(filters.status) ? filters.status.join(',') : filters.status);
      }
      if (filters.channel_type) params.append('channel_type', filters.channel_type);
      if (filters.telegram_chat_type) {
        params.append('telegram_chat_type', Array.isArray(filters.telegram_chat_type) ? filters.telegram_chat_type.join(',') : filters.telegram_chat_type);
      }
      if (filters.is_handover !== undefined) params.append('is_handover', String(filters.is_handover));
      if (filters.assigned_user_id) params.append('assigned_user_id', String(filters.assigned_user_id));
      if (filters.tags?.length) params.append('tags', filters.tags.join(','));
      if (filters.search) params.append('search', filters.search);
      if (filters.from_date) params.append('from_date', filters.from_date);
      if (filters.to_date) params.append('to_date', filters.to_date);
      if (filters.sort_by) params.append('sort_by', filters.sort_by);
      if (filters.sort_direction) params.append('sort_direction', filters.sort_direction);
      if (filters.per_page) params.append('per_page', String(filters.per_page));
      if (filters.page) params.append('page', String(filters.page));

      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    // WebSocket (Echo) handles real-time updates, polling disabled for performance
    staleTime: 30000,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  });
}

/**
 * Hook to fetch conversations with infinite scroll pagination
 */
export function useInfiniteConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  return useInfiniteQuery({
    queryKey: ['conversations-infinite', botId, filters],
    queryFn: async ({ pageParam = 1 }) => {
      const params = new URLSearchParams();

      if (filters.status) {
        params.append('status', Array.isArray(filters.status) ? filters.status.join(',') : filters.status);
      }
      if (filters.channel_type) params.append('channel_type', filters.channel_type);
      if (filters.telegram_chat_type) {
        params.append('telegram_chat_type', Array.isArray(filters.telegram_chat_type) ? filters.telegram_chat_type.join(',') : filters.telegram_chat_type);
      }
      if (filters.is_handover !== undefined) params.append('is_handover', String(filters.is_handover));
      if (filters.assigned_user_id) params.append('assigned_user_id', String(filters.assigned_user_id));
      if (filters.tags?.length) params.append('tags', filters.tags.join(','));
      if (filters.search) params.append('search', filters.search);
      if (filters.from_date) params.append('from_date', filters.from_date);
      if (filters.to_date) params.append('to_date', filters.to_date);
      if (filters.sort_by) params.append('sort_by', filters.sort_by);
      if (filters.sort_direction) params.append('sort_direction', filters.sort_direction);

      params.append('per_page', String(filters.per_page || 30));
      params.append('page', String(pageParam));

      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const { current_page, last_page } = lastPage.meta;
      return current_page < last_page ? current_page + 1 : undefined;
    },
    enabled: !!botId,
    // WebSocket (Echo) handles real-time updates, polling disabled for performance
    staleTime: 30000,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  });
}

/**
 * Hook to fetch a single conversation with messages
 */
export function useConversation(botId: number | undefined, conversationId: number | undefined, messagesLimit?: number) {
  return useQuery({
    queryKey: ['conversation', botId, conversationId],
    queryFn: async () => {
      const params = messagesLimit ? `?messages_limit=${messagesLimit}` : '';
      const response = await api.get<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}${params}`
      );
      return response.data.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 10000, // 10 seconds
  });
}

/**
 * Hook to fetch messages for a conversation with pagination
 */
export function useConversationMessages(
  botId: number | undefined,
  conversationId: number | undefined,
  options: { page?: number; perPage?: number; order?: 'asc' | 'desc' } = {}
) {
  return useQuery({
    queryKey: ['conversation-messages', botId, conversationId, options],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (options.page) params.append('page', String(options.page));
      if (options.perPage) params.append('per_page', String(options.perPage));
      if (options.order) params.append('order', options.order);

      const response = await api.get<MessagesResponse>(
        `/bots/${botId}/conversations/${conversationId}/messages?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId && !!conversationId,
    // WebSocket (Echo) handles real-time updates, polling disabled for performance
    staleTime: 30000,
    refetchInterval: false,
    refetchOnWindowFocus: false,
  });
}

/**
 * Hook to fetch conversation statistics for a bot
 */
export function useConversationStats(botId: number | undefined) {
  return useQuery({
    queryKey: ['conversation-stats', botId],
    queryFn: async () => {
      const response = await api.get<StatsResponse>(`/bots/${botId}/conversations/stats`);
      return response.data.data;
    },
    enabled: !!botId,
    staleTime: 60000, // 1 minute
  });
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
      autoEnableMinutes = 30,
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
    onSuccess: (_, { conversationId }) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

/**
 * Hook to mark conversation as read
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
    onSuccess: (_, conversationId) => {
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      // Also invalidate stats which tracks unread counts
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

  return useMutation({
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

  return useMutation({
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
        data
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      // Cancel any outgoing refetches to avoid overwriting optimistic update
      await queryClient.cancelQueries({
        queryKey: ['conversation-messages', botId, conversationId],
      });

      // Snapshot previous messages
      const previousMessages = queryClient.getQueryData<MessagesResponse>([
        'conversation-messages',
        botId,
        conversationId,
        { order: 'asc', perPage: 100 },
      ]);

      // Optimistically add the new message
      if (previousMessages) {
        const optimisticMessage: Message = {
          id: Date.now(), // Temporary ID
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
          ['conversation-messages', botId, conversationId, { order: 'asc', perPage: 100 }],
          {
            ...previousMessages,
            data: [...previousMessages.data, optimisticMessage],
          }
        );
      }

      return { previousMessages };
    },
    onError: (_err, { conversationId }, context) => {
      // Rollback on error
      if (context?.previousMessages) {
        queryClient.setQueryData(
          ['conversation-messages', botId, conversationId, { order: 'asc', perPage: 100 }],
          context.previousMessages
        );
      }
    },
    onSettled: (_, __, { conversationId }) => {
      // Always refetch after mutation to sync with server
      queryClient.invalidateQueries({ queryKey: ['conversation', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversation-messages', botId, conversationId] });
      queryClient.invalidateQueries({ queryKey: ['conversations', botId] });
      queryClient.invalidateQueries({ queryKey: ['conversations-infinite', botId] });
    },
  });
}
