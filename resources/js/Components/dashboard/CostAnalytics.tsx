import * as React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { cn } from '@/Lib/utils';

interface CostAnalyticsProps {
  data: Array<{
    model: string;
    cost: number;
    percentage: number;
  }>;
  totalCost: number;
}

const MODEL_COLORS: Record<string, string> = {
  'gpt-4o': 'bg-emerald-500',
  'gpt-4o-mini': 'bg-emerald-400',
  'gpt-4': 'bg-emerald-600',
  'gpt-3.5-turbo': 'bg-emerald-300',
  'claude-3-opus': 'bg-violet-600',
  'claude-3-sonnet': 'bg-violet-500',
  'claude-3-haiku': 'bg-violet-400',
  'claude-3.5-sonnet': 'bg-violet-500',
  'gemini-pro': 'bg-blue-500',
  'gemini-flash': 'bg-blue-400',
  default: 'bg-slate-500',
};

function getModelColor(model: string): string {
  const normalizedModel = model.toLowerCase();
  for (const [key, color] of Object.entries(MODEL_COLORS)) {
    if (normalizedModel.includes(key)) {
      return color;
    }
  }
  return MODEL_COLORS.default;
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function CostAnalytics({ data, totalCost }: CostAnalyticsProps) {
  const sortedData = [...data].sort((a, b) => b.cost - a.cost);

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base font-medium">
          ค่าใช้จ่าย AI ตาม Model
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-3">
          {sortedData.map((item) => (
            <div key={item.model} className="space-y-1.5">
              <div className="flex items-center justify-between text-sm">
                <span className="truncate font-medium">{item.model}</span>
                <span className="text-muted-foreground ml-2 shrink-0">
                  {formatCurrency(item.cost)} ({item.percentage.toFixed(1)}%)
                </span>
              </div>
              <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                <div
                  className={cn(
                    'h-full rounded-full transition-all duration-500',
                    getModelColor(item.model)
                  )}
                  style={{ width: `${Math.max(item.percentage, 2)}%` }}
                />
              </div>
            </div>
          ))}
        </div>

        {data.length === 0 && (
          <p className="text-muted-foreground py-4 text-center text-sm">
            ยังไม่มีข้อมูลค่าใช้จ่าย
          </p>
        )}

        <div className="border-border flex items-center justify-between border-t pt-4">
          <span className="font-medium">รวมทั้งหมด</span>
          <span className="text-lg font-bold">{formatCurrency(totalCost)}</span>
        </div>
      </CardContent>
    </Card>
  );
}

export { CostAnalytics };
export type { CostAnalyticsProps };
