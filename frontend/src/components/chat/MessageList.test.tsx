import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { MessageList } from './MessageList';
import type { Message } from '@/types/api';

// jsdom viewports have zero size, so the real TanStack Virtual renders no
// items there. Fake it with a render-all virtualizer: every message is
// mounted with its true index, which is all this component's own logic
// (filtering, count, previousMessage wiring) depends on.
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: (options: {
    count: number;
    getItemKey: (index: number) => number;
  }) => {
    const items = Array.from({ length: options.count }, (_, index) => ({
      index,
      key: options.getItemKey(index),
      start: index * 80,
      size: 80,
      end: (index + 1) * 80,
    }));
    return {
      getTotalSize: () => options.count * 80,
      getVirtualItems: () => items,
      getOffsetForIndex: () => [0, 'start'] as const,
      scrollToIndex: vi.fn(),
      measureElement: () => {},
    };
  },
}));

/**
 * Invented fixture text only — never real customer messages.
 * Mirrors the contract Message schema required fields.
 */
function makeMessage(
  overrides: Partial<Message> & Pick<Message, 'id' | 'sender' | 'content'>
): Message {
  return {
    conversation_id: 1,
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
    cached_tokens: null,
    reasoning_tokens: null,
    reasoning_content: null,
    created_at: '2026-11-12T09:00:00Z',
    updated_at: '2026-11-12T09:00:00Z',
    ...overrides,
  } as Message;
}

/**
 * MessageBubble renders exactly one div with both `rounded-lg` and `px-3`
 * per message (separators/pills use `rounded-full`, the scroll-to-bottom
 * button is not rendered while autoScroll is on), so this counts bubbles.
 */
function countBubbles(container: HTMLElement): number {
  return container.querySelectorAll('div.rounded-lg.px-3').length;
}

describe('MessageList empty bot text suppression', () => {
  it('hides a bot text message with empty content and no media, and the row after it gets the last visible row as previousMessage', () => {
    // The timestamp separator renders when >5 min passed since the PREVIOUS
    // row handed to MessageBubble. The last user message is ~7 min after the
    // visible bot reply but ~1 min after the hidden empty row, so a separator
    // before it proves previousMessage skipped the hidden row.
    const messages = [
      makeMessage({ id: 1, sender: 'user', content: 'I cannot log in', created_at: '2026-11-12T09:00:00Z' }),
      makeMessage({
        id: 2,
        sender: 'bot',
        content: 'Please contact Support',
        model_used: 'support_router',
        created_at: '2026-11-12T09:00:05Z',
      }),
      makeMessage({
        id: 3,
        sender: 'bot',
        content: '',
        model_used: 'support_router',
        created_at: '2026-11-12T09:06:00Z',
      }),
      makeMessage({ id: 4, sender: 'user', content: 'ok thanks', created_at: '2026-11-12T09:07:00Z' }),
    ];

    const { container } = render(<MessageList messages={messages} />);

    expect(countBubbles(container)).toBe(3);
    screen.getByText('I cannot log in');
    screen.getByText('Please contact Support');
    screen.getByText('ok thanks');

    const lastRowSeparator = format(new Date(messages[3].created_at), 'HH:mm', { locale: th });
    screen.getByText(lastRowSeparator);
  });

  it('still renders a bot image message with empty content when a media_url is set', () => {
    const { container } = render(
      <MessageList
        messages={[
          makeMessage({
            id: 10,
            sender: 'bot',
            type: 'image',
            content: '',
            media_url: 'https://example.invalid/receipt.png',
          }),
        ]}
      />
    );
    expect(countBubbles(container)).toBe(1);
  });

  it('still renders a user message with empty content', () => {
    const { container } = render(
      <MessageList
        messages={[makeMessage({ id: 11, sender: 'user', content: '' })]}
      />
    );
    expect(countBubbles(container)).toBe(1);
  });

  it('still renders a bot text message whose content is a leading space plus text', () => {
    render(
      <MessageList
        messages={[makeMessage({ id: 12, sender: 'bot', content: ' please contact Support' })]}
      />
    );
    // RTL normalizes the leading whitespace, so the trimmed text must be found.
    screen.getByText('please contact Support');
  });

  it('hides a bot text message whose content is only whitespace and has no media', () => {
    const { container } = render(
      <MessageList
        messages={[makeMessage({ id: 13, sender: 'bot', content: '   ' })]}
      />
    );
    expect(countBubbles(container)).toBe(0);
  });

  it('hides a bot message with a missing type, empty content and no media', () => {
    const { container } = render(
      <MessageList
        messages={[
          makeMessage({
            id: 14,
            sender: 'bot',
            type: undefined as unknown as Message['type'],
            content: '',
          }),
        ]}
      />
    );
    expect(countBubbles(container)).toBe(0);
  });
});
