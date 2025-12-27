import { useState } from 'react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  Settings,
  Bot as BotIcon,
  Plus,
  Copy,
  Check,
  Workflow,
  Pencil,
  MoreHorizontal,
  Trash2
} from 'lucide-react';

// LINE icon component
function LineIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={className}
      fill="#06C755"
    >
      <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
    </svg>
  );
}

// Facebook Messenger icon component
function MessengerIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={className}
      fill="#0084FF"
    >
      <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.497 1.745 6.616 4.472 8.652V24l4.086-2.242c1.09.301 2.246.464 3.442.464 6.627 0 12-4.974 12-11.111C24 4.974 18.627 0 12 0zm1.193 14.963l-3.056-3.259-5.963 3.259 6.559-6.963 3.13 3.259 5.889-3.259-6.559 6.963z"/>
    </svg>
  );
}

export function BotsPage() {
  const { data: botsResponse, isLoading, error } = useBots();
  const { toast } = useToast();
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [botToDelete, setBotToDelete] = useState<any | null>(null);

  const bots = botsResponse?.data || [];

  const copyWebhookUrl = async (botId: number, webhookUrl: string) => {
    // Validate URL format
    try {
      new URL(webhookUrl);
    } catch {
      toast({
        title: 'ข้อผิดพลาด',
        description: 'Webhook URL ไม่ถูกต้อง',
        variant: 'destructive',
      });
      return;
    }

    try {
      await navigator.clipboard.writeText(webhookUrl);
      setCopiedId(botId);
      toast({
        title: 'คัดลอกแล้ว',
        description: 'คัดลอก Webhook URL เรียบร้อยแล้ว',
      });
      setTimeout(() => setCopiedId(null), 2000);
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถคัดลอก URL ได้',
        variant: 'destructive',
      });
    }
  };

  const handleDeleteClick = (bot: any) => {
    setBotToDelete(bot);
    setDeleteDialogOpen(true);
  };

  const handleDeleteConfirm = async () => {
    // TODO: Implement actual delete API call
    if (!botToDelete) return;
    try {
      // await deleteBot(botToDelete.id);
      toast({
        title: 'ลบแล้ว',
        description: `"${botToDelete.name}" ลบเรียบร้อยแล้ว`,
      });
    } catch (err) {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: err instanceof Error ? err.message : 'ไม่สามารถลบการเชื่อมต่อได้',
        variant: 'destructive',
      });
    } finally {
      setDeleteDialogOpen(false);
      setBotToDelete(null);
    }
  };

  const getChannelIcon = (channelType: string | null | undefined) => {
    switch (channelType?.toLowerCase()) {
      case 'line':
        return <LineIcon className="h-8 w-8" />;
      case 'facebook':
      case 'messenger':
        return <MessengerIcon className="h-8 w-8" />;
      default:
        return <BotIcon className="h-8 w-8 text-muted-foreground" />;
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'active':
        return 'ทำงาน';
      case 'inactive':
        return 'หยุดทำงาน';
      case 'paused':
        return 'พักการใช้งาน';
      default:
        return status;
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-destructive">เกิดข้อผิดพลาดในการโหลดข้อมูล: {error.message}</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header - dabby.io style */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold tracking-tight">การเชื่อมต่อ</h1>
        <Button variant="orange" asChild>
          <Link to="/connections/add">
            <Plus className="h-4 w-4" />
            เพิ่มการเชื่อมต่อ
          </Link>
        </Button>
      </div>

      {bots.length === 0 ? (
        /* Empty state - dabby.io style */
        <Card className="bg-white dark:bg-card">
          <CardHeader className="text-center py-12">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[oklch(0.953_0.06_63.612)] dark:bg-[oklch(0.2_0.1_63.612)]">
              <BotIcon className="h-8 w-8" style={{ color: 'var(--warning)' }} />
            </div>
            <CardTitle className="text-xl">ยังไม่มีการเชื่อมต่อ</CardTitle>
            <p className="text-muted-foreground mt-2">
              สร้างการเชื่อมต่อใหม่เพื่อเริ่มใช้งาน AI Chatbot
            </p>
          </CardHeader>
          <CardContent className="text-center pb-12">
            <Button variant="orange" size="lg" asChild>
              <Link to="/connections/add">
                <Plus className="h-4 w-4" />
                สร้างการเชื่อมต่อแรก
              </Link>
            </Button>
          </CardContent>
        </Card>
      ) : (
        /* Bot list - dabby.io style */
        <div className="grid gap-4">
          {bots.map(bot => (
            <Card key={bot.id} className="bg-white dark:bg-card">
              <CardContent className="p-6">
                {/* Top row: Icon, Name, Status, Action buttons */}
                <div className="flex items-center justify-between mb-4">
                  <div className="flex items-center gap-4">
                    {/* Channel Icon */}
                    <div className="flex-shrink-0 w-12 h-12 flex items-center justify-center bg-[oklch(0.961_0_0)] dark:bg-[oklch(0.15_0_0)] rounded-lg">
                      {getChannelIcon(bot.channel_type)}
                    </div>

                    {/* Bot Name & Status */}
                    <div>
                      <h3 className="text-lg font-semibold">{bot.name}</h3>
                      <Badge variant={bot.status === 'active' ? 'success' : 'inactive'}>
                        {getStatusText(bot.status)}
                      </Badge>
                    </div>
                  </div>

                  {/* Action Buttons - dabby.io style */}
                  <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" asChild>
                      <Link to={`/bots/${bot.id}/edit`}>
                        <Pencil className="h-4 w-4" />
                        แก้ไขการเชื่อมต่อ
                      </Link>
                    </Button>
                    <Button variant="orange" size="sm" asChild>
                      <Link to={`/bots/${bot.id}/settings`}>
                        <Settings className="h-4 w-4" />
                        ตั้งค่า Bot
                      </Link>
                    </Button>
                    <Button variant="orange-outline" size="sm" asChild>
                      <Link to={`/flows/editor?botId=${bot.id}`}>
                        <Workflow className="h-4 w-4" />
                        แก้ไข AI Flow
                      </Link>
                    </Button>
                  </div>
                </div>

                {/* Webhook URL Section - dabby.io style */}
                {bot.webhook_url && (
                  <div className="space-y-2">
                    <label className="text-sm text-muted-foreground">Webhook URL</label>
                    <div className="flex items-center gap-2">
                      <Input
                        readOnly
                        value={bot.webhook_url}
                        className="flex-1 bg-[oklch(0.961_0_0)] dark:bg-[oklch(0.15_0_0)] text-sm font-mono"
                      />
                      <Button
                        variant="orange-outline"
                        size="sm"
                        onClick={() => copyWebhookUrl(bot.id, bot.webhook_url!)}
                        className="flex-shrink-0"
                      >
                        {copiedId === bot.id ? (
                          <Check className="h-4 w-4" />
                        ) : (
                          <Copy className="h-4 w-4" />
                        )}
                        คัดลอก Webhook URL
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            className="flex-shrink-0"
                            aria-label="ตัวเลือกเพิ่มเติม"
                          >
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/bots/${bot.id}/edit`}>
                              <Pencil className="h-4 w-4 mr-2" />
                              แก้ไขการเชื่อมต่อ
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem asChild>
                            <Link to={`/bots/${bot.id}/settings`}>
                              <Settings className="h-4 w-4 mr-2" />
                              ตั้งค่า
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => handleDeleteClick(bot)}
                            className="text-destructive focus:text-destructive"
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            ลบการเชื่อมต่อ
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Delete confirmation dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ลบการเชื่อมต่อ</AlertDialogTitle>
            <AlertDialogDescription>
              คุณแน่ใจหรือไม่ว่าต้องการลบ "{botToDelete?.name}"? การดำเนินการนี้ไม่สามารถยกเลิกได้
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteConfirm}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              ลบ
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
