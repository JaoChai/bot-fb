import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import {
  FollowUpSettingsTab,
  followUpBlockedNoticeLine,
  followUpSaveErrorLine,
  FOLLOW_UP_UNAVAILABLE_LINE,
  FOLLOW_UP_SAVE_FAILED_LINE,
} from './FollowUpSettingsTab';
import type { CustomerFollowUpCapabilities } from '@/types/api';

// Synthetic rule values — the production numbers must come from the API and
// never be copied into the component source (D127). The rule-number test
// overrides these with deliberately different values and asserts the
// factory values disappear.
const RULES: CustomerFollowUpCapabilities['rules'] = {
  payment_wait_hours: 3,
  sale_wait_hours: 24,
  customer_gap_days: 7,
  daily_draft_cap: 10,
  approval_ttl_hours: 24,
  send_start: '08:00',
  send_end: '22:00',
  timezone: 'Asia/Bangkok',
};

function caps(overrides: Partial<CustomerFollowUpCapabilities> = {}): CustomerFollowUpCapabilities {
  return {
    supported: true,
    telegram_ready: true,
    automatic_allowed: true,
    blocked_reason: null,
    rules: { ...RULES },
    ...overrides,
  };
}

type TabProps = Parameters<typeof FollowUpSettingsTab>[0];

function renderTab(props: Partial<TabProps> = {}) {
  const onChange = vi.fn();
  const view = render(
    <FollowUpSettingsTab
      follow_up_enabled={false}
      follow_up_mode="approve"
      storedMode="approve"
      onChange={onChange}
      {...props}
    />,
  );
  return { onChange, view };
}

const SWITCH = () => screen.getByRole('switch', { name: 'เปิดตามลูกค้า' });
const APPROVE_RADIO = () => screen.getByRole('radio', { name: 'เจ้านายอนุมัติทุกข้อความ' });
const AUTOMATIC_RADIO = () => screen.getByRole('radio', { name: 'ส่งเองเมื่อ Luna มั่นใจ' });

