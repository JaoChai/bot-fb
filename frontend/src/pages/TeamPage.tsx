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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
  Users,
  Search,
  UserPlus,
  Trash2,
  Bot,
  MessageSquare,
  Settings2,
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

  // Redirect non-owners
  if (user?.role !== 'owner') {
    return <Navigate to="/dashboard" replace />;
  }

  const selectedBot = bots?.find((b: { id: number }) => b.id === selectedBotId);

  // Filter search results to exclude current user and existing admins
  const filteredResults = searchResults?.filter(
    (u: { id: number }) =>
      u.id !== user?.id &&
      !admins?.some((a: { user_id: number }) => a.user_id === u.id)
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

  return (
    <div className="container max-w-4xl py-6 space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <Users className="h-6 w-6" />
          จัดการทีม
        </h1>
        <p className="text-muted-foreground mt-1">
          เพิ่มและจัดการ Admin สำหรับแต่ละ Bot
        </p>
      </div>

      {/* Bot Selector */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg flex items-center gap-2">
            <Bot className="h-5 w-5" />
            เลือก Bot
          </CardTitle>
          <CardDescription>เลือก Bot ที่ต้องการจัดการทีม Admin</CardDescription>
        </CardHeader>
        <CardContent>
          <Select
            value={selectedBotId?.toString() || ''}
            onValueChange={(v) => setSelectedBotId(Number(v))}
          >
            <SelectTrigger className="w-full">
              <SelectValue placeholder={botsLoading ? 'กำลังโหลด...' : 'เลือก Bot'} />
            </SelectTrigger>
            <SelectContent>
              {bots?.map((bot: { id: number; name: string }) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardContent>
      </Card>

      {selectedBotId && (
        <>
          {/* Add Admin */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <UserPlus className="h-5 w-5" />
                เพิ่ม Admin
              </CardTitle>
              <CardDescription>ค้นหา User ด้วย Email เพื่อเพิ่มเป็น Admin</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="ค้นหาด้วย email..."
                  value={searchEmail}
                  onChange={(e) => setSearchEmail(e.target.value)}
                  className="pl-9"
                />
              </div>

              {searchLoading && deferredSearch.length >= 3 && (
                <div className="flex items-center gap-2 text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  กำลังค้นหา...
                </div>
              )}

              {filteredResults && filteredResults.length > 0 && (
                <div className="space-y-2">
                  {filteredResults.map((u: { id: number; name: string; email: string }) => (
                    <div
                      key={u.id}
                      className="flex items-center justify-between p-3 rounded-lg border bg-card hover:bg-accent/50 transition-colors"
                    >
                      <div className="flex items-center gap-3">
                        <Avatar className="h-9 w-9">
                          <AvatarFallback>
                            {u.name.substring(0, 2).toUpperCase()}
                          </AvatarFallback>
                        </Avatar>
                        <div>
                          <p className="font-medium">{u.name}</p>
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
                          <UserPlus className="h-4 w-4" />
                        )}
                        <span className="ml-1">เพิ่ม</span>
                      </Button>
                    </div>
                  ))}
                </div>
              )}

              {deferredSearch.length >= 3 &&
                !searchLoading &&
                filteredResults?.length === 0 && (
                  <p className="text-muted-foreground text-sm">ไม่พบ User ที่ค้นหา</p>
                )}
            </CardContent>
          </Card>

          {/* Admin List */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Users className="h-5 w-5" />
                รายชื่อ Admin
                {admins && <Badge variant="secondary">{admins.length} คน</Badge>}
              </CardTitle>
              <CardDescription>Admin ที่สามารถดูแลการสนทนาของ Bot นี้</CardDescription>
            </CardHeader>
            <CardContent>
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
                        className="flex items-center justify-between p-3 rounded-lg border"
                      >
                        <div className="flex items-center gap-3">
                          <Avatar className="h-9 w-9">
                            <AvatarFallback>
                              {admin.user?.name?.substring(0, 2).toUpperCase() || 'AD'}
                            </AvatarFallback>
                          </Avatar>
                          <div>
                            <p className="font-medium">{admin.user?.name || 'Unknown'}</p>
                            <p className="text-sm text-muted-foreground">
                              {admin.user?.email || ''}
                            </p>
                          </div>
                        </div>
                        <div className="flex items-center gap-3">
                          {admin.active_conversations_count !== undefined && (
                            <Badge variant="outline" className="gap-1">
                              <MessageSquare className="h-3 w-3" />
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
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    )
                  )}
                </div>
              ) : (
                <p className="text-muted-foreground py-4 text-center">
                  ยังไม่มี Admin สำหรับ Bot นี้
                </p>
              )}
            </CardContent>
          </Card>

          {/* Auto-Assignment Settings */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Settings2 className="h-5 w-5" />
                การกระจายงานอัตโนมัติ
              </CardTitle>
              <CardDescription>
                ตั้งค่าการมอบหมายการสนทนาให้ Admin อัตโนมัติ
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
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
                        Round Robin - สลับคนตามลำดับ
                      </SelectItem>
                      <SelectItem value="load_balanced">
                        Load Balanced - ให้คนที่งานน้อยสุด
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}

      {/* Remove Admin Dialog */}
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
              {removeAdmin.isPending ? (
                <Loader2 className="h-4 w-4 animate-spin mr-2" />
              ) : null}
              ลบ Admin
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

