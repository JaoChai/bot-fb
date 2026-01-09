/**
 * T034: useNotes hook for chat panel
 * Query and mutations for conversation notes with optimistic updates
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ConversationNote, CreateNoteData, UpdateNoteData } from '@/types/api';

// Query key factory
export const notesKeys = {
  all: ['conversation-notes'] as const,
  list: (botId: number, conversationId: number) =>
    [...notesKeys.all, botId, conversationId] as const,
};

// Response types
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
export function useNotes(botId: number | undefined, conversationId: number | undefined) {
  return useQuery({
    queryKey:
      botId && conversationId
        ? notesKeys.list(botId, conversationId)
        : ['conversation-notes', 'disabled'],
    queryFn: async () => {
      const response = await api.get<NotesResponse>(
        `/bots/${botId}/conversations/${conversationId}/notes`
      );
      return response.data.data;
    },
    enabled: !!botId && !!conversationId,
    staleTime: 30000, // 30 seconds
  });
}

/**
 * Hook to add a note with optimistic update
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
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });

      // Snapshot previous notes
      const previousNotes = queryClient.getQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId)
      );

      // Optimistically add the new note
      const optimisticNote: ConversationNote = {
        id: `temp-${Date.now()}`,
        content: data.content,
        type: data.type || 'note',
        created_by: 0, // Will be replaced by server
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };

      queryClient.setQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId),
        (old) => (old ? [optimisticNote, ...old] : [optimisticNote])
      );

      return { previousNotes };
    },
    onError: (_err, { conversationId }, context) => {
      // Rollback on error
      if (context?.previousNotes && botId) {
        queryClient.setQueryData(
          notesKeys.list(botId, conversationId),
          context.previousNotes
        );
      }
    },
    onSettled: (_, __, { conversationId }) => {
      if (!botId) return;
      // Refetch to sync with server
      queryClient.invalidateQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });
    },
  });
}

/**
 * Hook to update a note with optimistic update
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
    onMutate: async ({ conversationId, noteId, data }) => {
      if (!botId) return;

      await queryClient.cancelQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });

      const previousNotes = queryClient.getQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId)
      );

      // Optimistically update the note
      queryClient.setQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId),
        (old) =>
          old?.map((note) =>
            note.id === noteId
              ? { ...note, ...data, updated_at: new Date().toISOString() }
              : note
          )
      );

      return { previousNotes };
    },
    onError: (_err, { conversationId }, context) => {
      if (context?.previousNotes && botId) {
        queryClient.setQueryData(
          notesKeys.list(botId, conversationId),
          context.previousNotes
        );
      }
    },
    onSettled: (_, __, { conversationId }) => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });
    },
  });
}

/**
 * Hook to delete a note with optimistic update
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
    onMutate: async ({ conversationId, noteId }) => {
      if (!botId) return;

      await queryClient.cancelQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });

      const previousNotes = queryClient.getQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId)
      );

      // Optimistically remove the note
      queryClient.setQueryData<ConversationNote[]>(
        notesKeys.list(botId, conversationId),
        (old) => old?.filter((note) => note.id !== noteId)
      );

      return { previousNotes };
    },
    onError: (_err, { conversationId }, context) => {
      if (context?.previousNotes && botId) {
        queryClient.setQueryData(
          notesKeys.list(botId, conversationId),
          context.previousNotes
        );
      }
    },
    onSettled: (_, __, { conversationId }) => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: notesKeys.list(botId, conversationId),
      });
    },
  });
}
