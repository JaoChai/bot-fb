import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPut, apiPost } from '@/lib/api';
import { useBotChannel } from '@/hooks/useEcho';
import type {
  QAInspectorSettings,
  UpdateQAInspectorSettingsData,
  QAEvaluationLog,
  QAWeeklyReport,
  QAInspectorDashboard,
  QAEvaluationLogFilters,
  QAWeeklyReportFilters,
  QAStatsData,
  ApplySuggestionResponse,
  ApplySuggestionConflict,
} from '@/types/qa-inspector';
import type { ApiResponse, PaginatedResponse } from '@/types/api';
import type { BotSettingsUpdatedEvent } from '@/types/realtime';

// Query keys for QA Inspector
export const qaInspectorKeys = {
  all: ['qa-inspector'] as const,
  settings: (botId: number) => [...qaInspectorKeys.all, 'settings', botId] as const,
  logs: (botId: number, filters?: QAEvaluationLogFilters) =>
    [...qaInspectorKeys.all, 'logs', botId, filters] as const,
  log: (botId: number, logId: number) =>
    [...qaInspectorKeys.all, 'log', botId, logId] as const,
  stats: (botId: number, period?: string) =>
    [...qaInspectorKeys.all, 'stats', botId, period] as const,
  reports: (botId: number, filters?: QAWeeklyReportFilters) =>
    [...qaInspectorKeys.all, 'reports', botId, filters] as const,
  report: (botId: number, reportId: number) =>
    [...qaInspectorKeys.all, 'report', botId, reportId] as const,
  dashboard: (botId: number) =>
    [...qaInspectorKeys.all, 'dashboard', botId] as const,
  applySuggestion: (botId: number, reportId: number, suggestionIndex: number) =>
    [...qaInspectorKeys.all, 'apply-suggestion', botId, reportId, suggestionIndex] as const,
};

/**
 * Hook to fetch QA Inspector settings for a bot
 */
export function useQAInspectorSettings(botId: number) {
  return useQuery({
    queryKey: qaInspectorKeys.settings(botId),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QAInspectorSettings>>(
        `/bots/${botId}/qa-inspector/settings`
      );
      return response.data;
    },
    enabled: !!botId,
  });
}

/**
 * Hook to update QA Inspector settings
 */
export function useUpdateQAInspectorSettings(botId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateQAInspectorSettingsData) => {
      const response = await apiPut<ApiResponse<QAInspectorSettings>>(
        `/bots/${botId}/qa-inspector/settings`,
        data
      );
      return response.data;
    },
    onSuccess: (data) => {
      // Update cache with new settings
      queryClient.setQueryData(qaInspectorKeys.settings(botId), data);
      // Invalidate dashboard to reflect changes
      queryClient.invalidateQueries({ queryKey: qaInspectorKeys.dashboard(botId) });
    },
  });
}

/**
 * Hook to toggle QA Inspector enabled/disabled with optimistic update
 */
export function useToggleQAInspector(botId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      const response = await apiPut<ApiResponse<QAInspectorSettings>>(
        `/bots/${botId}/qa-inspector/settings`,
        { qa_inspector_enabled: enabled }
      );
      return response.data;
    },
    onMutate: async (enabled) => {
      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: qaInspectorKeys.settings(botId) });

      // Snapshot previous value
      const previousSettings = queryClient.getQueryData<QAInspectorSettings>(
        qaInspectorKeys.settings(botId)
      );

      // Optimistically update
      if (previousSettings) {
        queryClient.setQueryData<QAInspectorSettings>(
          qaInspectorKeys.settings(botId),
          { ...previousSettings, qa_inspector_enabled: enabled }
        );
      }

      return { previousSettings };
    },
    onError: (_err, _enabled, context) => {
      // Rollback on error
      if (context?.previousSettings) {
        queryClient.setQueryData(
          qaInspectorKeys.settings(botId),
          context.previousSettings
        );
      }
    },
    onSuccess: (data) => {
      // Update cache with ACTUAL server response (ensures persistence)
      queryClient.setQueryData(qaInspectorKeys.settings(botId), data);
    },
    onSettled: () => {
      // Only invalidate dashboard (settings already updated via onSuccess)
      queryClient.invalidateQueries({ queryKey: qaInspectorKeys.dashboard(botId) });
    },
  });
}

