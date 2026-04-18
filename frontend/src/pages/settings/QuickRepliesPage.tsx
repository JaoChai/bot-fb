import { useState, useCallback } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
import {
  Zap,
  Plus,
  Pencil,
  Trash2,
  Loader2,
  GripVertical,
  ChevronUp,
  ChevronDown,
} from 'lucide-react';
import { PageHeader } from '@/components/connections';
import { Panel, EmptyState, Toolbar } from '@/components/common';
import {
  useQuickReplies,
  useCreateQuickReply,
  useUpdateQuickReply,
  useDeleteQuickReply,
  useToggleQuickReply,
  useReorderQuickReplies,
} from '@/hooks/useQuickReplies';
import type { QuickReply, QuickReplyInput } from '@/types/quick-reply';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface QuickReplyFormData {
  shortcut: string;
  title: string;
  content: string;
  category: string;
  is_active: boolean;
}

const defaultFormData: QuickReplyFormData = {
  shortcut: '',
  title: '',
  content: '',
  category: '',
  is_active: true,
};

export function QuickRepliesPage() {
  const { user } = useAuthStore();
  const isOwner = user?.role === 'owner';

  // Queries and mutations
  const { data: quickReplies = [], isLoading, refetch } = useQuickReplies();
  const createMutation = useCreateQuickReply();
  const deleteMutation = useDeleteQuickReply();
  const toggleMutation = useToggleQuickReply();
  const reorderMutation = useReorderQuickReplies();

  // Local state
  const [searchQuery, setSearchQuery] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<QuickReply | null>(null);
  const [formData, setFormData] = useState<QuickReplyFormData>(defaultFormData);

  // Get update mutation for the editing ID
  const updateMutation = useUpdateQuickReply(editingId ?? 0);

  // Filtered quick replies
  const filteredQuickReplies = quickReplies.filter((qr) => {
    if (!searchQuery) return true;
    const query = searchQuery.toLowerCase();
    return (
      qr.shortcut.toLowerCase().includes(query) ||
      qr.title.toLowerCase().includes(query) ||
      qr.content.toLowerCase().includes(query)
    );
  });

  // Handlers
  const handleOpenCreate = useCallback(() => {
    setEditingId(null);
    setFormData(defaultFormData);
    setIsDialogOpen(true);
  }, []);

  const handleOpenEdit = useCallback((qr: QuickReply) => {
    setEditingId(qr.id);
    setFormData({
      shortcut: qr.shortcut,
      title: qr.title,
      content: qr.content,
      category: qr.category ?? '',
      is_active: qr.is_active,
    });
    setIsDialogOpen(true);
  }, []);

  const handleCloseDialog = useCallback(() => {
    setIsDialogOpen(false);
    setEditingId(null);
    setFormData(defaultFormData);
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Validate shortcut format
    if (!/^[a-z0-9_-]+$/.test(formData.shortcut)) {
      toast.error('Shortcut ต้องเป็นตัวอักษรพิมพ์เล็ก ตัวเลข - หรือ _ เท่านั้น');
      return;
    }

    const input: QuickReplyInput = {
      shortcut: formData.shortcut,
      title: formData.title,
      content: formData.content,
      category: formData.category || null,
      is_active: formData.is_active,
    };

    try {
      if (editingId) {
        await updateMutation.mutateAsync(input);
        toast.success('บันทึก Quick Reply สำเร็จ');
      } else {
        await createMutation.mutateAsync(input);
        toast.success('สร้าง Quick Reply สำเร็จ');
      }
      handleCloseDialog();
    } catch {
      toast.error(editingId ? 'ไม่สามารถบันทึกได้' : 'ไม่สามารถสร้างได้');
    }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;

    try {
      await deleteMutation.mutateAsync(deleteTarget.id);
      toast.success('ลบ Quick Reply สำเร็จ');
      setDeleteTarget(null);
    } catch {
      toast.error('ไม่สามารถลบได้');
    }
  };

  const handleToggle = async (qr: QuickReply) => {
    try {
      await toggleMutation.mutateAsync(qr.id);
      toast.success(qr.is_active ? 'ปิดใช้งานแล้ว' : 'เปิดใช้งานแล้ว');
    } catch {
      toast.error('ไม่สามารถเปลี่ยนสถานะได้');
    }
  };

  const handleMoveUp = async (index: number) => {
    if (index === 0) return;
    const newOrder = [...quickReplies];
    [newOrder[index - 1], newOrder[index]] = [newOrder[index], newOrder[index - 1]];
    try {
      await reorderMutation.mutateAsync({ ids: newOrder.map((qr) => qr.id) });
    } catch {
      toast.error('ไม่สามารถเรียงลำดับได้');
      refetch();
    }
  };

  const handleMoveDown = async (index: number) => {
    if (index === quickReplies.length - 1) return;
    const newOrder = [...quickReplies];
    [newOrder[index], newOrder[index + 1]] = [newOrder[index + 1], newOrder[index]];
    try {
      await reorderMutation.mutateAsync({ ids: newOrder.map((qr) => qr.id) });
    } catch {
      toast.error('ไม่สามารถเรียงลำดับได้');
      refetch();
    }
  };

  // Non-owner view
  if (!isOwner) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Quick Replies"
          description="คำตอบสำเร็จรูปสำหรับการแชท"
          backTo="/settings"
        />
        <EmptyState
          icon={Zap}
          title="ไม่มีสิทธิ์จัดการ"
          description="เฉพาะ Owner เท่านั้นที่สามารถจัดการ Quick Replies ได้"
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title="Quick Replies"
        description="จัดการคำตอบสำเร็จรูปสำหรับทีม"
        backTo="/settings"
        actions={
          <Button onClick={handleOpenCreate}>
            <Plus className="h-4 w-4 mr-2" strokeWidth={2} />
            สร้างใหม่
          </Button>
        }
      />

      {/* Search and List */}
      <Panel
        icon={Zap}
        title="รายการ Quick Replies"
        description={`${quickReplies.length} รายการ`}
      >
        <div className="space-y-4">
          {/* Search */}
          <Toolbar
            search={searchQuery}
            onSearchChange={setSearchQuery}
            searchPlaceholder="ค้นหา shortcut, title หรือ content..."
          />

          {/* List */}
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : filteredQuickReplies.length === 0 ? (
            <EmptyState
              icon={Zap}
              title={searchQuery ? 'ไม่พบรายการ' : 'ยังไม่มี Quick Replies'}
              description={searchQuery ? 'ลองค้นหาด้วยคำอื่น' : 'สร้าง Quick Reply แรกของคุณ'}
              action={
                !searchQuery ? (
                  <Button onClick={handleOpenCreate}>
                    <Plus className="h-4 w-4 mr-2" strokeWidth={2} />
                    สร้างใหม่
                  </Button>
                ) : undefined
              }
            />
          ) : (
            <ScrollArea className="h-[400px]">
              <div className="space-y-2">
                {filteredQuickReplies.map((qr, index) => (
                  <div
                    key={qr.id}
                    className={cn(
                      'flex items-center gap-3 p-3 rounded-lg border transition-colors',
                      qr.is_active
                        ? 'bg-background hover:bg-muted/40'
                        : 'bg-muted/50 opacity-60'
                    )}
                  >
                    {/* Reorder buttons */}
                    <div className="flex flex-col gap-0.5">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5"
                        disabled={index === 0 || reorderMutation.isPending}
                        onClick={() => handleMoveUp(index)}
                      >
                        <ChevronUp className="h-3 w-3" strokeWidth={1.5} />
                      </Button>
                      <GripVertical className="h-4 w-4 text-muted-foreground mx-auto" strokeWidth={1.5} />
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5"
                        disabled={
                          index === quickReplies.length - 1 ||
                          reorderMutation.isPending
                        }
                        onClick={() => handleMoveDown(index)}
                      >
                        <ChevronDown className="h-3 w-3" strokeWidth={1.5} />
                      </Button>
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <Badge
                          variant="outline"
                          className="font-mono text-xs"
                        >
                          /{qr.shortcut}
                        </Badge>
                        <span className="font-medium truncate">{qr.title}</span>
                        {qr.category && (
                          <Badge variant="secondary" className="text-xs">
                            {qr.category}
                          </Badge>
                        )}
                      </div>
                      <p className="text-sm text-muted-foreground line-clamp-2">
                        {qr.content}
                      </p>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-2">
                      <Switch
                        checked={qr.is_active}
                        onCheckedChange={() => handleToggle(qr)}
                        disabled={toggleMutation.isPending}
                      />
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleOpenEdit(qr)}
                      >
                        <Pencil className="h-4 w-4" strokeWidth={1.5} />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="text-destructive hover:text-destructive"
                        onClick={() => setDeleteTarget(qr)}
                      >
                        <Trash2 className="h-4 w-4" strokeWidth={1.5} />
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </ScrollArea>
          )}
        </div>
      </Panel>

      {/* Create/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editingId ? 'แก้ไข Quick Reply' : 'สร้าง Quick Reply'}
            </DialogTitle>
            <DialogDescription>
              {editingId
                ? 'แก้ไขข้อมูล Quick Reply'
                : 'สร้างคำตอบสำเร็จรูปใหม่สำหรับทีม'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="shortcut">Shortcut</Label>
              <div className="flex items-center gap-2">
                <span className="text-muted-foreground">/</span>
                <Input
                  id="shortcut"
                  value={formData.shortcut}
                  onChange={(e) =>
                    setFormData((prev) => ({
                      ...prev,
                      shortcut: e.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''),
                    }))
                  }
                  placeholder="hello"
                  className="font-mono"
                  required
                />
              </div>
              <p className="text-xs text-muted-foreground">
                ใช้ a-z, 0-9, - หรือ _ เท่านั้น
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="title">Title</Label>
              <Input
                id="title"
                value={formData.title}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, title: e.target.value }))
                }
                placeholder="ทักทายลูกค้า"
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="content">Content</Label>
              <Textarea
                id="content"
                value={formData.content}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, content: e.target.value }))
                }
                placeholder="สวัสดีครับ ยินดีให้บริการครับ..."
                rows={4}
                required
              />
              <p className="text-xs text-muted-foreground">
                {formData.content.length}/5000 ตัวอักษร
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="category">Category (optional)</Label>
              <Input
                id="category"
                value={formData.category}
                onChange={(e) =>
                  setFormData((prev) => ({ ...prev, category: e.target.value }))
                }
                placeholder="ทักทาย, คำถาม, อื่นๆ"
              />
            </div>

            <div className="flex items-center justify-between">
              <Label htmlFor="is_active">เปิดใช้งาน</Label>
              <Switch
                id="is_active"
                checked={formData.is_active}
                onCheckedChange={(checked) =>
                  setFormData((prev) => ({ ...prev, is_active: checked }))
                }
              />
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={handleCloseDialog}
              >
                ยกเลิก
              </Button>
              <Button
                type="submit"
                disabled={
                  createMutation.isPending || updateMutation.isPending
                }
              >
                {createMutation.isPending || updateMutation.isPending ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    กำลังบันทึก...
                  </>
                ) : editingId ? (
                  'บันทึก'
                ) : (
                  'สร้าง'
                )}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ยืนยันการลบ</AlertDialogTitle>
            <AlertDialogDescription>
              คุณต้องการลบ Quick Reply "{deleteTarget?.title}" หรือไม่?
              การกระทำนี้ไม่สามารถย้อนกลับได้
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMutation.isPending ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  กำลังลบ...
                </>
              ) : (
                'ลบ'
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

