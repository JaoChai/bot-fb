import { useMemo } from 'react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { formatBaht } from '@/lib/currency';
import { CHART_COLORS, groupProductsByCategory } from '@/lib/charts';
import type { ProductOrderBreakdown } from '@/types/api';

interface ProductsSummaryCardProps {
  products: ProductOrderBreakdown[];
}

function PieTooltip({ active, payload }: { active?: boolean; payload?: Array<{ name: string; value: number }> }) {
  if (!active || !payload || payload.length === 0) return null;
  return (
    <div className="rounded-lg border bg-card px-3 py-2 shadow-lg text-sm">
      <span className="font-medium">{payload[0].name}:</span>{' '}
      <span className="font-semibold">{formatBaht(payload[0].value)}</span>
    </div>
  );
}

export function ProductsSummaryCard({ products }: ProductsSummaryCardProps) {
  const topProducts = useMemo(
    () => [...products].sort((a, b) => b.total_revenue - a.total_revenue).slice(0, 5),
    [products],
  );

  const categoryData = useMemo(() => groupProductsByCategory(products), [products]);

  if (products.length === 0) {
    return (
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="text-base font-semibold">สินค้าและหมวดหมู่</h3>
        <div className="flex h-48 items-center justify-center text-muted-foreground">
          ยังไม่มีข้อมูลสินค้า
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 text-base font-semibold">สินค้าและหมวดหมู่</h3>
      <div className="grid gap-6 md:grid-cols-2">
        {/* Left: Top 5 Products */}
        <div className="space-y-3">
          {topProducts.map((product, index) => (
            <div key={`${product.product_name}-${product.category}`} className="flex items-center gap-3">
              <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                {index + 1}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="truncate text-sm font-medium">{product.product_name}</span>
                  <Badge variant="outline" className="shrink-0 text-xs">
                    {product.category}
                  </Badge>
                </div>
                <div className="text-xs text-muted-foreground">{product.quantity_sold} ชิ้น</div>
              </div>
              <div className="shrink-0 text-right font-semibold">
                {formatBaht(product.total_revenue)}
              </div>
            </div>
          ))}
        </div>

        {/* Right: Donut Chart */}
        <div className="flex flex-col items-center justify-center">
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie
                data={categoryData}
                dataKey="total_revenue"
                nameKey="category"
                cx="50%"
                cy="50%"
                innerRadius={40}
                outerRadius={70}
                paddingAngle={2}
                label={({ percent }) => {
                  const pct = (percent || 0) * 100;
                  if (pct < 5) return null;
                  return `${pct.toFixed(0)}%`;
                }}
                labelLine={false}
              >
                {categoryData.map((_, index) => (
                  <Cell key={`cell-${index}`} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                ))}
              </Pie>
              <Tooltip content={<PieTooltip />} />
            </PieChart>
          </ResponsiveContainer>

          <div className="mt-3 flex flex-wrap justify-center gap-3">
            {categoryData.map((category, index) => (
              <div key={category.category} className="flex items-center gap-1.5">
                <div
                  className="h-2.5 w-2.5 rounded-full"
                  style={{ backgroundColor: CHART_COLORS[index % CHART_COLORS.length] }}
                />
                <span className="text-xs text-muted-foreground">{category.category}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
