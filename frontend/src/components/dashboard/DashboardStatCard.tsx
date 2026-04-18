import type { ElementType, ReactNode } from 'react';
import { Metric } from '@/components/common';

interface DashboardStatCardProps {
  title: string;
  value: ReactNode;
  description?: ReactNode;
  icon?: ElementType;
  trend?: { value: number; direction: 'up' | 'down' | 'stable' };
}

export function DashboardStatCard({ title, value, description, icon, trend }: DashboardStatCardProps) {
  return <Metric label={title} value={value} hint={description} icon={icon} trend={trend} />;
}