describe('FollowUpSettingsTab', () => {
  it('renders_the_ready_bot_tab_exact_copy_top_to_bottom', () => {
    renderTab({ capabilities: caps() });

    // Notice hidden for a ready bot
    expect(screen.queryByText('ยังเปิดไม่ได้')).toBeNull();

    // Panel 1: the switch
    expect(screen.getByText('ตามลูกค้าที่เงียบไป')).toBeInTheDocument();
    expect(
      screen.getByText('ร่างข้อความให้เจ้านายตรวจ ก่อนตามลูกค้าที่คุยค้างไว้'),
    ).toBeInTheDocument();
    expect(SWITCH()).toBeInTheDocument();
    expect(screen.getByText('ปิดอยู่เป็นค่าเริ่มต้น เปิดเมื่อพร้อมเท่านั้น')).toBeInTheDocument();

    // One radio group with two rows, labels clickable, helpers visible
    const group = screen.getByRole('radiogroup', { name: 'วิธีส่ง' });
    expect(within(group).getByRole('radio', { name: 'เจ้านายอนุมัติทุกข้อความ' })).toBeInTheDocument();
    expect(within(group).getByRole('radio', { name: 'ส่งเองเมื่อ Luna มั่นใจ' })).toBeInTheDocument();
    expect(
      screen.getByText('ร่างเด้งเข้า Telegram ให้กด ส่ง / ข้าม / แก้ ก่อนทุกครั้ง'),
    ).toBeInTheDocument();

    // Panel 2: read-only rules, six rows, numbers from the capability rules
    expect(screen.getByText('ระบบทำงานอย่างไร')).toBeInTheDocument();
    expect(screen.getByText('กติกาเหล่านี้ตั้งไว้ตายตัว แก้ในหน้านี้ไม่ได้')).toBeInTheDocument();
    expect(screen.getByText('ตาม 2 กลุ่ม')).toBeInTheDocument();
    expect(screen.getByText('ค้างที่ขั้นจ่ายเงิน และสนใจแต่ยังลังเล')).toBeInTheDocument();
    expect(screen.getByText('รอก่อนตาม')).toBeInTheDocument();
    expect(screen.getByText('ค้างจ่าย 3 ชั่วโมง · ลังเล 24 ชั่วโมง')).toBeInTheDocument();
    expect(screen.getByText('ส่งเฉพาะ 08:00–22:00')).toBeInTheDocument();
    expect(screen.getByText('นอกเวลานี้ไม่ส่ง')).toBeInTheDocument();
    expect(screen.getByText('เงียบ 1 รอบ ตาม 1 ข้อความ')).toBeInTheDocument();
    expect(screen.getByText('ห่างกันอย่างน้อย 7 วัน')).toBeInTheDocument();
    expect(screen.getByText('ร่างไม่เกิน 10 ข้อความต่อวัน')).toBeInTheDocument();
    expect(screen.getByText('ไม่ตาม')).toBeInTheDocument();
    expect(screen.getByText('ห้องที่ปิดบอท และลูกค้าที่จ่ายแล้ว')).toBeInTheDocument();

    // Default off: radios disabled
    expect(SWITCH()).not.toBeChecked();
    expect(APPROVE_RADIO()).toBeDisabled();
    expect(AUTOMATIC_RADIO()).toBeDisabled();

    // No text field anywhere in this tab (rules are read-only, no template editor)
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByRole('spinbutton')).toBeNull();
    expect(document.querySelector('textarea')).toBeNull();
  });

  it('renders_rule_numbers_from_capabilities_not_source_literals', () => {
    renderTab({
      capabilities: caps({
        rules: {
          ...RULES,
          payment_wait_hours: 5,
          sale_wait_hours: 30,
          customer_gap_days: 9,
          daily_draft_cap: 12,
          send_start: '09:30',
          send_end: '21:15',
        },
      }),
    });
    expect(screen.getByText('ค้างจ่าย 5 ชั่วโมง · ลังเล 30 ชั่วโมง')).toBeInTheDocument();
    expect(screen.getByText('ส่งเฉพาะ 09:30–21:15')).toBeInTheDocument();
    expect(screen.getByText('ห่างกันอย่างน้อย 9 วัน')).toBeInTheDocument();
    expect(screen.getByText('ร่างไม่เกิน 12 ข้อความต่อวัน')).toBeInTheDocument();
    // The factory's default numbers must not appear anywhere
    expect(screen.queryByText(/08:00|22:00/)).toBeNull();
    expect(screen.queryByText(/3 ชั่วโมง|24 ชั่วโมง|7 วัน|10 ข้อความ/)).toBeNull();
  });

  const NOTICE_CASES: [string, string, string][] = [
    ['line_only', 'line_only', 'ตอนนี้ตามลูกค้าได้เฉพาะบอท LINE'],
    ['bot_not_in_pilot', 'bot_not_in_pilot', 'ช่วงทดลองเปิดใช้เฉพาะบอทที่กำหนด'],
    ['telegram_missing', 'telegram_missing', 'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน'],
    [
      'telegram_allowlist_missing',
      'telegram_allowlist_missing',
      'บอทนี้ยังไม่ได้ตั้งรายชื่อคนที่กดอนุมัติได้',
    ],
    ['webhook_secret_missing', 'webhook_secret_missing', 'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ'],
    ['writer_missing', 'writer_missing', 'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ'],
    ['admin_url_missing', 'admin_url_missing', 'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ'],
    ['unknown_code', 'unknown_code', 'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ'],
  ];

  it.each(NOTICE_CASES)(
    'notice_for_%s_shows_its_line_and_disables_the_switch_while_off',
    (_label, reason, line) => {
      renderTab({ capabilities: caps({ supported: false, blocked_reason: reason as never }) });
      expect(screen.getByText('ยังเปิดไม่ได้')).toBeInTheDocument();
      expect(screen.getByText(line)).toBeInTheDocument();
      expect(SWITCH()).toBeDisabled();
    },
  );

  it('absent_capabilities_show_unavailable_line_no_rule_list_and_disabled_controls', () => {
    renderTab({}); // capabilities undefined: the API did not send it (D123)
    expect(screen.getByText('ยังเปิดไม่ได้')).toBeInTheDocument();
    expect(FOLLOW_UP_UNAVAILABLE_LINE).toBe('ยังโหลดข้อมูลตามลูกค้าไม่ได้ ลองโหลดหน้าใหม่');
    expect(screen.getByText(FOLLOW_UP_UNAVAILABLE_LINE)).toBeInTheDocument();
    expect(screen.queryByText('ระบบทำงานอย่างไร')).toBeNull();
    expect(SWITCH()).toBeDisabled();
    expect(APPROVE_RADIO()).toBeDisabled();
    expect(AUTOMATIC_RADIO()).toBeDisabled();
  });

  it('stored_on_but_not_ready_shows_the_notice_and_the_switch_can_turn_off', () => {
    const { onChange } = renderTab({
      follow_up_enabled: true,
      follow_up_mode: 'automatic',
      capabilities: caps({ supported: false, blocked_reason: 'telegram_missing' }),
    });
    expect(screen.getByText('ยังเปิดไม่ได้')).toBeInTheDocument();
    expect(SWITCH()).toBeEnabled();
    expect(SWITCH()).toBeChecked();
    fireEvent.click(SWITCH());
    expect(onChange).toHaveBeenCalledWith('follow_up_enabled', false);
    expect(onChange).toHaveBeenCalledWith('follow_up_mode', 'approve');
  });

  it('switch_on_enables_both_mode_radios', () => {
    renderTab({ follow_up_enabled: true, capabilities: caps() });
    expect(APPROVE_RADIO()).toBeEnabled();
    expect(AUTOMATIC_RADIO()).toBeEnabled();
  });

  it('automatic_row_locked_without_automatic_allowed_shows_the_helper', () => {
    renderTab({
      follow_up_enabled: true,
      storedMode: 'approve',
      capabilities: caps({ automatic_allowed: false }),
    });
    expect(AUTOMATIC_RADIO()).toBeDisabled();
    expect(screen.getByText('เปิดได้หลังครบช่วงทดลอง')).toBeInTheDocument();
    expect(APPROVE_RADIO()).toBeEnabled();
    expect(
      screen.getByText('ร่างเด้งเข้า Telegram ให้กด ส่ง / ข้าม / แก้ ก่อนทุกครั้ง'),
    ).toBeInTheDocument();
  });

  it('automatic_row_unlocked_when_allowed_and_shows_no_helper', () => {
    renderTab({ follow_up_enabled: true, capabilities: caps({ automatic_allowed: true }) });
    expect(AUTOMATIC_RADIO()).toBeEnabled();
    expect(screen.queryByText('เปิดได้หลังครบช่วงทดลอง')).toBeNull();
  });

  it('stored_automatic_stays_selectable_even_when_not_allowed', () => {
    renderTab({
      follow_up_enabled: true,
      follow_up_mode: 'automatic',
      storedMode: 'automatic',
      capabilities: caps({ automatic_allowed: false }),
    });
    expect(AUTOMATIC_RADIO()).toBeEnabled();
    expect(AUTOMATIC_RADIO()).toBeChecked();
  });

  it('clicking_a_mode_label_selects_that_radio', () => {
    const { onChange } = renderTab({ follow_up_enabled: true, capabilities: caps() });
    fireEvent.click(screen.getByText('ส่งเองเมื่อ Luna มั่นใจ'));
    expect(onChange).toHaveBeenCalledWith('follow_up_mode', 'automatic');
    fireEvent.click(screen.getByText('เจ้านายอนุมัติทุกข้อความ'));
    expect(onChange).toHaveBeenCalledWith('follow_up_mode', 'approve');
  });

  it('mode_rows_are_44px_touch_targets', () => {
    renderTab({ capabilities: caps() });
    expect(screen.getByTestId('follow-up-mode-approve-row')).toHaveClass('min-h-11');
    expect(screen.getByTestId('follow-up-mode-automatic-row')).toHaveClass('min-h-11');
  });

  it('field_errors_render_under_their_controls', () => {
    renderTab({
      follow_up_enabled: true,
      capabilities: caps(),
      errors: {
        follow_up_enabled: 'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
        follow_up_mode: 'ยังเลือกส่งเองไม่ได้ ต้องครบช่วงทดลองก่อน',
      },
    });
    expect(screen.getByTestId('follow-up-enabled-error')).toHaveTextContent(
      'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
    );
    expect(screen.getByTestId('follow-up-mode-error')).toHaveTextContent(
      'ยังเลือกส่งเองไม่ได้ ต้องครบช่วงทดลองก่อน',
    );
  });
});

