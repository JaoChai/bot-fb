import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { BotSettingsPage } from './BotSettingsPage';

vi.mock('react-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router')>();
  return { ...actual, useParams: () => ({ botId: '28' }) };
});

// ต้องเป็น reference เดิมทุก render — ถ้าสร้าง object ใหม่ใน mock ทุกครั้ง
// useEffect([serverSettings]) ใน page จะยิงซ้ำไม่รู้จบ (setFormData ให้ object ใหม่เสมอ)
const mockSettings = {
  daily_message_limit: 500,
  per_user_limit: 30,
  response_hours_enabled: false,
  response_hours: null,
};

vi.mock('@/hooks/useBotSettings', () => ({
  useBotSettings: () => ({ data: mockSettings, isLoading: false }),
  useUpdateBotSettings: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/bots/28/settings']}>
      <BotSettingsPage />
    </MemoryRouter>
  );
}

describe('BotSettingsPage', () => {
  it('gives the tab-nav aside min-w-0 so it cannot force the page wider than the viewport', () => {
    const { container } = renderPage();
    const aside = container.querySelector('aside');
    expect(aside).not.toBeNull();
    expect(aside).toHaveClass('min-w-0');
  });

  it('shows a right-edge scroll-hint fade next to the tab row for narrow screens', () => {
    renderPage();
    const fade = screen.getByTestId('tab-scroll-fade');
    expect(fade).toHaveClass('md:hidden');
    expect(fade).toHaveAttribute('aria-hidden', 'true');
  });

  it('still renders all 5 tab buttons', () => {
    renderPage();
    for (const label of ['ข้อจำกัด', 'เวลาตอบกลับ', 'พฤติกรรม', 'สติกเกอร์', 'ตรวจสลิป']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    }
  });
});
