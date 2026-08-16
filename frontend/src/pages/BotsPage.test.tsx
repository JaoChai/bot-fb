import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BotsPage } from './BotsPage';

const BOTS = [
  {
    id: 28,
    name: 'Line Support Adsvance',
    channel_type: 'line',
    status: 'active',
    updated_at: new Date(Date.now() - 6 * 3600 * 1000).toISOString(),
  },
  {
    id: 29,
    name: 'Line - Adsvance',
    channel_type: 'line',
    status: 'active',
    updated_at: new Date(Date.now() - 9 * 3600 * 1000).toISOString(),
  },
];

vi.mock('@/hooks/useKnowledgeBase', () => ({
  useBots: () => ({ data: { data: BOTS }, isLoading: false, error: null }),
}));

function renderPage() {
  // BotsPage เรียก useQueryClient ตรงๆ — ต้องมี provider ไม่งั้น render พังก่อนถึง assertion
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/bots']}>
        <BotsPage />
      </MemoryRouter>
    </QueryClientProvider>
  );
}

describe('BotsPage', () => {
  it('does not truncate the bot name to a single ellipsized line', () => {
    renderPage();
    const name = screen.getByText('Line Support Adsvance');
    expect(name).not.toHaveClass('truncate');
    expect(name).toHaveClass('line-clamp-2');
  });

  it('keeps the updated-time text in its own element, not swallowed by a shared truncated string', () => {
    renderPage();
    // The platform label and the "อัพเดต ..." time must be independently findable text nodes.
    expect(screen.getAllByText('LINE Official Account').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/^อัพเดต /).length).toBe(BOTS.length);
  });

  it('gives the Flow and settings icon buttons an accessible name per bot', () => {
    renderPage();
    expect(screen.getByRole('button', { name: 'เปิด Flow ของ Line Support Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ตั้งค่า Line Support Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'เปิด Flow ของ Line - Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ตั้งค่า Line - Adsvance' })).toBeInTheDocument();
  });

  it('shows a total bot count above the list', () => {
    renderPage();
    expect(screen.getByText('ทั้งหมด 2 บอท')).toBeInTheDocument();
  });
});
