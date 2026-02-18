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

/**
 * Helper to check if there are any alerts that need attention
 */
export function hasActiveAlerts(data: DashboardData | undefined): boolean {
  if (!data?.alerts) return false;

  return data.alerts.handover_conversations.length > 0;
}

/**
 * Helper to get total alert count
 */
export function getTotalAlertCount(data: DashboardData | undefined): number {
  if (!data?.alerts) return 0;

  return data.alerts.handover_conversations.length;
}
