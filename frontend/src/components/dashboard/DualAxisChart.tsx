import { useMemo } from 'react';
import {
  ComposedChart,
  Line,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Crown } from 'lucide-react';
import { formatBaht, usdToTHB } from '@/lib/currency';
import { CHART_TOOLTIP_STYLE } from '@/lib/charts';
import type { OrderTimeSeries, CostTimeSeries } from '@/types/api';

interface DualAxisChartProps {
  orderTimeSeries: OrderTimeSeries[];
  costTimeSeries: CostTimeSeries[];
  vipCustomers?: number;
  vipTotalSpent?: number;
}

export function DualAxisChart({
  orderTimeSeries,
  costTimeSeries,
  vipCustomers,
  vipTotalSpent,
}: DualAxisChartProps) {
  // Merge data: left-join from orderTimeSeries, match cost by date===period
  // Convert cost from USD to THB using usdToTHB()
  const merged = useMemo(() => {
    return orderTimeSeries.map((o) => ({
      date: o.date,
      revenue: o.revenue,
      cost: usdToTHB(
        costTimeSeries.find((c) => c.period === o.date)?.total_cost ?? 0
      ),
    }));
  }, [orderTimeSeries, costTimeSeries]);

  // Custom tooltip that shows both values with proper formatting
  const CustomTooltip = ({ active, payload, label }: { active?: boolean; payload?: Array<{ name: string; value: number }>; label?: string }) => {
    if (!active || !payload || payload.length === 0) return null;

    const revenuePayload = payload.find((p) => p.name === 'ยอดขาย');
    const costPayload = payload.find((p) => p.name === 'ค่า AI');

    const date = new Date(label ?? '');
    const formattedDate = date.toLocaleDateString('th-TH', {
      day: 'numeric',
      month: 'short',
    });

    return (
      <div style={CHART_TOOLTIP_STYLE} className="p-3">
        <p className="font-semibold text-sm mb-2">{formattedDate}</p>
        {revenuePayload && (
          <p className="text-sm text-blue-600">
            {revenuePayload.name}: {formatBaht(revenuePayload.value)}
          </p>
        )}
        {costPayload && (
          <p className="text-sm text-amber-600">
            {costPayload.name}: ฿{(costPayload.value as number).toFixed(2)}
          </p>
        )}
      </div>
    );
  };

  // Handle empty state
  if (merged.length === 0) {
    return (
      <Card className="h-full">
        <CardHeader className="pb-2">
          <CardTitle className="text-base">ยอดขาย vs ค่า AI (30 วัน)</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-center h-[280px] text-sm text-muted-foreground">
            ยังไม่มีข้อมูล
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="h-full">
      <CardHeader className="pb-2">
        <CardTitle className="text-base">ยอดขาย vs ค่า AI (30 วัน)</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="h-[280px]">
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={merged}>
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
              {/* Left Y-axis: Revenue in THB */}
              <YAxis
                yAxisId="left"
                tickFormatter={(v) => `฿${(Number(v) / 1000).toFixed(0)}k`}
                className="text-xs"
                tick={{ fill: 'currentColor', fontSize: 11 }}
                width={50}
              />
              {/* Right Y-axis: Cost in THB */}
              <YAxis
                yAxisId="right"
                orientation="right"
                tickFormatter={(v) => `฿${Number(v).toFixed(0)}`}
                className="text-xs"
                tick={{ fill: 'currentColor', fontSize: 11 }}
                width={50}
              />
              <Tooltip content={<CustomTooltip />} />
              <Legend
                wrapperStyle={{ paddingTop: '16px' }}
                iconType="line"
                formatter={(value: string) => (
                  <span className="text-sm">{value}</span>
                )}
              />
              {/* Cost as Area on right axis (subtle fill) */}
              <Area
                yAxisId="right"
                type="monotone"
                dataKey="cost"
                name="ค่า AI"
                fill="#F59E0B"
                fillOpacity={0.15}
                stroke="#F59E0B"
                strokeWidth={1.5}
                isAnimationActive
              />
              {/* Revenue as Line on left axis */}
              <Line
                yAxisId="left"
                type="monotone"
                dataKey="revenue"
                name="ยอดขาย"
                stroke="#3B82F6"
                strokeWidth={2}
                dot={{ fill: '#3B82F6', strokeWidth: 2, r: 3 }}
                isAnimationActive
              />
            </ComposedChart>
          </ResponsiveContainer>
        </div>

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
