import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiDelete, apiPost, apiPut } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import { useMutationWithToast } from './useMutationWithToast';
import type { ApiResponse } from '@/types/api';
import type {
  QuickReply,
  QuickReplyInput,
  QuickReplyListParams,
  ReorderQuickRepliesInput,
} from '@/types/quick-reply';

// Fetch all quick replies with optional filters
export function useQuickReplies(params?: QuickReplyListParams) {
  return useQuery({
    queryKey: queryKeys.quickReplies.list(params as Record<string, unknown> | undefined),
    queryFn: async () => {
      const searchParams = new URLSearchParams();
      if (params?.is_active !== undefined) {
        searchParams.set('is_active', String(params.is_active));
      }
      if (params?.category) {
        searchParams.set('category', params.category);
      }
      if (params?.search) {
        searchParams.set('search', params.search);
      }
      const queryString = searchParams.toString();
      const url = queryString ? `/quick-replies?${queryString}` : '/quick-replies';
      const response = await apiGet<{ data: QuickReply[] }>(url);
      return response.data;
    },
  });
}

// Fetch active quick replies only (for chat usage)
export function useActiveQuickReplies() {
  return useQuickReplies({ is_active: true });
}

// Search quick replies by shortcut prefix (for autocomplete)
export function useQuickReplySearch(query: string, enabled = true) {
  return useQuery({
    queryKey: queryKeys.quickReplies.search(query),
    queryFn: async () => {
      const response = await apiGet<{ data: QuickReply[] }>(
        `/quick-replies/search?q=${encodeURIComponent(query)}`
      );
      return response.data;
    },
    enabled: enabled && query.length > 0,
    staleTime: 30 * 1000, // 30 seconds
  });
}

// Fetch a specific quick reply
export function useQuickReply(id: number | null) {
  return useQuery({
    queryKey: queryKeys.quickReplies.detail(id ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QuickReply>>(`/quick-replies/${id}`);
      return response.data;
    },
    enabled: !!id,
  });
}

// Create quick reply mutation
export function useCreateQuickReply() {
  return useMutationWithToast({
    mutationFn: async (data: QuickReplyInput) => {
      const response = await apiPost<ApiResponse<QuickReply>>('/quick-replies', data);
      return response.data;
    },
    successMessage: (qr) => `สร้าง Quick Reply "/${qr.shortcut}" สำเร็จ`,
    invalidateKeys: [queryKeys.quickReplies.all],
  });
}

// Update quick reply mutation
export function useUpdateQuickReply(id: number) {
  return useMutationWithToast({
    mutationFn: async (data: Partial<QuickReplyInput>) => {
      const response = await apiPut<ApiResponse<QuickReply>>(`/quick-replies/${id}`, data);
      return response.data;
    },
    successMessage: 'บันทึกการเปลี่ยนแปลงสำเร็จ',
    invalidateKeys: [
      queryKeys.quickReplies.detail(id),
      queryKeys.quickReplies.lists(),
    ],
  });
}

// Delete quick reply mutation
export function useDeleteQuickReply() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => {
      await apiDelete(`/quick-replies/${id}`);
      return id;
    },
    onMutate: async (id: number) => {
      await queryClient.cancelQueries({
        queryKey: queryKeys.quickReplies.lists(),
      });

      const previousQuickReplies = queryClient.getQueryData<QuickReply[]>(
        queryKeys.quickReplies.lists()
      );

      if (previousQuickReplies) {
        queryClient.setQueryData<QuickReply[]>(
          queryKeys.quickReplies.lists(),
          previousQuickReplies.filter((qr) => qr.id !== id)
        );
      }

      queryClient.removeQueries({
        queryKey: queryKeys.quickReplies.detail(id),
      });

      return { previousQuickReplies };
    },
    onError: (_err, _id, context) => {
      if (context?.previousQuickReplies) {
        queryClient.setQueryData(
          queryKeys.quickReplies.lists(),
          context.previousQuickReplies
        );
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.quickReplies.all,
      });
    },
  });
}

// Toggle quick reply active status mutation
export function useToggleQuickReply() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: number) => {
      const response = await apiPost<ApiResponse<QuickReply>>(`/quick-replies/${id}/toggle`);
      return response.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(queryKeys.quickReplies.detail(data.id), data);
      queryClient.invalidateQueries({
        queryKey: queryKeys.quickReplies.lists(),
      });
    },
  });
}

// Reorder quick replies mutation
export function useReorderQuickReplies() {
  return useMutationWithToast({
    mutationFn: async (data: ReorderQuickRepliesInput) => {
      await apiPost('/quick-replies/reorder', data);
    },
    successMessage: 'จัดเรียงลำดับสำเร็จ',
    invalidateKeys: [queryKeys.quickReplies.all],
  });
}

// Convenience hook combining all Quick Reply operations
export function useQuickReplyOperations() {
  const quickReplies = useActiveQuickReplies();
  const createMutation = useCreateQuickReply();
  const deleteMutation = useDeleteQuickReply();
  const toggleMutation = useToggleQuickReply();
  const reorderMutation = useReorderQuickReplies();

  return {
    quickReplies: quickReplies.data ?? [],
    isLoading: quickReplies.isLoading,
    isCreating: createMutation.isPending,
    isDeleting: deleteMutation.isPending,
    isToggling: toggleMutation.isPending,
    isReordering: reorderMutation.isPending,
    error: quickReplies.error,
    createQuickReply: createMutation.mutateAsync,
    deleteQuickReply: deleteMutation.mutateAsync,
    toggleQuickReply: toggleMutation.mutateAsync,
    reorderQuickReplies: reorderMutation.mutateAsync,
    refetch: quickReplies.refetch,
  };
}
