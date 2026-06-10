import { useMemo, Suspense } from 'react';
import { PageHeader } from '@/components/connections';
import { lazyWithRetryNamed } from '@/lib/lazyWithRetry';

// OrdersAnalytics pulls recharts (vendor-charts). Lazy-load so the orders route
// chunk stays light and recharts loads as a separate async chunk.
const OrdersAnalytics = lazyWithRetryNamed(
  () => import('@/components/analytics/OrdersAnalytics'),
  'OrdersAnalytics',
);

export function OrdersPage() {
  const today = useMemo(
    () =>
      new Date().toLocaleDateString('th-TH', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }),
    [],
  );

  return (
    <div className="space-y-6">
      <PageHeader title="ออเดอร์" meta={today} />
      <Suspense
        fallback={
          <div className="flex items-center justify-center py-12">
            <div className="text-muted-foreground">กำลังโหลดข้อมูล...</div>
          </div>
        }
      >
        <OrdersAnalytics />
      </Suspense>
    </div>
  );
}
