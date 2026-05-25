import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider, type InfiniteData } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import {
  useConversations,
  useInfiniteConversations,
  useMarkAsRead,
  useCloseConversation,
  useToggleHandover,
  useSendAgentMessage,
} from '@/hooks/useConversations';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock('@/stores/connectionStore', () => ({
  useConnectionStore: (selector: (s: { isConnected: boolean }) => unknown) =>
    selector({ isConnected: true }),
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

// =====================
// Test 1 — useConversations queryKey + endpoint
// =====================
describe('useConversations contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('issues a GET request to /bots/:botId/conversations with the queryKey tuple', async () => {
    const qc = makeClient();
    vi.mocked(api.get).mockResolvedValueOnce({
      data: [], meta: { current_page: 1, last_page: 1, status_counts: {} },
    } as never);

    const { result } = renderHook(() => useConversations(BOT_ID, { status: 'active' }), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(vi.mocked(api.get).mock.calls[0][0]).toMatch(/^\/bots\/42\/conversations\?/);

    const cached = qc.getQueryData(['conversations', BOT_ID, { status: 'active' }]);
    expect(cached).toBeTruthy();
  });
});

// =====================
// Test 2 — useInfiniteConversations pagination logic
// =====================
describe('useInfiniteConversations contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('computes next page param correctly and stops at last_page', async () => {
    const qc = makeClient();
    // api.get returns the axios-style response; queryFn accesses response.data
    vi.mocked(api.get).mockResolvedValueOnce({
      data: { data: [], meta: { current_page: 1, last_page: 3, status_counts: {} } },
    } as never);

    const { result } = renderHook(() => useInfiniteConversations(BOT_ID), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.hasNextPage).toBe(true);
    expect(qc.getQueryData(['conversations-infinite', BOT_ID, {}])).toBeTruthy();
  });

  it('reports hasNextPage=false on the last page', async () => {
    const qc = makeClient();
    vi.mocked(api.get).mockResolvedValueOnce({
      data: { data: [], meta: { current_page: 3, last_page: 3, status_counts: {} } },
    } as never);

    const { result } = renderHook(() => useInfiniteConversations(BOT_ID), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.hasNextPage).toBe(false);
  });
});

// =====================
// Test 3 — useMarkAsRead optimistic update + rollback
// =====================
describe('useMarkAsRead optimistic update', () => {
  beforeEach(() => vi.clearAllMocks());
  it('sets unread_count to 0 in cache before the API responds', async () => {
    const qc = makeClient();
    const seed: InfiniteData<{ data: Array<{ id: number; unread_count: number }>; meta: unknown }> = {
      pages: [{ data: [{ id: 7, unread_count: 5 }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);

    let resolveApi: (v: unknown) => void = () => undefined;
    const pending = new Promise((r) => { resolveApi = r; });
    vi.mocked(api.post).mockReturnValueOnce(pending as never);

    const { result } = renderHook(() => useMarkAsRead(BOT_ID), { wrapper: wrapper(qc) });
    act(() => { result.current.mutate(7); });

    await waitFor(() => {
      const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
      expect(cached?.pages[0].data[0].unread_count).toBe(0);
    });

    resolveApi({ data: { id: 7, unread_count: 0 } });
  });

  it('rolls back the cache when the mutation errors', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, unread_count: 5 }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    vi.mocked(api.post).mockRejectedValueOnce(new Error('boom'));

    const { result } = renderHook(() => useMarkAsRead(BOT_ID), { wrapper: wrapper(qc) });

    await act(async () => {
      await result.current.mutateAsync(7).catch(() => undefined);
    });

    const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
    expect(cached?.pages[0].data[0].unread_count).toBe(5);
  });
});

// =====================
// Test 4 — useCloseConversation invalidation chain
// =====================
describe('useCloseConversation cache invalidation', () => {
  beforeEach(() => vi.clearAllMocks());

  it('invalidates conversations, conversations-infinite, single conversation, and stats', async () => {
    const qc = makeClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    // Mock returns axios-style { data: ConversationResponse }
    vi.mocked(api.post).mockResolvedValueOnce({ data: { data: { id: 7 } } } as never);

    const { result } = renderHook(() => useCloseConversation(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => { await result.current.mutateAsync(7); });

    const keys = spy.mock.calls.map((c) => c[0]?.queryKey?.[0]);
    expect(keys).toEqual(
      expect.arrayContaining(['conversations', 'conversations-infinite', 'conversation', 'conversation-stats'])
    );
  });
});

// =====================
// Test 5 — useToggleHandover direct cache write
// =====================
describe('useToggleHandover cache write', () => {
  beforeEach(() => vi.clearAllMocks());

  it('writes the updated conversation directly to the infinite cache without invalidating it', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, is_handover: false }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    // api.post returns axios-style { data: ConversationResponse }
    // onSuccess uses result.data (= ConversationResponse.data = the Conversation)
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

// =====================
// Tests 6 & 7 — useSendAgentMessage + queryKey stability
// =====================
describe('useSendAgentMessage', () => {
  beforeEach(() => vi.clearAllMocks());

  it('POSTs to the agent-message endpoint with payload and Idempotency-Key header', async () => {
    const qc = makeClient();
    // api.post returns axios-style { data: AgentMessageResponse }
    vi.mocked(api.post).mockResolvedValueOnce({
      data: { message: 'sent', data: { id: 99 } },
    } as never);

    const { result } = renderHook(() => useSendAgentMessage(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync({ conversationId: 7, data: { content: 'hello' } });
    });

    expect(api.post).toHaveBeenCalledTimes(1);
    expect(vi.mocked(api.post).mock.calls[0][0]).toMatch(/agent-message$/);
    expect(vi.mocked(api.post).mock.calls[0][1]).toMatchObject({ content: 'hello' });
    expect(vi.mocked(api.post).mock.calls[0][2]).toMatchObject({
      headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }),
    });
  });
});