/**
 * Hook to fetch QA evaluation logs with pagination and filters
 */
export function useQAEvaluationLogs(botId: number, filters?: QAEvaluationLogFilters) {
  return useQuery({
    queryKey: qaInspectorKeys.logs(botId, filters),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.is_flagged !== undefined) params.append('is_flagged', String(filters.is_flagged));
      if (filters?.issue_type) params.append('issue_type', filters.issue_type);
      if (filters?.min_score !== undefined) params.append('min_score', String(filters.min_score));
      if (filters?.max_score !== undefined) params.append('max_score', String(filters.max_score));
      if (filters?.from_date) params.append('from_date', filters.from_date);
      if (filters?.to_date) params.append('to_date', filters.to_date);
      if (filters?.per_page) params.append('per_page', String(filters.per_page));
      if (filters?.page) params.append('page', String(filters.page));

      const queryString = params.toString();
      const url = `/bots/${botId}/qa-inspector/logs${queryString ? `?${queryString}` : ''}`;
      const response = await apiGet<PaginatedResponse<QAEvaluationLog>>(url);
      return response;
    },
    enabled: !!botId,
  });
}

/**
 * Hook to fetch a single evaluation log detail
 */
export function useQAEvaluationLog(botId: number, logId: number) {
  return useQuery({
    queryKey: qaInspectorKeys.log(botId, logId),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QAEvaluationLog>>(
        `/bots/${botId}/qa-inspector/logs/${logId}`
      );
      return response.data;
    },
    enabled: !!botId && !!logId,
  });
}

/**
 * Hook to fetch weekly reports with pagination
 */
export function useQAWeeklyReports(botId: number, filters?: QAWeeklyReportFilters) {
  return useQuery({
    queryKey: qaInspectorKeys.reports(botId, filters),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters?.status) params.append('status', filters.status);
      if (filters?.from_date) params.append('from_date', filters.from_date);
      if (filters?.to_date) params.append('to_date', filters.to_date);
      if (filters?.per_page) params.append('per_page', String(filters.per_page));
      if (filters?.page) params.append('page', String(filters.page));

      const queryString = params.toString();
      const url = `/bots/${botId}/qa-inspector/reports${queryString ? `?${queryString}` : ''}`;
      const response = await apiGet<PaginatedResponse<QAWeeklyReport>>(url);
      return response;
    },
    enabled: !!botId,
  });
}

/**
 * Hook to fetch a single weekly report detail
 */
export function useQAWeeklyReport(botId: number, reportId: number) {
  return useQuery({
    queryKey: qaInspectorKeys.report(botId, reportId),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QAWeeklyReport>>(
        `/bots/${botId}/qa-inspector/reports/${reportId}`
      );
      return response.data;
    },
    enabled: !!botId && !!reportId,
  });
}

/**
 * Hook to generate a new weekly report
 */
export function useGenerateReport(botId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (weekStart?: string) => {
      const response = await apiPost<ApiResponse<QAWeeklyReport>>(
        `/bots/${botId}/qa-inspector/reports/generate`,
        { week_start: weekStart }
      );
      return response.data;
    },
    onSuccess: () => {
      // Invalidate reports list to show the new report
      queryClient.invalidateQueries({ queryKey: qaInspectorKeys.reports(botId) });
      // Also invalidate dashboard since it shows latest report
      queryClient.invalidateQueries({ queryKey: qaInspectorKeys.dashboard(botId) });
    },
  });
}

