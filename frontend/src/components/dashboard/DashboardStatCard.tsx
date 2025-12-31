import type { LucideIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
  variant?: 'default' | 'warning' | 'danger';
  className?: string;
}

export function DashboardStatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  variant = 'default',
  className,
}: DashboardStatCardProps) {
  return (
    <Card className={cn(className)}>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
        <Icon
          className={cn(
            'h-4 w-4',
            variant === 'default' && 'text-muted-foreground',
            variant === 'warning' && 'text-yellow-500',
            variant === 'danger' && 'text-red-500'
          )}
        />
      </CardHeader>
      <CardContent>
        <div
          className={cn(
            'text-2xl font-bold',
            variant === 'danger' && 'text-red-600',
            variant === 'warning' && 'text-yellow-600'
          )}
        >
          {value}
        </div>
        {description && (
          <p className="text-xs text-muted-foreground">{description}</p>
        )}
        {trend && (
          <p
            className={cn(
              'text-xs mt-1',
              trend.direction === 'up' && 'text-green-600',
              trend.direction === 'down' && 'text-red-600',
              trend.direction === 'stable' && 'text-muted-foreground'
            )}
          >
            {trend.direction === 'up' && '+'}
            {trend.direction === 'down' && '-'}
            {trend.value}% จากเมื่อวาน
          </p>
        )}
      </CardContent>
    </Card>
  );
}
