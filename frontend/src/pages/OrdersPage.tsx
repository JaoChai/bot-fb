import { useMemo } from 'react';
import { OrdersAnalytics } from '@/components/analytics/OrdersAnalytics';

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
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold tracking-tight">ออเดอร์</h1>
        <span className="text-sm text-muted-foreground">{today}</span>
      </div>
      <OrdersAnalytics />
    </div>
  );
}
