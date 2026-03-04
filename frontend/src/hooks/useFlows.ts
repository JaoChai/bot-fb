import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import { useMutationWithToast } from './useMutationWithToast';
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
export function useFlowTemplates(enabled = true) {
  return useQuery({
    queryKey: queryKeys.flows.templates(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<FlowTemplate[]>>('/flow-templates');
      return response.data;
    },
    enabled,
  });
}

// Create flow mutation
export function useCreateFlow(botId: number | null) {
  return useMutationWithToast({
    mutationFn: async (data: CreateFlowData) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows`, data);
      return response.data;
    },
    successMessage: (flow) => `สร้าง Flow "${flow.name}" สำเร็จ`,
    invalidateKeys: botId ? [queryKeys.flows.list(botId)] : [],
  });
}

// Update flow mutation with optimistic update
export function useUpdateFlow(botId: number | null, flowId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateFlowData) => {
      if (!botId || !flowId) throw new Error('Bot ID and Flow ID are required');
      const response = await apiPut<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}`, data);
      return response.data;
    },
    onMutate: async (data) => {
      if (!botId || !flowId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.list(botId) });
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.detail(botId, flowId) });

      // Snapshot previous values
      const previousFlows = queryClient.getQueryData<PaginatedResponse<Flow>>(
        queryKeys.flows.list(botId)
      );
      const previousFlow = queryClient.getQueryData<Flow>(
        queryKeys.flows.detail(botId, flowId)
      );

      // Extract only safe fields to update (exclude knowledge_bases which has different type)
      const { knowledge_bases: _kb, ...safeData } = data; // eslint-disable-line @typescript-eslint/no-unused-vars
      const partialUpdate = safeData as Partial<Flow>;

      // Optimistically update detail cache
      queryClient.setQueryData<Flow | undefined>(
        queryKeys.flows.detail(botId, flowId),
        (oldData) => oldData ? { ...oldData, ...partialUpdate } : oldData
      );

      // Optimistically update list cache
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.map((flow) =>
              flow.id === flowId ? { ...flow, ...partialUpdate } : flow
            ),
          };
        }
      );

      return { previousFlows, previousFlow };
    },
    onError: (_err, _data, context) => {
      // Rollback on error
      if (!botId || !flowId) return;
      if (context?.previousFlows) {
        queryClient.setQueryData(queryKeys.flows.list(botId), context.previousFlows);
      }
      if (context?.previousFlow) {
        queryClient.setQueryData(queryKeys.flows.detail(botId, flowId), context.previousFlow);
      }
    },
    onSuccess: (updatedFlow) => {
      if (!botId || !flowId) return;

      // Update with actual server response
      queryClient.setQueryData(queryKeys.flows.detail(botId, flowId), updatedFlow);

      // Update flow in list cache with server data
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.map((flow) =>
              flow.id === flowId ? { ...flow, ...updatedFlow } : flow
            ),
          };
        }
      );
    },
    onSettled: () => {
      // Only invalidate if is_default might have changed (affects order)
      if (botId) {
        queryClient.invalidateQueries({
          queryKey: queryKeys.flows.list(botId),
        });
      }
    },
  });
}

// Delete flow mutation with optimistic update
export function useDeleteFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      await apiDelete(`/bots/${botId}/flows/${flowId}`);
      return flowId;
    },
    onMutate: async (flowId) => {
      if (!botId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.list(botId) });

      // Snapshot previous value
      const previousFlows = queryClient.getQueryData<PaginatedResponse<Flow>>(
        queryKeys.flows.list(botId)
      );

      // Optimistically remove flow from list
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.filter((flow) => flow.id !== flowId),
            meta: oldData.meta ? {
              ...oldData.meta,
              total: Math.max(0, (oldData.meta.total || 0) - 1),
            } : oldData.meta,
          };
        }
      );

      return { previousFlows };
    },
    onError: (_err, _flowId, context) => {
      // Rollback on error
      if (context?.previousFlows && botId) {
        queryClient.setQueryData(queryKeys.flows.list(botId), context.previousFlows);
      }
    },
    onSettled: (deletedFlowId) => {
      if (!botId) return;
      // Refetch to ensure server state
      queryClient.invalidateQueries({
        queryKey: queryKeys.flows.list(botId),
      });
      // Remove detail cache for the deleted flow
      if (deletedFlowId) {
        queryClient.removeQueries({
          queryKey: queryKeys.flows.detail(botId, deletedFlowId),
        });
      }
    },
  });
}

// Duplicate flow mutation
export function useDuplicateFlow(botId: number | null) {
  return useMutationWithToast({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}/duplicate`);
      return response.data;
    },
    successMessage: (flow) => `สร้างสำเนา Flow "${flow.name}" สำเร็จ`,
    invalidateKeys: botId ? [queryKeys.flows.list(botId)] : [],
  });
}

// Set default flow mutation with optimistic update
export function useSetDefaultFlow(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (flowId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}/set-default`);
      return response.data;
    },
    onMutate: async (flowId) => {
      if (!botId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.list(botId) });

      // Snapshot previous value
      const previousFlows = queryClient.getQueryData<PaginatedResponse<Flow>>(
        queryKeys.flows.list(botId)
      );

      // Optimistically update: set new default, unset others
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.map((flow) => ({
              ...flow,
              is_default: flow.id === flowId,
            })),
          };
        }
      );

      return { previousFlows };
    },
    onError: (_err, _flowId, context) => {
      // Rollback on error
      if (context?.previousFlows && botId) {
        queryClient.setQueryData(
          queryKeys.flows.list(botId),
          context.previousFlows
        );
      }
    },
    onSuccess: (updatedFlow, flowId) => {
      if (!botId) return;

      // Update cache with actual API response (works with localStorage persister)
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.map((flow) => ({
              ...flow,
              is_default: flow.id === flowId,
              // Merge with updated flow data if this is the one that was set as default
              ...(flow.id === flowId ? updatedFlow : {}),
            })),
          };
        }
      );

      // Update detail cache if exists
      queryClient.setQueryData(queryKeys.flows.detail(botId, flowId), updatedFlow);
    },
  });
}

// Test flow response interface
export interface FlowTestResponse {
  success: boolean;
  response?: string;
  model?: string;
  usage?: {
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
  };
  error?: string;
  error_code?: string;
}

// Test flow message interface
export interface FlowTestMessage {
  message: string;
  conversation_history?: Array<{
    role: 'user' | 'assistant';
    content: string;
  }>;
}

// Test flow mutation - sends message to AI and gets response
export function useTestFlow(botId: number | null, flowId: number | null) {
  return useMutation({
    mutationFn: async (data: FlowTestMessage): Promise<FlowTestResponse> => {
      if (!botId || !flowId) throw new Error('Bot ID and Flow ID are required');
      const response = await apiPost<FlowTestResponse>(`/bots/${botId}/flows/${flowId}/test`, data);
      return response;
    },
  });
}

// Convenience hook combining all flow operations
export function useFlowOperations(botId: number | null, options?: { includeTemplates?: boolean }) {
  const flows = useFlows(botId);
  const templates = useFlowTemplates(options?.includeTemplates ?? false);
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
    isFetching: flows.isFetching,
    isSuccess: flows.isSuccess,
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
