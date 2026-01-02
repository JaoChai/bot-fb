import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type {
  ApiResponse,
  CreateEvaluationData,
  Evaluation,
  EvaluationFilters,
  EvaluationPersona,
  EvaluationProgress,
  EvaluationReport,
  EvaluationTestCase,
  PaginatedResponse,
} from '@/types/api';

// Fetch all evaluations for a bot
export function useEvaluations(botId: number | null, filters?: EvaluationFilters) {
  return useQuery({
    queryKey: queryKeys.evaluations.list(botId ?? 0, filters as Record<string, unknown> | undefined),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.flow_id) params.append('flow_id', String(filters.flow_id));
      if (filters?.status) params.append('status', filters.status);
      if (filters?.per_page) params.append('per_page', String(filters.per_page));
      if (filters?.page) params.append('page', String(filters.page));

      const queryString = params.toString();
      const url = `/bots/${botId}/evaluations${queryString ? `?${queryString}` : ''}`;
      const response = await apiGet<PaginatedResponse<Evaluation>>(url);
      return response;
    },
    enabled: !!botId,
  });
}

// Fetch a single evaluation
export function useEvaluation(botId: number | null, evaluationId: number | null) {
  return useQuery({
    queryKey: queryKeys.evaluations.detail(botId ?? 0, evaluationId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<Evaluation>>(`/bots/${botId}/evaluations/${evaluationId}`);
      return response.data;
    },
    enabled: !!botId && !!evaluationId,
  });
}

// Fetch evaluation progress (for polling during running state)
export function useEvaluationProgress(botId: number | null, evaluationId: number | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.evaluations.progress(botId ?? 0, evaluationId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<EvaluationProgress>>(`/bots/${botId}/evaluations/${evaluationId}/progress`);
      return response.data;
    },
    enabled: !!botId && !!evaluationId && enabled,
    refetchInterval: 3000, // Poll every 3 seconds
  });
}

// Fetch test cases for an evaluation
export function useEvaluationTestCases(
  botId: number | null,
  evaluationId: number | null,
  filters?: { status?: string; persona_key?: string; per_page?: number; page?: number }
) {
  return useQuery({
    queryKey: queryKeys.evaluations.testCases(botId ?? 0, evaluationId ?? 0),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.status) params.append('status', filters.status);
      if (filters?.persona_key) params.append('persona_key', filters.persona_key);
      if (filters?.per_page) params.append('per_page', String(filters.per_page));
      if (filters?.page) params.append('page', String(filters.page));

      const queryString = params.toString();
      const url = `/bots/${botId}/evaluations/${evaluationId}/test-cases${queryString ? `?${queryString}` : ''}`;
      const response = await apiGet<PaginatedResponse<EvaluationTestCase>>(url);
      return response;
    },
    enabled: !!botId && !!evaluationId,
  });
}

// Fetch evaluation report
export function useEvaluationReport(botId: number | null, evaluationId: number | null) {
  return useQuery({
    queryKey: queryKeys.evaluations.report(botId ?? 0, evaluationId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<EvaluationReport>>(`/bots/${botId}/evaluations/${evaluationId}/report`);
      return response.data;
    },
    enabled: !!botId && !!evaluationId,
  });
}

// Fetch available personas
export function useEvaluationPersonas() {
  return useQuery({
    queryKey: queryKeys.evaluations.personas(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<EvaluationPersona[]>>('/evaluation-personas');
      return response.data;
    },
  });
}

// Create evaluation mutation
export function useCreateEvaluation(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateEvaluationData) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Evaluation>>(`/bots/${botId}/evaluations`, data);
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.list(botId),
      });
    },
  });
}

// Cancel evaluation mutation
export function useCancelEvaluation(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (evaluationId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Evaluation>>(`/bots/${botId}/evaluations/${evaluationId}/cancel`);
      return response.data;
    },
    onSuccess: (_, evaluationId) => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.detail(botId, evaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.list(botId),
      });
      // Also invalidate progress and testCases which are affected by cancellation
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.progress(botId, evaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.testCases(botId, evaluationId),
      });
    },
  });
}

// Retry evaluation mutation
export function useRetryEvaluation(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (evaluationId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Evaluation>>(`/bots/${botId}/evaluations/${evaluationId}/retry`);
      return response.data;
    },
    onSuccess: (_, evaluationId) => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.detail(botId, evaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.list(botId),
      });
      // Also invalidate progress and testCases which reset on retry
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.progress(botId, evaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.testCases(botId, evaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.report(botId, evaluationId),
      });
    },
  });
}

// Delete evaluation mutation
export function useDeleteEvaluation(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (evaluationId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      await apiDelete(`/bots/${botId}/evaluations/${evaluationId}`);
      return evaluationId; // Return evaluationId for onSuccess
    },
    onSuccess: (deletedEvaluationId) => {
      if (!botId) return;
      // Invalidate list
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.list(botId),
      });
      // Invalidate all related caches for the deleted evaluation
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.detail(botId, deletedEvaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.progress(botId, deletedEvaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.testCases(botId, deletedEvaluationId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.report(botId, deletedEvaluationId),
      });
    },
  });
}

// Convenience hook combining all evaluation operations
export function useEvaluationOperations(botId: number | null) {
  const evaluations = useEvaluations(botId);
  const personas = useEvaluationPersonas();
  const createMutation = useCreateEvaluation(botId);
  const cancelMutation = useCancelEvaluation(botId);
  const retryMutation = useRetryEvaluation(botId);
  const deleteMutation = useDeleteEvaluation(botId);

  return {
    // Data
    evaluations: evaluations.data?.data ?? [],
    personas: personas.data ?? [],
    pagination: evaluations.data?.meta,

    // Loading states
    isLoading: evaluations.isLoading,
    isFetching: evaluations.isFetching,
    isSuccess: evaluations.isSuccess,
    isPersonasLoading: personas.isLoading,
    isCreating: createMutation.isPending,
    isCancelling: cancelMutation.isPending,
    isRetrying: retryMutation.isPending,
    isDeleting: deleteMutation.isPending,

    // Errors
    error: evaluations.error,
    createError: createMutation.error,

    // Actions
    createEvaluation: botId ? createMutation.mutateAsync : undefined,
    cancelEvaluation: botId ? cancelMutation.mutateAsync : undefined,
    retryEvaluation: botId ? retryMutation.mutateAsync : undefined,
    deleteEvaluation: botId ? deleteMutation.mutateAsync : undefined,

    // Refetch
    refetch: evaluations.refetch,
  };
}
