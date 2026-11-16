import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { BotSettingsPage } from './BotSettingsPage';
import { Toaster } from '@/components/ui/sonner';
import type { OutageTextDefaults } from '@/types/api';

// Mutable harness state shared with the vi.mock factories (vi.hoisted so the
// factories can close over it). Each test assigns fresh values BEFORE render.
const h = vi.hoisted(() => {
  const state = {
    botId: '28',
    settings: undefined as Record<string, unknown> | undefined,
    loadError: false,
    isPending: false,
  };
  const mutateAsync = vi.fn();
  const refetch = vi.fn();
  return { state, mutateAsync, refetch };
});

vi.mock('react-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router')>();
  return { ...actual, useParams: () => ({ botId: h.state.botId }) };
});

vi.mock('@/hooks/useBotSettings', () => ({
  useBotSettings: () => ({
    data: h.state.settings,
    isLoading: !h.state.settings && !h.state.loadError,
    isError: h.state.loadError,
    error: h.state.loadError ? new Error('boom') : null,
    refetch: h.refetch,
  }),
  useUpdateBotSettings: () => ({
    mutateAsync: h.mutateAsync,
    isPending: h.state.isPending,
  }),
}));

// Synthetic defaults — never the real product copy (plan rule)
const DEF: OutageTextDefaults = {
  outage_recovery_message_th: 'DEFAULT-TH-กลับมาแล้วครับ',
  outage_recovery_message_en: 'DEFAULT-EN-back online now',
  outage_down_message: 'DEFAULT-DOWN-ขออภัยครับ',
};

function makeSettings(overrides: Record<string, unknown> = {}) {
  return {
    daily_message_limit: 500,
    per_user_limit: 30,
    response_hours_enabled: false,
    response_hours: null,
    outage_recovery_enabled: true,
    outage_recovery_message_th: null,
    outage_recovery_message_en: null,
    outage_down_message: null,
    outage_defaults: { ...DEF },
    ...overrides,
  };
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/bots/28/settings']}>
      <BotSettingsPage />
    </MemoryRouter>
  );
}

function goToOutageTab() {
  fireEvent.click(screen.getByRole('button', { name: 'เมื่อบอทล่ม' }));
}

function goToFollowUpTab() {
  fireEvent.click(screen.getByRole('button', { name: 'ตามลูกค้า' }));
}

// Synthetic follow-up capability — never the production numbers (D127).
const FOLLOW_UP_RULES = {
  payment_wait_hours: 5,
  sale_wait_hours: 30,
  customer_gap_days: 9,
  daily_draft_cap: 12,
  approval_ttl_hours: 6,
  send_start: '09:30',
  send_end: '21:15',
  timezone: 'Asia/Bangkok',
};

const READY_CAPS = {
  supported: true,
  telegram_ready: true,
  automatic_allowed: true,
  blocked_reason: null,
  rules: { ...FOLLOW_UP_RULES },
};

// Keys that must NEVER leave the page on a PUT (D124)
const NEVER_SENT_KEYS = [
  'follow_up_started_at',
  'follow_up_capabilities',
  'lead_recovery_enabled',
  'lead_recovery_timeout_hours',
  'lead_recovery_mode',
  'lead_recovery_message',
  'lead_recovery_max_attempts',
];

function forbiddenKeys(payload: Record<string, unknown>, extraForbidden: string[] = []) {
  return Object.keys(payload).filter(
    (k) =>
      k.startsWith('follow_up_') ||
      k.startsWith('lead_recovery_') ||
      extraForbidden.includes(k),
  );
}

function neverSentKeys(payload: Record<string, unknown>) {
  return NEVER_SENT_KEYS.filter((k) => k in payload);
}

function getSaveButton() {
  return screen.getByRole('button', { name: 'บันทึกตั้งค่า' });
}

const TH_EDITOR = () => screen.getByRole('textbox', { name: 'ข้อความภาษาไทย' });
const DOWN_EDITOR = () => screen.getByRole('textbox', { name: 'ข้อความ' });

