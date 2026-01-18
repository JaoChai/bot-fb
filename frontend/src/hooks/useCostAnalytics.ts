import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildFilterParams } from '@/lib/params';
import type { CostAnalyticsData, CostAnalyticsFilters } from '@/types/api';

interface CostAnalyticsResponse {
  data: CostAnalyticsData;
}

/**
 * Hook to fetch cost analytics data with optional filters
 */
export function useCostAnalytics(filters: CostAnalyticsFilters = {}) {
  return useQuery({
    queryKey: ['cost-analytics', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        from_date: filters.from_date,
        to_date: filters.to_date,
        group_by: filters.group_by,
        bot_id: filters.bot_id,
      });

      const queryString = params.toString();
      const url = queryString ? `/analytics/costs?${queryString}` : '/analytics/costs';

      const response = await api.get<CostAnalyticsResponse>(url);
      return response.data.data;
    },
    staleTime: 60000, // 1 minute
  });
}
