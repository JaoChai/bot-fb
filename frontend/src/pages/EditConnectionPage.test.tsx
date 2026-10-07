import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { EditConnectionPage } from './EditConnectionPage';
import type { Bot } from '@/types/api';

// ต้องเป็น reference เดิมทุก render — ถ้าสร้าง object ใหม่ใน mock ทุกครั้ง
// state-sync ใน useConnectionForm จะ setFormData ซ้ำไม่รู้จบ
const mockBot: Bot = {
  id: 28,
  name: 'Bot ทดสอบ',
  description: null,
  status: 'active',
  channel_type: 'testing',
  webhook_url: 'https://example.com/webhook',
  auto_handover: false,
  auto_delivery_enabled: false,
  primary_chat_model: 'google/gemini-2.5-flash-preview',
  fallback_chat_model: 'google/gemini-2.0-flash-001',
  utility_model: null,
  reasoning_effort: 'medium',
  support_router_mode: 'on',
  support_router_model: null,
  support_handover_message: 'ส่งต่อทีม Support แล้วครับ',
  system_prompt: null,
  llm_temperature: 0.7,
  llm_max_tokens: 2048,
  context_window: 20,
  kb_enabled: false,
  kb_relevance_threshold: 0.5,
  kb_max_results: 5,
  total_conversations: 0,
  total_messages: 0,
  last_active_at: null,
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
};

vi.mock('react-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router')>();
  return { ...actual, useParams: () => ({ botId: '28' }) };
});

const toastMock = vi.fn();
vi.mock('@/hooks/use-toast', () => ({
  useToast: () => ({ toast: toastMock }),
}));

const updateMutateAsync = vi.fn();
// Mutable holder so renderPage() can swap the bot per test while keeping one
// stable reference per test (a fresh object per render would re-trigger the
// state-sync loop in useConnectionForm).
const botState: { current: Bot } = { current: mockBot };
vi.mock('@/hooks/useConnections', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useConnections')>();
  return {
    ...actual,
    useConnection: () => ({ data: botState.current, isLoading: false }),
    useCreateConnection: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useUpdateConnection: () => ({ mutateAsync: updateMutateAsync, isPending: false }),
    useDeleteConnection: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useToggleBotStatus: () => ({ mutateAsync: vi.fn(), isPending: false }),
  };
});

function renderPage(botOverrides: Partial<Bot> = {}) {
  botState.current = { ...mockBot, ...botOverrides };
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <MemoryRouter initialEntries={['/connections/28/edit']}>
      <QueryClientProvider client={queryClient}>
        <EditConnectionPage />
      </QueryClientProvider>
    </MemoryRouter>
  );
}

describe('EditConnectionPage support router save guards', () => {
  // The guard branch `!support_router_model.endsWith('-decisions')` also
  // catches the empty string, but a legacy bot saved with a NON-decisions
  // model reaches that same branch and stays constructible after the
  // load-time default made "mode on + empty model" unreachable.
  it('blocks save with a destructive toast and no update request for a non-decisions router model', async () => {
    const user = userEvent.setup();
    renderPage({ support_router_model: 'google/gemini-2.5-flash-preview' });

    await screen.findByDisplayValue('Bot ทดสอบ');
    await user.click(screen.getByRole('button', { name: 'บันทึกการเปลี่ยนแปลง' }));

    expect(toastMock).toHaveBeenCalledWith(
      expect.objectContaining({
        variant: 'destructive',
        description: expect.stringContaining('โมเดลประเภท Decisions'),
      })
    );
    expect(updateMutateAsync).not.toHaveBeenCalled();
  });

  it('persists the Luna fallback on save for a legacy bot whose router model is null', async () => {
    // Review round 2, blocker: this bot was saved before the fix — the UI
    // displayed the Luna fallback while support_router_model was null, so
    // saving sent model = null. The load must persist the fallback instead.
    // @ts-expect-error -- mutateAsync mock narrows the payload type for inspection
    updateMutateAsync.mockResolvedValue(mockBot);
    const user = userEvent.setup();
    renderPage({ support_router_model: null });

    expect(await screen.findByText('GPT-6 Luna Decisions')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'บันทึกการเปลี่ยนแปลง' }));

    expect(updateMutateAsync).toHaveBeenCalledTimes(1);
    const payload = updateMutateAsync.mock.calls[0][0];
    expect(payload.support_router_mode).toBe('on');
    expect(payload.support_router_model).toBe('openai/gpt-6-luna-decisions');
    expect(payload.support_handover_message).toBe('ส่งต่อทีม Support แล้วครับ');
  });
});