beforeEach(() => {
  h.mutateAsync.mockReset();
  h.refetch.mockReset();
  h.mutateAsync.mockResolvedValue({});
  h.state.botId = '28';
  h.state.settings = undefined;
  h.state.loadError = false;
  h.state.isPending = false;
});

describe('BotSettingsPage (outage tab)', () => {
  it('gives the tab-nav aside min-w-0 so it cannot force the page wider than the viewport', () => {
    h.state.settings = makeSettings();
    const { container } = renderPage();
    const aside = container.querySelector('aside');
    expect(aside).not.toBeNull();
    expect(aside).toHaveClass('min-w-0');
  });

  it('shows a right-edge scroll-hint fade next to the tab row for narrow screens', () => {
    h.state.settings = makeSettings();
    renderPage();
    const fade = screen.getByTestId('tab-scroll-fade');
    expect(fade).toHaveClass('md:hidden');
    expect(fade).toHaveAttribute('aria-hidden', 'true');
  });

  it('shows_seven_tabs_with_follow_up_after_outage_and_hydrates_nulls_from_api_defaults', () => {
    h.state.settings = makeSettings();
    const { container } = renderPage();

    const labels = ['ข้อจำกัด', 'เวลาตอบกลับ', 'พฤติกรรม', 'สติกเกอร์', 'ตรวจสลิป', 'เมื่อบอทล่ม', 'ตามลูกค้า'];
    const buttons = labels.map((l) => screen.getByRole('button', { name: l }));
    expect(buttons).toHaveLength(7);
    // เมื่อบอทล่ม comes right after ตรวจสลิป in DOM order
    expect(
      buttons[5].compareDocumentPosition(buttons[4]) & Node.DOCUMENT_POSITION_PRECEDING
    ).toBeTruthy();
    // ตามลูกค้า is the seventh tab, right after เมื่อบอทล่ม (F1a)
    expect(
      buttons[5].compareDocumentPosition(buttons[6]) & Node.DOCUMENT_POSITION_FOLLOWING
    ).toBeTruthy();
    void container;

    goToOutageTab();
    // Null stored values hydrate to the API defaults, not placeholders
    expect(TH_EDITOR()).toHaveDisplayValue(DEF.outage_recovery_message_th);
    expect(DOWN_EDITOR()).toHaveDisplayValue(DEF.outage_down_message);
    // Thai-only (owner order 14 Nov): no English editor on the tab
    expect(screen.queryByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' })).toBeNull();
  });

  it('saves_three_outage_fields_and_never_sends_english', async () => {
    h.state.settings = makeSettings();
    renderPage();
    goToOutageTab();

    fireEvent.change(TH_EDITOR(), { target: { value: 'กลับมาแล้วจริงๆ' } });
    fireEvent.change(DOWN_EDITOR(), { target: { value: 'ตอบไม่ได้ตอนนี้' } });
    fireEvent.click(screen.getByRole('switch'));

    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const first = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(first.outage_recovery_enabled).toBe(false);
    expect(first.outage_recovery_message_th).toBe('กลับมาแล้วจริงๆ');
    expect(first.outage_down_message).toBe('ตอบไม่ได้ตอนนี้');
    expect(first).not.toHaveProperty('outage_defaults');
    // The English key must never leave the page, even as null
    expect(first).not.toHaveProperty('outage_recovery_message_en');

    // Reset TH to the API default → next save must send null (inheritance)
    fireEvent.click(screen.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาไทย' }));
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(2));

    const second = h.mutateAsync.mock.calls[1][0] as Record<string, unknown>;
    expect(second.outage_recovery_message_th).toBeNull();
    expect(second.outage_down_message).toBe('ตอบไม่ได้ตอนนี้');
    expect(second).not.toHaveProperty('outage_recovery_message_en');

    // Blank/whitespace-only overrides also save as null (inherit outage_defaults)
    fireEvent.change(TH_EDITOR(), { target: { value: '   ' } });
    fireEvent.change(DOWN_EDITOR(), { target: { value: '' } });
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(3));

    const third = h.mutateAsync.mock.calls[2][0] as Record<string, unknown>;
    expect(third.outage_recovery_message_th).toBeNull();
    expect(third.outage_down_message).toBeNull();
    expect(third).not.toHaveProperty('outage_recovery_message_en');
  });

  it('stored_english_text_is_never_sent_back', async () => {
    // D5: the API keeps returning a stored outage_recovery_message_en; the
    // page loads, edits and saves the Thai texts without ever sending it back
    h.state.settings = makeSettings({
      outage_recovery_message_en: 'STORED-EN-old text',
    });
    renderPage();
    goToOutageTab();

    expect(TH_EDITOR()).toHaveDisplayValue(DEF.outage_recovery_message_th);
    expect(DOWN_EDITOR()).toHaveDisplayValue(DEF.outage_down_message);
    expect(screen.queryByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' })).toBeNull();

    fireEvent.change(TH_EDITOR(), { target: { value: 'ไทยอย่างเดียว' } });
    fireEvent.change(DOWN_EDITOR(), { target: { value: 'DRAFT-DOWN' } });
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));
    const first = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(first.outage_recovery_message_th).toBe('ไทยอย่างเดียว');
    expect(first.outage_down_message).toBe('DRAFT-DOWN');
    expect(first).not.toHaveProperty('outage_recovery_message_en');

    // Saving from ANOTHER tab never sends the key either
    fireEvent.click(screen.getByRole('button', { name: 'ข้อจำกัด' }));
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(2));
    expect(h.mutateAsync.mock.calls[1][0]).not.toHaveProperty(
      'outage_recovery_message_en'
    );
  });

  it('keeps_dirty_edits_across_tab_changes_and_failed_save', async () => {
    h.state.settings = makeSettings();
    const view = renderPage();
    goToOutageTab();

    fireEvent.change(TH_EDITOR(), { target: { value: 'แก้สดๆ' } });
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();

    // Tab away and back — the draft survives
    fireEvent.click(screen.getByRole('button', { name: 'ตรวจสลิป' }));
    fireEvent.click(screen.getByRole('button', { name: 'เมื่อบอทล่ม' }));
    expect(TH_EDITOR()).toHaveDisplayValue('แก้สดๆ');
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();

    // 422 keeps typed text, dirty state, and shows the field message
    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: {
        outage_recovery_message_th: ['ใช้ได้เฉพาะข้อความธรรมดา ห้ามใส่แท็ก HTML หรือ {ตัวแปร}'],
      },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('outage-recovery-th-error')).toHaveTextContent(
        'ใช้ได้เฉพาะข้อความธรรมดา ห้ามใส่แท็ก HTML หรือ {ตัวแปร}'
      )
    );
    expect(TH_EDITOR()).toHaveDisplayValue('แก้สดๆ');
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();

    // Successful save + fresh server data clears dirty
    h.state.settings = makeSettings({ outage_recovery_message_th: 'แก้สดๆ' });
    h.mutateAsync.mockResolvedValueOnce({});
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.queryByText('มีการเปลี่ยนแปลง')).not.toBeInTheDocument()
    );
    void view;
  });

  it('blocks_overlong_fields_even_when_recovery_is_off', async () => {
    h.state.settings = makeSettings({ outage_recovery_enabled: false });
    renderPage();
    goToOutageTab();

    fireEvent.change(DOWN_EDITOR(), { target: { value: 'ก'.repeat(501) } });
    expect(screen.getByTestId('outage-down-error')).toHaveTextContent(
      'ข้อความยาวเกิน 500 ตัวอักษร'
    );
    expect(getSaveButton()).toBeDisabled();

    // Other tab cannot bypass the handleSave guard
    fireEvent.click(screen.getByRole('button', { name: 'ข้อจำกัด' }));
    const disabledSave = screen.getByRole('button', { name: 'บันทึกตั้งค่า' });
    expect(disabledSave).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'เมื่อบอทล่ม' }));
    // Exactly 500 emoji code points is fine again
    fireEvent.change(DOWN_EDITOR(), { target: { value: '🙏'.repeat(500) } });
    expect(screen.queryByTestId('outage-down-error')).toBeNull();
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));
    expect((h.mutateAsync.mock.calls[0][0] as Record<string, unknown>).outage_down_message).toBe(
      '🙏'.repeat(500)
    );

    // '0' is a real custom value, not blank
    fireEvent.change(DOWN_EDITOR(), { target: { value: '0' } });
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(2));
    expect((h.mutateAsync.mock.calls[1][0] as Record<string, unknown>).outage_down_message).toBe('0');
  });

  it('switching_bot_uses_that_bots_values_and_defaults', () => {
    const BOT29_DEF: OutageTextDefaults = {
      outage_recovery_message_th: 'B29-TH',
      outage_recovery_message_en: 'B29-EN',
      outage_down_message: 'B29-DOWN',
    };
    h.state.settings = makeSettings({ outage_recovery_message_th: 'BOT28-TH' });
    const view = renderPage();
    goToOutageTab();
    expect(TH_EDITOR()).toHaveDisplayValue('BOT28-TH');
    fireEvent.change(DOWN_EDITOR(), { target: { value: 'DRAFT-28' } });

    h.state.botId = '29';
    h.state.settings = makeSettings({
      outage_recovery_message_th: null,
      outage_defaults: { ...BOT29_DEF },
    });
    view.rerender(
      <MemoryRouter initialEntries={['/bots/29/settings']}>
        <BotSettingsPage />
      </MemoryRouter>
    );

    expect(TH_EDITOR()).toHaveDisplayValue('B29-TH');
    expect(DOWN_EDITOR()).toHaveDisplayValue('B29-DOWN');
  });

  it('load_failure_or_missing_defaults_cannot_save_fake_settings', () => {
    // Case A: settings loaded but outage_defaults missing
    h.state.settings = makeSettings();
    delete (h.state.settings as Record<string, unknown>).outage_defaults;
    const caseA = renderPage();
    goToOutageTab();

    expect(screen.getByRole('button', { name: 'ลองโหลดอีกครั้ง' })).toBeInTheDocument();
    expect(getSaveButton()).toBeDisabled();
    expect(TH_EDITOR()).toHaveDisplayValue('');
    expect(screen.queryByDisplayValue(DEF.outage_recovery_message_th)).toBeNull();

    fireEvent.click(getSaveButton());
    expect(h.mutateAsync).not.toHaveBeenCalled();
    caseA.unmount();

    // Case B: initial load failed entirely
    h.state.settings = undefined;
    h.state.loadError = true;
    renderPage();
    expect(screen.getByRole('button', { name: 'ลองโหลดอีกครั้ง' })).toBeInTheDocument();
    expect(getSaveButton()).toBeDisabled();
    expect(document.body.textContent).not.toContain('DEFAULT-TH');

    fireEvent.click(screen.getByRole('button', { name: 'ลองโหลดอีกครั้ง' }));
    expect(h.refetch).toHaveBeenCalledTimes(1);
    expect(h.mutateAsync).not.toHaveBeenCalled();
  });

  it('loading_and_saving_reuse_existing_page_states', () => {
    // Loading: spinner, no editors, no save
    renderPage();
    expect(screen.queryByRole('button', { name: 'บันทึกตั้งค่า' })).toBeNull();
    expect(document.querySelector('.animate-spin')).not.toBeNull();

    h.state.settings = makeSettings();
    const view = renderPage();
    expect(getSaveButton()).toBeEnabled();
    goToOutageTab();

    // Saving: pending disables the button and blocks duplicate submits
    h.state.isPending = true;
    view.rerender(
      <MemoryRouter initialEntries={['/bots/28/settings']}>
        <BotSettingsPage />
      </MemoryRouter>
    );
    const saving = screen.getByRole('button', { name: /บันทึก/ });
    expect(saving).toBeDisabled();
    fireEvent.click(saving);
    expect(h.mutateAsync).not.toHaveBeenCalled();
  });
});

