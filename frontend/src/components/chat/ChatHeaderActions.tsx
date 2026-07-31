/**
 * ปุ่ม action ของหัวแชท
 *
 * มือถือ (<640px): ยุบเป็นเมนู ⋮ เพราะจอ 390px ใส่ปุ่มได้ไม่พอ
 *   จนชื่อลูกค้าเหลือที่ไม่ถึง 40px
 * แท็บเล็ตขึ้นไป (≥640px): เรียงเป็นปุ่มแนวนอนเหมือนเดิม
 *
 * dialog ทั้งสองตัว render เป็น sibling ของเมนู ไม่ใช่ลูกของ menu item
 * เพราะ Radix จะ unmount ลูกของเมนูทันทีที่เมนูปิด
 */
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { BadgeCheck, Info, MoreVertical, RotateCcw } from 'lucide-react';
import { ClearContextDialog } from './ClearContextDialog';
import { ConfirmPaymentDialog } from './ConfirmPaymentDialog';
import type { ConfirmPaymentResponse } from '@/hooks/chat/useConfirmPayment';

interface ChatHeaderActionsProps {
  showConfirmPayment: boolean;
  onConfirmPayment: (amount?: number) => Promise<ConfirmPaymentResponse>;
  isConfirmPaymentPending: boolean;
  showClearContext: boolean;
  onClearContext: () => Promise<void>;
  isClearingContext: boolean;
  onShowInfo?: () => void;
}

type OpenDialog = 'payment' | 'clear' | null;

export function ChatHeaderActions({
  showConfirmPayment,
  onConfirmPayment,
  isConfirmPaymentPending,
  showClearContext,
  onClearContext,
  isClearingContext,
  onShowInfo,
}: ChatHeaderActionsProps) {
  const [openDialog, setOpenDialog] = useState<OpenDialog>(null);

  return (
    <>
      {/* มือถือ: เมนู ⋮ */}
      <div className="sm:hidden">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              className="size-11 sm:size-9"
              aria-label="ตัวเลือกเพิ่มเติม"
            >
              <MoreVertical className="size-5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {showConfirmPayment && (
              <DropdownMenuItem onSelect={() => setOpenDialog('payment')}>
                <BadgeCheck className="size-4 mr-2" />
                ยืนยันรับเงิน
              </DropdownMenuItem>
            )}
            {showClearContext && (
              <DropdownMenuItem onSelect={() => setOpenDialog('clear')}>
                <RotateCcw className="size-4 mr-2" />
                Reset context
              </DropdownMenuItem>
            )}
            {onShowInfo && (
              <DropdownMenuItem onSelect={onShowInfo}>
                <Info className="size-4 mr-2" />
                ข้อมูลลูกค้า
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {/* แท็บเล็ตขึ้นไป: ปุ่มแนวนอนเหมือนเดิม */}
      <div className="hidden sm:flex items-center gap-2">
        {showConfirmPayment && (
          <ConfirmPaymentDialog
            onConfirm={onConfirmPayment}
            isPending={isConfirmPaymentPending}
          />
        )}
        {showClearContext && (
          <ClearContextDialog
            onClearContext={onClearContext}
            isPending={isClearingContext}
          />
        )}
      </div>

      {/* dialog ที่เมนูมือถือสั่งเปิด — เป็น sibling ของเมนู ไม่ใช่ลูก */}
      {showConfirmPayment && (
        <ConfirmPaymentDialog
          onConfirm={onConfirmPayment}
          isPending={isConfirmPaymentPending}
          showTrigger={false}
          open={openDialog === 'payment'}
          onOpenChange={(next) => setOpenDialog(next ? 'payment' : null)}
        />
      )}
      {showClearContext && (
        <ClearContextDialog
          onClearContext={onClearContext}
          isPending={isClearingContext}
          showTrigger={false}
          open={openDialog === 'clear'}
          onOpenChange={(next) => setOpenDialog(next ? 'clear' : null)}
        />
      )}
    </>
  );
}
