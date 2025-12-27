import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiDelete, apiPost } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, Bot, Document, KnowledgeBase, PaginatedResponse, SearchResponse } from '@/types/api';

// Knowledge base list item type
export interface KnowledgeBaseListItem {
  id: number;
  name: string;
  description: string | null;
  bot_id: number;
  bot_name: string | null;
  document_count: number;
  chunk_count: number;
}

// Fetch all knowledge bases for the user
export function useAllKnowledgeBases() {
  return useQuery({
    queryKey: ['knowledge-bases'],
    queryFn: async () => {
      const response = await apiGet<{ data: KnowledgeBaseListItem[] }>('/knowledge-bases');
      return response.data;
    },
  });
}

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

// Create document mutation
export interface CreateDocumentData {
  title: string;
  content: string;
}

export function useCreateDocument(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateDocumentData) => {
      if (!botId) throw new Error('Bot ID is required');

      const response = await apiPost<ApiResponse<Document>>(
        `/bots/${botId}/knowledge-base/documents`,
        data
      );

      return response;
    },
    onSuccess: () => {
      if (!botId) return;
      // Invalidate both documents and knowledge base queries
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(botId),
      });
    },
  });
}

// Delete document mutation
export function useDeleteDocument(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (documentId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      await apiDelete(`/bots/${botId}/knowledge-base/documents/${documentId}`);
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(botId),
      });
    },
  });
}

// Semantic search mutation
export interface SearchParams {
  query: string;
  limit?: number;
  threshold?: number;
}

export function useSemanticSearch(botId: number) {
  return useMutation({
    mutationFn: async (params: SearchParams): Promise<SearchResponse> => {
      const response = await apiPost<SearchResponse>(
        `/bots/${botId}/knowledge-base/search`,
        params
      );
      return response;
    },
  });
}

// Convenience hook combining all KB operations
export function useKnowledgeBaseOperations(botId: number | null) {
  const knowledgeBase = useKnowledgeBase(botId);
  const documents = useDocuments(botId);
  // Always call hooks unconditionally to respect React rules of hooks
  const createMutation = useCreateDocument(botId);
  const deleteMutation = useDeleteDocument(botId);

  return {
    // Data
    knowledgeBase: knowledgeBase.data,
    documents: documents.data?.data ?? [],

    // Loading states
    isLoading: knowledgeBase.isLoading || documents.isLoading,
    isSubmitting: createMutation.isPending,
    isDeleting: deleteMutation.isPending,

    // Errors
    error: knowledgeBase.error || documents.error,
    createError: createMutation.error,
    deleteError: deleteMutation.error,

    // Actions - only expose when botId is valid
    createDocument: botId ? createMutation.mutateAsync : undefined,
    deleteDocument: botId ? deleteMutation.mutateAsync : undefined,

    // Refetch
    refetch: () => {
      knowledgeBase.refetch();
      documents.refetch();
    },
  };
}
