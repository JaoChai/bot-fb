import { useState, useCallback, useMemo } from 'react';
import { Link, useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { queryKeys } from '@/lib/query';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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
import { apiDelete } from '@/lib/api';
import {
  Loader2,
  Bot as BotIcon,
  Plus,
  Workflow,
  Settings,
  MoreHorizontal,
} from 'lucide-react';
import { PageHeader } from '@/components/connections';
import {
  EmptyState,
  Toolbar,
  PlatformBadge,
  StatusDot,
} from '@/components/common';
import type { Platform } from '@/components/common';

// ─── Helpers ────────────────────────────────────────────────────────────────

const PLATFORM_LABEL: Record<Platform, string> = {
  line: 'LINE Official Account',
  facebook: 'Facebook Page',
  telegram: 'Telegram Bot',
  testing: 'Testing',
};

function formatRelativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'เมื่อสักครู่';
  if (m < 60) return `${m} นาทีที่แล้ว`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} ชั่วโมงที่แล้ว`;
  const d = Math.floor(h / 24);
  if (d < 30) return `${d} วันที่แล้ว`;
  return new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' });
}

// ─── Component ───────────────────────────────────────────────────────────────

export function BotsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data: botsResponse, isLoading, error } = useBots();
  const { toast } = useToast();

  // Filter state
  const [search, setSearch] = useState('');
  const [platformFilter, setPlatformFilter] = useState<'all' | Platform>('all');

  // Delete state
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [botToDelete, setBotToDelete] = useState<{ id: number; name: string } | null>(null);

  const bots = botsResponse?.data ?? [];

  // Derived filtered list
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return bots.filter((b: { name: string; channel_type: string }) => {
      if (platformFilter !== 'all' && b.channel_type !== platformFilter) return false;
      if (q && !b.name.toLowerCase().includes(q)) return false;
      return true;
    });
  }, [bots, search, platformFilter]);

  const handleDeleteClick = useCallback((bot: { id: number; name: string }) => {
    setBotToDelete(bot);
    setDeleteDialogOpen(true);
  }, []);

  const handleDeleteConfirm = useCallback(async () => {
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
  }, [botToDelete, toast, queryClient]);

  // ── Loading / Error ────────────────────────────────────────────────────────

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

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      <PageHeader
        title="การเชื่อมต่อ"
        description="จัดการการเชื่อมต่อ Chatbot กับ Platform ต่างๆ"
      />

      {bots.length === 0 ? (
        <EmptyState
          icon={BotIcon}
          title="เริ่มต้นใช้งาน AI Chatbot"
          description="สร้างการเชื่อมต่อแรกเพื่อเชื่อม AI Chatbot กับ LINE, Facebook หรือทดสอบก่อนใช้งานจริง"
          action={
            <Button size="lg" asChild>
              <Link to="/connections/add">
                <Plus className="h-4 w-4" strokeWidth={2} />
                สร้างการเชื่อมต่อแรก
              </Link>
            </Button>
          }
        />
      ) : (
        <>
          {/* Toolbar: search + platform filter + create action */}
          <Toolbar
            search={search}
            onSearchChange={setSearch}
            searchPlaceholder="ค้นหาบอท..."
            filters={
              <Select
                value={platformFilter}
                onValueChange={(v) => setPlatformFilter(v as 'all' | Platform)}
              >
                <SelectTrigger className="w-36 h-9">
                  <SelectValue placeholder="ทั้งหมด" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">ทั้งหมด</SelectItem>
                  <SelectItem value="line">LINE</SelectItem>
                  <SelectItem value="facebook">Facebook</SelectItem>
                  <SelectItem value="telegram">Telegram</SelectItem>
                  <SelectItem value="testing">Testing</SelectItem>
                </SelectContent>
              </Select>
            }
            actions={
              <Button asChild>
                <Link to="/connections/add">
                  <Plus className="h-4 w-4" strokeWidth={2} />
                  สร้าง Bot
                </Link>
              </Button>
            }
          />

          {/* Filtered-to-zero message */}
          {filtered.length === 0 ? (
            <div className="rounded-lg border bg-card px-6 py-10 text-center text-sm text-muted-foreground">
              ไม่พบบอทที่ตรงกับคำค้น
            </div>
          ) : (
            /* List rows */
            <div className="rounded-lg border bg-card divide-y">
              {filtered.map((bot) => {
                const isActive = bot.status === 'active';
                const platform = bot.channel_type as Platform;

                return (
                  <div
                    key={bot.id}
                    className="group flex items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/40"
                  >
                    {/* Leading: platform badge */}
                    <PlatformBadge
                      platform={platform}
                      size="md"
                      showLabel={false}
                      className="shrink-0"
                    />

                    {/* Main: name + meta */}
                    <button
                      type="button"
                      onClick={() => navigate(`/bots/${bot.id}/settings`)}
                      className="flex-1 min-w-0 text-left focus:outline-none"
                    >
                      <div className="flex items-center gap-2">
                        <h3 className="font-medium truncate">{bot.name}</h3>
                        <StatusDot
                          status={isActive ? 'active' : 'inactive'}
                          pulse={isActive}
                        />
                      </div>
                      <p className="text-xs text-muted-foreground truncate tabular-nums">
                        {PLATFORM_LABEL[platform]} · อัพเดต {formatRelativeTime(bot.updated_at)}
                      </p>
                    </button>

                    {/* Trailing: quick actions */}
                    <div className="flex items-center gap-1 shrink-0 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:focus-within:opacity-100 transition-opacity">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => navigate(`/flows/editor?botId=${bot.id}`)}
                      >
                        <Workflow className="h-4 w-4 mr-1" strokeWidth={1.5} />
                        <span className="hidden sm:inline">Flow</span>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => navigate(`/bots/${bot.id}/settings`)}
                      >
                        <Settings className="h-4 w-4 mr-1" strokeWidth={1.5} />
                        <span className="hidden sm:inline">ตั้งค่า</span>
                      </Button>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" aria-label="เมนูเพิ่มเติม">
                            <MoreHorizontal className="h-4 w-4" strokeWidth={1.5} />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                          <DropdownMenuItem onClick={() => navigate(`/bots/${bot.id}/edit`)}>
                            แก้ไขการเชื่อมต่อ
                          </DropdownMenuItem>
                          <DropdownMenuItem onClick={() => navigate(`/chat?botId=${bot.id}`)}>
                            ดูการสนทนา
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => handleDeleteClick(bot)}
                            className="text-destructive focus:text-destructive focus:bg-destructive/10"
                          >
                            ลบบอท
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
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
