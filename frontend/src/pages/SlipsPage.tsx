import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { Receipt, ChevronLeft, ChevronRight, MessageSquare, Wallet, AlertTriangle, ShieldAlert } from 'lucide-react';
import { useSlips } from '@/hooks/useSlips';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useChatStore } from '@/stores/chatStore';
import { slipStatusMeta, STATUS_GROUPS, STATUS_GROUP_LABELS, bangkokTodayRange } from '@/lib/slipStatus';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/connections';
import { Metric, BotPicker, EmptyState, ErrorState, Toolbar } from '@/components/common';
import type { Slip } from '@/types/api';

const PER_PAGE = 20;

function formatBaht(n: number | null): string {
  if (n == null) return '-';
  return `฿${n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDateTime(iso: string): string {
  // แสดงเป็นเวลาไทย
  return new Date(iso).toLocaleString('th-TH', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit', month: '2-digit', year: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

export function SlipsPage() {
  const navigate = useNavigate();
  const selectConversation = useChatStore((s) => s.selectConversation);
  const { data: botsResponse } = useBots();
  const bots = botsResponse?.data ?? [];

  const [selectedBotId, setSelectedBotId] = useState<number | undefined>(undefined);
  const [statusGroup, setStatusGroup] = useState<string>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const todayRange = useMemo(() => bangkokTodayRange(), []);

  const statusCsv = STATUS_GROUPS[statusGroup]?.join(',') || undefined;

  const { data, isLoading, error } = useSlips({
    bot_id: selectedBotId,
    status: statusCsv,
    date_from: todayRange.date_from,
    date_to: todayRange.date_to,
    search: search.trim() || undefined,
    page,
    per_page: PER_PAGE,
  });

  const slips = data?.slips ?? [];
  const summary = data?.meta.summary;
  const pageCount = data?.meta.last_page ?? 1;

  const botOptions = bots.map((b) => ({ id: b.id, name: b.name }));
  const showBotPicker = botOptions.length > 1;

  // เปิดแชทของ conversation นี้: chat page เลือก conversation ผ่าน Zustand
  // store (ไม่มี query param สำหรับ conversation) และ list ถูก filter ด้วย
  // botId ใน URL — ถ้ายังไม่ได้เลือกบอท (ดูรวมทุกบอท) จะพาไปหน้าแชทเฉยๆ
  // ให้ผู้ใช้เลือกบอทเอง เพราะไม่รู้ว่า conversation นี้เป็นของบอทไหน
  const handleOpenChat = (conversationId: number) => {
    selectConversation(conversationId);
    navigate(selectedBotId ? `/chat?botId=${selectedBotId}` : '/chat');
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="สลิป / การชำระเงิน"
        description="รายการผลตรวจสลิปจาก EasySlip (ข้อมูลวันนี้)"
        actions={
          showBotPicker ? (
            <div className="w-48">
              <BotPicker bots={botOptions} value={selectedBotId} onChange={(id) => setSelectedBotId(Number(id))} />
            </div>
          ) : undefined
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Metric icon={Wallet} label="เงินเข้าวันนี้" value={formatBaht(summary?.total_amount_passed ?? 0)} />
        <Metric icon={Receipt} label="สลิปวันนี้" value={summary?.count_total ?? 0} />
        <Metric
          icon={AlertTriangle}
          label="ผิดปกติ"
          value={
            summary?.count_abnormal ? (
              <span className="text-destructive">{summary.count_abnormal}</span>
            ) : (summary?.count_abnormal ?? 0)
          }
        />
        <Metric
          icon={ShieldAlert}
          label="error ระบบ"
          value={
            summary?.count_system_error ? (
              <span className="text-destructive">{summary.count_system_error}</span>
            ) : (summary?.count_system_error ?? 0)
          }
        />
      </div>

      <Toolbar
        search={search}
        onSearchChange={(v) => { setSearch(v); setPage(1); }}
        searchPlaceholder="ค้นหาเลขอ้างอิงหรือชื่อลูกค้า"
        filters={
          <Select value={statusGroup} onValueChange={(v) => { setStatusGroup(v); setPage(1); }}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="สถานะ" />
            </SelectTrigger>
            <SelectContent>
              {Object.keys(STATUS_GROUPS).map((key) => (
                <SelectItem key={key} value={key}>{STATUS_GROUP_LABELS[key]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        }
      />

      {isLoading && (
        <div className="py-10 text-center text-muted-foreground text-sm">กำลังโหลด...</div>
      )}
      {error && <ErrorState title="เกิดข้อผิดพลาด" description={String(error)} />}

      {!isLoading && !error && (
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">เวลา</TableHead>
                    <TableHead>ลูกค้า</TableHead>
                    <TableHead className="w-32 text-right">ยอด</TableHead>
                    <TableHead className="w-36">เลขอ้างอิง</TableHead>
                    <TableHead className="w-32">บัญชีรับ</TableHead>
                    <TableHead className="w-28">สถานะ</TableHead>
                    <TableHead className="w-16 text-right">แชท</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {slips.map((slip: Slip) => {
                    const meta = slipStatusMeta(slip.status);
                    return (
                      <TableRow key={slip.id}>
                        <TableCell className="text-muted-foreground tabular-nums text-xs">
                          {formatDateTime(slip.created_at)}
                        </TableCell>
                        <TableCell className="text-sm">{slip.customer_name ?? '-'}</TableCell>
                        <TableCell className="text-right tabular-nums font-medium">{formatBaht(slip.amount)}</TableCell>
                        <TableCell className="text-xs tabular-nums text-muted-foreground">{slip.trans_ref ?? '-'}</TableCell>
                        <TableCell className="text-xs tabular-nums text-muted-foreground">{slip.receiver_account ?? '-'}</TableCell>
                        <TableCell><Badge variant={meta.variant}>{meta.label}</Badge></TableCell>
                        <TableCell className="text-right">
                          {slip.conversation_id && (
                            <Button size="sm" variant="ghost"
                              onClick={() => handleOpenChat(slip.conversation_id as number)}>
                              <MessageSquare className="size-4" strokeWidth={1.5} />
                            </Button>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                  {slips.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                        <EmptyState icon={Receipt} title="ยังไม่มีรายการสลิปวันนี้" size="sm" />
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>

            {pageCount > 1 && (
              <div className="flex items-center justify-between border-t px-4 py-3">
                <div className="text-sm text-muted-foreground tabular-nums">หน้า {page} / {pageCount}</div>
                <div className="flex items-center gap-1">
                  <Button size="sm" variant="outline" disabled={page === 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}>
                    <ChevronLeft className="size-4" strokeWidth={1.5} />ก่อนหน้า
                  </Button>
                  <Button size="sm" variant="outline" disabled={page === pageCount}
                    onClick={() => setPage((p) => Math.min(pageCount, p + 1))}>
                    ถัดไป<ChevronRight className="size-4" strokeWidth={1.5} />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
