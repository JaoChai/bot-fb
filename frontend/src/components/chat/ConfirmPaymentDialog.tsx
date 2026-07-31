/**
 * ConfirmPaymentDialog
 *
 * Admin button + confirmation dialog for manually confirming a payment (e.g. when
 * EasySlip is down). Routes the confirmation through the bot's output pipeline so an
 * order is created just like the automatic slip-verification path.
 *
 * Shown only for LINE conversations whose bot has slip verification enabled.
 */
import { useId, useState } from 'react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BadgeCheck, Loader2 } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { getErrorMessage } from '@/lib/api';
import type { ConfirmPaymentResponse } from '@/hooks/chat/useConfirmPayment';

interface ConfirmPaymentDialogProps {
  onConfirm: (amount?: number) => Promise<ConfirmPaymentResponse>;
  isPending: boolean;
  /** คุม open จากข้างนอก — ไม่ส่งจะใช้ state ในตัวเอง */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** ส่ง false เมื่อจะเปิด dialog จากเมนูข้างนอกแทนปุ่มในตัว */
  showTrigger?: boolean;
}

export function ConfirmPaymentDialog({
  onConfirm,
  isPending,
  open: controlledOpen,
  onOpenChange,
  showTrigger = true,
}: ConfirmPaymentDialogProps) {
  const { toast } = useToast();
  const [internalOpen, setInternalOpen] = useState(false);
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? (onOpenChange ?? (() => {})) : setInternalOpen;
  const [amount, setAmount] = useState('');
  // หัวแชทบนมือถือ render dialog นี้ 2 instance (ปุ่มตรง + ตัวที่เมนู ⋮ สั่งเปิด)
  // id ตายตัวจึงชนกันได้ — useId ให้ id ที่ไม่ซ้ำต่อ instance
  const amountId = useId();

  const handleConfirm = async () => {
    const trimmed = amount.trim();
    let parsed: number | undefined;

    if (trimmed !== '') {
      parsed = Number(trimmed);
      if (!Number.isFinite(parsed) || parsed <= 0) {
        toast({
          title: 'ยอดเงินไม่ถูกต้อง',
          description: 'กรุณาระบุยอดเป็นตัวเลขที่มากกว่า 0 หรือเว้นว่างไว้',
          variant: 'destructive',
        });
        return;
      }
    }

    try {
      const result = await onConfirm(parsed);
      toast({
        title: 'ยืนยันรับเงินแล้ว',
        description: result.order_created
          ? 'สร้างออเดอร์เรียบร้อยและส่งข้อความยืนยันให้ลูกค้าแล้ว'
          : 'ส่งข้อความยืนยันให้ลูกค้าแล้ว',
      });
      setAmount('');
      setOpen(false);
    } catch (error) {
      toast({
        title: 'ยืนยันรับเงินไม่สำเร็จ',
        description: getErrorMessage(error),
        variant: 'destructive',
      });
    }
  };

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      {showTrigger && (
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={isPending}>
            {isPending ? (
              <Loader2 className="size-4 animate-spin sm:mr-1" />
            ) : (
              <BadgeCheck className="size-4 sm:mr-1" />
            )}
            <span className="hidden sm:inline">ยืนยันรับเงิน ✅</span>
          </Button>
        </AlertDialogTrigger>
      )}
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>ยืนยันรับเงินด้วยตนเอง?</AlertDialogTitle>
          <AlertDialogDescription>
            ระบบจะส่งข้อความยืนยันการชำระเงินให้ลูกค้า และ
            <strong> สร้างออเดอร์ </strong>
            เหมือนบอทยืนยันเอง ใช้เมื่อยืนยันสลิปด้วยมือ (เช่นตอนระบบตรวจสลิปล่ม)
          </AlertDialogDescription>
        </AlertDialogHeader>

        <div className="space-y-2">
          <Label htmlFor={amountId}>ยอดเงิน (บาท)</Label>
          <Input
            id={amountId}
            inputMode="decimal"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="เว้นว่าง = ใช้ยอดจากแชท"
          />
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel disabled={isPending}>ยกเลิก</AlertDialogCancel>
          <AlertDialogAction
            onClick={(e) => {
              e.preventDefault();
              void handleConfirm();
            }}
            disabled={isPending}
          >
            {isPending ? <Loader2 className="size-4 animate-spin mr-1" /> : null}
            ยืนยันรับเงิน
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
