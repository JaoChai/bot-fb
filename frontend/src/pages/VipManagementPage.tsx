import { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router';
import { toast } from 'sonner';
import { UserPlus, ChevronLeft, ChevronRight, Users } from 'lucide-react';
import { useVipCustomers, useRevokeVip, usePromoteVip } from '@/hooks/useVipCustomers';
import { useBots } from '@/hooks/useKnowledgeBase';
import { VipBadge } from '@/components/conversation/VipBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent } from '@/components/ui/card';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { PageHeader } from '@/components/connections';
import { Metric, BotPicker, EmptyState, ErrorState, Toolbar } from '@/components/common';
import type { VipCustomer } from '@/types/api';

const PAGE_SIZE = 20;

export function VipManagementPage() {
  const { botId: paramBotId } = useParams<{ botId?: string }>();
  const { data: botsResponse, isLoading: botsLoading } = useBots();
  const bots = botsResponse?.data ?? [];
  const [selectedBotId, setSelectedBotId] = useState<string | undefined>(paramBotId);

  useEffect(() => {
    if (paramBotId) return;
    if (selectedBotId) return;
    if (bots.length === 1) setSelectedBotId(String(bots[0].id));
  }, [paramBotId, selectedBotId, bots]);

  const activeBotId = paramBotId ?? selectedBotId;
  const showBotPicker = !paramBotId && bots.length > 1;

  const { data: vips, isLoading, error } = useVipCustomers(activeBotId);
  const revokeMutation = useRevokeVip(activeBotId);
  const promoteMutation = usePromoteVip(activeBotId);

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [promoteOpen, setPromoteOpen] = useState(false);
  const [promoteForm, setPromoteForm] = useState({ customerProfileId: '', content: '' });
  const [revokeTarget, setRevokeTarget] = useState<VipCustomer | null>(null);

  const filtered = useMemo(() => {
    if (!vips) return [];
    const q = search.trim().toLowerCase();
    if (!q) return vips;
    return vips.filter(
      (v) =>
        v.display_name?.toLowerCase().includes(q) ||
        String(v.customer_profile_id).includes(q),
    );
  }, [vips, search]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const visible = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  useEffect(() => {
    setPage(1);
  }, [search, activeBotId]);

  const total = vips?.length ?? 0;
  const autoCount = vips?.filter((v) => v.note_source === 'vip_auto').length ?? 0;
  const manualCount = total - autoCount;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('th-TH', { maximumFractionDigits: 0 }).format(amount);

  const formatDate = (iso: string | null) =>
    iso
      ? new Date(iso).toLocaleDateString('th-TH', {
          day: '2-digit',
          month: 'short',
          year: '2-digit',
        })
      : '—';

  const handlePromoteSubmit = () => {
    const id = Number(promoteForm.customerProfileId);
    if (!id || !promoteForm.content.trim()) return;
    promoteMutation.mutate(
      { customerProfileId: id, content: promoteForm.content.trim() },
      {
        onSuccess: () => {
          toast.success('เพิ่ม VIP สำเร็จ');
          setPromoteOpen(false);
          setPromoteForm({ customerProfileId: '', content: '' });
        },
        onError: () => toast.error('เพิ่ม VIP ไม่สำเร็จ'),
      },
    );
  };

  const handleRevokeConfirm = () => {
    if (!revokeTarget) return;
    revokeMutation.mutate(revokeTarget.customer_profile_id, {
      onSuccess: () => {
        toast.success('ยกเลิก VIP สำเร็จ');
        setRevokeTarget(null);
      },
      onError: () => toast.error('ยกเลิก VIP ไม่สำเร็จ'),
    });
  };

  const botOptions = bots.map((b) => ({ id: b.id, name: b.name }));

  return (
    <div className="space-y-6">
      <PageHeader
        title="ลูกค้า VIP"
        description="ระบบเพิ่ม VIP อัตโนมัติเมื่อลูกค้าชำระยืนยันตั้งแต่ 3 ครั้งขึ้นไป"
        actions={
          <div className="flex items-center gap-2">
            {showBotPicker && (
              <div className="w-48">
                <BotPicker
                  bots={botOptions}
                  value={selectedBotId}
                  onChange={setSelectedBotId}
                />
              </div>
            )}
            <Button onClick={() => setPromoteOpen(true)} disabled={!activeBotId} className="gap-2">
              <UserPlus className="h-4 w-4" strokeWidth={1.5} />
              เพิ่ม VIP ด้วยตนเอง
            </Button>
          </div>
        }
      />

      {!activeBotId && !botsLoading && (
        <EmptyState
          icon={Users}
          title={bots.length === 0 ? 'ยังไม่มีบอท' : 'เลือกบอทเพื่อดูรายการ VIP'}
          description={bots.length === 0 ? 'สร้างบอทก่อนเพื่อดูลูกค้า VIP' : undefined}
        />
      )}

      {activeBotId && isLoading && (
        <div className="py-10 text-center text-muted-foreground text-sm">กำลังโหลด...</div>
      )}

      {activeBotId && error && (
        <ErrorState
          title="เกิดข้อผิดพลาด"
          description={String(error)}
        />
      )}

      {activeBotId && !isLoading && !error && (
        <>
          <div className="grid grid-cols-3 gap-4">
            <Metric label="ทั้งหมด" value={total} />
            <Metric label="อัตโนมัติ" value={autoCount} />
            <Metric label="กำหนดเอง" value={manualCount} />
          </div>

          <Toolbar
            search={search}
            onSearchChange={setSearch}
            searchPlaceholder="ค้นหาชื่อลูกค้าหรือรหัส"
            filters={
              <span className="text-sm text-muted-foreground tabular-nums">
                {filtered.length}{search && ` / ${total}`} รายการ
              </span>
            }
          />

          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>ลูกค้า</TableHead>
                      <TableHead className="w-24">ประเภท</TableHead>
                      <TableHead className="w-24 text-right">ออเดอร์</TableHead>
                      <TableHead className="w-32 text-right">ยอดรวม</TableHead>
                      <TableHead className="w-28">ซื้อล่าสุด</TableHead>
                      <TableHead className="w-20 text-right">จัดการ</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {visible.map((vip) => (
                      <TableRow key={vip.customer_profile_id}>
                        <TableCell>
                          <div className="font-medium text-sm">
                            {vip.display_name ?? `#${vip.customer_profile_id}`}
                          </div>
                          <div className="text-xs text-muted-foreground">
                            รหัส #{vip.customer_profile_id}
                          </div>
                        </TableCell>
                        <TableCell>
                          <VipBadge
                            variant={vip.note_source === 'vip_manual' ? 'manual' : 'auto'}
                          />
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {vip.order_count}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          ฿{formatCurrency(vip.total_amount)}
                        </TableCell>
                        <TableCell className="text-muted-foreground tabular-nums">
                          {formatDate(vip.last_order_at)}
                        </TableCell>
                        <TableCell className="text-right">
                          {vip.note_source === 'vip_auto' ? (
                            <Button
                              size="sm"
                              variant="ghost"
                              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                              onClick={() => setRevokeTarget(vip)}
                            >
                              ยกเลิก
                            </Button>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                    {visible.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                          {search ? 'ไม่พบลูกค้าที่ตรงกับคำค้น' : 'ยังไม่มี VIP'}
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>

              {pageCount > 1 && (
                <div className="flex items-center justify-between border-t px-4 py-3">
                  <div className="text-sm text-muted-foreground tabular-nums">
                    หน้า {currentPage} / {pageCount}
                  </div>
                  <div className="flex items-center gap-1">
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={currentPage === 1}
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                    >
                      <ChevronLeft className="h-4 w-4" strokeWidth={1.5} />
                      ก่อนหน้า
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={currentPage === pageCount}
                      onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                    >
                      ถัดไป
                      <ChevronRight className="h-4 w-4" strokeWidth={1.5} />
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}

      <Dialog open={promoteOpen} onOpenChange={setPromoteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>เพิ่ม VIP ด้วยตนเอง</DialogTitle>
            <DialogDescription>
              กำหนดให้ลูกค้าเป็น VIP แบบกำหนดเอง — จะไม่ถูกแทนที่โดยระบบอัตโนมัติ
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">รหัสลูกค้า (Customer Profile ID)</label>
              <Input
                type="number"
                placeholder="เช่น 669"
                value={promoteForm.customerProfileId}
                onChange={(e) =>
                  setPromoteForm((p) => ({ ...p, customerProfileId: e.target.value }))
                }
              />
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">รายละเอียด VIP</label>
              <Input
                placeholder="เช่น ลูกค้า VIP ระดับพรีเมียม ดูแลเป็นพิเศษ"
                value={promoteForm.content}
                onChange={(e) => setPromoteForm((p) => ({ ...p, content: e.target.value }))}
              />
              <p className="text-xs text-muted-foreground">
                ข้อความนี้จะถูกส่งให้ AI ใช้ตอนสนทนากับลูกค้า (สูงสุด 2,000 ตัวอักษร)
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPromoteOpen(false)}>
              ยกเลิก
            </Button>
            <Button
              onClick={handlePromoteSubmit}
              disabled={
                !promoteForm.customerProfileId ||
                !promoteForm.content.trim() ||
                promoteMutation.isPending
              }
            >
              {promoteMutation.isPending ? 'กำลังเพิ่ม...' : 'เพิ่ม VIP'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!revokeTarget} onOpenChange={(open) => !open && setRevokeTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ยกเลิกสถานะ VIP?</AlertDialogTitle>
            <AlertDialogDescription>
              จะลบ memory note สำหรับ{' '}
              <span className="font-medium">
                {revokeTarget?.display_name ?? `#${revokeTarget?.customer_profile_id}`}
              </span>
              {' '}— AI จะไม่ใช้ context VIP กับลูกค้านี้อีก (แต่ถ้าลูกค้าซื้อครบเกณฑ์อีก ระบบจะเพิ่มกลับอัตโนมัติ)
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRevokeConfirm}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {revokeMutation.isPending ? 'กำลังยกเลิก...' : 'ยืนยันยกเลิก'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
