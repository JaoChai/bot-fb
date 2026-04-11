import { useState } from 'react';
import { Package } from 'lucide-react';
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
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="mb-4 flex items-center gap-2 text-base font-semibold">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/40">
            <Package className="h-4 w-4 text-violet-600 dark:text-violet-400" />
          </div>
          สต็อกสินค้า
        </h3>
        <div className="space-y-2">
          {Array.from({ length: 2 }).map((_, i) => (
            <Skeleton key={i} className="h-12 rounded-lg" />
          ))}
        </div>
      </div>
    );
  }

  if (!products?.length) return null;

  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 flex items-center gap-2 text-base font-semibold">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 dark:bg-violet-950/40">
          <Package className="h-4 w-4 text-violet-600 dark:text-violet-400" />
        </div>
        สต็อกสินค้า
      </h3>
      <div className="space-y-2">
        {products.map((product) => (
          <div
            key={product.slug}
            className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 transition-colors hover:bg-accent/30"
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
    </div>
  );
}
