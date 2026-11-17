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

// Update bot settings mutation.
// Omitted on top of the response row: row identity keys, the response-only
// outage defaults, the response-only follow-up keys (D124), and the legacy
// lead_recovery_* keys (the API answers 422 for all of them).
export type UpdateSettingsPayload = Partial<
  Omit<
    BotSettings,
    | 'id'
    | 'bot_id'
    | 'created_at'
    | 'updated_at'
    | 'outage_defaults'
    | 'follow_up_started_at'
    | 'follow_up_capabilities'
    | 'lead_recovery_enabled'
    | 'lead_recovery_timeout_hours'
    | 'lead_recovery_mode'
    | 'lead_recovery_message'
    | 'lead_recovery_max_attempts'
  >
>;

// Type proof (F1a acceptance): the omissions above are verified by tsc during
// `npm run build`. Remove a key from the Omit and its @ts-expect-error below
// turns into an "Unused '@ts-expect-error' directive" build error.
// TS reports only the first excess property per literal, so each forbidden
// key gets its own one-key literal.
const payloadTypeProbeStartedAt: UpdateSettingsPayload = {
  daily_message_limit: 100,
  // @ts-expect-error response-only snapshot: never sendable (D124)
  follow_up_started_at: null,
};
const payloadTypeProbeCapabilities: UpdateSettingsPayload = {
  daily_message_limit: 100,
  // @ts-expect-error response-only capabilities: never sendable (D124)
  follow_up_capabilities: null,
};
const payloadTypeProbeLegacy: UpdateSettingsPayload = {
  daily_message_limit: 100,
  // @ts-expect-error legacy lead recovery: never sendable (D124)
  lead_recovery_enabled: false,
};
void payloadTypeProbeStartedAt;
void payloadTypeProbeCapabilities;
void payloadTypeProbeLegacy;

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
