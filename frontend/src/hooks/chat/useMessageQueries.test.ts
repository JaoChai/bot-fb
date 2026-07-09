import { describe, it, expect } from 'vitest';
import type { InfiniteData } from '@tanstack/react-query';
import { makeMessage } from '@/test-utils/messageFactory';
import type { MessagesResponse } from './messageKeys';
import { flattenInfiniteMessages } from './useMessageQueries';

const meta = { current_page: 1, from: 1, last_page: 2, per_page: 50, to: 50, total: 100 };

describe('flattenInfiniteMessages', () => {
  it('returns [] for undefined data', () => {
    expect(flattenInfiniteMessages(undefined)).toEqual([]);
  });

  it('flattens pages newest→oldest into oldest-first order', () => {
    const data: InfiniteData<MessagesResponse> = {
      pages: [
        { data: [makeMessage(6, '2026-07-09T12:00:00Z'), makeMessage(5, '2026-07-09T11:00:00Z')], meta },
        { data: [makeMessage(4, '2026-07-09T10:00:00Z'), makeMessage(3, '2026-07-09T09:00:00Z')], meta },
      ],
      pageParams: [1, 2],
    };
    expect(flattenInfiniteMessages(data).map((m) => m.id)).toEqual([3, 4, 5, 6]);
  });

  it('drops rows an older page repeats after offset drift, keeping the newer-page copy', () => {
    const five = makeMessage(5, '2026-07-09T11:00:00Z');
    const staleFive = makeMessage(5, '2026-07-09T11:00:00Z', { content: 'stale copy' });
    const data: InfiniteData<MessagesResponse> = {
      pages: [
        { data: [makeMessage(6, '2026-07-09T12:00:00Z'), five], meta },
        { data: [staleFive, makeMessage(4, '2026-07-09T10:00:00Z')], meta },
      ],
      pageParams: [1, 2],
    };
    const result = flattenInfiniteMessages(data);
    expect(result.map((m) => m.id)).toEqual([4, 5, 6]);
    expect(result.find((m) => m.id === 5)?.content).toBe(five.content);
  });
});
