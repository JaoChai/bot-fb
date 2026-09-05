import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { useToggleHandover, useClearContext } from './useConversationActions';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

function wrapper(qc: QueryClient) {
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return Wrapper;
}

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

const BOT_ID = 42;

describe('useToggleHandover cache write', () => {
  beforeEach(() => vi.clearAllMocks());

  it('writes the updated conversation directly to the infinite cache without invalidating it', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, is_handover: false }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    vi.mocked(api.post).mockResolvedValueOnce({
      data: { data: { id: 7, is_handover: true } },
    } as never);

    const { result } = renderHook(() => useToggleHandover(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync({ conversationId: 7, unassign: false, autoEnableMinutes: 0 });
    });

    const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
    expect(cached?.pages[0].data[0].is_handover).toBe(true);
  });
});

describe('useClearContext', () => {
  beforeEach(() => vi.clearAllMocks());

  it('invalidates the 4 expected prefix keys on success', async () => {
    const qc = makeClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    vi.mocked(api.post).mockResolvedValueOnce({ data: { data: { id: 7 } } } as never);

    const { result } = renderHook(() => useClearContext(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => { await result.current.mutateAsync(7); });

    const invalidatedKeys = spy.mock.calls.map((c) => c[0]?.queryKey);
    expect(invalidatedKeys).toEqual(
      expect.arrayContaining([
        ['conversations', BOT_ID],
        ['conversations-infinite', BOT_ID],
        ['conversation', BOT_ID],
        ['conversation-stats', BOT_ID],
      ])
    );
  });

  it('fires toast.error with the server message when the mutation fails', async () => {
    const qc = makeClient();
    vi.mocked(api.post).mockRejectedValueOnce(new Error('Server exploded'));

    const { result } = renderHook(() => useClearContext(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync(7).catch(() => undefined);
    });

    expect(toast.error).toHaveBeenCalledTimes(1);
    expect(vi.mocked(toast.error).mock.calls[0][0]).toBe('Server exploded');
  });
});
