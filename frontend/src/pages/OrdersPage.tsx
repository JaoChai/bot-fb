import { useMemo } from 'react';
import { PageHeader } from '@/components/connections';
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
      <PageHeader title="ออเดอร์" meta={today} />
      <OrdersAnalytics />
    </div>
  );
}
