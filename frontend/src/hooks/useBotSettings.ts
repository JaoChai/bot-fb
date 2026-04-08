import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPut } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, BotSettings } from '@/types/api';

// Fetch bot settings
export function useBotSettings(botId: number | null) {
  return useQuery({
    queryKey: queryKeys.bots.settings(botId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<BotSettings>>(`/bots/${botId}/settings`);
      return response.data;
    },
    enabled: !!botId,
  });
}

// Update bot settings mutation
export type UpdateSettingsPayload = Partial<Omit<BotSettings, 'id' | 'bot_id' | 'created_at' | 'updated_at'>>;

export function useUpdateBotSettings(botId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (settings: UpdateSettingsPayload) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPut<ApiResponse<BotSettings>>(`/bots/${botId}/settings`, settings);
      return response;
    },
    onSuccess: (data) => {
      if (!botId) return;
      // Update the cache with new settings
      queryClient.setQueryData(queryKeys.bots.settings(botId), data.data);
      // Invalidate bot detail to reflect updated settings
      queryClient.invalidateQueries({
        queryKey: queryKeys.bots.detail(botId),
      });
    },
  });
}
