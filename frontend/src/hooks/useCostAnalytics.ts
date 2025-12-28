import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
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
      const params = new URLSearchParams();

      if (filters.from_date) params.append('from_date', filters.from_date);
      if (filters.to_date) params.append('to_date', filters.to_date);
      if (filters.group_by) params.append('group_by', filters.group_by);
      if (filters.bot_id) params.append('bot_id', String(filters.bot_id));

      const queryString = params.toString();
      const url = queryString ? `/analytics/costs?${queryString}` : '/analytics/costs';

      const response = await api.get<CostAnalyticsResponse>(url);
      return response.data.data;
    },
    staleTime: 60000, // 1 minute
  });
}
