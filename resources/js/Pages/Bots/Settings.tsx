import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  ArrowLeft,
  Settings as SettingsIcon,
  Key,
  Brain,
  Bell,
  Database,
  Copy,
  Check,
  ExternalLink,
  Workflow,
  MessageCircle,
  Users,
} from 'lucide-react';
import { useState } from 'react';
import type { SharedProps, Bot } from '@/types';

interface Props extends SharedProps {
  bot: Bot & {
    flow?: { id: number; name: string; is_active: boolean } | null;
    knowledge_bases?: Array<{ id: number; name: string; document_count: number }>;
  };
  webhookUrl: string;
  stats: {
    conversations_count: number;
    messages_count: number;
    customers_count: number;
  };
}

export default function Settings() {
  const { bot, webhookUrl, stats, flash } = usePage<Props>().props;
  const [copied, setCopied] = useState(false);

  const copyWebhookUrl = async () => {
    try {
      await navigator.clipboard.writeText(webhookUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Handle copy error silently
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return <Badge className="bg-emerald-500">ทำงาน</Badge>;
      case 'inactive':
        return <Badge variant="secondary">หยุดทำงาน</Badge>;
      case 'paused':
        return <Badge variant="outline">พักการใช้งาน</Badge>;
      default:
        return <Badge variant="secondary">{status}</Badge>;
    }
  };

  return (
    <AuthenticatedLayout header="ตั้งค่าบอท">
      <Head title={`ตั้งค่า - ${bot.name}`} />

      <div className="space-y-6">
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

        {/* Bot Info Header */}
        <Card>
          <CardContent className="p-6">
            <div className="flex items-start gap-4">
              <div className="flex-shrink-0 w-16 h-16 flex items-center justify-center bg-muted rounded-xl">
                <ChannelIcon channel={bot.channel_type} className="h-10 w-10" />
              </div>
              <div className="flex-1">
                <div className="flex items-center gap-3 mb-2">
                  <h1 className="text-2xl font-bold">{bot.name}</h1>
                  {getStatusBadge(bot.status)}
                </div>
                {bot.description && (
                  <p className="text-muted-foreground">{bot.description}</p>
                )}

                {/* Webhook URL */}
                {webhookUrl && (
                  <div className="flex items-center gap-2 mt-4">
                    <span className="text-sm text-muted-foreground">Webhook URL:</span>
                    <code className="text-sm bg-muted px-2 py-1 rounded font-mono truncate max-w-md">
                      {webhookUrl}
                    </code>
                    <Button variant="ghost" size="sm" onClick={copyWebhookUrl} className="h-7 w-7 p-0">
                      {copied ? (
                        <Check className="h-4 w-4 text-green-600" />
                      ) : (
                        <Copy className="h-4 w-4" />
                      )}
                    </Button>
                    <Button variant="ghost" size="sm" className="h-7 w-7 p-0" asChild>
                      <a href={webhookUrl} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="h-4 w-4" />
                      </a>
                    </Button>
                  </div>
                )}
              </div>

              {/* Quick Actions */}
              <div className="flex gap-2">
                <Button variant="outline" asChild>
                  <Link href={`/bots/${bot.id}/edit`}>
                    <SettingsIcon className="h-4 w-4 mr-2" />
                    แก้ไข
                  </Link>
                </Button>
                <Button asChild>
                  <Link href={`/flows/editor?botId=${bot.id}`}>
                    <Workflow className="h-4 w-4 mr-2" />
                    AI Flow
                  </Link>
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Stats Grid */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-blue-100 dark:bg-blue-900 rounded-lg">
                  <MessageCircle className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.conversations_count}</p>
                  <p className="text-sm text-muted-foreground">การสนทนา</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-green-100 dark:bg-green-900 rounded-lg">
                  <MessageCircle className="h-6 w-6 text-green-600 dark:text-green-400" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.messages_count}</p>
                  <p className="text-sm text-muted-foreground">ข้อความ</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-purple-100 dark:bg-purple-900 rounded-lg">
                  <Users className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.customers_count}</p>
                  <p className="text-sm text-muted-foreground">ลูกค้า</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Settings Menu */}
        <div className="grid gap-4 md:grid-cols-2">
          {/* Credentials */}
          <Link href={`/bots/${bot.id}/settings/credentials`} className="block">
            <Card className="hover:border-foreground/20 transition-colors cursor-pointer h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-3">
                  <div className="p-2 bg-amber-100 dark:bg-amber-900 rounded-lg">
                    <Key className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                  </div>
                  Credentials
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  จัดการ API Keys และ Tokens สำหรับการเชื่อมต่อ
                </p>
              </CardContent>
            </Card>
          </Link>

          {/* AI Settings */}
          <Link href={`/bots/${bot.id}/settings/ai`} className="block">
            <Card className="hover:border-foreground/20 transition-colors cursor-pointer h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-3">
                  <div className="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                    <Brain className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                  </div>
                  ตั้งค่า AI
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  เลือกโมเดล AI และปรับแต่งพารามิเตอร์
                </p>
              </CardContent>
            </Card>
          </Link>

          {/* Notifications */}
          <Link href={`/bots/${bot.id}/settings/notifications`} className="block">
            <Card className="hover:border-foreground/20 transition-colors cursor-pointer h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-3">
                  <div className="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                    <Bell className="h-5 w-5 text-green-600 dark:text-green-400" />
                  </div>
                  การแจ้งเตือน
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  ตั้งค่าการแจ้งเตือนเมื่อมีข้อความใหม่
                </p>
              </CardContent>
            </Card>
          </Link>

          {/* Knowledge Base */}
          <Link href={`/bots/${bot.id}/knowledge`} className="block">
            <Card className="hover:border-foreground/20 transition-colors cursor-pointer h-full">
              <CardHeader>
                <CardTitle className="flex items-center gap-3">
                  <div className="p-2 bg-purple-100 dark:bg-purple-900 rounded-lg">
                    <Database className="h-5 w-5 text-purple-600 dark:text-purple-400" />
                  </div>
                  ฐานความรู้
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  จัดการเอกสารและข้อมูลสำหรับ AI ใช้อ้างอิง
                </p>
                {bot.knowledge_bases && bot.knowledge_bases.length > 0 && (
                  <p className="text-xs text-muted-foreground mt-2">
                    {bot.knowledge_bases.length} ฐานความรู้
                  </p>
                )}
              </CardContent>
            </Card>
          </Link>
        </div>

        {/* Flow Info */}
        {bot.flow && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-3">
                <Workflow className="h-5 w-5" />
                AI Flow ที่ใช้งาน
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium">{bot.flow.name}</p>
                  <p className="text-sm text-muted-foreground">
                    {bot.flow.is_active ? 'กำลังใช้งาน' : 'ไม่ได้ใช้งาน'}
                  </p>
                </div>
                <Button variant="outline" asChild>
                  <Link href={`/flows/editor?botId=${bot.id}`}>
                    แก้ไข Flow
                  </Link>
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AuthenticatedLayout>
  );
}
