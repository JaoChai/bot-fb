import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildFilterParams } from '@/lib/params';
import { useAuthStore } from '@/stores/authStore';
import type { SlipsResponse, SlipFilters } from '@/types/api';

export function useSlips(filters: SlipFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();
  return useQuery({
    queryKey: ['slips', 'list', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        status: filters.status,
        date_from: filters.date_from,
        date_to: filters.date_to,
        search: filters.search,
        page: filters.page,
        per_page: filters.per_page,
      });
      const queryString = params.toString();
      const url = queryString ? `/slips?${queryString}` : '/slips';
      const response = await api.get<SlipsResponse>(url);
      return {
        slips: response.data.data,
        meta: response.data.meta,
      };
    },
    staleTime: 30_000,
    enabled: !!user && options?.enabled !== false,
  });
}
