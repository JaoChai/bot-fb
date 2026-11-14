import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { ChannelMessageArea } from './ChannelMessageArea';
import { makeMessage } from '@/test-utils/messageFactory';
import type { Conversation, Message } from '@/types/api';

// jsdom has no IntersectionObserver — LazyImage (bot image bubbles) reads it
// on mount. Mirrors the ResizeObserver stub in src/test/setup.ts, which is
// outside this card's allowed diff scope.
globalThis.IntersectionObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
  takeRecords() {
    return [];
  }
} as unknown as typeof IntersectionObserver;

/**
 * ChannelMessageArea is the list component ChatWindow renders for LINE and
 * Telegram conversations (useCustomBubbles), i.e. the path the Support
 * Router's duplicate-suppressed rows actually appear on. The bubbles only
 * render their timestamp separator when >5 min passed since previousMessage,
 * so the separator doubles as the previousMessage assertion.
 *
 * Invented fixture text only — never real customer messages.
 */

const CONVERSATION = {
  id: 1,
  bot_id: 26,
  channel_type: 'line',
  created_at: '2026-11-12T08:00:00Z',
} as unknown as Conversation;

// The card fixture: [user text, bot text with content, bot text with
// content: '' and no media, user text].
function cardFixture() {
  return [
    makeMessage(1, '2026-11-12T09:00:00Z', { sender: 'user', content: 'I cannot log in' }),
    makeMessage(2, '2026-11-12T09:00:05Z', {
      sender: 'bot',
      content: 'Please contact Support',
      model_used: 'support_router',
    }),
    makeMessage(3, '2026-11-12T09:06:00Z', {
      sender: 'bot',
      content: '',
      model_used: 'support_router',
    }),
    makeMessage(4, '2026-11-12T09:07:00Z', { sender: 'user', content: 'ok thanks' }),
  ];
}

// LINE bubbles stamp 'PPp', Telegram bubbles stamp 'HH:mm'.
const SEPARATOR_FORMAT = { line: 'PPp', telegram: 'HH:mm' } as const;

describe.each([
  { channelType: 'line' as const },
  { channelType: 'telegram' as const },
])('ChannelMessageArea empty bot text suppression ($channelType)', ({ channelType }) => {
  const fmt = SEPARATOR_FORMAT[channelType];

  function renderArea(messages: ReturnType<typeof cardFixture>) {
    return render(
      <ChannelMessageArea
        messages={messages}
        conversation={CONVERSATION}
        isLoading={false}
        channelType={channelType}
      />
    );
  }

  it('renders 3 of 4 rows: the empty bot text row is hidden and the row after it gets the last visible row as previousMessage', () => {
    const messages = cardFixture();
    const { container } = renderArea(messages);

    const rows = container.querySelectorAll('[data-message-id]');
    expect(rows).toHaveLength(3);
    expect(container.querySelector('[data-message-id="3"]')).toBeNull();
    screen.getByText('I cannot log in');
    screen.getByText('Please contact Support');
    screen.getByText('ok thanks');

    // Timestamp separator: rendered when >5 min passed since the PREVIOUS
    // row handed to the bubble. Row 4 (09:07) must be compared against the
    // visible row 2 (09:00:05, ~7 min → separator shown), not the hidden
    // row 3 (09:06, ~1 min → no separator). So the separator of row 4 is
    // present and the separator of the hidden row 3 is absent.
    const row4Separator = format(new Date(messages[3].created_at), fmt, { locale: th });
    const row3Separator = format(new Date(messages[2].created_at), fmt, { locale: th });
    expect(screen.queryAllByText(row4Separator)).toHaveLength(1);
    expect(screen.queryAllByText(row3Separator)).toHaveLength(0);
  });

  it('still renders a bot image message with empty content when a media_url is set', () => {
    const { container } = renderArea([
      makeMessage(10, '2026-11-12T09:00:00Z', {
        sender: 'bot',
        type: 'image',
        content: '',
        media_url: 'https://example.invalid/receipt.png',
      }),
    ]);
    expect(container.querySelectorAll('[data-message-id]')).toHaveLength(1);
  });

  it('still renders a user message with empty content', () => {
    const { container } = renderArea([
      makeMessage(11, '2026-11-12T09:00:00Z', { sender: 'user', content: '' }),
    ]);
    expect(container.querySelectorAll('[data-message-id]')).toHaveLength(1);
  });

  it('still renders a bot text message whose content is a leading space plus text', () => {
    const { container } = renderArea([
      makeMessage(12, '2026-11-12T09:00:00Z', {
        sender: 'bot',
        content: ' please contact Support',
      }),
    ]);
    expect(container.querySelectorAll('[data-message-id]')).toHaveLength(1);
    screen.getByText('please contact Support');
  });

  it('hides a bot text message whose content is only whitespace and has no media', () => {
    const { container } = renderArea([
      makeMessage(13, '2026-11-12T09:00:00Z', { sender: 'bot', content: '   ' }),
    ]);
    expect(container.querySelectorAll('[data-message-id]')).toHaveLength(0);
  });

  it('hides a bot message with a missing type, empty content and no media', () => {
    const { container } = renderArea([
      makeMessage(14, '2026-11-12T09:00:00Z', {
        sender: 'bot',
        type: undefined as unknown as Message['type'],
        content: '',
      }),
    ]);
    expect(container.querySelectorAll('[data-message-id]')).toHaveLength(0);
  });
});

describe.each([
  { channelType: 'line' as const },
  { channelType: 'telegram' as const },
])('ChannelMessageArea outage badge ($channelType)', ({ channelType }) => {
  function renderArea(messages: Message[]) {
    return render(
      <ChannelMessageArea
        messages={messages}
        conversation={CONVERSATION}
        isLoading={false}
        channelType={channelType}
      />
    );
  }

  it('all_three_bubbles_render_the_same_notice_label: flagged bot row shows the badge, neighbours do not', () => {
    const { container } = renderArea([
      makeMessage(21, '2026-11-12T09:00:00Z', { sender: 'user', content: 'สวัสดีครับ' }),
      makeMessage(22, '2026-11-12T09:00:05Z', {
        sender: 'bot',
        content: 'ระบบกลับมาใช้งานได้แล้ว',
        is_outage_notice: true,
      }),
      makeMessage(23, '2026-11-12T09:01:00Z', { sender: 'bot', content: 'ตอบปกติ' }),
    ]);

    // Exactly one badge in the whole list — never on the user row / other bot row.
    expect(screen.getAllByText('ส่งอัตโนมัติหลังบอทล่ม')).toHaveLength(1);
    expect(container.querySelectorAll('[data-slot="badge"]')).toHaveLength(1);
  });

  it('never shows the badge from matching the text alone', () => {
    const { container } = renderArea([
      makeMessage(24, '2026-11-12T09:00:05Z', {
        sender: 'bot',
        content: 'ส่งอัตโนมัติหลังบอทล่ม',
      }),
    ]);

    // The content text is visible, but no badge element exists without the flag.
    screen.getByText('ส่งอัตโนมัติหลังบอทล่ม');
    expect(container.querySelector('[data-slot="badge"]')).toBeNull();
  });
});
