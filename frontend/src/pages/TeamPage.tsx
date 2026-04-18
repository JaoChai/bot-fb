import { useState, useDeferredValue } from 'react';
import { Navigate } from 'react-router';
import { useAuthStore } from '@/stores/authStore';
import { useBots } from '@/hooks/useKnowledgeBase';
import {
  useBotAdminsWithCounts,
  useSearchUsers,
  useAddAdmin,
  useRemoveAdmin,
  useUpdateAutoAssignment,
} from '@/hooks/useAdmins';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/connections';
import { Panel, BotPicker, EmptyState } from '@/components/common';
import {
  Users,
  Search,
  UserPlus,
  Trash2,
  MessageSquare,
  Loader2,
} from 'lucide-react';
import { toast } from 'sonner';

export function TeamPage() {
  const { user } = useAuthStore();
  const [selectedBotId, setSelectedBotId] = useState<number | undefined>();
  const [searchEmail, setSearchEmail] = useState('');
  const [adminToRemove, setAdminToRemove] = useState<{ id: number; name: string } | null>(null);

  const deferredSearch = useDeferredValue(searchEmail);

  const { data: botsResponse, isLoading: botsLoading } = useBots();
  const bots = botsResponse?.data;
  const { data: admins, isLoading: adminsLoading } = useBotAdminsWithCounts(selectedBotId);
  const { data: searchResults, isLoading: searchLoading } = useSearchUsers(deferredSearch);
  const addAdmin = useAddAdmin(selectedBotId);
  const removeAdmin = useRemoveAdmin(selectedBotId);
  const updateAutoAssignment = useUpdateAutoAssignment(selectedBotId);

  if (user?.role !== 'owner') {
    return <Navigate to="/dashboard" replace />;
  }

  const selectedBot = bots?.find((b: { id: number }) => b.id === selectedBotId);

  const filteredResults = searchResults?.filter(
    (u: { id: number }) =>
      u.id !== user?.id &&
      !admins?.some((a: { user_id: number }) => a.user_id === u.id),
  );

  const handleAddAdmin = async (userId: number) => {
    try {
      await addAdmin.mutateAsync(userId);
      setSearchEmail('');
      toast.success('เพิ่ม Admin สำเร็จ');
    } catch {
      toast.error('ไม่สามารถเพิ่ม Admin ได้');
    }
  };

  const handleRemoveAdmin = async () => {
    if (!adminToRemove) return;
    try {
      await removeAdmin.mutateAsync(adminToRemove.id);
      toast.success('ลบ Admin สำเร็จ');
    } catch {
      toast.error('ไม่สามารถลบ Admin ได้');
    } finally {
      setAdminToRemove(null);
    }
  };

  const handleAutoAssignmentChange = async (enabled: boolean) => {
    try {
      await updateAutoAssignment.mutateAsync({
        enabled,
        mode: (selectedBot?.settings?.auto_assignment_mode as 'round_robin' | 'load_balanced') || 'round_robin',
      });
      toast.success(enabled ? 'เปิด Auto-assign แล้ว' : 'ปิด Auto-assign แล้ว');
    } catch {
      toast.error('ไม่สามารถอัพเดตการตั้งค่าได้');
    }
  };

  const handleAutoAssignmentModeChange = async (mode: 'round_robin' | 'load_balanced') => {
    try {
      await updateAutoAssignment.mutateAsync({
        enabled: selectedBot?.settings?.auto_assignment_enabled ?? false,
        mode,
      });
      toast.success('อัพเดตโหมดสำเร็จ');
    } catch {
      toast.error('ไม่สามารถอัพเดตการตั้งค่าได้');
    }
  };

  const botOptions = (bots ?? []).map((b: { id: number; name: string }) => ({ id: b.id, name: b.name }));

  return (
    <div className="space-y-6">
      <PageHeader
        title="จัดการทีม"
        description="เพิ่มและจัดการ Admin สำหรับแต่ละ Bot"
        actions={
          <div className="w-64">
            <BotPicker
              bots={botOptions}
              value={selectedBotId}
              onChange={(v) => setSelectedBotId(Number(v))}
              placeholder={botsLoading ? 'กำลังโหลด...' : 'เลือก Bot'}
            />
          </div>
        }
      />

      {!selectedBotId && (
        <EmptyState
          icon={Users}
          title="เลือก Bot เพื่อจัดการทีม"
          description="เลือก Bot จากเมนูด้านบนเพื่อดูและเพิ่ม Admin"
        />
      )}

      {selectedBotId && (
        <>
          <Panel icon={UserPlus} title="เพิ่ม Admin" description="ค้นหา User ด้วย Email เพื่อเพิ่มเป็น Admin">
            <div className="space-y-4">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
                <Input
                  placeholder="ค้นหาด้วย email..."
                  value={searchEmail}
                  onChange={(e) => setSearchEmail(e.target.value)}
                  className="pl-9"
                />
              </div>

              {searchLoading && deferredSearch.length >= 3 && (
                <div className="flex items-center gap-2 text-muted-foreground text-sm">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  กำลังค้นหา...
                </div>
              )}

              {filteredResults && filteredResults.length > 0 && (
                <div className="space-y-2">
                  {filteredResults.map((u: { id: number; name: string; email: string }) => (
                    <div
                      key={u.id}
                      className="flex items-center justify-between p-3 rounded-md border bg-card transition-colors hover:bg-muted/40"
                    >
                      <div className="flex items-center gap-3">
                        <Avatar className="h-9 w-9">
                          <AvatarFallback>
                            {u.name.substring(0, 2).toUpperCase()}
                          </AvatarFallback>
                        </Avatar>
                        <div>
                          <p className="font-medium text-sm">{u.name}</p>
                          <p className="text-sm text-muted-foreground">{u.email}</p>
                        </div>
                      </div>
                      <Button
                        size="sm"
                        onClick={() => handleAddAdmin(u.id)}
                        disabled={addAdmin.isPending}
                      >
                        {addAdmin.isPending ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <UserPlus className="h-4 w-4" strokeWidth={1.5} />
                        )}
                        <span className="ml-1">เพิ่ม</span>
                      </Button>
                    </div>
                  ))}
                </div>
              )}

              {deferredSearch.length >= 3 && !searchLoading && filteredResults?.length === 0 && (
                <p className="text-muted-foreground text-sm">ไม่พบ User ที่ค้นหา</p>
              )}
            </div>
          </Panel>

          <Panel
            icon={Users}
            title="รายชื่อ Admin"
            description="Admin ที่สามารถดูแลการสนทนาของ Bot นี้"
            actions={admins ? <Badge variant="secondary" className="tabular-nums">{admins.length} คน</Badge> : null}
          >
            {adminsLoading ? (
              <div className="flex items-center gap-2 text-muted-foreground py-4">
                <Loader2 className="h-4 w-4 animate-spin" />
                กำลังโหลด...
              </div>
            ) : admins && admins.length > 0 ? (
              <div className="space-y-2">
                {admins.map(
                  (admin: {
                    id: number;
                    user_id: number;
                    user?: { name: string; email: string };
                    active_conversations_count?: number;
                    created_at: string;
                  }) => (
                    <div
                      key={admin.id}
                      className="flex items-center justify-between p-3 rounded-md border bg-card transition-colors hover:bg-muted/30"
                    >
                      <div className="flex items-center gap-3">
                        <Avatar className="h-9 w-9">
                          <AvatarFallback>
                            {admin.user?.name?.substring(0, 2).toUpperCase() || 'AD'}
                          </AvatarFallback>
                        </Avatar>
                        <div>
                          <p className="font-medium text-sm">{admin.user?.name || 'Unknown'}</p>
                          <p className="text-sm text-muted-foreground">
                            {admin.user?.email || ''}
                          </p>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        {admin.active_conversations_count !== undefined && (
                          <Badge variant="outline" className="gap-1 tabular-nums">
                            <MessageSquare className="h-3 w-3" strokeWidth={1.5} />
                            {admin.active_conversations_count}
                          </Badge>
                        )}
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-destructive hover:text-destructive hover:bg-destructive/10"
                          onClick={() =>
                            setAdminToRemove({
                              id: admin.user_id,
                              name: admin.user?.name || 'Admin',
                            })
                          }
                        >
                          <Trash2 className="h-4 w-4" strokeWidth={1.5} />
                        </Button>
                      </div>
                    </div>
                  ),
                )}
              </div>
            ) : (
              <EmptyState
                icon={Users}
                title="ยังไม่มี Admin สำหรับ Bot นี้"
                size="sm"
              />
            )}
          </Panel>

          <Panel
            title="การกระจายงานอัตโนมัติ"
            description="ตั้งค่าการมอบหมายการสนทนาให้ Admin อัตโนมัติ"
          >
            <div className="space-y-6">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>เปิดใช้งาน Auto-assign</Label>
                  <p className="text-sm text-muted-foreground">
                    มอบหมายการสนทนาใหม่ให้ Admin อัตโนมัติ
                  </p>
                </div>
                <Switch
                  checked={selectedBot?.settings?.auto_assignment_enabled ?? false}
                  onCheckedChange={handleAutoAssignmentChange}
                  disabled={updateAutoAssignment.isPending}
                />
              </div>

              {selectedBot?.settings?.auto_assignment_enabled && (
                <div className="space-y-2">
                  <Label>โหมดการกระจายงาน</Label>
                  <Select
                    value={selectedBot?.settings?.auto_assignment_mode || 'round_robin'}
                    onValueChange={(v) =>
                      handleAutoAssignmentModeChange(v as 'round_robin' | 'load_balanced')
                    }
                    disabled={updateAutoAssignment.isPending}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="round_robin">
                        Round Robin — สลับคนตามลำดับ
                      </SelectItem>
                      <SelectItem value="load_balanced">
                        Load Balanced — ให้คนที่งานน้อยสุด
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              )}
            </div>
          </Panel>
        </>
      )}

      <AlertDialog open={!!adminToRemove} onOpenChange={() => setAdminToRemove(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ยืนยันการลบ Admin</AlertDialogTitle>
            <AlertDialogDescription>
              คุณต้องการลบ <strong>{adminToRemove?.name}</strong> ออกจากการเป็น Admin
              ของ Bot นี้หรือไม่? Admin จะไม่สามารถดูหรือตอบการสนทนาของ Bot นี้ได้อีก
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRemoveAdmin}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {removeAdmin.isPending ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
              ลบ Admin
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
