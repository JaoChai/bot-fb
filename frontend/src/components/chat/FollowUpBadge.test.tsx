import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { FollowUpBadge } from './FollowUpBadge';
import { MessageBubble } from './MessageBubble';
import { LINEMessageBubble } from '@/components/line/LINEMessageBubble';
import { TelegramMessageBubble } from '@/components/telegram/TelegramMessageBubble';
import { makeMessage } from '@/test-utils/messageFactory';
import type { Message } from '@/types/api';

const FU_LABEL = 'ข้อความตามลูกค้า';
const OUTAGE_LABEL = 'ส่งอัตโนมัติหลังบอทล่ม';

describe('FollowUpBadge', () => {
  it('only_bot_boolean_true_shows_badge', () => {
    function shows(msg: Pick<Message, 'sender' | 'is_follow_up'>): boolean {
      const view = render(<FollowUpBadge message={msg} />);
      const found = screen.queryByText(FU_LABEL);
      view.unmount();
      return found !== null;
    }

    expect(shows({ sender: 'bot', is_follow_up: true })).toBe(true);
    // Every non-true value stays hidden, even when cast past the type.
    expect(shows({ sender: 'bot', is_follow_up: false })).toBe(false);
    expect(shows({ sender: 'bot' })).toBe(false); // absent means false
    expect(shows({ sender: 'bot', is_follow_up: null as unknown as boolean })).toBe(false);
    expect(shows({ sender: 'bot', is_follow_up: 'true' as unknown as boolean })).toBe(false);
    expect(shows({ sender: 'bot', is_follow_up: 1 as unknown as boolean })).toBe(false);
    expect(shows({ sender: 'user', is_follow_up: true })).toBe(false);
    expect(shows({ sender: 'agent', is_follow_up: true })).toBe(false);
  });

  it('badge_in_all_three_bubbles_wraps_and_preserves_text', () => {
    const flagged = makeMessage(911, '2026-11-17T09:00:00Z', {
      sender: 'bot',
      content: 'E2E-FU ตอบกลับลูกค้าครับ',
      is_follow_up: true,
    });
    const unflagged = makeMessage(912, '2026-11-17T09:00:00Z', {
      sender: 'bot',
      content: 'ตอบปกติ',
    });
    // The label TEXT alone never implies the badge.
    const textOnly = makeMessage(913, '2026-11-17T09:00:00Z', {
      sender: 'bot',
      content: FU_LABEL,
    });
    const both = makeMessage(914, '2026-11-17T09:00:00Z', {
      sender: 'bot',
      content: 'E2E-FU ทั้งสองป้าย',
      is_outage_notice: true,
      is_follow_up: true,
    });

    const cases: [string, (m: Message) => JSX.Element][] = [
      ['generic', (m) => <MessageBubble message={m} />],
      ['line', (m) => <LINEMessageBubble message={m} />],
      ['telegram', (m) => <TelegramMessageBubble message={m} />],
    ];

    for (const [name, renderBubble] of cases) {
      // Flagged bot row: the exact label and the message text both render.
      const view = render(renderBubble(flagged));
      expect(screen.getByText(FU_LABEL), `${name} bubble must show the label`).toBeInTheDocument();
      expect(
        screen.getByText('E2E-FU ตอบกลับลูกค้าครับ'),
        `${name} bubble must keep the message text`,
      ).toBeInTheDocument();
      // Same component/variant/classes as the outage label: wrap + full width.
      const badge = screen.getByText(FU_LABEL).closest('[data-slot="badge"]');
      expect(badge, `${name} label must be a Badge element`).not.toBeNull();
      expect(badge!.className).toContain('whitespace-normal');
      expect(badge!.className).toContain('max-w-full');
      view.unmount();

      // Unflagged bot row: no badge at all.
      const plain = render(renderBubble(unflagged));
      expect(
        plain.container.querySelector('[data-slot="badge"]'),
        `${name} bubble must not show a badge without the flag`,
      ).toBeNull();
      plain.unmount();

      // A bot row whose text merely equals the label shows no badge.
      const texty = render(renderBubble(textOnly));
      expect(
        texty.container.querySelector('[data-slot="badge"]'),
        `${name} bubble must not badge the label text alone`,
      ).toBeNull();
      expect(screen.getByText(FU_LABEL)).toBeInTheDocument(); // as plain text
      texty.unmount();

      // Both flags: two badges, the outage label first.
      const bothView = render(renderBubble(both));
      const badges = bothView.container.querySelectorAll('[data-slot="badge"]');
      expect(badges.length, `${name} bubble must show two badges for both flags`).toBe(2);
      expect(badges[0].textContent).toBe(OUTAGE_LABEL);
      expect(badges[1].textContent).toBe(FU_LABEL);
      bothView.unmount();
    }
  });

  it('flagged_row_renders_content_as_text_not_html', () => {
    const evil = '<img src=x onerror=alert(1)>';
    const flagged = makeMessage(915, '2026-11-17T09:00:00Z', {
      sender: 'bot',
      content: evil,
      is_follow_up: true,
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
      expect(screen.getByText(FU_LABEL)).toBeInTheDocument();
      unmount();
    }
  });
});
