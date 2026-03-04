import { useMemo } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { formatBaht } from '@/lib/currency';
import type { ProductOrderBreakdown } from '@/types/api';

interface TopProductsListProps {
  products: ProductOrderBreakdown[];
  limit?: number;
}

export function TopProductsList({ products, limit = 5 }: TopProductsListProps) {
  const topProducts = useMemo(
    () => [...products].sort((a, b) => b.total_revenue - a.total_revenue).slice(0, limit),
    [products, limit],
  );

  return (
    <Card className="h-full">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">สินค้าขายดี</CardTitle>
      </CardHeader>
      <CardContent>
        {topProducts.length === 0 ? (
          <div className="flex items-center justify-center py-8 text-sm text-muted-foreground">
            ยังไม่มีข้อมูลสินค้า
          </div>
        ) : (
          <div className="space-y-3">
            {topProducts.map((product, index) => (
              <div key={product.product_name} className="flex items-center gap-3">
                <span className="text-sm font-bold text-muted-foreground w-5 text-right">
                  {index + 1}.
                </span>
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{product.product_name}</p>
                  <div className="flex items-center gap-2 mt-0.5">
                    <Badge variant="outline" className="text-xs px-1.5 py-0">
                      {product.category || 'อื่นๆ'}
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {product.quantity_sold} ชิ้น
                    </span>
                  </div>
                </div>
                <span className="text-sm font-medium text-right flex-shrink-0">
                  {formatBaht(product.total_revenue)}
                </span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
