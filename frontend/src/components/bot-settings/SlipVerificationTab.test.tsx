import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SlipVerificationTab } from './SlipVerificationTab';

const baseProps = {
  slip_verification_enabled: false,
  slip_receiver_account: '',
  slip_amount_tolerance: 0,
  slip_success_message: '',
  slip_fail_message: '',
  onChange: vi.fn(),
};

describe('SlipVerificationTab', () => {
  it('toggle เปิดใช้งานเรียก onChange', () => {
    const onChange = vi.fn();
    render(<SlipVerificationTab {...baseProps} onChange={onChange} />);

    fireEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith('slip_verification_enabled', true);
  });

  it('ซ่อนรายละเอียดเมื่อปิดใช้งาน', () => {
    render(<SlipVerificationTab {...baseProps} />);
    expect(screen.queryByLabelText(/เลขบัญชี/)).toBeNull();
  });

  it('แสดงช่องตั้งค่าเมื่อเปิดใช้งาน', () => {
    render(<SlipVerificationTab {...baseProps} slip_verification_enabled={true} />);
    expect(screen.getByText(/เลขบัญชีร้าน/)).toBeInTheDocument();
    expect(screen.getByText(/ยอดคลาดเคลื่อน/)).toBeInTheDocument();
  });
});
