import { describe, it, expect, beforeEach, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { syncBot, useSyncCursors } from './syncEngine';
import { messageKeys } from '@/hooks/chat/messageKeys';
import type { InfiniteMessages } from '@/hooks/chat/infiniteMessageCache';
import { makeMessage } from '@/test-utils/messageFactory';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}));

// Reset store between tests
beforeEach(() => {
  useSyncCursors.setState({
    lastConvSyncAt: {},
    lastMessageId: {},
  });
});

describe('useSyncCursors', () => {
  it('stores conversation sync timestamp', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-01-01T00:00:00Z');
  });

  it('stores message cursor', () => {
    useSyncCursors.getState().setCursor('1:100', 42);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(42);
  });

  it('stores multiple conversation cursors independently', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    useSyncCursors.getState().setCursor('conv:2', '2026-02-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-01-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[2]).toBe('2026-02-01T00:00:00Z');
  });

  it('stores multiple message cursors independently', () => {
    useSyncCursors.getState().setCursor('1:100', 10);
    useSyncCursors.getState().setCursor('1:200', 20);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(10);
    expect(useSyncCursors.getState().lastMessageId['1:200']).toBe(20);
  });

  it('overwrites existing conversation cursor', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    useSyncCursors.getState().setCursor('conv:1', '2026-06-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-06-01T00:00:00Z');
  });

  it('overwrites existing message cursor', () => {
    useSyncCursors.getState().setCursor('1:100', 10);
    useSyncCursors.getState().setCursor('1:100', 99);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(99);
  });

  it('initialises with empty cursors', () => {
    expect(useSyncCursors.getState().lastConvSyncAt).toEqual({});
    expect(useSyncCursors.getState().lastMessageId).toEqual({});
  });
});

describe('syncConversation via syncBot', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    useSyncCursors.setState({ lastConvSyncAt: {}, lastMessageId: {} });
  });

  it('prepends synced messages to the infinite cache newest-first', async () => {
    const queryClient = new QueryClient();
    const meta = { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 };
    const initial: InfiniteMessages = {
      pages: [{ data: [makeMessage(5, '2026-07-09T10:00:00Z')], meta }],
      pageParams: [1],
    };
    queryClient.setQueryData(messageKeys.infinite(1, 10), initial);

    vi.mocked(api.get)
      // 1st call: conversations delta sync
      .mockResolvedValueOnce({ data: { data: [], synced_at: '2026-07-09T12:00:00Z' } } as never)
      // 2nd call: messages sync for selected conversation
      .mockResolvedValueOnce({
        data: {
          data: [
            makeMessage(6, '2026-07-09T11:00:00Z'),
            makeMessage(7, '2026-07-09T11:30:00Z'),
          ],
          has_more: false,
          synced_at: '2026-07-09T12:00:00Z',
        },
      } as never);

    await syncBot(1, queryClient, 10);

    const cached = queryClient.getQueryData<InfiniteMessages>(messageKeys.infinite(1, 10))!;
    expect(cached.pages[0].data.map((m) => m.id)).toEqual([7, 6, 5]);
    expect(useSyncCursors.getState().lastMessageId['1:10']).toBe(7);
  });
});
