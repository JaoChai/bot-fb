import { useMemo, useState } from 'react';
import { Package } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { useProductStocks, useUpdateProductStock } from '@/hooks/useProductStock';

export function StockManagementCard() {
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
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-20 rounded-lg" />
            ))}
          </div>
        </CardContent>
      </Card>
    );
  }

  if (!products?.length) return null;

  const lastUpdated = useMemo(
    () =>
      products.reduce((latest, p) => {
        const d = new Date(p.updated_at);
        return d > latest ? d : latest;
      }, new Date(0)),
    [products],
  );

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2 text-base">
            <Package className="h-4 w-4" />
            สต็อกสินค้า
          </CardTitle>
          <span className="text-xs text-muted-foreground">
            อัพเดทล่าสุด:{' '}
            {lastUpdated.toLocaleDateString('th-TH', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
            })}
          </span>
        </div>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {products.map((product) => (
            <div
              key={product.slug}
              className="flex flex-col items-center gap-2 rounded-lg border p-3"
            >
              <span className="text-sm font-medium">{product.name}</span>
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
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
