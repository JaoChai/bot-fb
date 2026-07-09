import { describe, it, expect } from 'vitest';
import type { InfiniteData } from '@tanstack/react-query';
import type { Message } from '@/types/api';
import type { MessagesResponse } from './messageKeys';
import {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
} from './infiniteMessageCache';

function makeMessage(id: number, createdAt: string): Message {
  return {
    id,
    conversation_id: 10,
    sender: 'user',
    content: `msg ${id}`,
    type: 'text',
    media_url: null,
    media_type: null,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: createdAt,
    updated_at: createdAt,
  };
}

const meta = {
  current_page: 1,
  from: 1,
  last_page: 4,
  per_page: 50,
  to: 50,
  total: 200,
};

// Pages are newest→oldest (order=desc): pages[0].data[0] is the newest message.
function makeInfinite(pages: Message[][]): InfiniteData<MessagesResponse> {
  return {
    pages: pages.map((data) => ({ data, meta })),
    pageParams: pages.map((_, i) => i + 1),
  };
}

describe('messageExistsInInfinite', () => {
  it('finds a message on any page', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T10:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    expect(messageExistsInInfinite(data, 6)).toBe(true);
    expect(messageExistsInInfinite(data, 3)).toBe(true);
    expect(messageExistsInInfinite(data, 99)).toBe(false);
  });

  it('returns false for undefined data', () => {
    expect(messageExistsInInfinite(undefined, 1)).toBe(false);
  });
});

describe('prependMessagesToInfinite', () => {
  it('prepends a new message to the first page', () => {
    const data = makeInfinite([[makeMessage(5, '2026-07-09T10:00:00Z')]]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
    ]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6, 5]);
  });

  it('drops messages that already exist on any page (dedup)', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T11:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
      makeMessage(3, '2026-07-08T10:00:00Z'),
    ]);
    expect(result).toBe(data); // unchanged object when nothing fresh
  });

  it('inserts multiple fresh messages newest-first', () => {
    const data = makeInfinite([[makeMessage(5, '2026-07-09T10:00:00Z')]]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
      makeMessage(7, '2026-07-09T12:00:00Z'),
    ]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([7, 6, 5]);
  });

  it('returns data unchanged when pages is empty', () => {
    const data: InfiniteData<MessagesResponse> = { pages: [], pageParams: [] };
    const result = prependMessagesToInfinite(data, [
      makeMessage(1, '2026-07-09T10:00:00Z'),
    ]);
    expect(result).toBe(data);
  });
});

describe('replaceMessageInInfinite', () => {
  it('replaces the optimistic message with the real one', () => {
    const optimistic = makeMessage(-1720500000000, '2026-07-09T10:00:00Z');
    const data = makeInfinite([[optimistic, makeMessage(5, '2026-07-09T09:00:00Z')]]);
    const real = makeMessage(6, '2026-07-09T10:00:01Z');
    const result = replaceMessageInInfinite(data, optimistic.id, real);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6, 5]);
  });

  it('removes the optimistic message when the real one already arrived via WebSocket', () => {
    const optimistic = makeMessage(-1720500000000, '2026-07-09T10:00:00Z');
    const real = makeMessage(6, '2026-07-09T10:00:01Z');
    const data = makeInfinite([[real, optimistic]]);
    const result = replaceMessageInInfinite(data, optimistic.id, real);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6]);
  });
});

describe('removeMessageFromInfinite', () => {
  it('removes a message by id from any page', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T11:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    const result = removeMessageFromInfinite(data, 3);
    expect(result.pages[1].data).toEqual([]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6]);
  });
});
