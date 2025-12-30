import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Bot, MessageSquare, Zap, Brain, ArrowRight, CheckCircle2 } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { CostAnalytics } from '@/components/analytics/CostAnalytics';

const stats = [
  {
    title: 'Bots ทั้งหมด',
    value: '0',
    description: 'สร้าง Bot แรกเพื่อเริ่มต้น',
    icon: Bot,
    trend: null,
  },
  {
    title: 'แชทที่ใช้งาน',
    value: '0',
    description: 'ไม่มีแชทที่ใช้งานอยู่',
    icon: MessageSquare,
    trend: null,
  },
  {
    title: 'ข้อความวันนี้',
    value: '0',
    description: 'ยังไม่มีข้อความ',
    icon: Zap,
    trend: null,
  },
  {
    title: 'AI Responses',
    value: '0',
    description: 'ยังไม่มีการตอบกลับ AI',
    icon: Brain,
    trend: null,
  },
];

const quickStartSteps = [
  { step: 1, title: 'สร้างการเชื่อมต่อแรก', done: false },
  { step: 2, title: 'เชื่อมต่อ LINE หรือ Facebook', done: false },
  { step: 3, title: 'อัปโหลดฐานความรู้', done: false },
  { step: 4, title: 'เริ่มรับข้อความ', done: false },
];

export function DashboardPage() {
  const { user } = useAuthStore();
  const firstName = user?.name?.split(' ')[0] || 'User';

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
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          {/* Stats Grid */}
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {stats.map((stat) => (
              <Card key={stat.title} className="border">
                <CardHeader className="pb-2">
                  <div className="flex items-center justify-between">
                    <CardDescription className="text-sm font-medium">
                      {stat.title}
                    </CardDescription>
                    <stat.icon className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <CardTitle className="text-2xl font-semibold">
                    {stat.value}
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-xs text-muted-foreground">
                    {stat.description}
                  </p>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* Quick Actions */}
          <div className="grid gap-6 md:grid-cols-2">
            {/* Quick Start Guide */}
            <Card className="border">
              <CardHeader>
                <CardTitle className="text-base font-semibold">
                  เริ่มต้นใช้งาน
                </CardTitle>
                <CardDescription>
                  ทำตามขั้นตอนเหล่านี้เพื่อเริ่มต้น
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-2">
                {quickStartSteps.map((item) => (
                  <div
                    key={item.step}
                    className="flex items-center gap-3 p-2 rounded-md hover:bg-accent transition-colors"
                  >
                    <div className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium ${
                      item.done
                        ? 'bg-foreground text-background'
                        : item.step === 1
                        ? 'bg-foreground text-background'
                        : 'bg-muted text-muted-foreground'
                    }`}>
                      {item.done ? <CheckCircle2 className="h-4 w-4" /> : item.step}
                    </div>
                    <span className={`text-sm ${item.done ? 'line-through text-muted-foreground' : ''}`}>
                      {item.title}
                    </span>
                  </div>
                ))}
                <Button className="w-full mt-4" asChild>
                  <Link to="/connections/add">
                    สร้างการเชื่อมต่อแรก
                    <ArrowRight className="h-4 w-4 ml-2" />
                  </Link>
                </Button>
              </CardContent>
            </Card>

            {/* Recent Activity */}
            <Card className="border">
              <CardHeader>
                <CardTitle className="text-base font-semibold">
                  กิจกรรมล่าสุด
                </CardTitle>
                <CardDescription>
                  การสนทนาและกิจกรรมล่าสุดของคุณ
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex flex-col items-center justify-center py-8 text-center">
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted mb-4">
                    <MessageSquare className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <p className="text-sm text-muted-foreground mb-4">
                    ยังไม่มีกิจกรรมล่าสุด
                  </p>
                  <Button variant="outline" size="sm" asChild>
                    <Link to="/bots">
                      ดูการเชื่อมต่อทั้งหมด
                    </Link>
                  </Button>
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="costs">
          <CostAnalytics />
        </TabsContent>
      </Tabs>
    </div>
  );
}
