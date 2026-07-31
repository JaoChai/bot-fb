import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ChatInputArea } from './ChatInputArea';
import type { Conversation } from '@/types/api';

// LINEMessageInput ลาก quick-reply query ตามมา — ไม่เกี่ยวกับ test นี้
vi.mock('@/components/line/LINEMessageInput', () => ({
  LINEMessageInput: () => <div data-testid="line-input" />,
}));

const noop = async () => {};

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
  return {
    id: 1,
    channel_type: 'line',
    status: 'closed',
    is_handover: false,
    unread_count: 0,
    message_count: 0,
    created_at: '2026-07-31T00:00:00Z',
    ...overrides,
  } as Conversation;
}

/** ไต่ขึ้นไปหา wrapper ที่มี border-t ซึ่งเป็นตัวที่ต้องกัน safe area */
function inputWrapper(container: HTMLElement) {
  return container.querySelector('.border-t');
}

describe('ChatInputArea safe area', () => {
  it('กัน safe area ขอบล่างในสถานะ closed', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'closed' })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(screen.getByText('This conversation is closed')).toBeInTheDocument();
    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ bot_active', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'active', is_handover: false })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ telegram', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ channel_type: 'telegram', status: 'active' })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ handover ทั่วไป (ไม่ใช่ LINE/Telegram)', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({
          channel_type: 'facebook',
          status: 'active',
          is_handover: true,
        })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ line_handover ที่พิมพ์ได้จริง', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'active', is_handover: true })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(screen.getByTestId('line-input')).toBeInTheDocument();
    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });
});
