import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiDelete, apiPost, apiPut } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, Bot, Document, KnowledgeBase, PaginatedResponse, SearchResponse } from '@/types/api';

// Knowledge base list item type (for listing all KBs)
export interface KnowledgeBaseListItem {
  id: number;
  name: string;
  description: string | null;
  document_count: number;
  chunk_count: number;
  embedding_model: string;
  created_at: string;
  updated_at: string;
}

// Fetch all knowledge bases for the user
export function useAllKnowledgeBases() {
  return useQuery({
    queryKey: queryKeys.knowledgeBase.lists(),
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
    staleTime: 0,
    refetchOnWindowFocus: true,
  });
}

// Fetch a specific knowledge base
export function useKnowledgeBase(kbId: number | null) {
  return useQuery({
    queryKey: queryKeys.knowledgeBase.detail(kbId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<KnowledgeBase>>(`/knowledge-bases/${kbId}`);
      return response.data;
    },
    enabled: !!kbId,
  });
}

// Create knowledge base mutation
export interface CreateKnowledgeBaseData {
  name: string;
  description?: string;
}

export function useCreateKnowledgeBase() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateKnowledgeBaseData) => {
      const response = await apiPost<ApiResponse<KnowledgeBase>>('/knowledge-bases', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.lists(),
      });
    },
  });
}

// Update knowledge base mutation
export interface UpdateKnowledgeBaseData {
  name?: string;
  description?: string;
}

export function useUpdateKnowledgeBase(kbId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateKnowledgeBaseData) => {
      const response = await apiPut<ApiResponse<KnowledgeBase>>(`/knowledge-bases/${kbId}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(kbId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.lists(),
      });
    },
  });
}

// Delete knowledge base mutation
export function useDeleteKnowledgeBase() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (kbId: number) => {
      await apiDelete(`/knowledge-bases/${kbId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.lists(),
      });
    },
  });
}

// Fetch documents for a knowledge base
export function useDocuments(kbId: number | null) {
  return useQuery({
    queryKey: [...queryKeys.knowledgeBase.detail(kbId ?? 0), 'documents'],
    queryFn: async () => {
      const response = await apiGet<PaginatedResponse<Document>>(`/knowledge-bases/${kbId}/documents`);
      return response;
    },
    enabled: !!kbId,
    // Smart polling: refetch every 3 seconds if any document is processing
    refetchInterval: (query) => {
      const documents = query.state.data?.data ?? [];
      const hasProcessing = documents.some(
        (doc) => doc.status === 'pending' || doc.status === 'processing'
      );
      return hasProcessing ? 3000 : false;
    },
  });
}

// Create document mutation
export interface CreateDocumentData {
  title: string;
  content: string;
}

export function useCreateDocument(kbId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateDocumentData) => {
      if (!kbId) throw new Error('Knowledge Base ID is required');

      const response = await apiPost<ApiResponse<Document>>(
        `/knowledge-bases/${kbId}/documents`,
        data
      );

      return response;
    },
    onSuccess: () => {
      if (!kbId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(kbId),
      });
      queryClient.invalidateQueries({
        queryKey: [...queryKeys.knowledgeBase.detail(kbId), 'documents'],
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.lists(),
      });
    },
  });
}

// Delete document mutation
export function useDeleteDocument(kbId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (documentId: number) => {
      if (!kbId) throw new Error('Knowledge Base ID is required');
      await apiDelete(`/knowledge-bases/${kbId}/documents/${documentId}`);
    },
    onSuccess: () => {
      if (!kbId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.detail(kbId),
      });
      queryClient.invalidateQueries({
        queryKey: [...queryKeys.knowledgeBase.detail(kbId), 'documents'],
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.knowledgeBase.lists(),
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

export function useSemanticSearch(kbId: number) {
  return useMutation({
    mutationFn: async (params: SearchParams): Promise<SearchResponse> => {
      const response = await apiPost<SearchResponse>(
        `/knowledge-bases/${kbId}/search`,
        params
      );
      return response;
    },
  });
}

// Convenience hook combining all KB operations
export function useKnowledgeBaseOperations(kbId: number | null) {
  const knowledgeBase = useKnowledgeBase(kbId);
  const documents = useDocuments(kbId);
  const createMutation = useCreateDocument(kbId);
  const deleteMutation = useDeleteDocument(kbId);

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

    // Actions
    createDocument: kbId ? createMutation.mutateAsync : undefined,
    deleteDocument: kbId ? deleteMutation.mutateAsync : undefined,

    // Refetch
    refetch: () => {
      knowledgeBase.refetch();
      documents.refetch();
    },
  };
}
