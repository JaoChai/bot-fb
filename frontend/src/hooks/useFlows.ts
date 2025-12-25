import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type {
  ApiResponse,
  CreateFlowData,
  Flow,
  FlowTemplate,
  PaginatedResponse,
  UpdateFlowData,
} from '@/types/api';

// Fetch all flows for a bot
export function useFlows(botId: number | null) {
  return useQuery({
    queryKey: queryKeys.flows.list(botId ?? 0),
    queryFn: async () => {
      const response = await apiGet<PaginatedResponse<Flow>>(`/bots/${botId}/flows`);
      return response;
    },
    enabled: !!botId,
  });
}

// Fetch a single flow
export function useFlow(botId: number | null, flowId: number | null) {
  return useQuery({
    queryKey: queryKeys.flows.detail(botId ?? 0, flowId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}`);
      return response.data;
    },
    enabled: !!botId && !!flowId,
  });
}

// Fetch flow templates
export function useFlowTemplates() {
  return useQuery({
    queryKey: queryKeys.flows.templates(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<FlowTemplate[]>>('/flow-templates');
      return response.data;
    },
  });
}

// Create flow mutation
export function useCreateFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateFlowData) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows`, data);
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
    },
  });
}

// Update flow mutation
export function useUpdateFlow(botId: number | null, flowId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateFlowData) => {
      if (!botId || !flowId) throw new Error('Bot ID and Flow ID are required');
      const response = await apiPut<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}`, data);
      return response.data;
    },
    onSuccess: () => {
      if (!botId || !flowId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.detail(botId, flowId),
      });
    },
  });
}

// Delete flow mutation
export function useDeleteFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      await apiDelete(`/bots/${botId}/flows/${flowId}`);
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
    },
  });
}

// Duplicate flow mutation
export function useDuplicateFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}/duplicate`);
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
    },
  });
}

// Set default flow mutation
export function useSetDefaultFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}/set-default`);
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
    },
  });
}

// Convenience hook combining all flow operations
export function useFlowOperations(botId: number | null) {
  const flows = useFlows(botId);
  const templates = useFlowTemplates();
  const createMutation = useCreateFlow(botId);
  const deleteMutation = useDeleteFlow(botId);
  const duplicateMutation = useDuplicateFlow(botId);
  const setDefaultMutation = useSetDefaultFlow(botId);

  return {
    // Data
    flows: flows.data?.data ?? [],
    templates: templates.data ?? [],

    // Loading states
    isLoading: flows.isLoading,
    isTemplatesLoading: templates.isLoading,
    isCreating: createMutation.isPending,
    isDeleting: deleteMutation.isPending,
    isDuplicating: duplicateMutation.isPending,
    isSettingDefault: setDefaultMutation.isPending,

    // Errors
    error: flows.error,
    createError: createMutation.error,
    deleteError: deleteMutation.error,

    // Actions
    createFlow: botId ? createMutation.mutateAsync : undefined,
    deleteFlow: botId ? deleteMutation.mutateAsync : undefined,
    duplicateFlow: botId ? duplicateMutation.mutateAsync : undefined,
    setDefaultFlow: botId ? setDefaultMutation.mutateAsync : undefined,

    // Refetch
    refetch: flows.refetch,
  };
}
