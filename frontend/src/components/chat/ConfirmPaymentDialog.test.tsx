import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ConfirmPaymentDialog } from './ConfirmPaymentDialog';

const toast = vi.fn();
vi.mock('@/hooks/use-toast', () => ({
  useToast: () => ({ toast }),
}));

describe('ConfirmPaymentDialog', () => {
  beforeEach(() => {
    toast.mockClear();
  });

  it('confirms with no amount (uses detected amount from chat)', async () => {
    const onConfirm = vi.fn().mockResolvedValue({ order_created: true });
    render(<ConfirmPaymentDialog onConfirm={onConfirm} isPending={false} />);

    fireEvent.click(screen.getByRole('button', { name: /ยืนยันรับเงิน/ }));
    // Dialog action button (second match in the confirm dialog)
    fireEvent.click(screen.getByRole('button', { name: 'ยืนยันรับเงิน' }));

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(undefined));
    await waitFor(() =>
      expect(toast).toHaveBeenCalledWith(
        expect.objectContaining({ title: 'ยืนยันรับเงินแล้ว' })
      )
    );
  });

  it('passes the typed amount to onConfirm', async () => {
    const onConfirm = vi.fn().mockResolvedValue({ order_created: false });
    render(<ConfirmPaymentDialog onConfirm={onConfirm} isPending={false} />);

    fireEvent.click(screen.getByRole('button', { name: /ยืนยันรับเงิน/ }));
    fireEvent.change(screen.getByLabelText('ยอดเงิน (บาท)'), {
      target: { value: '1500' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'ยืนยันรับเงิน' }));

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(1500));
  });

  it('rejects an invalid amount without calling onConfirm', async () => {
    const onConfirm = vi.fn().mockResolvedValue({ order_created: false });
    render(<ConfirmPaymentDialog onConfirm={onConfirm} isPending={false} />);

    fireEvent.click(screen.getByRole('button', { name: /ยืนยันรับเงิน/ }));
    fireEvent.change(screen.getByLabelText('ยอดเงิน (บาท)'), {
      target: { value: '-5' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'ยืนยันรับเงิน' }));

    await waitFor(() =>
      expect(toast).toHaveBeenCalledWith(
        expect.objectContaining({ variant: 'destructive' })
      )
    );
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
