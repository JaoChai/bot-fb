import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  BookOpen,
  Plus,
  FileText,
  Layers,
  Clock,
  AlertCircle,
  Loader2,
  Database,
} from 'lucide-react';
import type { SharedProps } from '@/types';
import { cn } from '@/Lib/utils';

interface KnowledgeBase {
  id: number;
  name: string;
  description: string | null;
  document_count: number;
  chunk_count: number;
  status: 'active' | 'processing' | 'error';
  created_at: string;
  updated_at: string;
}

interface Bot {
  id: number;
  name: string;
  channel_type: string;
}

interface Props extends SharedProps {
  bots: Bot[];
  selectedBotId: number | null;
  knowledgeBases: KnowledgeBase[] | null;
}

export default function Index() {
  const { bots, selectedBotId, knowledgeBases, flash } = usePage<Props>().props;

  const handleBotChange = (botId: string) => {
    router.get('/knowledge-base', { bot_id: botId }, { preserveState: true });
  };

  const getStatusBadge = (status: KnowledgeBase['status']) => {
    switch (status) {
      case 'active':
        return (
          <Badge variant="success" className="gap-1">
            <span className="h-1.5 w-1.5 rounded-full bg-current" />
            พร้อมใช้งาน
          </Badge>
        );
      case 'processing':
        return (
          <Badge variant="warning" className="gap-1">
            <Loader2 className="h-3 w-3 animate-spin" />
            กำลังประมวลผล
          </Badge>
        );
      case 'error':
        return (
          <Badge variant="destructive" className="gap-1">
            <AlertCircle className="h-3 w-3" />
            ผิดพลาด
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  const selectedBot = bots.find((bot) => bot.id === selectedBotId);

  return (
    <AuthenticatedLayout header="ฐานความรู้">
      <Head title="ฐานความรู้" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-xl sm:text-2xl font-semibold tracking-tight">ฐานความรู้</h1>
            <p className="text-muted-foreground text-sm mt-1 hidden sm:block">
              จัดการเอกสารและข้อมูลสำหรับ AI Chatbot
            </p>
          </div>

          <div className="flex flex-col sm:flex-row gap-3">
            {/* Bot Selector */}
            <Select
              value={selectedBotId?.toString() ?? ''}
              onValueChange={handleBotChange}
            >
              <SelectTrigger className="w-full sm:w-[200px]">
                <SelectValue placeholder="เลือกบอท">
                  {selectedBot && (
                    <div className="flex items-center gap-2">
                      <ChannelIcon channel={selectedBot.channel_type} className="h-4 w-4" />
                      <span className="truncate">{selectedBot.name}</span>
                    </div>
                  )}
                </SelectValue>
              </SelectTrigger>
              <SelectContent>
                {bots.length === 0 ? (
                  <div className="px-2 py-4 text-center text-sm text-muted-foreground">
                    ไม่มีบอท
                  </div>
                ) : (
                  bots.map((bot) => (
                    <SelectItem key={bot.id} value={bot.id.toString()}>
                      <div className="flex items-center gap-2">
                        <ChannelIcon channel={bot.channel_type} className="h-4 w-4" />
                        <span>{bot.name}</span>
                      </div>
                    </SelectItem>
                  ))
                )}
              </SelectContent>
            </Select>

            {/* Create Button */}
            {selectedBotId && (
              <Button asChild className="w-full sm:w-auto">
                <Link href={`/knowledge-base/create?bot_id=${selectedBotId}`}>
                  <Plus className="h-4 w-4 mr-2" />
                  สร้างฐานความรู้
                </Link>
              </Button>
            )}
          </div>
        </div>

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

        {/* Content */}
        {!selectedBotId ? (
          /* No Bot Selected State */
          <Card className="border-dashed border-2">
            <CardContent className="flex flex-col items-center justify-center py-16">
              <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                <BookOpen className="h-8 w-8 text-muted-foreground" />
              </div>
              <h2 className="text-xl font-semibold mb-2">เลือกบอทเพื่อดูฐานความรู้</h2>
              <p className="text-muted-foreground text-center max-w-md">
                เลือกบอทจาก dropdown ด้านบนเพื่อดูและจัดการฐานความรู้
              </p>
            </CardContent>
          </Card>
        ) : knowledgeBases === null || knowledgeBases.length === 0 ? (
          /* Empty State */
          <Card className="border-dashed border-2">
            <CardContent className="flex flex-col items-center justify-center py-16">
              <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                <Database className="h-8 w-8 text-muted-foreground" />
              </div>
              <h2 className="text-xl font-semibold mb-2">ยังไม่มีฐานความรู้</h2>
              <p className="text-muted-foreground text-center max-w-md mb-6">
                สร้างฐานความรู้แรกเพื่อให้ AI Chatbot เข้าถึงข้อมูลของคุณ
              </p>
              <Button size="lg" asChild>
                <Link href={`/knowledge-base/create?bot_id=${selectedBotId}`}>
                  <Plus className="h-5 w-5 mr-2" />
                  สร้างฐานความรู้แรก
                </Link>
              </Button>
            </CardContent>
          </Card>
        ) : (
          /* Knowledge Base Grid */
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {knowledgeBases.map((kb) => (
              <Link
                key={kb.id}
                href={`/knowledge-base/${kb.id}`}
                className="block"
              >
                <Card className="h-full cursor-pointer border hover:border-foreground/20 transition-colors">
                  <CardHeader className="pb-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex items-center gap-3 min-w-0">
                        <div className="flex-shrink-0 h-10 w-10 flex items-center justify-center bg-muted rounded-lg">
                          <BookOpen className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0">
                          <CardTitle className="text-base truncate">{kb.name}</CardTitle>
                          {kb.description && (
                            <CardDescription className="line-clamp-1 mt-1">
                              {kb.description}
                            </CardDescription>
                          )}
                        </div>
                      </div>
                      {getStatusBadge(kb.status)}
                    </div>
                  </CardHeader>

                  <CardContent className="pt-0">
                    {/* Stats */}
                    <div className="grid grid-cols-2 gap-4 mb-4">
                      <div className="flex items-center gap-2 text-sm">
                        <FileText className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground">เอกสาร:</span>
                        <span className="font-medium">{kb.document_count}</span>
                      </div>
                      <div className="flex items-center gap-2 text-sm">
                        <Layers className="h-4 w-4 text-muted-foreground" />
                        <span className="text-muted-foreground">Chunks:</span>
                        <span className="font-medium">{kb.chunk_count}</span>
                      </div>
                    </div>

                    {/* Timestamps */}
                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      <Clock className="h-3.5 w-3.5" />
                      <span>อัปเดต {formatDate(kb.updated_at)}</span>
                    </div>
                  </CardContent>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </div>
    </AuthenticatedLayout>
  );
}
