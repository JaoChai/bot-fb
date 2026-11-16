/**
 * F1b: useLinkedConversation — deep-link room fetch for
 * /chat?botId=<b>&conversationId=<c> (card D130-D133).
 *
 * The hook fetches ONE room outside the loaded list pages with
 * `?messages_limit=1`, caches the BARE Conversation under
 * conversationDetailKeys.detail(botId, id), and keeps that key fresh by
 * refetching after every successful mutation while it is mounted.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useMutation } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import {
  useLinkedConversation,
  parseConversationLinkParam,
} from './useLinkedConversation';
import { useToggleHandover } from './useConversationActions';
import { useAddTags } from './useTags';
import { conversationDetailKeys } from './useConversationDetails';
import { shouldDehydrateQuery } from '@/lib/query';
import type { Conversation } from '@/types/api';

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
vi.mock('@/lib/api', () => ({
  apiGet: (...args: unknown[]) => apiGetMock(...args),
  api: { post: (...args: unknown[]) => apiPostMock(...args) },
}));

function makeConversation(id: number, overrides: Partial<Conversation> = {}): Conversation {
  return {
    id,
    bot_id: 1,
    channel_type: 'line',
    status: 'active',
    is_handover: false,
    unread_count: 0,
    message_count: 1,
    memory_notes: null,
    tags: [],
    created_at: '2026-07-31T00:00:00Z',
    updated_at: '2026-07-31T00:00:00Z',
    customer_profile: { display_name: `E2E ลูกค้าทดสอบ ${id}` },
    ...overrides,
  } as Conversation;
}

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return { queryClient, wrapper };
}

describe('parseConversationLinkParam — raw parameter validation (D132)', () => {
  it.each([
    ['77', 77],
    ['1', 1],
    ['007', null], // leading zero: not ^[1-9][0-9]*$
    ['0', null],
    ['-5', null],
    ['1.5', null],
    ['12abc', null],
    ['1e3', null],
    ['abc', null],
    ['', null],
    ['9007199254740993', null], // exceeds MAX_SAFE_INTEGER even though the text matches
  ])('raw %j -> %j', (raw, expected) => {
    expect(parseConversationLinkParam(raw)).toBe(expected);
  });

  it('a missing parameter is null (not a bad link — the link is just absent)', () => {
    expect(parseConversationLinkParam(null)).toBeNull();
  });
});

describe('useLinkedConversation — query behaviour', () => {
  beforeEach(() => {
    apiGetMock.mockReset();
  });

  it('requests GET /bots/{bot}/conversations/{id}?messages_limit=1 and caches the BARE Conversation', async () => {
    const room = makeConversation(77, { unread_count: 2 });
    apiGetMock.mockResolvedValue({ data: room });
    const { queryClient, wrapper } = createWrapper();

    const { result } = renderHook(() => useLinkedConversation(1, 77), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(apiGetMock).toHaveBeenCalledTimes(1);
    expect(apiGetMock).toHaveBeenCalledWith('/bots/1/conversations/77?messages_limit=1');
    // The cached value is the bare Conversation — the same shape useRealtime
    // writes into this key — never the { data } envelope.
    expect(result.current.data).toBe(room);
    expect(result.current.data).not.toHaveProperty('data');
    expect(queryClient.getQueryData(conversationDetailKeys.detail(1, 77))).toBe(room);
  });

  it('is enabled only when both ids are positive safe integers — no request otherwise', async () => {
    const cases: Array<[number | null, number | null]> = [
      [null, 77],
      [1, null],
      [0, 77],
      [1, 0],
      [-1, 77],
      [1, -5],
      [1.5, 77],
      // Not a safe integer: the literal would lose precision, so build it at
      // runtime — this is exactly the value a bad Telegram link can carry.
      [Number('9007199254740993'), 77],
    ];
    apiGetMock.mockResolvedValue({ data: makeConversation(77) });

    for (const [botId, conversationId] of cases) {
      apiGetMock.mockClear();
      const { wrapper } = createWrapper();
      const { result, unmount } = renderHook(
        () => useLinkedConversation(botId, conversationId),
        { wrapper }
      );

      expect(result.current.fetchStatus).toBe('idle');
      // Let any accidental request surface.
      await new Promise((resolve) => setTimeout(resolve, 10));
      expect(apiGetMock).not.toHaveBeenCalled();
      expect(result.current.data).toBeUndefined();
      unmount();
    }
  });

  it('uses conversationDetailKeys.detail(botId, conversationId) as the query key', async () => {
    apiGetMock.mockResolvedValue({ data: makeConversation(77) });
    const { queryClient, wrapper } = createWrapper();

    const { result } = renderHook(() => useLinkedConversation(3, 42), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(queryClient.getQueryData(['conversation', 3, 42])).toBeDefined();
    expect(queryClient.getQueryData(['conversation', 3, 43])).toBeUndefined();
  });

  // Review 1, item 2b: the same room id under a DIFFERENT botId is a different
  // cache entry — two requests, two cache entries, no cross-bot reuse.
  it('does not reuse one bot\'s cached room for another bot (two requests, two cache entries)', async () => {
    const roomOfBot1 = makeConversation(77, { bot_id: 1, unread_count: 1 });
    const roomOfBot2 = makeConversation(77, {
      bot_id: 2,
      unread_count: 2,
      customer_profile: { display_name: 'ลูกค้าของบอท 2' },
    });
    apiGetMock.mockImplementation((url: string) =>
      Promise.resolve({ data: url.startsWith('/bots/1/') ? roomOfBot1 : roomOfBot2 })
    );
    const { queryClient, wrapper } = createWrapper();

    const first = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    await waitFor(() => expect(first.result.current.isSuccess).toBe(true));
    const second = renderHook(() => useLinkedConversation(2, 77), { wrapper });
    await waitFor(() => expect(second.result.current.isSuccess).toBe(true));

    expect(apiGetMock).toHaveBeenCalledTimes(2);
    expect(apiGetMock).toHaveBeenNthCalledWith(1, '/bots/1/conversations/77?messages_limit=1');
    expect(apiGetMock).toHaveBeenNthCalledWith(2, '/bots/2/conversations/77?messages_limit=1');
    expect(queryClient.getQueryData(conversationDetailKeys.detail(1, 77))).toBe(roomOfBot1);
    expect(queryClient.getQueryData(conversationDetailKeys.detail(2, 77))).toBe(roomOfBot2);
    expect(first.result.current.data).toBe(roomOfBot1);
    expect(second.result.current.data).toBe(roomOfBot2);
    first.unmount();
    second.unmount();
  });

  // Review 1, item 2c (privacy): the linked-room copy carries the customer's
  // profile — it must never be dehydrated into session storage.
  it('is excluded from session-cache dehydration (shouldDehydrateQuery false for the detail key)', () => {
    expect(shouldDehydrateQuery({ queryKey: conversationDetailKeys.detail(1, 2) })).toBe(false);
    expect(shouldDehydrateQuery({ queryKey: ['conversation', 1, 2] })).toBe(false);
  });

  it('does not retry a failed room request (retry: false)', async () => {
    apiGetMock.mockRejectedValue({ message: 'Server Error', status: 500 });
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useLinkedConversation(1, 77), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(apiGetMock).toHaveBeenCalledTimes(1);
  });
});

describe('useLinkedConversation — invalidation subscription (D133)', () => {
  beforeEach(() => {
    apiGetMock.mockReset();
  });

  it('refetches the linked room after a successful mutation anywhere in the app', async () => {
    const room = makeConversation(77);
    apiGetMock.mockResolvedValue({ data: room });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    const link = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    await waitFor(() => expect(link.result.current.isSuccess).toBe(true));
    apiGetMock.mockClear();

    const { result: mutation } = renderHook(
      () => useMutation({ mutationFn: async () => 'ok' }),
      { wrapper }
    );
    mutation.current.mutate();
    await waitFor(() => expect(mutation.current.isSuccess).toBe(true));

    // The successful mutation (e.g. handover/tags/mark-as-read, which only
    // write the LIST cache) triggers a refetch of the linked room.
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1));
    expect(apiGetMock).toHaveBeenCalledWith('/bots/1/conversations/77?messages_limit=1');
  });

  it('does not refetch after a failed mutation', async () => {
    apiGetMock.mockResolvedValue({ data: makeConversation(77) });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    const link = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    await waitFor(() => expect(link.result.current.isSuccess).toBe(true));
    apiGetMock.mockClear();

    const { result: mutation } = renderHook(
      () =>
        useMutation({
          mutationFn: async () => {
            throw new Error('boom');
          },
        }),
      { wrapper }
    );
    mutation.current.mutate();
    await waitFor(() => expect(mutation.current.isError).toBe(true));

    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiGetMock).not.toHaveBeenCalled();
  });

  it('stops invalidating after unmount', async () => {
    apiGetMock.mockResolvedValue({ data: makeConversation(77) });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    const link = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    await waitFor(() => expect(link.result.current.isSuccess).toBe(true));
    link.unmount();
    apiGetMock.mockClear();

    const { result: mutation } = renderHook(
      () => useMutation({ mutationFn: async () => 'ok' }),
      { wrapper }
    );
    mutation.current.mutate();
    await waitFor(() => expect(mutation.current.isSuccess).toBe(true));

    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiGetMock).not.toHaveBeenCalled();
  });

  // Acceptance "Fresh copy": real app mutations (handover, tags) that only
  // write the LIST cache must be followed by exactly ONE new room request,
  // after which the hook serves the fresh is_handover / tags.
  it('refetches the room once after a real useToggleHandover success and serves the new is_handover', async () => {
    apiGetMock
      .mockResolvedValueOnce({ data: makeConversation(77, { is_handover: false }) })
      .mockResolvedValue({ data: makeConversation(77, { is_handover: true }) });
    apiPostMock.mockResolvedValue({ data: makeConversation(77, { is_handover: true }) });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    const link = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    const handover = renderHook(() => useToggleHandover(1), { wrapper });
    await waitFor(() => expect(link.result.current.isSuccess).toBe(true));
    expect(link.result.current.data?.is_handover).toBe(false);
    apiGetMock.mockClear();

    handover.result.current.mutate({ conversationId: 77 });
    await waitFor(() => expect(handover.result.current.isSuccess).toBe(true));
    await waitFor(() => expect(link.result.current.data?.is_handover).toBe(true));

    // exactly ONE new room request followed the mutation
    expect(apiGetMock).toHaveBeenCalledTimes(1);
    expect(apiGetMock).toHaveBeenCalledWith('/bots/1/conversations/77?messages_limit=1');
  });

  it('refetches the room once after a real useAddTags success and serves the new tags', async () => {
    apiGetMock
      .mockResolvedValueOnce({ data: makeConversation(77) })
      .mockResolvedValue({ data: makeConversation(77, { tags: ['vip'] }) });
    apiPostMock.mockResolvedValue({ data: { tags: ['vip'] } });
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    const link = renderHook(() => useLinkedConversation(1, 77), { wrapper });
    const addTags = renderHook(() => useAddTags(1), { wrapper });
    await waitFor(() => expect(link.result.current.isSuccess).toBe(true));
    expect(link.result.current.data?.tags).toEqual([]);
    apiGetMock.mockClear();

    addTags.result.current.mutate({ conversationId: 77, data: { tags: ['vip'] } });
    await waitFor(() => expect(addTags.result.current.isSuccess).toBe(true));
    await waitFor(() => expect(link.result.current.data?.tags).toEqual(['vip']));

    expect(apiGetMock).toHaveBeenCalledTimes(1);
  });
});
