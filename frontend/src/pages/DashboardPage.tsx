import { useAuthStore } from '@/stores/authStore';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Bot, MessageSquare, Zap, Users, Banknote, Plus, ShoppingCart, DollarSign } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { CostAnalytics } from '@/components/analytics/CostAnalytics';
import { OrdersAnalytics } from '@/components/analytics/OrdersAnalytics';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  BotOverviewCard,
  RecentActivityTimeline,
  DashboardSkeleton,
} from '@/components/dashboard';

export function DashboardPage() {
  const { user } = useAuthStore();
  const firstName = user?.name?.split(' ')[0] || 'User';

  const { data, isLoading, error } = useDashboardSummary();
  const { data: costData } = useCostAnalytics({ group_by: 'day' });
  const { data: orderData } = useOrderSummary();

  if (isLoading) {
    return (
      <div className="space-y-8">
        {/* Header */}
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            สวัสดี, {firstName}
          </h1>
          <p className="text-muted-foreground">
            นี่คือภาพรวมกิจกรรม Chatbot ของคุณ
          </p>
        </div>

        <Tabs defaultValue="overview" className="space-y-6">
          <TabsList>
            <TabsTrigger value="overview">ภาพรวม</TabsTrigger>
            <TabsTrigger value="costs">ค่าใช้จ่าย API</TabsTrigger>
            <TabsTrigger value="orders">ยอดขาย</TabsTrigger>
          </TabsList>
          <TabsContent value="overview">
            <DashboardSkeleton />
          </TabsContent>
        </Tabs>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-8">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            สวัสดี, {firstName}
          </h1>
          <p className="text-muted-foreground">
            นี่คือภาพรวมกิจกรรม Chatbot ของคุณ
          </p>
        </div>
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

  const hasBots = data && data.bots.length > 0;

  return (
    <div className="space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          สวัสดี, {firstName}
        </h1>
        <p className="text-muted-foreground">
          นี่คือภาพรวมกิจกรรม Chatbot ของคุณ
        </p>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview" className="space-y-6">
        <TabsList>
          <TabsTrigger value="overview">ภาพรวม</TabsTrigger>
          <TabsTrigger value="costs">ค่าใช้จ่าย API</TabsTrigger>
          <TabsTrigger value="orders">ยอดขาย</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          {/* Stats Grid */}
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <DashboardStatCard
              title="Bots ทั้งหมด"
              value={data?.summary.total_bots ?? 0}
              description={`${data?.summary.active_bots ?? 0} ทำงานอยู่`}
              icon={Bot}
            />
            <DashboardStatCard
              title="แชทที่ใช้งาน"
              value={data?.summary.active_conversations ?? 0}
              description={`จาก ${data?.summary.total_conversations ?? 0} ทั้งหมด`}
              icon={MessageSquare}
            />
            <DashboardStatCard
              title="ข้อความวันนี้"
              value={data?.summary.messages_today ?? 0}
              description="ข้อความที่รับส่งวันนี้"
              icon={Zap}
            />
            <DashboardStatCard
              title="รอมนุษย์ตอบ"
              value={data?.summary.handover_conversations ?? 0}
              description="การสนทนาที่ต้องดูแล"
              icon={Users}
              variant={
                (data?.summary.handover_conversations ?? 0) > 0
                  ? 'danger'
                  : 'default'
              }
            />
            <DashboardStatCard
              title="ค่าใช้จ่ายวันนี้"
              value={formatTHB(costData?.summary.today_cost ?? 0)}
              description={`เดือนนี้ ${formatTHB(costData?.summary.month_cost ?? 0)}`}
              icon={Banknote}
            />
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
          </div>

          {/* Bot Overview Cards */}
          {hasBots ? (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Bot ทั้งหมด</h2>
                <Button variant="outline" size="sm" asChild>
                  <Link to="/connections/add">
                    <Plus className="h-4 w-4 mr-1" />
                    สร้าง Bot ใหม่
                  </Link>
                </Button>
              </div>
              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {data.bots.map((bot) => (
                  <BotOverviewCard key={bot.id} bot={bot} />
                ))}
              </div>
            </div>
          ) : (
            <Card>
              <CardHeader>
                <CardTitle className="text-base">เริ่มต้นใช้งาน</CardTitle>
                <CardDescription>
                  สร้าง Bot แรกของคุณเพื่อเริ่มต้นรับข้อความจากลูกค้า
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-col items-center py-8">
                <Bot className="h-12 w-12 text-muted-foreground/50 mb-4" />
                <p className="text-sm text-muted-foreground mb-4 text-center">
                  ยังไม่มี Bot สร้าง Bot แรกเพื่อเชื่อมต่อกับ LINE หรือ Facebook
                </p>
                <Button asChild>
                  <Link to="/connections/add">
                    <Plus className="h-4 w-4 mr-2" />
                    สร้าง Bot แรก
                  </Link>
                </Button>
              </CardContent>
            </Card>
          )}

          {/* Recent Activity Timeline */}
          <RecentActivityTimeline activities={data?.recent_activity ?? []} />
        </TabsContent>

        <TabsContent value="costs">
          <CostAnalytics />
        </TabsContent>

        <TabsContent value="orders">
          <OrdersAnalytics />
        </TabsContent>
      </Tabs>
    </div>
  );
}