/**
 * Hook to fetch QA Inspector dashboard summary
 */
export function useQAInspectorDashboard(botId: number) {
  return useQuery({
    queryKey: qaInspectorKeys.dashboard(botId),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QAInspectorDashboard>>(
        `/bots/${botId}/qa-inspector/dashboard`
      );
      return response.data;
    },
    enabled: !!botId,
  });
}

/**
 * Hook to fetch QA Inspector stats for a specific period
 */
export function useQAStats(botId: number, period: string = '7d') {
  return useQuery({
    queryKey: qaInspectorKeys.stats(botId, period),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<QAStatsData>>(
        `/bots/${botId}/qa-inspector/stats?period=${period}`
      );
      return response.data;
    },
    enabled: !!botId,
  });
}

/**
 * Combined hook for QA Inspector operations
 */
export function useQAInspectorOperations(botId: number) {
  const settings = useQAInspectorSettings(botId);
  const toggleMutation = useToggleQAInspector(botId);
  const updateMutation = useUpdateQAInspectorSettings(botId);

  return {
    // Data
    settings: settings.data,
    isEnabled: settings.data?.qa_inspector_enabled ?? false,

    // Loading states
    isLoading: settings.isLoading,
    isToggling: toggleMutation.isPending,
    isUpdating: updateMutation.isPending,

    // Errors
    error: settings.error,
    toggleError: toggleMutation.error,
    updateError: updateMutation.error,

    // Actions
    toggle: toggleMutation.mutate,
    toggleAsync: toggleMutation.mutateAsync,
    updateSettings: updateMutation.mutate,
    updateSettingsAsync: updateMutation.mutateAsync,

    // Refetch
    refetch: settings.refetch,
  };
}

/**
 * Hook to apply a prompt suggestion from a weekly report
 */
export function useApplyPromptSuggestion(botId: number, reportId: number) {
  const queryClient = useQueryClient();

  return useMutation<
    ApplySuggestionResponse,
    ApplySuggestionConflict | Error,
    { suggestionIndex: number; flowId: number; force?: boolean }
  >({
    mutationFn: async ({ suggestionIndex, flowId, force = false }) => {
      const response = await apiPost<ApiResponse<ApplySuggestionResponse>>(
        `/bots/${botId}/qa-inspector/reports/${reportId}/suggestions/${suggestionIndex}/apply`,
        { flow_id: flowId, force }
      );
      return response.data;
    },
    onSuccess: () => {
      // Invalidate report to refresh suggestion status
      queryClient.invalidateQueries({
        queryKey: qaInspectorKeys.report(botId, reportId),
      });
      // Also invalidate reports list
      queryClient.invalidateQueries({
        queryKey: qaInspectorKeys.reports(botId),
      });
    },
  });
}

/**
 * Hook to sync QA Inspector settings via WebSocket for realtime multi-tab sync
 */
export function useBotSettingsSync(botId: number) {
  const queryClient = useQueryClient();

  // Listen for settings updates via WebSocket
  useBotChannel(botId, {
    onSettingsUpdate: (event: BotSettingsUpdatedEvent) => {
      if (event.setting_type === 'qa_inspector') {
        // Update the cache with the new value from WebSocket
        const currentSettings = queryClient.getQueryData<QAInspectorSettings>(
          qaInspectorKeys.settings(botId)
        );

        if (currentSettings) {
          queryClient.setQueryData<QAInspectorSettings>(
            qaInspectorKeys.settings(botId),
            {
              ...currentSettings,
              qa_inspector_enabled: event.qa_inspector_enabled,
            }
          );
        } else {
          // If no cached settings, invalidate to trigger refetch
          queryClient.invalidateQueries({
            queryKey: qaInspectorKeys.settings(botId),
          });
        }

        // Also invalidate dashboard
        queryClient.invalidateQueries({
          queryKey: qaInspectorKeys.dashboard(botId),
        });
      }
    },
  });
}
