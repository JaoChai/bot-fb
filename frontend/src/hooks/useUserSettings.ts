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

// Update EasySlip API token
export function useUpdateEasySlipToken() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: { token: string }) => {
      const response = await apiPut<ApiResponse<UserSettings>>('/settings/easyslip', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Test EasySlip API connection
export function useTestEasySlipConnection() {
  return useMutation({
    mutationFn: async () => {
      const response = await apiPost<TestConnectionResponse>('/settings/test-easyslip', {});
      return response;
    },
  });
}

// Clear EasySlip API token
export function useClearEasySlipToken() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      const response = await apiDelete<{ message: string }>('/settings/easyslip');
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}

// Update quiet hours (ช่วงเวลาเงียบแจ้งเตือนซ้ำ)
export function useUpdateQuietHours() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: { enabled: boolean; start: string; end: string }) => {
      const response = await apiPut<ApiResponse<UserSettings>>('/settings/quiet-hours', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}
