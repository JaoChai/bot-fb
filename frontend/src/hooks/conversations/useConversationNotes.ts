import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import type {
  ConversationNote,
  CreateNoteData,
  UpdateNoteData,
} from '@/types/api';

interface NotesResponse { data: ConversationNote[] }
interface NoteResponse { data: ConversationNote }

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
