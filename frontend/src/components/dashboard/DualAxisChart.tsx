import { useMemo, useState } from 'react';
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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { formatBaht, usdToTHB } from '@/lib/currency';
import type { OrderTimeSeries, CostTimeSeries } from '@/types/api';

interface DualAxisChartProps {
  orderTimeSeries: OrderTimeSeries[];
  costTimeSeries: CostTimeSeries[];
}

const RANGE_OPTIONS = [7, 14, 30] as const;
type RangeDays = (typeof RANGE_OPTIONS)[number];

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
  const costPayload = payload.find((p) => p.name === 'ค่า API');

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
          <span className="size-2 rounded-full bg-blue-500" />
          <span className="text-muted-foreground">{revenuePayload.name}:</span>
          <span className="font-semibold">{formatBaht(revenuePayload.value)}</span>
        </div>
      )}
      {costPayload && (
        <div className="flex items-center gap-2 text-sm">
          <span className="size-2 rounded-full bg-amber-500" />
          <span className="text-muted-foreground">{costPayload.name}:</span>
          <span className="font-semibold">
            ฿{(costPayload.value as number).toFixed(2)}
          </span>
        </div>
      )}
    </div>
  );
}

export function DualAxisChart({ orderTimeSeries, costTimeSeries }: DualAxisChartProps) {
  const [range, setRange] = useState<RangeDays>(14);

  const merged = useMemo(() => {
    const costByPeriod = new Map(costTimeSeries.map((c) => [c.period, c.total_cost]));
    return orderTimeSeries.map((o) => ({
      date: o.date,
      revenue: o.revenue,
      cost: usdToTHB(costByPeriod.get(o.date) ?? 0),
    }));
  }, [orderTimeSeries, costTimeSeries]);

  if (merged.length === 0) {
    return (
      <div className="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
        <h3 className="text-base font-semibold">ยอดขาย vs ค่า API</h3>
        <div className="mt-4 flex h-[280px] items-center justify-center text-sm text-muted-foreground">
          ยังไม่มีข้อมูล
        </div>
      </div>
    );
  }

  const visible = merged.slice(-range);

  return (
    <div className="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-base font-semibold">ยอดขาย vs ค่า API</h3>
        <Tabs value={String(range)} onValueChange={(v) => setRange(Number(v) as RangeDays)}>
          <TabsList>
            {RANGE_OPTIONS.map((days) => (
              <TabsTrigger key={days} value={String(days)} className="tabular-nums">
                {days} วัน
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      </div>
      <div className="h-[300px]">
        <ResponsiveContainer width="100%" height="100%">
          <ComposedChart data={visible} margin={{ top: 5, right: 5, bottom: 5, left: 0 }}>
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
              width={40}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              yAxisId="right"
              orientation="right"
              tickFormatter={(v) => `฿${Number(v).toFixed(0)}`}
              tick={{ fontSize: 11 }}
              className="text-muted-foreground"
              width={40}
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
              name="ค่า API"
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
    </div>
  );
}
