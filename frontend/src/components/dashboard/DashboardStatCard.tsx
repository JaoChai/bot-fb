import type { LucideIcon } from 'lucide-react';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

interface DashboardStatCardProps {
  title: string;
  value: string | number;
  description?: string;
  icon: LucideIcon;
  trend?: {
    value: number;
    direction: 'up' | 'down' | 'stable';
  };
  className?: string;
}

export function DashboardStatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  className,
}: DashboardStatCardProps) {
  return (
    <div
      className={cn(
        'relative overflow-hidden rounded-xl border bg-card p-5 shadow-sm transition-shadow hover:shadow-md',
        className,
      )}
    >
      <div className="flex items-start justify-between">
        <div className="space-y-2">
          <p className="text-sm font-medium text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold tracking-tight">{value}</p>
          {description && (
            <p className="text-xs text-muted-foreground">{description}</p>
          )}
        </div>
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
        </div>
      </div>

      {trend && (
        <div className="mt-3 flex items-center gap-1.5">
          {trend.direction === 'up' && (
            <div className="flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
              <TrendingUp className="h-3 w-3" />
              +{trend.value}%
            </div>
          )}
          {trend.direction === 'down' && (
            <div className="flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-400">
              <TrendingDown className="h-3 w-3" />
              -{trend.value}%
            </div>
          )}
          {trend.direction === 'stable' && (
            <div className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
              <Minus className="h-3 w-3" />
              0%
            </div>
          )}
          <span className="text-xs text-muted-foreground">จากเมื่อวาน</span>
        </div>
      )}
    </div>
  );
}
