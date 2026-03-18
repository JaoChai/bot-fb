import { useMemo, useState } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { OrdersAnalytics } from '@/components/analytics/OrdersAnalytics';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  RecentActivityTimeline,
  DashboardSkeleton,
  BotStatusList,
  RevenueChart,
  TopProductsList,
  CategoryPieChart,
  CostSummaryCollapsible,
  CollapsibleCard,
  StockManagementCard,
} from '@/components/dashboard';
import { useAuthStore } from '@/stores/authStore';

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

  const [activityExpanded, setActivityExpanded] = useState(false);

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
  const displayActivities = activityExpanded ? activities : activities.slice(0, 5);

  return (
    <div className="space-y-6">
      {/* Section 0: Header */}
      <DashboardHeader today={today} />

      {/* Section 1: Stock Management (Owner only) */}
      {user?.role === 'owner' && <StockManagementCard />}

      {/* Section 2: Key Metrics (4 cards) */}
      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <DashboardStatCard
          title="ยอดขายวันนี้"
          value={formatBaht(orderData?.summary?.today_revenue ?? 0)}
          description={`${orderData?.summary?.today_orders ?? 0} ออเดอร์`}
          icon={ShoppingCart}
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
        />
        <DashboardStatCard
          title="ค่า API วันนี้"
          value={formatTHB(costData?.summary.today_cost ?? 0)}
          description={`เดือน ${formatTHB(costData?.summary.month_cost ?? 0)}`}
          icon={Banknote}
        />
      </div>

      {/* Section 3: Revenue Chart (2/3) + Bot Status (1/3) */}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <RevenueChart
            timeSeries={orderData?.time_series ?? []}
            vipCustomers={data?.summary.vip_customers}
            vipTotalSpent={data?.summary.vip_total_spent}
          />
        </div>
        <div className="lg:col-span-1">
          <BotStatusList bots={data?.bots ?? []} />
        </div>
      </div>

      {/* Section 4: Sales + Products (2 col) */}
      {productsData && productsData.length > 0 && (
        <div className="grid gap-4 md:grid-cols-2">
          <TopProductsList products={productsData} />
          <CategoryPieChart products={productsData} />
        </div>
      )}

      {/* Section 5: Cost Breakdown (collapsible) */}
      <CostSummaryCollapsible monthCost={costData?.summary.month_cost} />

      {/* Section 6: Orders Detail (collapsible) */}
      <CollapsibleCard icon={ShoppingCart} title="รายละเอียดยอดขาย">
        <OrdersAnalytics />
      </CollapsibleCard>

      {/* Section 7: Recent Activity */}
      <RecentActivityTimeline activities={displayActivities} />
      {activities.length > 5 && (
        <div className="flex justify-center -mt-4">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setActivityExpanded(!activityExpanded)}
          >
            {activityExpanded ? 'ย่อ' : `ดูทั้งหมด (${activities.length})`}
          </Button>
        </div>
      )}
    </div>
  );
}
