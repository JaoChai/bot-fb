import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { makeMessage } from '@/test-utils/messageFactory';
import { messageKeys, type InfiniteMessages } from '@/hooks/chat';
import { useSendAgentMessage } from './useSendAgentMessage';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { post: vi.fn(), get: vi.fn() },
}));

const BOT_ID = 1;
const CONV_ID = 10;

const meta = { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 2, total: 2 };

function makeInfinite(messages: Message[]): InfiniteMessages {
  return { pages: [{ data: messages, meta }], pageParams: [1] };
}

function setup() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  queryClient.setQueryData(
    messageKeys.infinite(BOT_ID, CONV_ID),
    makeInfinite([makeMessage(5, '2026-07-09T10:00:00Z')])
  );
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const { result } = renderHook(() => useSendAgentMessage(BOT_ID), { wrapper });
  return { queryClient, result };
}

function getCached(queryClient: QueryClient): InfiniteMessages {
  return queryClient.getQueryData<InfiniteMessages>(messageKeys.infinite(BOT_ID, CONV_ID))!;
}

beforeEach(() => {
  vi.mocked(api.post).mockReset();
});

describe('useSendAgentMessage — infinite cache', () => {
  it('prepends an optimistic message, then replaces it with the server message', async () => {
    const serverMessage = makeMessage(6, '2026-07-09T11:00:00Z', { sender: 'agent' });
    let resolvePost!: (value: unknown) => void;
    vi.mocked(api.post).mockReturnValue(
      new Promise((resolve) => {
        resolvePost = resolve;
      }) as never
    );

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    // Optimistic message (negative id) lands at the front of page 1
    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toHaveLength(2);
      expect(ids[0]).toBeLessThan(0);
    });

    resolvePost({ data: { message: 'sent', data: serverMessage } });

    // Server message replaces the optimistic one
    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toEqual([6, 5]);
    });
  });

  it('removes the optimistic message on error', async () => {
    vi.mocked(api.post).mockRejectedValue(new Error('network down'));

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    await waitFor(() => {
      expect(result.current.isError).toBe(true);
    });
    const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
    expect(ids).toEqual([5]);
  });

  it('drops the optimistic message when WebSocket already delivered the real one', async () => {
    const serverMessage = makeMessage(6, '2026-07-09T11:00:00Z', { sender: 'agent' });
    let resolvePost!: (value: unknown) => void;
    vi.mocked(api.post).mockReturnValue(
      new Promise((resolve) => {
        resolvePost = resolve;
      }) as never
    );

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    await waitFor(() => {
      expect(getCached(queryClient).pages[0].data).toHaveLength(2);
    });

    // Simulate the WebSocket event landing before the API response
    queryClient.setQueryData<InfiniteMessages>(
      messageKeys.infinite(BOT_ID, CONV_ID),
      (old) => old && { ...old, pages: [{ ...old.pages[0], data: [serverMessage, ...old.pages[0].data] }] }
    );

    resolvePost({ data: { message: 'sent', data: serverMessage } });

    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toEqual([6, 5]); // no duplicate 6, optimistic gone
    });
  });
});
