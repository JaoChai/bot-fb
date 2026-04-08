import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { DashboardData } from '@/types/api';

interface DashboardResponse {
  data: DashboardData;
}

/**
 * Hook to fetch dashboard summary data
 * Includes: summary stats, bot list with metrics, alerts, and recent activity
 */
export function useDashboardSummary() {
  return useQuery({
    queryKey: ['dashboard', 'summary'],
    queryFn: async () => {
      const response = await api.get<DashboardResponse>('/dashboard/summary');
      return response.data.data;
    },
    staleTime: 30000, // 30 seconds - more frequent updates for dashboard
    refetchInterval: 60000, // Poll every minute for real-time updates
  });
}
