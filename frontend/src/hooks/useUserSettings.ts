import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, UserSettings } from '@/types/api';

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
