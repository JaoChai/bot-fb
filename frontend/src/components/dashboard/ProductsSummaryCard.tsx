import { useMemo } from 'react';
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { formatBaht } from '@/lib/currency';
import { CHART_COLORS, CHART_TOOLTIP_STYLE, groupProductsByCategory } from '@/lib/charts';
import type { ProductOrderBreakdown } from '@/types/api';

interface ProductsSummaryCardProps {
  products: ProductOrderBreakdown[];
}

export function ProductsSummaryCard({ products }: ProductsSummaryCardProps) {
  // Top 5 products sorted by revenue
  const topProducts = useMemo(
    () => [...products].sort((a, b) => b.total_revenue - a.total_revenue).slice(0, 5),
    [products],
  );

  // Category data for pie
  const categoryData = useMemo(() => groupProductsByCategory(products), [products]);

  if (products.length === 0) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>สินค้าและหมวดหมู่</CardTitle>
        </CardHeader>
        <CardContent className="flex h-48 items-center justify-center text-muted-foreground">
          ยังไม่มีข้อมูลสินค้า
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>สินค้าและหมวดหมู่</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid gap-6 md:grid-cols-2">
          {/* Left Column: Top 5 Products */}
          <div className="space-y-3">
            {topProducts.map((product, index) => (
              <div key={`${product.product_name}-${product.category}`} className="flex items-center gap-3">
                {/* Rank Number */}
                <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                  {index + 1}
                </div>

                {/* Product Info */}
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-medium text-foreground">{product.product_name}</span>
                    <Badge variant="outline" className="shrink-0 text-xs">
                      {product.category}
                    </Badge>
                  </div>
                  <div className="text-xs text-muted-foreground">{product.quantity_sold} ชิ้น</div>
                </div>

                {/* Revenue */}
                <div className="shrink-0 text-right">
                  <div className="font-semibold text-foreground">{formatBaht(product.total_revenue)}</div>
                </div>
              </div>
            ))}
          </div>

          {/* Right Column: Pie Chart + Legend */}
          <div className="flex flex-col items-center justify-center">
            {/* Pie Chart */}
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie
                  data={categoryData}
                  dataKey="total_revenue"
                  nameKey="category"
                  cx="50%"
                  cy="50%"
                  outerRadius={70}
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
                <Tooltip formatter={(value) => formatBaht(value as number)} contentStyle={CHART_TOOLTIP_STYLE} />
              </PieChart>
            </ResponsiveContainer>

            {/* Legend */}
            <div className="mt-4 flex flex-wrap justify-center gap-3">
              {categoryData.map((category, index) => (
                <div key={category.category} className="flex items-center gap-2">
                  <div
                    className="h-2 w-2 rounded-full"
                    style={{ backgroundColor: CHART_COLORS[index % CHART_COLORS.length] }}
                  />
                  <span className="text-xs text-muted-foreground">{category.category}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
