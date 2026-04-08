import { useState } from 'react';
import { Package } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Skeleton } from '@/components/ui/skeleton';
import { useProductStocks, useUpdateProductStock } from '@/hooks/useProductStock';

export function CompactStockToggle() {
  const { data: products, isLoading } = useProductStocks();
  const { mutate: updateStock } = useUpdateProductStock();
  const [pendingSlug, setPendingSlug] = useState<string | null>(null);

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Package className="h-4 w-4" />
            สต็อกสินค้า
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-2">
            {Array.from({ length: 2 }).map((_, i) => (
              <Skeleton key={i} className="h-10 rounded-lg" />
            ))}
          </div>
        </CardContent>
      </Card>
    );
  }

  if (!products?.length) return null;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Package className="h-4 w-4" />
          สต็อกสินค้า
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="space-y-2">
          {products.map((product) => (
            <div
              key={product.slug}
              className="flex items-center justify-between gap-3 px-2 py-2"
            >
              <div className="flex-1">
                <p className="text-sm font-medium">{product.name}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={product.in_stock ? 'default' : 'destructive'}>
                  {product.in_stock ? 'มีสินค้า' : 'หมด'}
                </Badge>
                <Switch
                  checked={product.in_stock}
                  disabled={pendingSlug === product.slug}
                  onCheckedChange={(checked) => {
                    setPendingSlug(product.slug);
                    updateStock(
                      { slug: product.slug, in_stock: checked },
                      { onSettled: () => setPendingSlug(null) },
                    );
                  }}
                />
              </div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
