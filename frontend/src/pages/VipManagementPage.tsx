import { useEffect, useState } from 'react';
import { useParams } from 'react-router';
import { useVipCustomers, useRevokeVip, usePromoteVip } from '@/hooks/useVipCustomers';
import { useBots } from '@/hooks/useKnowledgeBase';
import { VipBadge } from '@/components/conversation/VipBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

export function VipManagementPage() {
  const { botId: paramBotId } = useParams<{ botId?: string }>();
  const { data: botsResponse, isLoading: botsLoading } = useBots();
  const bots = botsResponse?.data ?? [];
  const [selectedBotId, setSelectedBotId] = useState<string | undefined>(paramBotId);

  useEffect(() => {
    if (paramBotId) return;
    if (selectedBotId) return;
    if (bots.length === 1) {
      setSelectedBotId(String(bots[0].id));
    }
  }, [paramBotId, selectedBotId, bots]);

  const activeBotId = paramBotId ?? selectedBotId;
  const showBotPicker = !paramBotId && bots.length > 1;

  const { data: vips, isLoading, error } = useVipCustomers(activeBotId);
  const revokeMutation = useRevokeVip(activeBotId);
  const promoteMutation = usePromoteVip(activeBotId);
  const [promoteForm, setPromoteForm] = useState({ customerProfileId: '', content: '' });

  const total = vips?.length ?? 0;
  const autoCount = vips?.filter((v) => v.note_source === 'vip_auto').length ?? 0;
  const manualCount = total - autoCount;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">ลูกค้า VIP</h1>
          <p className="text-sm text-muted-foreground">
            ระบบจะเพิ่ม VIP ให้ลูกค้าอัตโนมัติเมื่อมียอดชำระยืนยันตั้งแต่ 3 ครั้งขึ้นไป
          </p>
        </div>
        {showBotPicker && (
          <div className="w-64 shrink-0">
            <Select value={selectedBotId} onValueChange={setSelectedBotId}>
              <SelectTrigger>
                <SelectValue placeholder="เลือกบอท" />
              </SelectTrigger>
              <SelectContent>
                {bots.map((bot) => (
                  <SelectItem key={bot.id} value={String(bot.id)}>
                    {bot.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      {!activeBotId && !botsLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            {bots.length === 0
              ? 'ยังไม่มีบอท — สร้างบอทก่อนเพื่อดูลูกค้า VIP'
              : 'เลือกบอทเพื่อดูรายการ VIP'}
          </CardContent>
        </Card>
      )}

      {activeBotId && isLoading && (
        <div className="py-10 text-center text-muted-foreground">กำลังโหลด...</div>
      )}

      {activeBotId && error && (
        <div className="py-10 text-center text-destructive">
          เกิดข้อผิดพลาด: {String(error)}
        </div>
      )}

      {activeBotId && !isLoading && !error && (
        <>
          <div className="grid grid-cols-3 gap-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">ทั้งหมด</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-bold">{total}</CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">อัตโนมัติ</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-bold">{autoCount}</CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">Manual</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-bold">{manualCount}</CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Manual Promote</CardTitle>
            </CardHeader>
            <CardContent className="flex gap-2">
              <Input
                type="number"
                placeholder="customer_profile_id"
                value={promoteForm.customerProfileId}
                onChange={(e) =>
                  setPromoteForm((p) => ({ ...p, customerProfileId: e.target.value }))
                }
              />
              <Input
                placeholder="เนื้อหา note"
                value={promoteForm.content}
                onChange={(e) => setPromoteForm((p) => ({ ...p, content: e.target.value }))}
                className="flex-1"
              />
              <Button
                disabled={
                  !promoteForm.customerProfileId ||
                  !promoteForm.content ||
                  promoteMutation.isPending
                }
                onClick={() => {
                  promoteMutation.mutate({
                    customerProfileId: Number(promoteForm.customerProfileId),
                    content: promoteForm.content,
                  });
                  setPromoteForm({ customerProfileId: '', content: '' });
                }}
              >
                Promote
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>รายการ VIP</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>ลูกค้า</TableHead>
                    <TableHead>ประเภท</TableHead>
                    <TableHead>จำนวน orders</TableHead>
                    <TableHead>ยอดรวม</TableHead>
                    <TableHead>ล่าสุด</TableHead>
                    <TableHead>Action</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {vips?.map((vip) => (
                    <TableRow key={vip.customer_profile_id}>
                      <TableCell className="font-medium">
                        {vip.display_name ?? `#${vip.customer_profile_id}`}
                      </TableCell>
                      <TableCell>
                        <VipBadge
                          variant={vip.note_source === 'vip_manual' ? 'manual' : 'auto'}
                        />
                      </TableCell>
                      <TableCell>{vip.order_count}</TableCell>
                      <TableCell>{vip.total_amount.toLocaleString()} บาท</TableCell>
                      <TableCell>
                        {vip.last_order_at
                          ? new Date(vip.last_order_at).toLocaleDateString('th-TH')
                          : '-'}
                      </TableCell>
                      <TableCell>
                        {vip.note_source === 'vip_auto' && (
                          <Button
                            size="sm"
                            variant="destructive"
                            disabled={revokeMutation.isPending}
                            onClick={() => revokeMutation.mutate(vip.customer_profile_id)}
                          >
                            Revoke
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                  {(!vips || vips.length === 0) && (
                    <TableRow>
                      <TableCell colSpan={6} className="text-center text-muted-foreground">
                        ยังไม่มี VIP
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
