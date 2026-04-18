import { useState } from 'react';
import { Package } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Skeleton } from '@/components/ui/skeleton';
import { Panel } from '@/components/common';
import { useProductStocks, useUpdateProductStock } from '@/hooks/useProductStock';

export function CompactStockToggle() {
  const { data: products, isLoading } = useProductStocks();
  const { mutate: updateStock } = useUpdateProductStock();
  const [pendingSlug, setPendingSlug] = useState<string | null>(null);

  if (isLoading) {
    return (
      <Panel title="สต็อกสินค้า" icon={Package}>
        <div className="space-y-2">
          {Array.from({ length: 2 }).map((_, i) => (
            <Skeleton key={i} className="h-12 rounded-lg" />
          ))}
        </div>
      </Panel>
    );
  }

  if (!products?.length) return null;

  return (
    <Panel title="สต็อกสินค้า" icon={Package}>
      <div className="space-y-2">
        {products.map((product) => (
          <div
            key={product.slug}
            className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/40"
          >
            <p className="flex-1 text-sm font-medium">{product.name}</p>
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
    </Panel>
  );
}
