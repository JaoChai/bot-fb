import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPut, apiPost, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type {
  ApiResponse,
  UserSettings,
  UpdateOpenRouterSettings,
  UpdateLineSettings,
  TestConnectionResponse,
} from '@/types/api';

// Fetch user settings
export function useUserSettings() {
  return useQuery({
    queryKey: queryKeys.settings.user(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<UserSettings>>('/settings');
      return response.data;
    },
  });
}

// Update OpenRouter settings
export function useUpdateOpenRouter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (settings: UpdateOpenRouterSettings) => {
      const response = await apiPut<ApiResponse<Partial<UserSettings>>>('/settings/openrouter', settings);
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Update LINE settings
export function useUpdateLine() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (settings: UpdateLineSettings) => {
      const response = await apiPut<ApiResponse<Partial<UserSettings>>>('/settings/line', settings);
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Test OpenRouter connection
export function useTestOpenRouter() {
  return useMutation({
    mutationFn: async () => {
      const response = await apiPost<TestConnectionResponse>('/settings/test-openrouter');
      return response;
    },
  });
}

// Test LINE connection
export function useTestLine() {
  return useMutation({
    mutationFn: async () => {
      const response = await apiPost<TestConnectionResponse>('/settings/test-line');
      return response;
    },
  });
}

// Clear OpenRouter API key
export function useClearOpenRouter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      const response = await apiDelete<{ message: string }>('/settings/openrouter');
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Clear LINE credentials
export function useClearLine() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      const response = await apiDelete<{ message: string }>('/settings/line');
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Convenience hook combining all settings operations
export function useUserSettingsOperations() {
  const settings = useUserSettings();
  const updateOpenRouter = useUpdateOpenRouter();
  const updateLine = useUpdateLine();
  const testOpenRouter = useTestOpenRouter();
  const testLine = useTestLine();
  const clearOpenRouter = useClearOpenRouter();
  const clearLine = useClearLine();

  return {
    // Data
    settings: settings.data,

    // Loading states
    isLoading: settings.isLoading,
    isUpdatingOpenRouter: updateOpenRouter.isPending,
    isUpdatingLine: updateLine.isPending,
    isTestingOpenRouter: testOpenRouter.isPending,
    isTestingLine: testLine.isPending,

    // Errors
    error: settings.error,

    // Actions
    updateOpenRouter: updateOpenRouter.mutateAsync,
    updateLine: updateLine.mutateAsync,
    testOpenRouter: testOpenRouter.mutateAsync,
    testLine: testLine.mutateAsync,
    clearOpenRouter: clearOpenRouter.mutateAsync,
    clearLine: clearLine.mutateAsync,

    // Refetch
    refetch: settings.refetch,
  };
}
