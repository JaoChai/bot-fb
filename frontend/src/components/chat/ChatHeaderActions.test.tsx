import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ChatHeaderActions } from './ChatHeaderActions';

vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));

// Radix ต้องการ API พวกนี้ซึ่ง jsdom ไม่มี
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn(() => false);
  Element.prototype.setPointerCapture = vi.fn();
  Element.prototype.releasePointerCapture = vi.fn();
  Element.prototype.scrollIntoView = vi.fn();
});

/** DropdownMenuTrigger ของ Radix เปิดด้วย pointerdown ไม่ใช่ click */
function openMenu() {
  fireEvent.pointerDown(
    screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' }),
    { button: 0, ctrlKey: false, pointerType: 'mouse' }
  );
}

const baseProps = {
  showConfirmPayment: true,
  onConfirmPayment: vi.fn().mockResolvedValue({ order_created: false }),
  isConfirmPaymentPending: false,
  showClearContext: true,
  onClearContext: vi.fn().mockResolvedValue(undefined),
  isClearingContext: false,
  onShowInfo: vi.fn(),
};

describe('ChatHeaderActions', () => {
  it('มีปุ่มเมนู overflow ที่มี accessible name', () => {
    render(<ChatHeaderActions {...baseProps} />);

    expect(
      screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' })
    ).toBeInTheDocument();
  });

  it('เมนูมีครบ 3 รายการ: ยืนยันรับเงิน / reset context / ข้อมูลลูกค้า', () => {
    render(<ChatHeaderActions {...baseProps} />);

    openMenu();

    expect(screen.getByRole('menuitem', { name: /ยืนยันรับเงิน/ })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /Reset context/i })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /ข้อมูลลูกค้า/ })).toBeInTheDocument();
  });

  it('ซ่อนรายการยืนยันรับเงินเมื่อบอทไม่ได้เปิดตรวจสลิป', () => {
    render(<ChatHeaderActions {...baseProps} showConfirmPayment={false} />);

    openMenu();

    expect(screen.queryByRole('menuitem', { name: /ยืนยันรับเงิน/ })).not.toBeInTheDocument();
  });

  it('ซ่อนรายการ reset context สำหรับ Telegram', () => {
    render(<ChatHeaderActions {...baseProps} showClearContext={false} />);

    openMenu();

    expect(screen.queryByRole('menuitem', { name: /Reset context/i })).not.toBeInTheDocument();
  });

  it('เลือก reset context จากเมนูแล้ว dialog เปิดขึ้นจริง (ไม่โดนเมนูพาปิดไปด้วย)', async () => {
    render(<ChatHeaderActions {...baseProps} />);

    openMenu();
    fireEvent.click(await screen.findByRole('menuitem', { name: /Reset context/i }));

    expect(await screen.findByText('Reset bot context?')).toBeInTheDocument();
  });

  it('เลือกข้อมูลลูกค้าแล้วเรียก onShowInfo', () => {
    const onShowInfo = vi.fn();
    render(<ChatHeaderActions {...baseProps} onShowInfo={onShowInfo} />);

    openMenu();
    fireEvent.click(screen.getByRole('menuitem', { name: /ข้อมูลลูกค้า/ }));

    expect(onShowInfo).toHaveBeenCalled();
  });

  it('ปุ่มเมนูมี touch target 44px บนมือถือ', () => {
    render(<ChatHeaderActions {...baseProps} />);

    const button = screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' });

    expect(button.className).toContain('size-11');
    expect(button.className).toContain('sm:size-9');
  });
});