describe('BotSettingsPage (customer follow-up tab)', () => {
  it('saves_from_another_tab_without_any_follow_up_or_legacy_key', async () => {
    // (a)/(f): ready bot, nothing changed on the follow-up tab, another tab edited
    h.state.settings = makeSettings({
      follow_up_enabled: false,
      follow_up_mode: 'approve',
      follow_up_started_at: null,
      follow_up_capabilities: { ...READY_CAPS },
    });
    renderPage();
    goToOutageTab();
    fireEvent.change(TH_EDITOR(), { target: { value: 'แก้จากแท็บอื่น' } });
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const payload = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.outage_recovery_message_th).toBe('แก้จากแท็บอื่น');
    expect(forbiddenKeys(payload)).toEqual([]);
  });

  it('switch_off_to_on_sends_only_follow_up_enabled_true', async () => {
    // (b): the only follow-up change is off -> on
    h.state.settings = makeSettings({
      follow_up_enabled: false,
      follow_up_mode: 'approve',
      follow_up_started_at: null,
      follow_up_capabilities: { ...READY_CAPS },
    });
    renderPage();
    goToFollowUpTab();
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const payload = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.follow_up_enabled).toBe(true);
    expect(payload).not.toHaveProperty('follow_up_mode');
    expect(neverSentKeys(payload)).toEqual([]);
  });

  it('stored_automatic_switch_off_sends_enabled_false_and_mode_approve', async () => {
    // (c): stored automatic + automatic_allowed false; switching off resets the mode
    h.state.settings = makeSettings({
      follow_up_enabled: true,
      follow_up_mode: 'automatic',
      follow_up_started_at: '2026-11-01T10:00:00+07:00',
      follow_up_capabilities: { ...READY_CAPS, automatic_allowed: false },
    });
    renderPage();
    goToFollowUpTab();
    const automatic = screen.getByRole('radio', { name: 'ส่งเองเมื่อ Luna มั่นใจ' });
    expect(automatic).toBeChecked();
    expect(automatic).toBeEnabled(); // stored automatic stays selectable (D125)
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const payload = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.follow_up_enabled).toBe(false);
    expect(payload.follow_up_mode).toBe('approve');
    expect(neverSentKeys(payload)).toEqual([]);
  });

  it('changing_only_the_mode_sends_only_follow_up_mode', async () => {
    // (d): on -> on with only the mode changed
    h.state.settings = makeSettings({
      follow_up_enabled: true,
      follow_up_mode: 'approve',
      follow_up_started_at: '2026-11-01T10:00:00+07:00',
      follow_up_capabilities: { ...READY_CAPS },
    });
    renderPage();
    goToFollowUpTab();
    fireEvent.click(screen.getByRole('radio', { name: 'ส่งเองเมื่อ Luna มั่นใจ' }));
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const payload = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.follow_up_mode).toBe('automatic');
    expect(payload).not.toHaveProperty('follow_up_enabled');
    expect(neverSentKeys(payload)).toEqual([]);
  });

  it('absent_follow_up_keys_show_unavailable_and_other_tab_save_has_no_follow_up_key', async () => {
    // (e): production API today returns none of the four keys (D123)
    h.state.settings = makeSettings();
    renderPage();
    goToFollowUpTab();
    expect(screen.getByText('ยังเปิดไม่ได้')).toBeInTheDocument();
    expect(screen.getByText('ยังโหลดข้อมูลตามลูกค้าไม่ได้ ลองโหลดหน้าใหม่')).toBeInTheDocument();
    expect(screen.getByRole('switch')).toBeDisabled();
    expect(screen.getByRole('radio', { name: 'เจ้านายอนุมัติทุกข้อความ' })).toBeDisabled();
    expect(screen.getByRole('radio', { name: 'ส่งเองเมื่อ Luna มั่นใจ' })).toBeDisabled();
    expect(screen.queryByText('ระบบทำงานอย่างไร')).toBeNull();

    // Another tab still saves, and no follow-up key leaves the page
    goToOutageTab();
    fireEvent.change(DOWN_EDITOR(), { target: { value: 'ยังบันทึกได้' } });
    fireEvent.click(getSaveButton());
    await waitFor(() => expect(h.mutateAsync).toHaveBeenCalledTimes(1));

    const payload = h.mutateAsync.mock.calls[0][0] as Record<string, unknown>;
    expect(payload.outage_down_message).toBe('ยังบันทึกได้');
    expect(forbiddenKeys(payload)).toEqual([]);
  });

  it('follow_up_422_maps_reason_codes_under_their_controls_and_stays_dirty', async () => {
    h.state.settings = makeSettings({
      follow_up_enabled: false,
      follow_up_mode: 'approve',
      follow_up_started_at: null,
      follow_up_capabilities: { ...READY_CAPS },
    });
    renderPage();
    goToFollowUpTab();
    fireEvent.click(screen.getByRole('switch'));
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();

    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: { follow_up_enabled: ['telegram_missing'] },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('follow-up-enabled-error')).toHaveTextContent(
        'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
      ),
    );
    // Values kept, page stays dirty
    expect(screen.getByRole('switch')).toBeChecked();
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();

    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: { follow_up_mode: ['switch_off'] },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('follow-up-mode-error')).toHaveTextContent(
        'ต้องเปิดตามลูกค้าก่อน จึงเลือกส่งเองได้',
      ),
    );

    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: { follow_up_mode: ['automatic_not_allowed'] },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('follow-up-mode-error')).toHaveTextContent(
        'ยังเลือกส่งเองไม่ได้ ต้องครบช่วงทดลองก่อน',
      ),
    );

    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: { follow_up_enabled: ['writer_missing'] },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('follow-up-enabled-error')).toHaveTextContent(
        'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ',
      ),
    );

    h.mutateAsync.mockRejectedValueOnce({
      message: 'The given data was invalid.',
      status: 422,
      errors: { follow_up_enabled: ['mystery_code'] },
    });
    fireEvent.click(getSaveButton());
    await waitFor(() =>
      expect(screen.getByTestId('follow-up-enabled-error')).toHaveTextContent(
        'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง',
      ),
    );
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();
  });

  it('save_500_keeps_follow_up_values_and_shows_the_failure_toast', async () => {
    // jsdom lacks matchMedia, which sonner's Toaster needs to mount
    if (!window.matchMedia) {
      window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
      })) as unknown as typeof window.matchMedia;
    }
    h.state.settings = makeSettings({
      follow_up_enabled: false,
      follow_up_mode: 'approve',
      follow_up_started_at: null,
      follow_up_capabilities: { ...READY_CAPS },
    });
    const view = render(
      <MemoryRouter initialEntries={['/bots/28/settings']}>
        <Toaster />
        <BotSettingsPage />
      </MemoryRouter>,
    );
    goToFollowUpTab();
    fireEvent.click(screen.getByRole('switch'));
    h.mutateAsync.mockRejectedValueOnce({ message: 'Server Error', status: 500 });
    fireEvent.click(getSaveButton());
    expect(await screen.findByText('ไม่สามารถบันทึกตั้งค่าบอทได้')).toBeInTheDocument();
    expect(screen.getByRole('switch')).toBeChecked();
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();
    view.unmount();
  });

  it('follow_up_fields_drive_the_dirty_badge', () => {
    h.state.settings = makeSettings({
      follow_up_enabled: false,
      follow_up_mode: 'approve',
      follow_up_started_at: null,
      follow_up_capabilities: { ...READY_CAPS },
    });
    renderPage();
    goToFollowUpTab();
    expect(screen.queryByText('มีการเปลี่ยนแปลง')).toBeNull();
    fireEvent.click(screen.getByRole('switch'));
    expect(screen.getByText('มีการเปลี่ยนแปลง')).toBeInTheDocument();
    // Back to the stored value restores clean state
    fireEvent.click(screen.getByRole('switch'));
    expect(screen.queryByText('มีการเปลี่ยนแปลง')).toBeNull();
  });
});
