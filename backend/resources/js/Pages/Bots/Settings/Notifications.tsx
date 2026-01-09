import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  ArrowLeft,
  Save,
  Loader2,
  Bell,
  CheckCircle2,
  AlertCircle,
  Mail,
  MessageSquare,
  UserPlus,
} from 'lucide-react';
import type { SharedProps, ChannelType } from '@/types';

interface Props extends SharedProps {
  bot: {
    id: number;
    name: string;
    channel_type: ChannelType;
    status: string;
    settings?: {
      notifications_enabled?: boolean;
      notification_email?: string;
      notify_on_new_conversation?: boolean;
      notify_on_handoff?: boolean;
    };
  };
}

export default function Notifications() {
  const { bot, flash } = usePage<Props>().props;

  const { data, setData, put, processing, errors } = useForm({
    settings: {
      notifications_enabled: bot.settings?.notifications_enabled ?? false,
      notification_email: bot.settings?.notification_email || '',
      notify_on_new_conversation: bot.settings?.notify_on_new_conversation ?? true,
      notify_on_handoff: bot.settings?.notify_on_handoff ?? true,
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(`/bots/${bot.id}/settings/notifications`);
  };

  return (
    <AuthenticatedLayout header="การแจ้งเตือน">
      <Head title={`การแจ้งเตือน - ${bot.name}`} />

      <div className="space-y-6 max-w-2xl mx-auto">
        {/* Back Button */}
        <Button variant="ghost" size="sm" asChild>
          <Link href={`/bots/${bot.id}`}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับไปตั้งค่าบอท
          </Link>
        </Button>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="rounded-lg border border-green-200 bg-green-50 dark:bg-green-950 p-4 text-green-700 dark:text-green-300 flex items-center gap-2">
            <CheckCircle2 className="h-5 w-5" />
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border border-red-200 bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300 flex items-center gap-2">
            <AlertCircle className="h-5 w-5" />
            {flash.error}
          </div>
        )}

        {/* Header */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-3">
              <div className="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                <Bell className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
              <div className="flex-1">
                <CardTitle>การแจ้งเตือน</CardTitle>
                <CardDescription>
                  <div className="flex items-center gap-2 mt-1">
                    <ChannelIcon channel={bot.channel_type} className="h-4 w-4" />
                    <span>{bot.name}</span>
                  </div>
                </CardDescription>
              </div>
            </div>
          </CardHeader>
        </Card>

        {/* Notifications Form */}
        <form onSubmit={handleSubmit}>
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">ตั้งค่าการแจ้งเตือน</CardTitle>
              <CardDescription>
                กำหนดว่าจะได้รับการแจ้งเตือนเมื่อใด
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Enable Notifications */}
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="notifications_enabled">เปิดใช้งานการแจ้งเตือน</Label>
                  <p className="text-xs text-muted-foreground">
                    รับการแจ้งเตือนเมื่อมีกิจกรรมใหม่
                  </p>
                </div>
                <Switch
                  id="notifications_enabled"
                  checked={data.settings.notifications_enabled}
                  onCheckedChange={(checked) =>
                    setData('settings', { ...data.settings, notifications_enabled: checked })
                  }
                />
              </div>

              {data.settings.notifications_enabled && (
                <>
                  {/* Notification Email */}
                  <div className="space-y-2">
                    <Label htmlFor="notification_email" className="flex items-center gap-2">
                      <Mail className="h-4 w-4" />
                      อีเมลรับการแจ้งเตือน
                    </Label>
                    <Input
                      id="notification_email"
                      type="email"
                      value={data.settings.notification_email}
                      onChange={(e) =>
                        setData('settings', {
                          ...data.settings,
                          notification_email: e.target.value,
                        })
                      }
                      placeholder="email@example.com"
                    />
                    <p className="text-xs text-muted-foreground">
                      ระบุอีเมลที่ต้องการรับการแจ้งเตือน
                    </p>
                    {errors['settings.notification_email'] && (
                      <p className="text-sm text-destructive">
                        {errors['settings.notification_email']}
                      </p>
                    )}
                  </div>

                  {/* Notification Triggers */}
                  <div className="space-y-4">
                    <Label>ประเภทการแจ้งเตือน</Label>

                    {/* New Conversation */}
                    <div className="flex items-center justify-between p-3 rounded-lg border">
                      <div className="flex items-center gap-3">
                        <div className="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                          <UserPlus className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                          <p className="font-medium text-sm">การสนทนาใหม่</p>
                          <p className="text-xs text-muted-foreground">
                            แจ้งเตือนเมื่อมีลูกค้าเริ่มสนทนาใหม่
                          </p>
                        </div>
                      </div>
                      <Switch
                        checked={data.settings.notify_on_new_conversation}
                        onCheckedChange={(checked) =>
                          setData('settings', {
                            ...data.settings,
                            notify_on_new_conversation: checked,
                          })
                        }
                      />
                    </div>

                    {/* Handoff */}
                    <div className="flex items-center justify-between p-3 rounded-lg border">
                      <div className="flex items-center gap-3">
                        <div className="p-2 bg-amber-100 dark:bg-amber-900 rounded-lg">
                          <MessageSquare className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                          <p className="font-medium text-sm">ส่งต่อให้คน (HITL)</p>
                          <p className="text-xs text-muted-foreground">
                            แจ้งเตือนเมื่อบอทส่งต่อการสนทนาให้คน
                          </p>
                        </div>
                      </div>
                      <Switch
                        checked={data.settings.notify_on_handoff}
                        onCheckedChange={(checked) =>
                          setData('settings', {
                            ...data.settings,
                            notify_on_handoff: checked,
                          })
                        }
                      />
                    </div>
                  </div>
                </>
              )}

              <div className="pt-4">
                <Button type="submit" disabled={processing}>
                  {processing ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Save className="h-4 w-4 mr-2" />
                  )}
                  บันทึก
                </Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </AuthenticatedLayout>
  );
}
