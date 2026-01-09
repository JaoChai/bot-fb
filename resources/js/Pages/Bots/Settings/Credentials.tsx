import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  ArrowLeft,
  Save,
  Loader2,
  Eye,
  EyeOff,
  Key,
  CheckCircle2,
  AlertCircle,
  RefreshCw,
} from 'lucide-react';
import { router } from '@inertiajs/react';
import type { SharedProps, Bot, ChannelType } from '@/types';

interface Props extends SharedProps {
  bot: {
    id: number;
    name: string;
    channel_type: ChannelType;
    status: string;
  };
  credentials: {
    channel_access_token?: string | null;
    channel_secret?: string | null;
    bot_token?: string | null;
    page_access_token?: string | null;
    app_secret?: string | null;
    verify_token?: string | null;
  };
  hasCredentials: boolean;
}

export default function Credentials() {
  const { bot, credentials, hasCredentials, flash } = usePage<Props>().props;
  const [showTokens, setShowTokens] = useState<Record<string, boolean>>({});
  const [isTesting, setIsTesting] = useState(false);

  // Initialize form data based on channel type
  const getInitialFormData = () => {
    switch (bot.channel_type) {
      case 'line':
        return {
          channel_access_token: '',
          channel_secret: '',
        };
      case 'telegram':
        return {
          bot_token: '',
        };
      case 'facebook':
        return {
          page_access_token: '',
          app_secret: '',
          verify_token: '',
        };
      default:
        return {};
    }
  };

  const { data, setData, post, processing, errors, reset } = useForm(getInitialFormData());

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(`/bots/${bot.id}/settings/credentials`, {
      onSuccess: () => reset(),
    });
  };

  const toggleShowToken = (field: string) => {
    setShowTokens(prev => ({ ...prev, [field]: !prev[field] }));
  };

  const handleTestConnection = () => {
    setIsTesting(true);
    router.post(`/bots/${bot.id}/test-connection`, {}, {
      onFinish: () => setIsTesting(false),
    });
  };

  const renderLINEFields = () => (
    <>
      {/* Channel Access Token */}
      <div className="space-y-2">
        <Label htmlFor="channel_access_token">Channel Access Token</Label>
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Input
              id="channel_access_token"
              type={showTokens.channel_access_token ? 'text' : 'password'}
              value={data.channel_access_token || ''}
              onChange={(e) => setData('channel_access_token', e.target.value)}
              placeholder={credentials.channel_access_token || 'กรอก Channel Access Token'}
              className="pr-10"
            />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="absolute right-0 top-0 h-full px-3"
              onClick={() => toggleShowToken('channel_access_token')}
            >
              {showTokens.channel_access_token ? (
                <EyeOff className="h-4 w-4" />
              ) : (
                <Eye className="h-4 w-4" />
              )}
            </Button>
          </div>
        </div>
        {credentials.channel_access_token && (
          <p className="text-xs text-muted-foreground">
            ค่าปัจจุบัน: {credentials.channel_access_token}
          </p>
        )}
        {errors.channel_access_token && (
          <p className="text-sm text-destructive">{errors.channel_access_token}</p>
        )}
      </div>

      {/* Channel Secret */}
      <div className="space-y-2">
        <Label htmlFor="channel_secret">Channel Secret</Label>
        <div className="relative">
          <Input
            id="channel_secret"
            type={showTokens.channel_secret ? 'text' : 'password'}
            value={data.channel_secret || ''}
            onChange={(e) => setData('channel_secret', e.target.value)}
            placeholder={credentials.channel_secret || 'กรอก Channel Secret'}
            className="pr-10"
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="absolute right-0 top-0 h-full px-3"
            onClick={() => toggleShowToken('channel_secret')}
          >
            {showTokens.channel_secret ? (
              <EyeOff className="h-4 w-4" />
            ) : (
              <Eye className="h-4 w-4" />
            )}
          </Button>
        </div>
        {credentials.channel_secret && (
          <p className="text-xs text-muted-foreground">
            ค่าปัจจุบัน: {credentials.channel_secret}
          </p>
        )}
        {errors.channel_secret && (
          <p className="text-sm text-destructive">{errors.channel_secret}</p>
        )}
      </div>
    </>
  );

  const renderTelegramFields = () => (
    <div className="space-y-2">
      <Label htmlFor="bot_token">Bot Token</Label>
      <div className="relative">
        <Input
          id="bot_token"
          type={showTokens.bot_token ? 'text' : 'password'}
          value={data.bot_token || ''}
          onChange={(e) => setData('bot_token', e.target.value)}
          placeholder={credentials.bot_token || 'กรอก Bot Token'}
          className="pr-10"
        />
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="absolute right-0 top-0 h-full px-3"
          onClick={() => toggleShowToken('bot_token')}
        >
          {showTokens.bot_token ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
        </Button>
      </div>
      {credentials.bot_token && (
        <p className="text-xs text-muted-foreground">
          ค่าปัจจุบัน: {credentials.bot_token}
        </p>
      )}
      {errors.bot_token && <p className="text-sm text-destructive">{errors.bot_token}</p>}
    </div>
  );

  const renderFacebookFields = () => (
    <>
      {/* Page Access Token */}
      <div className="space-y-2">
        <Label htmlFor="page_access_token">Page Access Token</Label>
        <div className="relative">
          <Input
            id="page_access_token"
            type={showTokens.page_access_token ? 'text' : 'password'}
            value={data.page_access_token || ''}
            onChange={(e) => setData('page_access_token', e.target.value)}
            placeholder={credentials.page_access_token || 'กรอก Page Access Token'}
            className="pr-10"
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="absolute right-0 top-0 h-full px-3"
            onClick={() => toggleShowToken('page_access_token')}
          >
            {showTokens.page_access_token ? (
              <EyeOff className="h-4 w-4" />
            ) : (
              <Eye className="h-4 w-4" />
            )}
          </Button>
        </div>
        {credentials.page_access_token && (
          <p className="text-xs text-muted-foreground">
            ค่าปัจจุบัน: {credentials.page_access_token}
          </p>
        )}
        {errors.page_access_token && (
          <p className="text-sm text-destructive">{errors.page_access_token}</p>
        )}
      </div>

      {/* App Secret */}
      <div className="space-y-2">
        <Label htmlFor="app_secret">App Secret</Label>
        <div className="relative">
          <Input
            id="app_secret"
            type={showTokens.app_secret ? 'text' : 'password'}
            value={data.app_secret || ''}
            onChange={(e) => setData('app_secret', e.target.value)}
            placeholder={credentials.app_secret || 'กรอก App Secret'}
            className="pr-10"
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="absolute right-0 top-0 h-full px-3"
            onClick={() => toggleShowToken('app_secret')}
          >
            {showTokens.app_secret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </Button>
        </div>
        {credentials.app_secret && (
          <p className="text-xs text-muted-foreground">
            ค่าปัจจุบัน: {credentials.app_secret}
          </p>
        )}
        {errors.app_secret && <p className="text-sm text-destructive">{errors.app_secret}</p>}
      </div>

      {/* Verify Token */}
      <div className="space-y-2">
        <Label htmlFor="verify_token">Verify Token</Label>
        <div className="relative">
          <Input
            id="verify_token"
            type={showTokens.verify_token ? 'text' : 'password'}
            value={data.verify_token || ''}
            onChange={(e) => setData('verify_token', e.target.value)}
            placeholder={credentials.verify_token || 'กรอก Verify Token'}
            className="pr-10"
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="absolute right-0 top-0 h-full px-3"
            onClick={() => toggleShowToken('verify_token')}
          >
            {showTokens.verify_token ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </Button>
        </div>
        {credentials.verify_token && (
          <p className="text-xs text-muted-foreground">
            ค่าปัจจุบัน: {credentials.verify_token}
          </p>
        )}
        {errors.verify_token && <p className="text-sm text-destructive">{errors.verify_token}</p>}
      </div>
    </>
  );

  return (
    <AuthenticatedLayout header="Credentials">
      <Head title={`Credentials - ${bot.name}`} />

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
              <div className="p-2 bg-amber-100 dark:bg-amber-900 rounded-lg">
                <Key className="h-5 w-5 text-amber-600 dark:text-amber-400" />
              </div>
              <div className="flex-1">
                <CardTitle className="flex items-center gap-3">
                  Credentials
                  <Badge className={hasCredentials ? 'bg-emerald-500' : 'bg-amber-500'}>
                    {hasCredentials ? 'ตั้งค่าแล้ว' : 'ยังไม่ได้ตั้งค่า'}
                  </Badge>
                </CardTitle>
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

        {/* Credentials Form */}
        <form onSubmit={handleSubmit}>
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">
                {bot.channel_type === 'line' && 'LINE Messaging API'}
                {bot.channel_type === 'telegram' && 'Telegram Bot API'}
                {bot.channel_type === 'facebook' && 'Facebook Messenger Platform'}
              </CardTitle>
              <CardDescription>
                กรอก credentials สำหรับเชื่อมต่อกับ{' '}
                {bot.channel_type === 'line' && 'LINE'}
                {bot.channel_type === 'telegram' && 'Telegram'}
                {bot.channel_type === 'facebook' && 'Facebook Messenger'}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {bot.channel_type === 'line' && renderLINEFields()}
              {bot.channel_type === 'telegram' && renderTelegramFields()}
              {bot.channel_type === 'facebook' && renderFacebookFields()}

              <div className="flex gap-3 pt-4">
                <Button type="submit" disabled={processing}>
                  {processing ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Save className="h-4 w-4 mr-2" />
                  )}
                  บันทึก
                </Button>
                {hasCredentials && (
                  <Button
                    type="button"
                    variant="outline"
                    onClick={handleTestConnection}
                    disabled={isTesting}
                  >
                    {isTesting ? (
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    ) : (
                      <RefreshCw className="h-4 w-4 mr-2" />
                    )}
                    ทดสอบการเชื่อมต่อ
                  </Button>
                )}
              </div>
            </CardContent>
          </Card>
        </form>

        {/* Help Section */}
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">วิธีการตั้งค่า</CardTitle>
          </CardHeader>
          <CardContent className="prose prose-sm dark:prose-invert">
            {bot.channel_type === 'line' && (
              <ol className="list-decimal list-inside space-y-2 text-sm text-muted-foreground">
                <li>ไปที่ LINE Developers Console</li>
                <li>เลือก Provider และ Channel ที่ต้องการ</li>
                <li>คัดลอก Channel Access Token จากแท็บ Messaging API</li>
                <li>คัดลอก Channel Secret จากแท็บ Basic Settings</li>
                <li>นำมาวางในฟอร์มด้านบน</li>
              </ol>
            )}
            {bot.channel_type === 'telegram' && (
              <ol className="list-decimal list-inside space-y-2 text-sm text-muted-foreground">
                <li>พูดคุยกับ @BotFather บน Telegram</li>
                <li>ใช้คำสั่ง /newbot หรือ /mybots</li>
                <li>คัดลอก Bot Token ที่ได้รับ</li>
                <li>นำมาวางในฟอร์มด้านบน</li>
              </ol>
            )}
            {bot.channel_type === 'facebook' && (
              <ol className="list-decimal list-inside space-y-2 text-sm text-muted-foreground">
                <li>ไปที่ Facebook Developers Console</li>
                <li>เลือก App ที่ต้องการ</li>
                <li>ไปที่ Messenger Settings</li>
                <li>คัดลอก Page Access Token</li>
                <li>คัดลอก App Secret จาก Basic Settings</li>
                <li>สร้าง Verify Token และกรอกในฟอร์ม</li>
              </ol>
            )}
          </CardContent>
        </Card>
      </div>
    </AuthenticatedLayout>
  );
}