describe('follow-up reason tables (shared by notice and 422 mapping)', () => {
  it('422_on_follow_up_enabled_maps_known_reason_codes_and_falls_back', () => {
    expect(followUpSaveErrorLine('follow_up_enabled', 'line_only')).toBe(
      'ตอนนี้ตามลูกค้าได้เฉพาะบอท LINE',
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'bot_not_in_pilot')).toBe(
      'ช่วงทดลองเปิดใช้เฉพาะบอทที่กำหนด',
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'telegram_missing')).toBe(
      'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'telegram_allowlist_missing')).toBe(
      'บอทนี้ยังไม่ได้ตั้งรายชื่อคนที่กดอนุมัติได้',
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'webhook_secret_missing')).toBe(
      FOLLOW_UP_SAVE_FAILED_LINE,
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'writer_missing')).toBe(
      FOLLOW_UP_SAVE_FAILED_LINE,
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'admin_url_missing')).toBe(
      FOLLOW_UP_SAVE_FAILED_LINE,
    );
    expect(followUpSaveErrorLine('follow_up_enabled', 'totally_unknown')).toBe(
      FOLLOW_UP_SAVE_FAILED_LINE,
    );
    expect(FOLLOW_UP_SAVE_FAILED_LINE).toBe('บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง');
  });

  it('422_on_follow_up_mode_maps_both_codes_and_falls_back', () => {
    expect(followUpSaveErrorLine('follow_up_mode', 'switch_off')).toBe(
      'ต้องเปิดตามลูกค้าก่อน จึงเลือกส่งเองได้',
    );
    expect(followUpSaveErrorLine('follow_up_mode', 'automatic_not_allowed')).toBe(
      'ยังเลือกส่งเองไม่ได้ ต้องครบช่วงทดลองก่อน',
    );
    expect(followUpSaveErrorLine('follow_up_mode', 'totally_unknown')).toBe(
      FOLLOW_UP_SAVE_FAILED_LINE,
    );
  });

  it('blocked_reason_notice_maps_system_and_unknown_codes_to_the_contact_line', () => {
    expect(followUpBlockedNoticeLine('line_only')).toBe('ตอนนี้ตามลูกค้าได้เฉพาะบอท LINE');
    expect(followUpBlockedNoticeLine('bot_not_in_pilot')).toBe('ช่วงทดลองเปิดใช้เฉพาะบอทที่กำหนด');
    expect(followUpBlockedNoticeLine('telegram_missing')).toBe(
      'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
    );
    expect(followUpBlockedNoticeLine('telegram_allowlist_missing')).toBe(
      'บอทนี้ยังไม่ได้ตั้งรายชื่อคนที่กดอนุมัติได้',
    );
    expect(followUpBlockedNoticeLine('webhook_secret_missing')).toBe(
      'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ',
    );
    expect(followUpBlockedNoticeLine('writer_missing')).toBe(
      'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ',
    );
    expect(followUpBlockedNoticeLine('admin_url_missing')).toBe(
      'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ',
    );
    expect(followUpBlockedNoticeLine('mystery')).toBe('ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ');
    expect(followUpBlockedNoticeLine(null)).toBe('ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ');
    expect(FOLLOW_UP_UNAVAILABLE_LINE).toBe('ยังโหลดข้อมูลตามลูกค้าไม่ได้ ลองโหลดหน้าใหม่');
  });
});
