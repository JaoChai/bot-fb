import { describe, it, expect, vi } from 'vitest';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { OutageSettingsTab } from './OutageSettingsTab';
import type { OutageTextDefaults } from '@/types/api';

// Synthetic defaults — never the real product copy (plan: literals stay out of frontend source)
const defaults: OutageTextDefaults = {
  outage_recovery_message_th: 'DEFAULT-TH-กลับมาแล้วครับ',
  outage_recovery_message_en: 'DEFAULT-EN-back online now',
  outage_down_message: 'DEFAULT-DOWN-ขออภัยครับ',
};

const baseProps = {
  outage_recovery_enabled: true,
  outage_recovery_message_th: defaults.outage_recovery_message_th,
  outage_recovery_message_en: defaults.outage_recovery_message_en,
  outage_down_message: defaults.outage_down_message,
  defaults,
  onChange: vi.fn(),
};

/** Controlled wrapper mirroring the page's real contract: value flows back via onChange. */
function ControlledOutageTab(props: typeof baseProps) {
  const [state, setState] = useState({
    outage_recovery_enabled: props.outage_recovery_enabled,
    outage_recovery_message_th: props.outage_recovery_message_th,
    outage_recovery_message_en: props.outage_recovery_message_en,
    outage_down_message: props.outage_down_message,
  });
  const onChangeSpy = vi.fn();
  return (
    <OutageSettingsTab
      {...props}
      {...state}
      onChange={(field, value) => {
        onChangeSpy(field, value);
        props.onChange(field, value);
        setState((prev) => ({ ...prev, [field]: value }));
      }}
    />
  );
}

describe('OutageSettingsTab', () => {
  it('renders_three_editors_and_resets_from_api_defaults', () => {
    render(<ControlledOutageTab {...baseProps} />);

    expect(
      screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' })
    ).toHaveDisplayValue(defaults.outage_recovery_message_th);
    expect(
      screen.getByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' })
    ).toHaveDisplayValue(defaults.outage_recovery_message_en);
    expect(
      screen.getByRole('textbox', { name: 'ข้อความ' })
    ).toHaveDisplayValue(defaults.outage_down_message);

    fireEvent.click(
      screen.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาไทย' })
    );
    expect(baseProps.onChange).toHaveBeenCalledWith(
      'outage_recovery_message_th',
      defaults.outage_recovery_message_th
    );
    expect(
      screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' })
    ).toHaveDisplayValue(defaults.outage_recovery_message_th);

    fireEvent.click(screen.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความ' }));
    expect(baseProps.onChange).toHaveBeenCalledWith(
      'outage_down_message',
      defaults.outage_down_message
    );
  });

  it('off_disables_recovery_controls_but_retains_values_and_down_editor', () => {
    render(
      <ControlledOutageTab {...baseProps} outage_recovery_enabled={false} />
    );

    const th = screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' });
    const en = screen.getByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' });
    const down = screen.getByRole('textbox', { name: 'ข้อความ' });

    // Values retained, controls disabled
    expect(th).toHaveDisplayValue(defaults.outage_recovery_message_th);
    expect(en).toHaveDisplayValue(defaults.outage_recovery_message_en);
    expect(th).toBeDisabled();
    expect(en).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาไทย' })
    ).toBeDisabled();
    expect(screen.getByTestId('outage-preview')).toHaveAttribute(
      'aria-disabled',
      'true'
    );

    // Down editor stays editable
    expect(down).not.toBeDisabled();
    fireEvent.change(down, { target: { value: 'ตอบไม่ได้ตอนนี้' } });
    expect(baseProps.onChange).toHaveBeenCalledWith(
      'outage_down_message',
      'ตอบไม่ได้ตอนนี้'
    );

    // Switch still toggles on
    fireEvent.click(screen.getByRole('switch'));
    expect(baseProps.onChange).toHaveBeenCalledWith('outage_recovery_enabled', true);
  });

  it('preview_uses_typed_thai_or_api_default', () => {
    render(<ControlledOutageTab {...baseProps} outage_recovery_message_th="" />);
    expect(screen.getByTestId('outage-preview')).toHaveTextContent(
      defaults.outage_recovery_message_th
    );

    const th = screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' });
    fireEvent.change(th, { target: { value: 'พิมพ์สดๆ' } });
    expect(screen.getByTestId('outage-preview')).toHaveTextContent('พิมพ์สดๆ');
  });

  it('counts_unicode_codepoints_and_refuses_501', () => {
    render(<ControlledOutageTab {...baseProps} outage_recovery_message_th="" />);
    const th = screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' });

    // 500 emoji = 500 code points, accepted
    fireEvent.change(th, { target: { value: '🙏'.repeat(500) } });
    expect(screen.getByTestId('outage-recovery-th-counter')).toHaveTextContent(
      '500 / 500'
    );
    expect(screen.queryByTestId('outage-recovery-th-error')).toBeNull();
    expect(th).not.toHaveAttribute('aria-invalid');

    // 501 emoji = 501 code points: destructive counter + message
    fireEvent.change(th, { target: { value: '🙏'.repeat(501) } });
    expect(screen.getByTestId('outage-recovery-th-counter')).toHaveTextContent(
      '501 / 500'
    );
    expect(screen.getByTestId('outage-recovery-th-counter')).toHaveClass(
      'text-destructive'
    );
    expect(screen.getByTestId('outage-recovery-th-error')).toHaveTextContent(
      'ข้อความยาวเกิน 500 ตัวอักษร'
    );
    expect(screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' })).toHaveAttribute(
      'aria-invalid',
      'true'
    );
  });

  it('renders_preview_and_notice_as_text_not_html', () => {
    const { container } = render(
      <ControlledOutageTab
        {...baseProps}
        outage_recovery_message_th='<img src=x onerror=alert(1)>'
      />
    );

    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('script')).toBeNull();
    expect(screen.getByTestId('outage-preview')).toHaveTextContent(
      '<img src=x onerror=alert(1)>'
    );
  });
});
