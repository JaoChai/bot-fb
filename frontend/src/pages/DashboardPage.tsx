import { useAuthStore } from '@/stores/authStore';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Bot, MessageSquare, Zap, Brain, ArrowRight, CheckCircle2, DollarSign } from 'lucide-react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { CostAnalytics } from '@/components/analytics/CostAnalytics';

const stats = [
  {
    title: 'Bots ทั้งหมด',
    value: '0',
    description: 'สร้าง Bot แรกเพื่อเริ่มต้น',
    icon: Bot,
    gradient: 'from-indigo-500 to-indigo-600',
    iconBg: 'bg-indigo-500/10',
    iconColor: 'text-indigo-600',
  },
  {
    title: 'แชทที่ใช้งาน',
    value: '0',
    description: 'ไม่มีแชทที่ใช้งานอยู่',
    icon: MessageSquare,
    gradient: 'from-emerald-500 to-emerald-600',
    iconBg: 'bg-emerald-500/10',
    iconColor: 'text-emerald-600',
  },
  {
    title: 'ข้อความวันนี้',
    value: '0',
    description: 'ยังไม่มีข้อความ',
    icon: Zap,
    gradient: 'from-amber-500 to-orange-500',
    iconBg: 'bg-amber-500/10',
    iconColor: 'text-amber-600',
  },
  {
    title: 'AI Responses',
    value: '0',
    description: 'ยังไม่มีการตอบกลับ AI',
    icon: Brain,
    gradient: 'from-purple-500 to-purple-600',
    iconBg: 'bg-purple-500/10',
    iconColor: 'text-purple-600',
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
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold tracking-tight">
          สวัสดี, {firstName}
        </h1>
        <p className="text-muted-foreground">
          นี่คือภาพรวมกิจกรรม Chatbot ของคุณ
        </p>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview" className="space-y-6">
        <TabsList>
          <TabsTrigger value="overview" className="gap-2">
            <Brain className="h-4 w-4" />
            ภาพรวม
          </TabsTrigger>
          <TabsTrigger value="costs" className="gap-2">
            <DollarSign className="h-4 w-4" />
            ค่าใช้จ่าย API
          </TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          {/* Stats Grid */}
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {stats.map((stat) => (
              <Card key={stat.title} className="relative overflow-hidden hover:shadow-md cursor-pointer group">
                <CardHeader className="pb-2">
                  <div className="flex items-center justify-between">
                    <CardDescription className="text-sm font-medium">{stat.title}</CardDescription>
                    <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${stat.iconBg}`}>
                      <stat.icon className={`h-5 w-5 ${stat.iconColor}`} />
                    </div>
                  </div>
                  <CardTitle className="text-3xl font-bold">{stat.value}</CardTitle>
                </CardHeader>
                <CardContent>
                  <p className="text-xs text-muted-foreground">
                    {stat.description}
                  </p>
                </CardContent>
                {/* Gradient accent */}
                <div className={`absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r ${stat.gradient} opacity-0 group-hover:opacity-100 transition-opacity`} />
              </Card>
            ))}
          </div>

          {/* Quick Actions */}
          <div className="grid gap-6 md:grid-cols-2">
            {/* Quick Start Guide */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Zap className="h-5 w-5 text-warning" />
                  เริ่มต้นใช้งาน
                </CardTitle>
                <CardDescription>
                  ทำตามขั้นตอนเหล่านี้เพื่อเริ่มต้น
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                {quickStartSteps.map((item) => (
                  <div
                    key={item.step}
                    className="flex items-center gap-3 p-2 rounded-lg hover:bg-accent/50 transition-colors"
                  >
                    <div className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium ${
                      item.done
                        ? 'bg-emerald-100 text-emerald-700'
                        : item.step === 1
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground'
                    }`}>
                      {item.done ? <CheckCircle2 className="h-4 w-4" /> : item.step}
                    </div>
                    <span className={item.done ? 'line-through text-muted-foreground' : ''}>
                      {item.title}
                    </span>
                  </div>
                ))}
                <Button variant="cta" className="w-full mt-4" asChild>
                  <Link to="/connections/add">
                    สร้างการเชื่อมต่อแรก
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>

            {/* Recent Activity */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MessageSquare className="h-5 w-5 text-primary" />
                  กิจกรรมล่าสุด
                </CardTitle>
                <CardDescription>
                  การสนทนาและกิจกรรมล่าสุดของคุณ
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex flex-col items-center justify-center py-8 text-center">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-muted mb-4">
                    <MessageSquare className="h-8 w-8 text-muted-foreground" />
                  </div>
                  <p className="text-sm text-muted-foreground mb-4">
                    ยังไม่มีกิจกรรมล่าสุด
                  </p>
                  <Button variant="outline" asChild>
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
