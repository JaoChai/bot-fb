import { useMemo } from 'react';
import {
  ComposedChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts';
import { Crown } from 'lucide-react';
import { formatBaht, usdToTHB } from '@/lib/currency';
import type { OrderTimeSeries, CostTimeSeries } from '@/types/api';

interface DualAxisChartProps {
  orderTimeSeries: OrderTimeSeries[];
  costTimeSeries: CostTimeSeries[];
  vipCustomers?: number;
  vipTotalSpent?: number;
}

function ChartTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ name: string; value: number; color: string }>;
  label?: string;
}) {
  if (!active || !payload || payload.length === 0) return null;

  const revenuePayload = payload.find((p) => p.name === 'ยอดขาย');
  const costPayload = payload.find((p) => p.name === 'ค่า AI');

  const date = new Date(label ?? '');
  const formattedDate = date.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
  });

  return (
    <div className="rounded-lg border bg-card px-3 py-2.5 shadow-lg">
      <p className="mb-2 text-xs font-semibold text-foreground">
        {formattedDate}
      </p>
      {revenuePayload && (
        <div className="flex items-center gap-2 text-sm">
          <span className="h-2 w-2 rounded-full bg-blue-500" />
          <span className="text-muted-foreground">{revenuePayload.name}:</span>
          <span className="font-semibold">{formatBaht(revenuePayload.value)}</span>
        </div>
      )}
      {costPayload && (
        <div className="flex items-center gap-2 text-sm">
          <span className="h-2 w-2 rounded-full bg-amber-500" />
          <span className="text-muted-foreground">{costPayload.name}:</span>
          <span className="font-semibold">
            ฿{(costPayload.value as number).toFixed(2)}
          </span>
        </div>
      )}
    </div>
  );
}

export function DualAxisChart({
  orderTimeSeries,
  costTimeSeries,
  vipCustomers,
  vipTotalSpent,
}: DualAxisChartProps) {
  const merged = useMemo(() => {
    return orderTimeSeries.map((o) => ({
      date: o.date,
      revenue: o.revenue,
      cost: usdToTHB(
        costTimeSeries.find((c) => c.period === o.date)?.total_cost ?? 0,
      ),
    }));
  }, [orderTimeSeries, costTimeSeries]);

  if (merged.length === 0) {
    return (
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="text-base font-semibold">ยอดขาย vs ค่า AI (30 วัน)</h3>
        <div className="mt-4 flex h-[280px] items-center justify-center text-sm text-muted-foreground">
          ยังไม่มีข้อมูล
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 text-base font-semibold">
        ยอดขาย vs ค่า AI (30 วัน)
      </h3>
      <div className="h-[300px]">
        <ResponsiveContainer width="100%" height="100%">
          <ComposedChart data={merged} margin={{ top: 5, right: 5, bottom: 5, left: 0 }}>
            <defs>
              <linearGradient id="revenueGradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#3B82F6" stopOpacity={0.2} />
                <stop offset="100%" stopColor="#3B82F6" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="costGradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#F59E0B" stopOpacity={0.15} />
                <stop offset="100%" stopColor="#F59E0B" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid
              strokeDasharray="3 3"
              vertical={false}
              className="stroke-border"
            />
            <XAxis
              dataKey="date"
              tick={{ fontSize: 11 }}
              className="text-muted-foreground"
              tickFormatter={(v) => {
                const d = new Date(v);
                return `${d.getDate()}/${d.getMonth() + 1}`;
              }}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              yAxisId="left"
              tickFormatter={(v) => `฿${(Number(v) / 1000).toFixed(0)}k`}
              tick={{ fontSize: 11 }}
              className="text-muted-foreground"
              width={50}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              yAxisId="right"
              orientation="right"
              tickFormatter={(v) => `฿${Number(v).toFixed(0)}`}
              tick={{ fontSize: 11 }}
              className="text-muted-foreground"
              width={50}
              axisLine={false}
              tickLine={false}
            />
            <Tooltip content={<ChartTooltip />} />
            <Legend
              wrapperStyle={{ paddingTop: '16px' }}
              iconType="circle"
              formatter={(value: string) => (
                <span className="text-xs text-muted-foreground">{value}</span>
              )}
            />
            <Area
              yAxisId="right"
              type="monotone"
              dataKey="cost"
              name="ค่า AI"
              fill="url(#costGradient)"
              stroke="#F59E0B"
              strokeWidth={2}
              isAnimationActive
            />
            <Area
              yAxisId="left"
              type="monotone"
              dataKey="revenue"
              name="ยอดขาย"
              fill="url(#revenueGradient)"
              stroke="#3B82F6"
              strokeWidth={2.5}
              dot={{ fill: '#3B82F6', strokeWidth: 0, r: 3 }}
              activeDot={{ fill: '#3B82F6', strokeWidth: 2, stroke: '#fff', r: 5 }}
              isAnimationActive
            />
          </ComposedChart>
        </ResponsiveContainer>
      </div>

      {(vipCustomers ?? 0) > 0 && (
        <div className="mt-4 flex items-center gap-2 rounded-lg bg-amber-50/60 px-3 py-2 text-sm dark:bg-amber-950/20">
          <Crown className="h-4 w-4 text-amber-500" />
          <span className="text-muted-foreground">
            VIP: {vipCustomers} คน
            {vipTotalSpent != null && (
              <span className="ml-1 font-semibold text-foreground">
                {formatBaht(vipTotalSpent)}
              </span>
            )}
          </span>
        </div>
      )}
    </div>
  );
}
