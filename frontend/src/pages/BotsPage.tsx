import { useState, useCallback } from 'react';
import { Link } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { queryKeys } from '@/lib/query';
import {
  Tooltip,
  TooltipContent,
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
import { ChannelIcon } from '@/components/ui/channel-icon';
import { PageHeader } from '@/components/connections';
import { cn } from '@/lib/utils';

export function BotsPage() {
  const queryClient = useQueryClient();
  const { data: botsResponse, isLoading, error } = useBots();
  const toggleStatusMutation = useToggleBotStatus();
  const { toast } = useToast();
  const [copiedId, setCopiedId] = useState<number | null>(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [botToDelete, setBotToDelete] = useState<{ id: number; name: string } | null>(null);
  const [togglingBotId, setTogglingBotId] = useState<number | null>(null);
  const [localStatuses, setLocalStatuses] = useState<Record<number, string>>({});

  const bots = botsResponse?.data || [];

  const handleToggleStatus = useCallback(async (bot: { id: number; name: string; status: string }) => {
    const currentStatus = localStatuses[bot.id] ?? bot.status;
    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
    setTogglingBotId(bot.id);
    setLocalStatuses(prev => ({ ...prev, [bot.id]: newStatus }));
    try {
      await toggleStatusMutation.mutateAsync({ botId: bot.id, status: newStatus });
      toast({
        title: newStatus === 'active' ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว',
        description: `"${bot.name}" ${newStatus === 'active' ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}แล้ว`,
      });
    } catch (err) {
      setLocalStatuses(prev => ({ ...prev, [bot.id]: currentStatus }));
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: err instanceof Error ? err.message : 'ไม่สามารถเปลี่ยนสถานะได้',
        variant: 'destructive',
      });
    } finally {
      setTogglingBotId(null);
    }
  }, [toggleStatusMutation, toast, localStatuses]);

  const copyWebhookUrl = async (botId: number, webhookUrl: string) => {
    try {
      new URL(webhookUrl);
    } catch {
      toast({ title: 'ข้อผิดพลาด', description: 'Webhook URL ไม่ถูกต้อง', variant: 'destructive' });
      return;
    }
    try {
      await navigator.clipboard.writeText(webhookUrl);
      setCopiedId(botId);
      toast({ title: 'คัดลอกแล้ว', description: 'คัดลอก Webhook URL เรียบร้อยแล้ว' });
      setTimeout(() => setCopiedId(null), 2000);
    } catch {
      toast({ title: 'เกิดข้อผิดพลาด', description: 'ไม่สามารถคัดลอก URL ได้', variant: 'destructive' });
    }
  };

  const handleDeleteClick = (bot: { id: number; name: string }) => {
    setBotToDelete(bot);
    setDeleteDialogOpen(true);
  };

  const handleDeleteConfirm = async () => {
    if (!botToDelete) return;
    try {
      await apiDelete(`/bots/${botToDelete.id}`);
      toast({ title: 'ลบแล้ว', description: `"${botToDelete.name}" ลบเรียบร้อยแล้ว` });
      await queryClient.refetchQueries({ queryKey: queryKeys.bots.lists(), type: 'active' });
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

  const getStatusText = (status: string) => {
    switch (status) {
      case 'active': return 'ทำงาน';
      case 'inactive': return 'หยุดทำงาน';
      case 'paused': return 'พักการใช้งาน';
      default: return status;
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
      <PageHeader
        title="การเชื่อมต่อ"
        description="จัดการการเชื่อมต่อ Chatbot กับ Platform ต่างๆ"
        actions={
          <Button asChild>
            <Link to="/connections/add">
              <Plus className="h-4 w-4" />
              เพิ่มการเชื่อมต่อ
            </Link>
          </Button>
        }
      />

      {bots.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-12 border border-dashed rounded-2xl">
          <div className="mb-5 flex h-16 w-16 items-center justify-center rounded-2xl border bg-background">
            <BotIcon className="h-7 w-7 text-muted-foreground" />
          </div>
          <h2 className="text-lg font-semibold mb-1">เริ่มต้นใช้งาน AI Chatbot</h2>
          <p className="text-sm text-muted-foreground text-center max-w-sm mb-6">
            สร้างการเชื่อมต่อแรกเพื่อเชื่อม AI Chatbot กับ LINE, Facebook หรือทดสอบก่อนใช้งานจริง
          </p>
          <Button size="lg" asChild>
            <Link to="/connections/add">
              <Plus className="h-4 w-4" />
              สร้างการเชื่อมต่อแรก
            </Link>
          </Button>
        </div>
      ) : (
        <div className="space-y-3">
          {bots.map(bot => {
            const currentStatus = localStatuses[bot.id] ?? bot.status;
            const isActive = currentStatus === 'active';
            const isToggling = togglingBotId === bot.id;

            return (
              <div
                key={bot.id}
                className="relative border rounded-lg p-4 md:p-5 transition-colors duration-150 hover:border-foreground/20 hover:bg-accent/30"
              >
                {/* Status switch — top-right */}
                <div className="absolute top-4 right-4 flex items-center gap-2">
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Switch
                        checked={isActive}
                        onCheckedChange={() => handleToggleStatus(bot)}
                        disabled={isToggling}
                        className="data-[state=checked]:bg-emerald-500"
                      />
                    </TooltipTrigger>
                    <TooltipContent>
                      {isActive ? 'คลิกเพื่อปิดใช้งาน' : 'คลิกเพื่อเปิดใช้งาน'}
                    </TooltipContent>
                  </Tooltip>
                </div>

                <div className="flex items-start gap-4 pr-16">
                  {/* Platform icon */}
                  <div className="shrink-0 w-11 h-11 flex items-center justify-center rounded-lg border bg-background">
                    <ChannelIcon channel={bot.channel_type} className="h-7 w-7" />
                  </div>

                  {/* Bot info */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <h3 className="text-base font-semibold truncate text-foreground">{bot.name}</h3>
                      <span className="flex items-center gap-1.5 shrink-0">
                        <span
                          className={cn(
                            'size-2 rounded-full shrink-0',
                            isActive ? 'bg-emerald-500' : 'bg-muted-foreground'
                          )}
                        />
                        <span
                          className={cn(
                            'text-xs',
                            isActive
                              ? 'text-emerald-600 dark:text-emerald-400'
                              : 'text-muted-foreground'
                          )}
                        >
                          {isToggling ? '...' : getStatusText(currentStatus)}
                        </span>
                      </span>
                    </div>

                    {/* Webhook URL */}
                    {bot.webhook_url && (
                      <div className="flex items-center gap-1.5 mt-1.5">
                        <code className="text-xs text-muted-foreground bg-muted px-2 py-0.5 rounded font-mono truncate min-w-0 max-w-[220px] sm:max-w-sm md:max-w-lg">
                          {bot.webhook_url}
                        </code>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => copyWebhookUrl(bot.id, bot.webhook_url!)}
                              className="shrink-0 h-7 w-7"
                            >
                              {copiedId === bot.id
                                ? <Check className="h-3.5 w-3.5 text-emerald-600" />
                                : <Copy className="h-3.5 w-3.5" />}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent>คัดลอก Webhook URL</TooltipContent>
                        </Tooltip>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="shrink-0 h-7 w-7"
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

                    {/* Action row — always visible, icon-only on mobile, with labels on md+ */}
                    <div className="flex items-center gap-2 mt-3">
                      {/* Settings — icon-only on mobile */}
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Button variant="outline" size="sm" asChild className="h-9 px-2.5 md:px-3">
                            <Link to={`/bots/${bot.id}/settings`}>
                              <Settings className="h-4 w-4" />
                              <span className="hidden md:inline ml-1.5">ตั้งค่า</span>
                            </Link>
                          </Button>
                        </TooltipTrigger>
                        <TooltipContent className="md:hidden">ตั้งค่า Bot และ Prompt</TooltipContent>
                      </Tooltip>

                      {/* AI Flow — icon-only on mobile */}
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Button size="sm" asChild className="h-9 px-2.5 md:px-3">
                            <Link to={`/flows/editor?botId=${bot.id}`}>
                              <Workflow className="h-4 w-4" />
                              <span className="hidden md:inline ml-1.5">AI Flow</span>
                            </Link>
                          </Button>
                        </TooltipTrigger>
                        <TooltipContent className="md:hidden">แก้ไข AI Flow และทดสอบ Chat</TooltipContent>
                      </Tooltip>

                      {/* More menu */}
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9"
                            aria-label="ตัวเลือกเพิ่มเติม"
                          >
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
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
                </div>
              </div>
            );
          })}
        </div>
      )}

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
