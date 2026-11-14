import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { OutageNoticeBadge } from './OutageNoticeBadge';
import { MessageBubble } from './MessageBubble';
import { LINEMessageBubble } from '@/components/line/LINEMessageBubble';
import { TelegramMessageBubble } from '@/components/telegram/TelegramMessageBubble';
import { makeMessage } from '@/test-utils/messageFactory';
import type { Message } from '@/types/api';

const BADGE_LABEL = 'ส่งอัตโนมัติหลังบอทล่ม';

describe('OutageNoticeBadge', () => {
  it('only_tagged_bot_messages_show_the_notice_badge', () => {
    function shows(msg: Pick<Message, 'sender' | 'is_outage_notice'>): boolean {
      const view = render(<OutageNoticeBadge message={msg} />);
      const found = screen.queryByText(BADGE_LABEL);
      view.unmount();
      return found !== null;
    }

    expect(shows({ sender: 'bot', is_outage_notice: true })).toBe(true);
    expect(shows({ sender: 'bot', is_outage_notice: false })).toBe(false);
    expect(shows({ sender: 'bot' })).toBe(false); // absent means false
    expect(shows({ sender: 'user', is_outage_notice: true })).toBe(false);
    expect(shows({ sender: 'agent', is_outage_notice: true })).toBe(false);
  });

  it('all_three_bubbles_render_the_same_notice_label', () => {
    const flagged = makeMessage(901, '2026-11-12T09:00:05Z', {
      sender: 'bot',
      content: 'ระบบกลับมาใช้งานได้แล้ว',
      is_outage_notice: true,
    });
    const unflagged = makeMessage(902, '2026-11-12T09:00:05Z', {
      sender: 'bot',
      content: 'ตอบปกติ',
    });

    const cases: [string, (m: Message) => JSX.Element][] = [
      ['generic', (m) => <MessageBubble message={m} />],
      ['line', (m) => <LINEMessageBubble message={m} />],
      ['telegram', (m) => <TelegramMessageBubble message={m} />],
    ];

    for (const [name, renderBubble] of cases) {
      const view = render(renderBubble(flagged));
      expect(screen.getByText(BADGE_LABEL), `${name} bubble must show the badge`).toBeInTheDocument();
      view.unmount();

      const plain = render(renderBubble(unflagged));
      expect(
        plain.container.querySelector('[data-slot="badge"]'),
        `${name} bubble must not show a badge without the flag`
      ).toBeNull();
      plain.unmount();
    }
  });

  it('renders_preview_and_notice_as_text_not_html', () => {
    const evil = '<img src=x onerror=alert(1)>';
    const flagged = makeMessage(903, '2026-11-12T09:00:05Z', {
      sender: 'bot',
      content: evil,
      is_outage_notice: true,
    });

    const cases: [string, (m: Message) => JSX.Element][] = [
      ['generic', (m) => <MessageBubble message={m} />],
      ['line', (m) => <LINEMessageBubble message={m} />],
      ['telegram', (m) => <TelegramMessageBubble message={m} />],
    ];

    for (const [name, renderBubble] of cases) {
      const { container, unmount } = render(renderBubble(flagged));
      expect(container.querySelector('img'), `${name} must not create img`).toBeNull();
      expect(container.querySelector('script'), `${name} must not create script`).toBeNull();
      expect(screen.getByText(evil), `${name} must render the content as text`).toBeInTheDocument();
      expect(screen.getByText(BADGE_LABEL)).toBeInTheDocument();
      unmount();
    }
  });
});
