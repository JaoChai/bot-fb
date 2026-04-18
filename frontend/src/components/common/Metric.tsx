import type { ElementType, ReactNode } from 'react';
import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

type Trend = { value: number; direction: 'up' | 'down' | 'stable' };

interface MetricProps {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  icon?: ElementType;
  trend?: Trend;
  className?: string;
}

export function Metric({ label, value, hint, icon: Icon, trend, className }: MetricProps) {
  const TrendIcon =
    trend?.direction === 'up' ? ArrowUp : trend?.direction === 'down' ? ArrowDown : Minus;
  const trendTone =
    trend?.direction === 'up'
      ? 'text-emerald-600 dark:text-emerald-400'
      : trend?.direction === 'down'
      ? 'text-destructive'
      : 'text-muted-foreground';

  return (
    <div className={cn('rounded-lg border bg-card p-4', className)}>
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        {Icon && <Icon className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />}
      </div>
      <p className="mt-2 text-2xl font-semibold tabular-nums leading-none">{value}</p>
      {(trend || hint) && (
        <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
          {trend && (
            <span className={cn('inline-flex items-center gap-0.5 tabular-nums', trendTone)}>
              <TrendIcon className="h-3 w-3" strokeWidth={2} />
              {trend.value}%
            </span>
          )}
          {hint && <span className="truncate">{hint}</span>}
        </div>
      )}
    </div>
  );
}
