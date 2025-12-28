import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type {
  ApiResponse,
  Bot,
  CreateConnectionData,
  UpdateConnectionData,
} from '@/types/api';

// Fetch a single connection (bot)
export function useConnection(botId: number | null) {
  return useQuery({
    queryKey: queryKeys.bots.detail(botId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<Bot>>(`/bots/${botId}`);
      return response.data;
    },
    enabled: !!botId,
    refetchOnWindowFocus: true,
    staleTime: 30 * 1000, // 30 seconds
  });
}

// Create connection (bot) mutation
export function useCreateConnection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateConnectionData) => {
      const response = await apiPost<ApiResponse<Bot>>('/bots', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.lists(),
      });
    },
  });
}

// Update connection (bot) mutation
export function useUpdateConnection(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateConnectionData) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPut<ApiResponse<Bot>>(`/bots/${botId}`, data);
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.lists(),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.detail(botId),
      });
    },
  });
}

// Delete connection (bot) mutation
export function useDeleteConnection() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (botId: number) => {
      await apiDelete(`/bots/${botId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.lists(),
      });
    },
  });
}

// Convenience hook combining all connection operations
export function useConnectionOperations(botId: number | null) {
  const connection = useConnection(botId);
  const createMutation = useCreateConnection();
  const updateMutation = useUpdateConnection(botId);
  const deleteMutation = useDeleteConnection();

  return {
    // Data
    connection: connection.data,

    // Loading states
    isLoading: connection.isLoading,
    isCreating: createMutation.isPending,
    isUpdating: updateMutation.isPending,
    isDeleting: deleteMutation.isPending,

    // Errors
    error: connection.error,
    createError: createMutation.error,
    updateError: updateMutation.error,
    deleteError: deleteMutation.error,

    // Actions
    createConnection: createMutation.mutateAsync,
    updateConnection: botId ? updateMutation.mutateAsync : undefined,
    deleteConnection: deleteMutation.mutateAsync,

    // Refetch
    refetch: connection.refetch,
  };
}
