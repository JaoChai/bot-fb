import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPut, apiPost, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, UserSettings, UpdateOpenRouterSettings, TestConnectionResponse } from '@/types/api';

// Fetch user settings (basic profile info only)
export function useUserSettings() {
  return useQuery({
    queryKey: queryKeys.settings.user(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<UserSettings>>('/settings');
      return response.data;
    },
  });
}

// Simplified convenience hook for user settings
export function useUserSettingsOperations() {
  const settings = useUserSettings();

  return {
    settings: settings.data,
    isLoading: settings.isLoading,
    error: settings.error,
    refetch: settings.refetch,
  };
}

// Update OpenRouter API key and model
export function useUpdateOpenRouterSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateOpenRouterSettings) => {
      const response = await apiPut<ApiResponse<UserSettings>>('/settings/openrouter', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Test OpenRouter API connection
export function useTestOpenRouterConnection() {
  return useMutation({
    mutationFn: async () => {
      const response = await apiPost<TestConnectionResponse>('/settings/test-openrouter', {});
      return response;
    },
  });
}

// Clear OpenRouter API key
export function useClearOpenRouterKey() {
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
