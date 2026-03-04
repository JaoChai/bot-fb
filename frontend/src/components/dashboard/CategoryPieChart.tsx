import { useMemo } from 'react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBaht } from '@/lib/currency';
import { CHART_COLORS, CHART_TOOLTIP_STYLE, groupProductsByCategory } from '@/lib/charts';
import type { ProductOrderBreakdown } from '@/types/api';

interface CategoryPieChartProps {
  products: ProductOrderBreakdown[];
}

export function CategoryPieChart({ products }: CategoryPieChartProps) {
  const categoryData = useMemo(() => groupProductsByCategory(products), [products]);

  return (
    <Card className="h-full">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">ยอดขายตามหมวดหมู่</CardTitle>
      </CardHeader>
      <CardContent>
        {categoryData.length === 0 ? (
          <div className="flex items-center justify-center py-8 text-sm text-muted-foreground">
            ยังไม่มีข้อมูล
          </div>
        ) : (
          <div className="h-[240px]">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={categoryData}
                  dataKey="total_revenue"
                  nameKey="category"
                  cx="50%"
                  cy="45%"
                  outerRadius={80}
                  label={({ percent }) => {
                    const pct = (percent || 0) * 100;
                    if (pct < 5) return null;
                    return `${pct.toFixed(0)}%`;
                  }}
                  labelLine={false}
                >
                  {categoryData.map((_, index) => (
                    <Cell
                      key={`cell-${index}`}
                      fill={CHART_COLORS[index % CHART_COLORS.length]}
                    />
                  ))}
                </Pie>
                <Tooltip
                  formatter={(v) => [formatBaht(Number(v) || 0), 'ยอดขาย']}
                  contentStyle={CHART_TOOLTIP_STYLE}
                />
                <Legend
                  verticalAlign="bottom"
                  iconType="circle"
                  iconSize={8}
                  wrapperStyle={{ fontSize: '12px' }}
                />
              </PieChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
