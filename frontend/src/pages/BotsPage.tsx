import { useState } from 'react';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
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
import { useToggleBotStatus } from '@/hooks/useConnections';
import { useToast } from '@/hooks/use-toast';
import { apiDelete } from '@/lib/api';
import {
  Loader2,
  Settings,
  Bot as BotIcon,
  Plus,
  Copy,
  Check,
  Workflow,
  MoreHorizontal,
  Trash2,
  ExternalLink,
  MessageCircle,
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

// Telegram icon component
function TelegramIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      className={className}
      fill="#0088CC"
    >
      <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
    </svg>
  );
}

export function BotsPage() {
  const { data: botsResponse, isLoading, error, refetch } = useBots();
  const toggleStatusMutation = useToggleBotStatus();
  const { toast } = useToast();
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [botToDelete, setBotToDelete] = useState<any | null>(null);
  const [togglingBotId, setTogglingBotId] = useState<number | null>(null);

  const bots = botsResponse?.data || [];

  const handleToggleStatus = async (bot: any) => {
    const newStatus = bot.status === 'active' ? 'inactive' : 'active';
    setTogglingBotId(bot.id);
    try {
      await toggleStatusMutation.mutateAsync({ botId: bot.id, status: newStatus });
      toast({
        title: newStatus === 'active' ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว',
        description: `"${bot.name}" ${newStatus === 'active' ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}แล้ว`,
      });
    } catch (err) {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: err instanceof Error ? err.message : 'ไม่สามารถเปลี่ยนสถานะได้',
        variant: 'destructive',
      });
    } finally {
      setTogglingBotId(null);
    }
  };

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
    if (!botToDelete) return;
    try {
      await apiDelete(`/bots/${botToDelete.id}`);
      toast({
        title: 'ลบแล้ว',
        description: `"${botToDelete.name}" ลบเรียบร้อยแล้ว`,
      });
      refetch();
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
      case 'telegram':
        return <TelegramIcon className="h-8 w-8" />;
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
    <TooltipProvider>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-xl sm:text-2xl font-semibold tracking-tight">การเชื่อมต่อ</h1>
            <p className="text-muted-foreground text-sm mt-1 hidden sm:block">
              จัดการการเชื่อมต่อ Chatbot กับ Platform ต่างๆ
            </p>
          </div>
          <Button asChild className="w-full sm:w-auto">
            <Link to="/connections/add">
              <Plus className="h-4 w-4 mr-2" />
              เพิ่มการเชื่อมต่อ
            </Link>
          </Button>
        </div>

        {bots.length === 0 ? (
          /* Empty State */
          <Card className="border-dashed border-2">
            <CardContent className="flex flex-col items-center justify-center py-16">
              <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                <BotIcon className="h-8 w-8 text-muted-foreground" />
              </div>
              <h2 className="text-xl font-semibold mb-2">เริ่มต้นใช้งาน AI Chatbot</h2>
              <p className="text-muted-foreground text-center max-w-md mb-6">
                สร้างการเชื่อมต่อแรกเพื่อเชื่อม AI Chatbot กับ LINE, Facebook หรือทดสอบก่อนใช้งานจริง
              </p>
              <Button size="lg" asChild>
                <Link to="/connections/add">
                  <Plus className="h-5 w-5 mr-2" />
                  สร้างการเชื่อมต่อแรก
                </Link>
              </Button>
            </CardContent>
          </Card>
        ) : (
          /* Bot List */
          <div className="space-y-3">
            {bots.map(bot => (
              <Card
                key={bot.id}
                className="border hover:border-foreground/20 transition-colors"
              >
                <CardContent className="p-4">
                  <div className="flex items-start gap-4">
                    {/* Platform Icon */}
                    <div className="flex-shrink-0 w-12 h-12 flex items-center justify-center bg-muted rounded-lg">
                      {getChannelIcon(bot.channel_type)}
                    </div>

                    {/* Bot Info */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-3 mb-1">
                        <h3 className="text-lg font-semibold truncate">{bot.name}</h3>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <div className="flex items-center gap-2 flex-shrink-0">
                              <Switch
                                checked={bot.status === 'active'}
                                onCheckedChange={() => handleToggleStatus(bot)}
                                disabled={togglingBotId === bot.id}
                                className="data-[state=checked]:bg-emerald-500"
                              />
                              <span className={`text-xs ${bot.status === 'active' ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'}`}>
                                {togglingBotId === bot.id ? '...' : getStatusText(bot.status)}
                              </span>
                            </div>
                          </TooltipTrigger>
                          <TooltipContent>
                            {bot.status === 'active' ? 'คลิกเพื่อปิดใช้งาน' : 'คลิกเพื่อเปิดใช้งาน'}
                          </TooltipContent>
                        </Tooltip>
                      </div>

                      {/* Webhook URL - Compact */}
                      {bot.webhook_url && (
                        <div className="flex items-center gap-2 mt-2">
                          <code className="text-xs text-muted-foreground bg-muted px-2 py-1 rounded font-mono truncate max-w-[180px] sm:max-w-md">
                            {bot.webhook_url}
                          </code>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Button
                                variant="ghost"
                                size="icon-sm"
                                onClick={() => copyWebhookUrl(bot.id, bot.webhook_url!)}
                                className="flex-shrink-0 h-7 w-7"
                              >
                                {copiedId === bot.id ? (
                                  <Check className="h-3.5 w-3.5 text-green-600" />
                                ) : (
                                  <Copy className="h-3.5 w-3.5" />
                                )}
                              </Button>
                            </TooltipTrigger>
                            <TooltipContent>คัดลอก Webhook URL</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Button
                                variant="ghost"
                                size="icon-sm"
                                className="flex-shrink-0 h-7 w-7"
                                asChild
                              >
                                <a href={bot.webhook_url} target="_blank" rel="noopener noreferrer">
                                  <ExternalLink className="h-3.5 w-3.5" />
                                </a>
                              </Button>
                            </TooltipTrigger>
                            <TooltipContent>เปิดใน Tab ใหม่</TooltipContent>
                          </Tooltip>
                        </div>
                      )}
                    </div>

                    {/* Action Buttons */}
                    <div className="flex items-center gap-2 flex-shrink-0">
                      {/* Primary Actions */}
                      <div className="hidden md:flex items-center gap-2">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button variant="outline" size="sm" asChild>
                              <Link to={`/bots/${bot.id}/settings`}>
                                <Settings className="h-4 w-4" />
                                <span className="ml-1.5">ตั้งค่า</span>
                              </Link>
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>ตั้งค่า Bot และ Prompt</TooltipContent>
                        </Tooltip>

                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button size="sm" asChild>
                              <Link to={`/flows/editor?botId=${bot.id}`}>
                                <Workflow className="h-4 w-4" />
                                <span className="ml-1.5">AI Flow</span>
                              </Link>
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>แก้ไข AI Flow และทดสอบ Chat</TooltipContent>
                        </Tooltip>
                      </div>

                      {/* More Menu */}
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            className="h-8 w-8"
                            aria-label="ตัวเลือกเพิ่มเติม"
                          >
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                          {/* Mobile-only items */}
                          <DropdownMenuItem asChild className="md:hidden">
                            <Link to={`/bots/${bot.id}/settings`}>
                              <Settings className="h-4 w-4 mr-2" />
                              ตั้งค่า Bot
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem asChild className="md:hidden">
                            <Link to={`/flows/editor?botId=${bot.id}`}>
                              <Workflow className="h-4 w-4 mr-2" />
                              แก้ไข AI Flow
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator className="md:hidden" />

                          <DropdownMenuItem asChild>
                            <Link to={`/bots/${bot.id}/edit`}>
                              <MessageCircle className="h-4 w-4 mr-2" />
                              แก้ไข API & Models
                            </Link>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => handleDeleteClick(bot)}
                            className="text-destructive focus:text-destructive focus:bg-destructive/10"
                          >
                            <Trash2 className="h-4 w-4 mr-2" />
                            ลบการเชื่อมต่อ
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
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
    </TooltipProvider>
  );
}
