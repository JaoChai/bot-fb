import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiGet, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, Bot, Document, KnowledgeBase, PaginatedResponse } from '@/types/api';

// Fetch all bots for the user
export function useBots() {
  return useQuery({
    queryKey: queryKeys.bots.lists(),
    queryFn: async () => {
      const response = await apiGet<PaginatedResponse<Bot>>('/bots');
      return response;
    },
  });
}

// Fetch knowledge base for a specific bot
export function useKnowledgeBase(botId: number | null) {
  return useQuery({
    queryKey: queryKeys.knowledgeBase.detail(botId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<KnowledgeBase>>(`/bots/${botId}/knowledge-base`);
      return response.data;
    },
    enabled: !!botId,
  });
}

// Fetch documents for a bot's knowledge base
export function useDocuments(botId: number | null) {
  return useQuery({
    queryKey: [...queryKeys.knowledgeBase.detail(botId ?? 0), 'documents'],
    queryFn: async () => {
      const response = await apiGet<PaginatedResponse<Document>>(`/bots/${botId}/knowledge-base/documents`);
      return response;
    },
    enabled: !!botId,
  });
}

// Upload document mutation
export function useUploadDocument(botId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData();
      formData.append('file', file);

      const response = await api.post<ApiResponse<Document>>(
        `/bots/${botId}/knowledge-base/documents`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
      );

      return response.data;
    },
    onSuccess: () => {
      // Invalidate both documents and knowledge base queries
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(botId),
      });
    },
  });
}

// Delete document mutation
export function useDeleteDocument(botId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (documentId: number) => {
      await apiDelete(`/bots/${botId}/knowledge-base/documents/${documentId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(botId),
      });
    },
  });
}

// Convenience hook combining all KB operations
export function useKnowledgeBaseOperations(botId: number | null) {
  const knowledgeBase = useKnowledgeBase(botId);
  const documents = useDocuments(botId);
  const uploadMutation = botId ? useUploadDocument(botId) : null;
  const deleteMutation = botId ? useDeleteDocument(botId) : null;

  return {
    // Data
    knowledgeBase: knowledgeBase.data,
    documents: documents.data?.data ?? [],

    // Loading states
    isLoading: knowledgeBase.isLoading || documents.isLoading,
    isUploading: uploadMutation?.isPending ?? false,
    isDeleting: deleteMutation?.isPending ?? false,

    // Errors
    error: knowledgeBase.error || documents.error,
    uploadError: uploadMutation?.error,
    deleteError: deleteMutation?.error,

    // Actions
    uploadDocument: uploadMutation?.mutateAsync,
    deleteDocument: deleteMutation?.mutateAsync,

    // Refetch
    refetch: () => {
      knowledgeBase.refetch();
      documents.refetch();
    },
  };
}
