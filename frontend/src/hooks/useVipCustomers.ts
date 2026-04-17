import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { VipCustomer, VipCustomersResponse } from '@/types/api';

const vipQueryKey = (botId: number | string) => ['vip-customers', String(botId)] as const;

export function useVipCustomers(botId: number | string | undefined) {
  return useQuery({
    queryKey: vipQueryKey(botId ?? 'none'),
    queryFn: async (): Promise<VipCustomer[]> => {
      const { data } = await api.get<VipCustomersResponse>(`/bots/${botId}/vip/customers`);
      return data.data;
    },
    enabled: Boolean(botId),
    staleTime: 30_000,
  });
}

export function useRevokeVip(botId: number | string | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (customerProfileId: number) => {
      const { data } = await api.post(
        `/bots/${botId}/vip/customers/${customerProfileId}/revoke`,
      );
      return data;
    },
    onSuccess: () => {
      if (botId !== undefined) {
        queryClient.invalidateQueries({ queryKey: vipQueryKey(botId) });
      }
    },
  });
}

export function usePromoteVip(botId: number | string | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { customerProfileId: number; content: string }) => {
      const { data } = await api.post(
        `/bots/${botId}/vip/customers/${payload.customerProfileId}/promote`,
        { content: payload.content },
      );
      return data;
    },
    onSuccess: () => {
      if (botId !== undefined) {
        queryClient.invalidateQueries({ queryKey: vipQueryKey(botId) });
      }
    },
  });
}
