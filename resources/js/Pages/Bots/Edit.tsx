import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Separator } from '@/Components/ui/separator';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  ArrowLeft,
  Save,
  Loader2,
  Eye,
  EyeOff,
  Trash2,
  ShieldCheck,
} from 'lucide-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { router } from '@inertiajs/react';
import type { SharedProps, Bot } from '@/types';

interface Props extends SharedProps {
  bot: Bot;
}

type ChannelType = 'line' | 'facebook' | 'telegram' | 'testing';

export default function Edit() {
  const { bot, flash } = usePage<Props>().props;
  const [showApiKey, setShowApiKey] = useState(false);
  const [showChannelSecret, setShowChannelSecret] = useState(false);
  const [showAccessToken, setShowAccessToken] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const { data, setData, put, processing, errors } = useForm({
    name: bot.name || '',
    status: bot.status || 'active',
    channel_type: (bot.channel_type || 'line') as ChannelType,
    description: bot.description || '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(`/bots/${bot.id}`);
  };

  const handleDelete = () => {
    setIsDeleting(true);
    router.delete(`/bots/${bot.id}`, {
      onFinish: () => {
        setIsDeleting(false);
        setDeleteDialogOpen(false);
      },
    });
  };

  return (
    <AuthenticatedLayout header="แก้ไขการเชื่อมต่อ">
      <Head title={`แก้ไข - ${bot.name}`} />

      <div className="space-y-6 max-w-4xl mx-auto">
        {/* Back Button */}
        <Button variant="ghost" size="sm" asChild>
          <Link href="/bots">
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับไปหน้าการเชื่อมต่อ
          </Link>
        </Button>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="rounded-lg border bg-green-50 dark:bg-green-950 p-4 text-green-700 dark:text-green-300">
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300">
            {flash.error}
          </div>
        )}

        {/* Title */}
        <h1 className="text-2xl font-bold tracking-tight text-center">แก้ไขการเชื่อมต่อ</h1>

        <form onSubmit={handleSubmit}>
          <Card className="bg-white dark:bg-card">
            <CardContent className="p-6 space-y-8">
              {/* Status Toggle */}
              <div className="flex items-center gap-3">
                <Switch
                  checked={data.status === 'active'}
                  onCheckedChange={(checked) => setData('status', checked ? 'active' : 'inactive')}
                  className="data-[state=checked]:bg-emerald-500"
                />
                <Label
                  className={data.status === 'active' ? 'font-medium text-emerald-600' : 'text-muted-foreground'}
                >
                  {data.status === 'active' ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}
                </Label>
              </div>

              {/* Connection Name */}
              <div className="space-y-2">
                <Label htmlFor="name">ชื่อการเชื่อมต่อ</Label>
                <Input
                  id="name"
                  value={data.name}
                  onChange={(e) => setData('name', e.target.value)}
                  placeholder="เช่น Line ร้าน ABC"
                />
                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
              </div>

              {/* Description */}
              <div className="space-y-2">
                <Label htmlFor="description">คำอธิบาย (ไม่จำเป็น)</Label>
                <Input
                  id="description"
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  placeholder="คำอธิบายสั้นๆ เกี่ยวกับบอทนี้"
                />
                {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
              </div>

              <Separator />

              {/* Platform Selection */}
              <div className="space-y-4">
                <Label>ใช้แพลตฟอร์ม</Label>
                <div className="grid grid-cols-3 gap-4">
                  {/* LINE */}
                  <div
                    className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                      data.channel_type === 'line'
                        ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950'
                        : 'border-border hover:border-muted-foreground/50'
                    }`}
                    onClick={() => setData('channel_type', 'line')}
                  >
                    <div className="flex items-center gap-3">
                      <ChannelIcon channel="line" className="h-8 w-8" />
                      <div>
                        <p className="font-semibold">LINE</p>
                        <p className="text-xs text-muted-foreground">LINE Messaging API</p>
                      </div>
                    </div>
                  </div>

                  {/* Facebook */}
                  <div
                    className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                      data.channel_type === 'facebook'
                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-950'
                        : 'border-border hover:border-muted-foreground/50'
                    }`}
                    onClick={() => setData('channel_type', 'facebook')}
                  >
                    <div className="flex items-center gap-3">
                      <ChannelIcon channel="facebook" className="h-8 w-8" />
                      <div>
                        <p className="font-semibold">Facebook</p>
                        <p className="text-xs text-muted-foreground">Messenger Platform</p>
                      </div>
                    </div>
                  </div>

                  {/* Telegram */}
                  <div
                    className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                      data.channel_type === 'telegram'
                        ? 'border-sky-500 bg-sky-50 dark:bg-sky-950'
                        : 'border-border hover:border-muted-foreground/50'
                    }`}
                    onClick={() => setData('channel_type', 'telegram')}
                  >
                    <div className="flex items-center gap-3">
                      <ChannelIcon channel="telegram" className="h-8 w-8" />
                      <div>
                        <p className="font-semibold">Telegram</p>
                        <p className="text-xs text-muted-foreground">Bot API</p>
                      </div>
                    </div>
                  </div>
                </div>
                {errors.channel_type && <p className="text-sm text-destructive">{errors.channel_type}</p>}
              </div>

              <Separator />

              {/* Security Note */}
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <ShieldCheck className="h-4 w-4" />
                <span>API keys และ Tokens จะถูกเข้ารหัสเพื่อความปลอดภัย</span>
              </div>
            </CardContent>
          </Card>

          {/* Action Buttons */}
          <div className="flex flex-col gap-3 mt-6">
            <Button type="submit" size="lg" className="w-full" disabled={processing}>
              {processing ? (
                <Loader2 className="h-4 w-4 animate-spin mr-2" />
              ) : (
                <Save className="h-4 w-4 mr-2" />
              )}
              อัพเดทข้อมูล
            </Button>

            <Button
              type="button"
              variant="destructive"
              size="lg"
              className="w-full"
              onClick={() => setDeleteDialogOpen(true)}
            >
              <Trash2 className="h-4 w-4 mr-2" />
              ลบการเชื่อมต่อนี้
            </Button>
          </div>
        </form>

        {/* Delete Confirmation Dialog */}
        <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>ลบการเชื่อมต่อ</AlertDialogTitle>
              <AlertDialogDescription>
                คุณแน่ใจหรือไม่ว่าต้องการลบ "{bot.name}"? การดำเนินการนี้ไม่สามารถยกเลิกได้
                และข้อมูลการสนทนาทั้งหมดจะถูกลบ
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDelete}
                disabled={isDeleting}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              >
                {isDeleting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                ลบ
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </AuthenticatedLayout>
  );
}
