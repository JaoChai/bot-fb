import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Crown } from 'lucide-react';
import { formatBaht } from '@/lib/currency';
import { CHART_TOOLTIP_STYLE } from '@/lib/charts';
import type { OrderTimeSeries } from '@/types/api';

interface RevenueChartProps {
  timeSeries: OrderTimeSeries[];
  vipCustomers?: number;
  vipTotalSpent?: number;
}

export function RevenueChart({ timeSeries, vipCustomers, vipTotalSpent }: RevenueChartProps) {
  return (
    <Card className="h-full">
      <CardHeader className="pb-2">
        <CardTitle className="text-base">แนวโน้มยอดขาย (30 วัน)</CardTitle>
      </CardHeader>
      <CardContent>
        {timeSeries.length > 0 ? (
          <div className="h-[240px]">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={timeSeries}>
                <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                <XAxis
                  dataKey="date"
                  className="text-xs"
                  tick={{ fill: 'currentColor', fontSize: 11 }}
                  tickFormatter={(v) => {
                    const d = new Date(v);
                    return `${d.getDate()}/${d.getMonth() + 1}`;
                  }}
                />
                <YAxis
                  tickFormatter={(v) => `฿${(Number(v) / 1000).toFixed(0)}k`}
                  className="text-xs"
                  tick={{ fill: 'currentColor', fontSize: 11 }}
                  width={50}
                />
                <Tooltip
                  formatter={(v) => [formatBaht(Number(v) || 0), 'ยอดขาย']}
                  labelFormatter={(label) => {
                    const d = new Date(label);
                    return d.toLocaleDateString('th-TH', {
                      day: 'numeric',
                      month: 'short',
                    });
                  }}
                  contentStyle={CHART_TOOLTIP_STYLE}
                />
                <Line
                  type="monotone"
                  dataKey="revenue"
                  stroke="#3B82F6"
                  strokeWidth={2}
                  dot={{ fill: '#3B82F6', strokeWidth: 2, r: 3 }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        ) : (
          <div className="flex items-center justify-center h-[240px] text-sm text-muted-foreground">
            ยังไม่มีข้อมูลยอดขาย
          </div>
        )}

        {/* VIP stats footer */}
        {(vipCustomers ?? 0) > 0 && (
          <div className="flex items-center gap-2 mt-3 pt-3 border-t text-sm text-muted-foreground">
            <Crown className="h-4 w-4 text-amber-500" />
            <span>
              VIP: {vipCustomers} คน
              {vipTotalSpent != null && (
                <span className="ml-1 font-medium text-foreground">
                  {formatBaht(vipTotalSpent)}
                </span>
              )}
            </span>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
