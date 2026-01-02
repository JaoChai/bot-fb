import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiPatch } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type {
  ApiResponse,
  ImprovementSession,
  ImprovementSuggestion,
} from '@/types/api';

// Fetch improvement session with details
export function useImprovementSession(
  botId: number | null,
  sessionId: number | null,
  options?: { polling?: boolean }
) {
  return useQuery({
    queryKey: queryKeys.improvements.detail(botId ?? 0, sessionId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<ImprovementSession>>(
        `/bots/${botId}/improvement-sessions/${sessionId}`
      );
      return response.data;
    },
    enabled: !!botId && !!sessionId,
    // Poll while session is in progress
    refetchInterval: options?.polling ? 3000 : false,
  });
}

// Fetch suggestions for a session
export function useImprovementSuggestions(
  botId: number | null,
  sessionId: number | null
) {
  return useQuery({
    queryKey: queryKeys.improvements.suggestions(botId ?? 0, sessionId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<ImprovementSuggestion[]>>(
        `/bots/${botId}/improvement-sessions/${sessionId}/suggestions`
      );
      return response.data;
    },
    enabled: !!botId && !!sessionId,
  });
}

// Start improvement session mutation
export function useStartImprovement(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (evaluationId: number) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<ImprovementSession>>(
        `/bots/${botId}/evaluations/${evaluationId}/improve`
      );
      return response.data;
    },
    onSuccess: () => {
      if (!botId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.list(botId),
      });
    },
  });
}

// Toggle suggestion selection mutation
export function useToggleSuggestion(botId: number | null, sessionId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      suggestionId,
      isSelected,
    }: {
      suggestionId: number;
      isSelected: boolean;
    }) => {
      if (!botId || !sessionId) throw new Error('Bot ID and Session ID are required');
      const response = await apiPatch<ApiResponse<ImprovementSuggestion>>(
        `/bots/${botId}/improvement-sessions/${sessionId}/suggestions/${suggestionId}`,
        { is_selected: isSelected }
      );
      return response.data;
    },
    onSuccess: () => {
      if (!botId || !sessionId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.suggestions(botId, sessionId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.detail(botId, sessionId),
      });
      // Also invalidate list which may have summary counts
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.list(botId),
      });
    },
  });
}

// Apply improvements mutation
export function useApplyImprovements(botId: number | null, sessionId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      if (!botId || !sessionId) throw new Error('Bot ID and Session ID are required');
      const response = await apiPost<ApiResponse<ImprovementSession>>(
        `/bots/${botId}/improvement-sessions/${sessionId}/apply`
      );
      return response.data;
    },
    onSuccess: () => {
      if (!botId || !sessionId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.detail(botId, sessionId),
      });
      // Also invalidate improvements list to reflect the applied state
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.list(botId),
      });
      // Also invalidate evaluations since a new one may be created
      queryClient.invalidateQueries({
        queryKey: queryKeys.evaluations.list(botId),
      });
    },
  });
}

// Cancel improvement session mutation
export function useCancelImprovement(botId: number | null, sessionId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      if (!botId || !sessionId) throw new Error('Bot ID and Session ID are required');
      const response = await apiPost<ApiResponse<ImprovementSession>>(
        `/bots/${botId}/improvement-sessions/${sessionId}/cancel`
      );
      return response.data;
    },
    onSuccess: () => {
      if (!botId || !sessionId) return;
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.detail(botId, sessionId),
      });
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.list(botId),
      });
      // Also invalidate suggestions which become invalid after cancel
      queryClient.invalidateQueries({
        queryKey: queryKeys.improvements.suggestions(botId, sessionId),
      });
    },
  });
}

// Helper to determine if session is in a running state
export function isSessionRunning(status: ImprovementSession['status']) {
  return ['analyzing', 'applying', 're_evaluating'].includes(status);
}

// Helper to determine if session can be cancelled
export function canCancelSession(status: ImprovementSession['status']) {
  return ['analyzing', 'suggestions_ready', 'applying', 're_evaluating'].includes(status);
}

// Convenience hook combining all improvement operations
export function useImprovementOperations(
  botId: number | null,
  sessionId: number | null
) {
  const session = useImprovementSession(botId, sessionId, {
    polling: sessionId !== null,
  });
  const suggestions = useImprovementSuggestions(botId, sessionId);
  const startMutation = useStartImprovement(botId);
  const toggleMutation = useToggleSuggestion(botId, sessionId);
  const applyMutation = useApplyImprovements(botId, sessionId);
  const cancelMutation = useCancelImprovement(botId, sessionId);

  // Stop polling when session is in a terminal state
  const shouldPoll = session.data
    ? isSessionRunning(session.data.status)
    : false;

  return {
    // Data
    session: session.data,
    suggestions: suggestions.data ?? [],
    selectedCount: (suggestions.data ?? []).filter((s) => s.is_selected).length,

    // Loading states
    isLoading: session.isLoading,
    isFetching: session.isFetching,
    isSuggestionsLoading: suggestions.isLoading,
    isStarting: startMutation.isPending,
    isToggling: toggleMutation.isPending,
    isApplying: applyMutation.isPending,
    isCancelling: cancelMutation.isPending,

    // Status helpers
    isRunning: session.data ? isSessionRunning(session.data.status) : false,
    canCancel: session.data ? canCancelSession(session.data.status) : false,
    shouldPoll,

    // Errors
    error: session.error,
    suggestionsError: suggestions.error,
    startError: startMutation.error,
    applyError: applyMutation.error,

    // Actions
    startImprovement: startMutation.mutateAsync,
    toggleSuggestion: toggleMutation.mutateAsync,
    applyImprovements: applyMutation.mutateAsync,
    cancelImprovement: cancelMutation.mutateAsync,

    // Refetch
    refetch: session.refetch,
    refetchSuggestions: suggestions.refetch,
  };
}
