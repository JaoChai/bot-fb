import { Head, router, Link, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import { cn } from '@/Lib/utils';
import {
  Bot,
  MessageSquare,
  DollarSign,
  TrendingUp,
  TrendingDown,
  Calendar,
  ChevronRight,
  Activity,
  Minus,
} from 'lucide-react';
import type { SharedProps, ChannelType } from '@/types';

// Dashboard Page Props Interface
interface DashboardPageProps extends SharedProps {
  stats: {
    total_bots: number;
    total_conversations: number;
    total_messages: number;
    total_ai_cost: number;
    conversation_change: number; // % change from previous period
    message_change: number;
    cost_change: number;
  };
  conversationTrend: Array<{
    date: string;
    count: number;
  }>;
  costBreakdown: Array<{
    model: string;
    cost: number;
    percentage: number;
  }>;
  recentConversations: Array<{
    id: number;
    customer_name: string;
    last_message: string;
    channel_type: ChannelType;
    updated_at: string;
  }>;
  filters: {
    start_date?: string;
    end_date?: string;
  };
}

// Stats Card Component
interface StatsCardProps {
  title: string;
  value: string | number;
  change?: number;
  icon: React.ElementType;
  isCurrency?: boolean;
}

function StatsCard({ title, value, change, icon: Icon, isCurrency = false }: StatsCardProps) {
  const formattedValue = useMemo(() => {
    if (isCurrency && typeof value === 'number') {
      return new Intl.NumberFormat('th-TH', {
        style: 'currency',
        currency: 'THB',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value);
    }
    if (typeof value === 'number') {
      return new Intl.NumberFormat('th-TH').format(value);
    }
    return value;
  }, [value, isCurrency]);

  const changeIcon = useMemo(() => {
    if (change === undefined || change === 0) {
      return <Minus className="h-3 w-3" />;
    }
    return change > 0 ? (
      <TrendingUp className="h-3 w-3" />
    ) : (
      <TrendingDown className="h-3 w-3" />
    );
  }, [change]);

  const changeColor = useMemo(() => {
    if (change === undefined || change === 0) return 'text-muted-foreground';
    // For cost, decrease is good (green), increase is bad (red)
    if (isCurrency) {
      return change > 0 ? 'text-red-500' : 'text-green-500';
    }
    // For other metrics, increase is good (green), decrease is bad (red)
    return change > 0 ? 'text-green-500' : 'text-red-500';
  }, [change, isCurrency]);

  return (
    <Card className="py-4">
      <CardContent className="p-0 px-4">
        <div className="flex items-center justify-between">
          <div className="space-y-1">
            <p className="text-sm text-muted-foreground">{title}</p>
            <p className="text-2xl font-bold tracking-tight">{formattedValue}</p>
            {change !== undefined && (
              <div className={cn('flex items-center gap-1 text-xs', changeColor)}>
                {changeIcon}
                <span>{Math.abs(change).toFixed(1)}% จากช่วงก่อนหน้า</span>
              </div>
            )}
          </div>
          <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted">
            <Icon className="h-6 w-6 text-foreground" />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

// Simple Bar Chart Component for Conversation Trend
interface ConversationTrendChartProps {
  data: Array<{ date: string; count: number }>;
}

function ConversationTrendChart({ data }: ConversationTrendChartProps) {
  const maxCount = useMemo(() => Math.max(...data.map((d) => d.count), 1), [data]);

  if (data.length === 0) {
    return (
      <div className="flex h-48 items-center justify-center text-muted-foreground">
        ไม่มีข้อมูลในช่วงเวลานี้
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex h-48 items-end gap-1">
        {data.map((item, index) => {
          const height = (item.count / maxCount) * 100;
          return (
            <div
              key={index}
              className="group relative flex flex-1 flex-col items-center"
            >
              <div
                className="w-full rounded-t bg-foreground transition-all duration-200 hover:bg-foreground/80"
                style={{ height: `${Math.max(height, 4)}%` }}
              />
              {/* Tooltip */}
              <div className="absolute bottom-full mb-2 hidden rounded bg-foreground px-2 py-1 text-xs text-background group-hover:block">
                {item.count} การสนทนา
              </div>
            </div>
          );
        })}
      </div>
      {/* X-axis labels */}
      <div className="flex justify-between text-xs text-muted-foreground">
        {data.length > 0 && (
          <>
            <span>{formatDate(data[0].date)}</span>
            {data.length > 1 && <span>{formatDate(data[data.length - 1].date)}</span>}
          </>
        )}
      </div>
    </div>
  );
}

// Cost Breakdown Component
interface CostBreakdownProps {
  data: Array<{ model: string; cost: number; percentage: number }>;
}

function CostBreakdown({ data }: CostBreakdownProps) {
  if (data.length === 0) {
    return (
      <div className="flex h-48 items-center justify-center text-muted-foreground">
        ไม่มีข้อมูลค่าใช้จ่ายในช่วงเวลานี้
      </div>
    );
  }

  const totalCost = data.reduce((sum, item) => sum + item.cost, 0);

  return (
    <div className="space-y-4">
      {/* Progress bars */}
      <div className="space-y-3">
        {data.map((item, index) => (
          <div key={index} className="space-y-1.5">
            <div className="flex items-center justify-between text-sm">
              <span className="truncate font-medium">{item.model}</span>
              <span className="text-muted-foreground">
                {new Intl.NumberFormat('th-TH', {
                  style: 'currency',
                  currency: 'THB',
                }).format(item.cost)}{' '}
                ({item.percentage.toFixed(1)}%)
              </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div
                className="h-full rounded-full bg-foreground transition-all duration-300"
                style={{ width: `${item.percentage}%` }}
              />
            </div>
          </div>
        ))}
      </div>

      {/* Total */}
      <div className="border-t pt-3">
        <div className="flex items-center justify-between font-medium">
          <span>รวมทั้งหมด</span>
          <span>
            {new Intl.NumberFormat('th-TH', {
              style: 'currency',
              currency: 'THB',
            }).format(totalCost)}
          </span>
        </div>
      </div>
    </div>
  );
}

// Recent Conversations Component
interface RecentConversationsProps {
  conversations: Array<{
    id: number;
    customer_name: string;
    last_message: string;
    channel_type: ChannelType;
    updated_at: string;
  }>;
}

function RecentConversations({ conversations }: RecentConversationsProps) {
  if (conversations.length === 0) {
    return (
      <div className="flex h-48 flex-col items-center justify-center gap-2 text-muted-foreground">
        <MessageSquare className="h-8 w-8" />
        <span>ยังไม่มีการสนทนา</span>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      {conversations.map((conversation) => (
        <Link
          key={conversation.id}
          href={`/chat?conversation=${conversation.id}`}
          className="group flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-accent cursor-pointer"
        >
          <ChannelIcon channel={conversation.channel_type} className="h-8 w-8 shrink-0" />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <span className="truncate font-medium">{conversation.customer_name}</span>
              <Badge variant="outline" className="text-xs">
                {conversation.channel_type}
              </Badge>
            </div>
            <p className="truncate text-sm text-muted-foreground">
              {conversation.last_message || 'ไม่มีข้อความ'}
            </p>
            <p className="text-xs text-muted-foreground">{formatRelativeTime(conversation.updated_at)}</p>
          </div>
          <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-1" />
        </Link>
      ))}
    </div>
  );
}

// Helper functions
function formatDate(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

function formatRelativeTime(dateString: string): string {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

  if (diffMins < 1) return 'เมื่อสักครู่';
  if (diffMins < 60) return `${diffMins} นาทีที่แล้ว`;
  if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
  if (diffDays < 7) return `${diffDays} วันที่แล้ว`;

  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function getDefaultDateRange() {
  const end = new Date();
  const start = new Date();
  start.setDate(start.getDate() - 30);

  return {
    start_date: start.toISOString().split('T')[0],
    end_date: end.toISOString().split('T')[0],
  };
}

export default function Dashboard() {
  const props = usePage<DashboardPageProps>().props;
  const { auth, flash } = props;

  // Check if data is available (from controller) or use defaults
  const stats = props.stats ?? {
    total_bots: 0,
    total_conversations: 0,
    total_messages: 0,
    total_ai_cost: 0,
    conversation_change: 0,
    message_change: 0,
    cost_change: 0,
  };

  const conversationTrend = props.conversationTrend ?? [];
  const costBreakdown = props.costBreakdown ?? [];
  const recentConversations = props.recentConversations ?? [];
  const filters = props.filters ?? getDefaultDateRange();

  // Date filter state
  const [startDate, setStartDate] = useState(filters.start_date ?? getDefaultDateRange().start_date);
  const [endDate, setEndDate] = useState(filters.end_date ?? getDefaultDateRange().end_date);

  // Handle date filter change
  const handleDateFilterApply = () => {
    router.get(
      '/dashboard',
      {
        start_date: startDate,
        end_date: endDate,
      },
      {
        preserveState: true,
        preserveScroll: true,
      }
    );
  };

  return (
    <AuthenticatedLayout header="แดชบอร์ด">
      <Head title="แดชบอร์ด" />

      <div className="space-y-6">
        {/* Header with Date Range Picker */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">แดชบอร์ด</h1>
            <p className="text-muted-foreground">ภาพรวมการใช้งานระบบ</p>
          </div>

          {/* Date Range Picker */}
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex items-center gap-2">
              <Calendar className="h-4 w-4 text-muted-foreground" />
              <Input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="w-36"
              />
              <span className="text-muted-foreground">ถึง</span>
              <Input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="w-36"
              />
            </div>
            <Button onClick={handleDateFilterApply} size="sm">
              กรอง
            </Button>
          </div>
        </div>

        {/* Stats Cards Grid */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatsCard
            title="บอททั้งหมด"
            value={stats.total_bots}
            icon={Bot}
          />
          <StatsCard
            title="การสนทนาทั้งหมด"
            value={stats.total_conversations}
            change={stats.conversation_change}
            icon={MessageSquare}
          />
          <StatsCard
            title="ข้อความทั้งหมด"
            value={stats.total_messages}
            change={stats.message_change}
            icon={Activity}
          />
          <StatsCard
            title="ค่าใช้จ่าย AI"
            value={stats.total_ai_cost}
            change={stats.cost_change}
            icon={DollarSign}
            isCurrency
          />
        </div>

        {/* Charts Section */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Conversation Trend Chart */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">แนวโน้มการสนทนา</CardTitle>
              <CardDescription>จำนวนการสนทนาในช่วง 30 วันที่ผ่านมา</CardDescription>
            </CardHeader>
            <CardContent>
              <ConversationTrendChart data={conversationTrend} />
            </CardContent>
          </Card>

          {/* Cost Breakdown Chart */}
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">ค่าใช้จ่ายตามโมเดล</CardTitle>
              <CardDescription>สัดส่วนค่าใช้จ่าย AI แยกตามโมเดลที่ใช้</CardDescription>
            </CardHeader>
            <CardContent>
              <CostBreakdown data={costBreakdown} />
            </CardContent>
          </Card>
        </div>

        {/* Recent Activity */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <div>
              <CardTitle className="text-base">การสนทนาล่าสุด</CardTitle>
              <CardDescription>การสนทนาที่มีการอัปเดตล่าสุด</CardDescription>
            </div>
            <Button variant="outline" size="sm" asChild>
              <Link href="/chat">ดูทั้งหมด</Link>
            </Button>
          </CardHeader>
          <CardContent>
            <RecentConversations conversations={recentConversations} />
          </CardContent>
        </Card>
      </div>
    </AuthenticatedLayout>
  );
}
