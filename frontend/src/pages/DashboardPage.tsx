import { useMemo, Suspense } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote, Crown } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/connections';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  AlertStrip,
  DashboardStatCard,
  DashboardSkeleton,
  BotStatusList,
  CompactStockToggle,
  RecentOrdersPreview,
} from '@/components/dashboard';
import { lazyWithRetryNamed } from '@/lib/lazyWithRetry';
import { useAuthStore } from '@/stores/authStore';

// Charts pull the 109 KB-gzip recharts (vendor-charts) chunk. Lazy-load them so
// the metric cards above paint before recharts is fetched.
const DualAxisChart = lazyWithRetryNamed(
  () => import('@/components/dashboard/DualAxisChart'),
  'DualAxisChart',
);
const ProductsSummaryCard = lazyWithRetryNamed(
  () => import('@/components/dashboard/ProductsSummaryCard'),
  'ProductsSummaryCard',
);

function calcTrend(today: number, yesterday: number) {
  if (yesterday <= 0) return undefined;
  const pct = ((today - yesterday) / yesterday) * 100;
  return {
    value: Math.abs(Math.round(pct)),
    direction: pct > 0 ? ('up' as const) : pct < 0 ? ('down' as const) : ('stable' as const),
  };
}

export function DashboardPage() {
  const { user } = useAuthStore();
  const { data, isLoading, error } = useDashboardSummary();
  const { data: costData } = useCostAnalytics({ group_by: 'day' });
  const { data: orderData } = useOrderSummary();
  const { data: productsData } = useOrdersByProduct({});

  const today = useMemo(
    () =>
      new Date().toLocaleDateString('th-TH', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }),
    [],
  );

  const header = (
    <PageHeader
      title="แดชบอร์ด"
      description="ภาพรวมธุรกิจของคุณ"
      meta={today}
    />
  );

  if (isLoading) {
    return (
      <div className="space-y-6">
        {header}
        <DashboardSkeleton />
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-6">
        {header}
        <div className="rounded-lg border bg-card p-8 text-center">
          <p className="text-destructive font-medium">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
          <Button variant="outline" className="mt-4" onClick={() => window.location.reload()}>
            ลองใหม่
          </Button>
        </div>
      </div>
    );
  }

  const revTrend = calcTrend(
    orderData?.summary?.today_revenue ?? 0,
    orderData?.summary?.yesterday_revenue ?? 0,
  );
  const msgTrend = calcTrend(
    data?.summary.messages_today ?? 0,
    data?.summary.messages_yesterday ?? 0,
  );

  const vipCustomers = data?.summary.vip_customers ?? 0;
  const monthDescription = (
    <span className="inline-flex items-center gap-1">
      {orderData?.summary?.this_month_orders ?? 0} ออเดอร์
      {vipCustomers > 0 && (
        <>
          {' · '}
          <Crown className="size-3 text-amber-500" aria-hidden="true" />
          VIP {vipCustomers} คน ({formatBaht(data?.summary.vip_total_spent ?? 0)})
        </>
      )}
    </span>
  );

  return (
    <div className="space-y-6">
      {header}

      <AlertStrip bots={data?.bots ?? []} />

      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <DashboardStatCard
          title="ยอดขายวันนี้"
          value={formatBaht(orderData?.summary?.today_revenue ?? 0)}
          description={`${orderData?.summary?.today_orders ?? 0} ออเดอร์`}
          icon={ShoppingCart}
          trend={revTrend}
        />
        <DashboardStatCard
          title="ยอดขายเดือนนี้"
          value={formatBaht(orderData?.summary?.this_month_revenue ?? 0)}
          description={monthDescription}
          icon={DollarSign}
        />
        <DashboardStatCard
          title="ข้อความวันนี้"
          value={data?.summary.messages_today ?? 0}
          description={`จาก ${data?.summary.total_bots ?? 0} บอท`}
          icon={MessageSquare}
          trend={msgTrend}
        />
        <DashboardStatCard
          title="ค่า API วันนี้"
          value={formatTHB(costData?.summary.today_cost ?? 0)}
          description={`เดือน ${formatTHB(costData?.summary.month_cost ?? 0)} · เฉลี่ย ${formatTHB(costData?.summary.avg_cost_per_response ?? 0)}/ตอบ`}
          icon={Banknote}
        />
      </div>

      <Suspense
        fallback={
          <div className="rounded-xl border bg-card p-6 shadow-sm">
            <Skeleton className="mb-4 h-5 w-48" />
            <Skeleton className="h-[300px] w-full rounded-lg" />
          </div>
        }
      >
        <DualAxisChart
          orderTimeSeries={orderData?.time_series ?? []}
          costTimeSeries={costData?.time_series ?? []}
        />
      </Suspense>

      <div className="grid gap-4 lg:grid-cols-2">
        {productsData && productsData.length > 0 && (
          <Suspense
            fallback={
              <div className="rounded-xl border bg-card p-6 shadow-sm">
                <Skeleton className="mb-4 h-5 w-36" />
                <div className="grid gap-6 md:grid-cols-2">
                  <div className="space-y-3">
                    {[...Array(5)].map((_, i) => (
                      <div key={i} className="flex items-center gap-3">
                        <Skeleton className="size-7 rounded-full" />
                        <Skeleton className="h-4 flex-1" />
                        <Skeleton className="h-4 w-16" />
                      </div>
                    ))}
                  </div>
                  <Skeleton className="h-[200px] w-full rounded-lg" />
                </div>
              </div>
            }
          >
            <ProductsSummaryCard products={productsData} />
          </Suspense>
        )}
        <RecentOrdersPreview />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <BotStatusList bots={data?.bots ?? []} />
        {user?.role === 'owner' && <CompactStockToggle />}
      </div>
    </div>
  );
}
