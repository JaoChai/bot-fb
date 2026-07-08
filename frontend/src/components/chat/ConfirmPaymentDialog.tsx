/**
 * ConfirmPaymentDialog
 *
 * Admin button + confirmation dialog for manually confirming a payment (e.g. when
 * EasySlip is down). Routes the confirmation through the bot's output pipeline so an
 * order is created just like the automatic slip-verification path.
 *
 * Shown only for LINE conversations whose bot has slip verification enabled.
 */
import { useState } from 'react';
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
}

export function ConfirmPaymentDialog({ onConfirm, isPending }: ConfirmPaymentDialogProps) {
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState('');

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
          <Label htmlFor="confirm-payment-amount">ยอดเงิน (บาท)</Label>
          <Input
            id="confirm-payment-amount"
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
