import { Head, useForm, usePage, Link } from '@inertiajs/react';
import { useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import {
  User,
  Mail,
  Bell,
  Shield,
  CheckCircle2,
  AlertCircle,
  Save,
  Loader2,
  Clock,
  Calendar,
} from 'lucide-react';
import type { SharedProps } from '@/types';

interface SettingsPageProps extends SharedProps {
  user: {
    id: number;
    name: string;
    email: string;
    avatar_url?: string;
    email_verified_at?: string;
    created_at: string;
  };
  notificationSettings: {
    email_new_message: boolean;
    email_daily_summary: boolean;
    email_weekly_report: boolean;
  };
}

export default function Settings() {
  const { user, notificationSettings, flash } = usePage<SettingsPageProps>().props;

  // Profile form
  const profileForm = useForm({
    name: user.name,
  });

  // Notification form
  const notificationForm = useForm({
    email_new_message: notificationSettings.email_new_message,
    email_daily_summary: notificationSettings.email_daily_summary,
    email_weekly_report: notificationSettings.email_weekly_report,
  });

  const handleProfileSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    profileForm.put('/settings/profile');
  };

  // Auto-save notifications when toggled
  const handleNotificationChange = (
    key: keyof typeof notificationSettings,
    value: boolean
  ) => {
    notificationForm.setData(key, value);
  };

  // Submit notification changes after state update
  useEffect(() => {
    // Only submit if form has been modified (not initial load)
    if (notificationForm.isDirty) {
      notificationForm.put('/settings/notifications', {
        preserveScroll: true,
      });
    }
  }, [
    notificationForm.data.email_new_message,
    notificationForm.data.email_daily_summary,
    notificationForm.data.email_weekly_report,
  ]);

  const userInitials = user.name
    ? user.name.substring(0, 2).toUpperCase()
    : 'U';

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  return (
    <AuthenticatedLayout header="ตั้งค่า">
      <Head title="ตั้งค่า" />

      <div className="space-y-6 max-w-2xl mx-auto">
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

        {/* Profile Section */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-3">
              <div className="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                <User className="h-5 w-5 text-blue-600 dark:text-blue-400" />
              </div>
              <div className="flex-1">
                <CardTitle>โปรไฟล์</CardTitle>
                <CardDescription>
                  ข้อมูลบัญชีและการตั้งค่าส่วนตัว
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-6">
            {/* Avatar Display */}
            <div className="flex items-center gap-4">
              <Avatar className="h-16 w-16">
                {user.avatar_url ? (
                  <AvatarImage src={user.avatar_url} alt={user.name} />
                ) : null}
                <AvatarFallback className="text-lg bg-muted">
                  {userInitials}
                </AvatarFallback>
              </Avatar>
              <div>
                <p className="font-medium">{user.name}</p>
                <p className="text-sm text-muted-foreground">{user.email}</p>
              </div>
            </div>

            <Separator />

            {/* Profile Form */}
            <form onSubmit={handleProfileSubmit} className="space-y-4">
              {/* Name */}
              <div className="space-y-2">
                <Label htmlFor="name">ชื่อที่แสดง</Label>
                <Input
                  id="name"
                  type="text"
                  value={profileForm.data.name}
                  onChange={(e) => profileForm.setData('name', e.target.value)}
                  placeholder="ชื่อของคุณ"
                />
                {profileForm.errors.name && (
                  <p className="text-sm text-destructive">
                    {profileForm.errors.name}
                  </p>
                )}
              </div>

              {/* Email (Read-only) */}
              <div className="space-y-2">
                <Label htmlFor="email" className="flex items-center gap-2">
                  <Mail className="h-4 w-4" />
                  อีเมล
                </Label>
                <div className="flex items-center gap-2">
                  <Input
                    id="email"
                    type="email"
                    value={user.email}
                    disabled
                    className="flex-1"
                  />
                  {user.email_verified_at ? (
                    <Badge variant="success" className="shrink-0">
                      <CheckCircle2 className="h-3 w-3" />
                      ยืนยันแล้ว
                    </Badge>
                  ) : (
                    <Badge variant="warning" className="shrink-0">
                      <AlertCircle className="h-3 w-3" />
                      ยังไม่ยืนยัน
                    </Badge>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  ไม่สามารถเปลี่ยนอีเมลได้
                </p>
              </div>

              {/* Account Info */}
              <div className="flex items-center gap-4 text-sm text-muted-foreground">
                <div className="flex items-center gap-1">
                  <Calendar className="h-4 w-4" />
                  <span>สมัครเมื่อ {formatDate(user.created_at)}</span>
                </div>
              </div>

              <div className="pt-2">
                <Button type="submit" disabled={profileForm.processing}>
                  {profileForm.processing ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Save className="h-4 w-4 mr-2" />
                  )}
                  บันทึก
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        {/* Notifications Section */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-3">
              <div className="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                <Bell className="h-5 w-5 text-green-600 dark:text-green-400" />
              </div>
              <div className="flex-1">
                <CardTitle>การแจ้งเตือน</CardTitle>
                <CardDescription>
                  ตั้งค่าการรับการแจ้งเตือนทางอีเมล
                </CardDescription>
              </div>
              {notificationForm.processing && (
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* New Message Notification */}
            <div className="flex items-center justify-between p-3 rounded-lg border">
              <div className="space-y-0.5">
                <Label htmlFor="email_new_message">ข้อความใหม่</Label>
                <p className="text-xs text-muted-foreground">
                  รับอีเมลเมื่อมีข้อความใหม่จากลูกค้า
                </p>
              </div>
              <Switch
                id="email_new_message"
                checked={notificationForm.data.email_new_message}
                onCheckedChange={(checked) =>
                  handleNotificationChange('email_new_message', checked)
                }
                disabled={notificationForm.processing}
              />
            </div>

            {/* Daily Summary */}
            <div className="flex items-center justify-between p-3 rounded-lg border">
              <div className="space-y-0.5">
                <Label htmlFor="email_daily_summary">สรุปรายวัน</Label>
                <p className="text-xs text-muted-foreground">
                  รับอีเมลสรุปกิจกรรมประจำวัน
                </p>
              </div>
              <Switch
                id="email_daily_summary"
                checked={notificationForm.data.email_daily_summary}
                onCheckedChange={(checked) =>
                  handleNotificationChange('email_daily_summary', checked)
                }
                disabled={notificationForm.processing}
              />
            </div>

            {/* Weekly Report */}
            <div className="flex items-center justify-between p-3 rounded-lg border">
              <div className="space-y-0.5">
                <Label htmlFor="email_weekly_report">รายงานรายสัปดาห์</Label>
                <p className="text-xs text-muted-foreground">
                  รับอีเมลรายงานประสิทธิภาพประจำสัปดาห์
                </p>
              </div>
              <Switch
                id="email_weekly_report"
                checked={notificationForm.data.email_weekly_report}
                onCheckedChange={(checked) =>
                  handleNotificationChange('email_weekly_report', checked)
                }
                disabled={notificationForm.processing}
              />
            </div>
          </CardContent>
        </Card>

        {/* Security Section */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-3">
              <div className="p-2 bg-amber-100 dark:bg-amber-900 rounded-lg">
                <Shield className="h-5 w-5 text-amber-600 dark:text-amber-400" />
              </div>
              <div className="flex-1">
                <CardTitle>ความปลอดภัย</CardTitle>
                <CardDescription>
                  จัดการรหัสผ่านและการรักษาความปลอดภัยของบัญชี
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Change Password */}
            <div className="flex items-center justify-between p-3 rounded-lg border">
              <div className="space-y-0.5">
                <p className="font-medium text-sm">เปลี่ยนรหัสผ่าน</p>
                <p className="text-xs text-muted-foreground">
                  อัปเดตรหัสผ่านเพื่อรักษาความปลอดภัยของบัญชี
                </p>
              </div>
              <Button variant="outline" size="sm" asChild>
                <Link href="/settings/password">
                  เปลี่ยนรหัสผ่าน
                </Link>
              </Button>
            </div>

            {/* Last Activity Info */}
            <div className="flex items-center gap-2 text-sm text-muted-foreground p-3">
              <Clock className="h-4 w-4" />
              <span>เข้าสู่ระบบครั้งล่าสุด: วันนี้</span>
            </div>
          </CardContent>
        </Card>
      </div>
    </AuthenticatedLayout>
  );
}
