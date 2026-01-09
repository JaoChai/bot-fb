import * as React from 'react';
import { TrendingUp, TrendingDown } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/Lib/utils';

interface StatsCardProps {
  title: string;
  value: number | string;
  change?: number;
  changeLabel?: string;
  icon: React.ReactNode;
  formatValue?: (value: number) => string;
}

function StatsCard({
  title,
  value,
  change,
  changeLabel,
  icon,
  formatValue,
}: StatsCardProps) {
  const displayValue =
    typeof value === 'number' && formatValue ? formatValue(value) : value;

  const isPositiveChange = change !== undefined && change >= 0;

  return (
    <Card className="py-4">
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <div className="text-muted-foreground">{icon}</div>
          {change !== undefined && (
            <div
              className={cn(
                'flex items-center gap-1 text-xs font-medium',
                isPositiveChange ? 'text-green-600' : 'text-red-600'
              )}
            >
              {isPositiveChange ? (
                <TrendingUp className="h-3 w-3" />
              ) : (
                <TrendingDown className="h-3 w-3" />
              )}
              <span>
                {isPositiveChange ? '+' : ''}
                {change.toFixed(1)}%
              </span>
            </div>
          )}
        </div>

        <div>
          <p className="text-muted-foreground text-sm">{title}</p>
          <p className="text-2xl font-bold tracking-tight">{displayValue}</p>
        </div>

        {changeLabel && (
          <p className="text-muted-foreground text-xs">{changeLabel}</p>
        )}
      </CardContent>
    </Card>
  );
}

export { StatsCard };
export type { StatsCardProps };
