import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ClearContextDialog } from './ClearContextDialog';

const noop = async () => {};

describe('ClearContextDialog', () => {
  it('ยังทำงานแบบเดิมเมื่อไม่ส่ง prop ใหม่ (มีปุ่มในตัว กดแล้วเปิด)', () => {
    render(<ClearContextDialog onClearContext={noop} isPending={false} />);

    fireEvent.click(screen.getByRole('button', { name: /Reset bot context/i }));

    expect(screen.getByText('Reset bot context?')).toBeInTheDocument();
  });

  it('ซ่อนปุ่ม trigger ได้เมื่อ showTrigger เป็น false', () => {
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={false}
        onOpenChange={() => {}}
      />
    );

    expect(
      screen.queryByRole('button', { name: /Reset bot context/i })
    ).not.toBeInTheDocument();
  });

  it('เปิดจากข้างนอกได้ผ่าน prop open', () => {
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={true}
        onOpenChange={() => {}}
      />
    );

    expect(screen.getByText('Reset bot context?')).toBeInTheDocument();
  });

  it('แจ้ง onOpenChange เมื่อกด Cancel', () => {
    const onOpenChange = vi.fn();
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={true}
        onOpenChange={onOpenChange}
      />
    );

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
