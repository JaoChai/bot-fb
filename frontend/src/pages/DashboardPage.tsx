import { useMemo } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  RecentActivityTimeline,
  DashboardSkeleton,
  BotStatusList,
  BusinessHealthBar,
  DualAxisChart,
  CompactCostBreakdown,
  CompactStockToggle,
  RecentOrdersPreview,
  ProductsSummaryCard,
} from '@/components/dashboard';
import { useAuthStore } from '@/stores/authStore';

function calcTrend(today: number, yesterday: number) {
  if (yesterday <= 0) return undefined;
  const pct = ((today - yesterday) / yesterday) * 100;
  return {
    value: Math.abs(Math.round(pct)),
    direction: pct > 0 ? 'up' as const : pct < 0 ? 'down' as const : 'stable' as const,
  };
}

function DashboardHeader({ today }: { today: string }) {
  return (
    <div className="flex items-center justify-between">
      <h1 className="text-2xl font-semibold tracking-tight">แดชบอร์ด</h1>
      <span className="text-sm text-muted-foreground">{today}</span>
    </div>
  );
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

  if (isLoading) {
    return (
      <div className="space-y-6">
        <DashboardHeader today={today} />
        <DashboardSkeleton />
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-6">
        <DashboardHeader today={today} />
        <Card className="border-destructive">
          <CardContent className="py-8 text-center">
            <p className="text-destructive">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            <Button
              variant="outline"
              className="mt-4"
              onClick={() => window.location.reload()}
            >
              ลองใหม่
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  const activities = data?.recent_activity ?? [];
  const revTrend = calcTrend(orderData?.summary?.today_revenue ?? 0, orderData?.summary?.yesterday_revenue ?? 0);
  const msgTrend = calcTrend(data?.summary.messages_today ?? 0, data?.summary.messages_yesterday ?? 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <DashboardHeader today={today} />

      {/* Section 1: Business Health Bar */}
      <BusinessHealthBar
        bots={data?.bots ?? []}
        alerts={data?.alerts ?? { handover_conversations: [] }}
      />

      {/* Section 2: Key Metrics (4 cards) */}
      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <DashboardStatCard
          title="ยอดขายวันนี้"
          value={formatBaht(orderData?.summary?.today_revenue ?? 0)}
          description={`${orderData?.summary?.today_orders ?? 0} ออเดอร์`}
          icon={ShoppingCart}
          trend={revTrend}
          className="border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-950/20"
        />
        <DashboardStatCard
          title="ยอดขายเดือนนี้"
          value={formatBaht(orderData?.summary?.this_month_revenue ?? 0)}
          description={`${orderData?.summary?.this_month_orders ?? 0} ออเดอร์`}
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
          description={`เดือน ${formatTHB(costData?.summary.month_cost ?? 0)}`}
          icon={Banknote}
        />
      </div>

      {/* Section 3: Dual Axis Chart (Revenue + Cost) */}
      <DualAxisChart
        orderTimeSeries={orderData?.time_series ?? []}
        costTimeSeries={costData?.time_series ?? []}
        vipCustomers={data?.summary.vip_customers}
        vipTotalSpent={data?.summary.vip_total_spent}
      />

      {/* Section 4: Bots + Products (2 columns) */}
      <div className="grid gap-4 lg:grid-cols-2">
        <BotStatusList bots={data?.bots ?? []} />
        {productsData && productsData.length > 0 && (
          <ProductsSummaryCard products={productsData} />
        )}
      </div>

      {/* Section 5: Cost Breakdown + Stock/Activity (2 columns) */}
      <div className="grid gap-4 lg:grid-cols-2">
        {costData?.summary && (
          <CompactCostBreakdown summary={costData.summary} />
        )}
        <div className="space-y-4">
          {user?.role === 'owner' && <CompactStockToggle />}
          <RecentActivityTimeline activities={activities.slice(0, 3)} />
        </div>
      </div>

      {/* Section 6: Recent Orders Preview */}
      <RecentOrdersPreview />
    </div>
  );
}
