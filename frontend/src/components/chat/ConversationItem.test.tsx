import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConversationItem } from './ConversationItem';
import type { Conversation, Message } from '@/types/api';

/**
 * Invented fixture data only — never real customer messages.
 * Mirrors the contract Conversation / Message schemas.
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
    created_at: '2026-11-12T09:06:00Z',
    updated_at: '2026-11-12T09:06:00Z',
    ...overrides,
  } as Message;
}

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
  return {
    id: 1,
    bot_id: 26,
    customer_profile_id: null,
    external_customer_id: 'customer-fixture-1',
    channel_type: 'line',
    status: 'active',
    is_handover: false,
    assigned_user_id: null,
    memory_notes: null,
    tags: [],
    context: null,
    current_flow_id: null,
    message_count: 3,
    unread_count: 0,
    last_message_at: '2026-11-12T09:06:00Z',
    bot_auto_enable_at: null,
    bot_auto_enable_remaining_seconds: null,
    context_cleared_at: null,
    created_at: '2026-11-12T08:00:00Z',
    updated_at: '2026-11-12T09:06:00Z',
    customer_profile: null,
    assigned_user: null,
    last_message: null,
    needs_response: false,
    ...overrides,
  } as Conversation;
}

const noop = () => {};

describe('ConversationItem last message preview', () => {
  it('shows the content preview when last_message.content is non-empty', () => {
    render(
      <ConversationItem
        conversation={makeConversation({
          last_message: makeMessage({ id: 98, sender: 'bot', content: 'Please contact Support' }),
        })}
        isSelected={false}
        onClick={noop}
      />
    );
    screen.getByText('Please contact Support');
    expect(screen.queryByText('3 messages')).not.toBeInTheDocument();
  });

  it('shows no blank or undefined preview line when last_message.content is empty', () => {
    render(
      <ConversationItem
        conversation={makeConversation({
          last_message: makeMessage({ id: 99, sender: 'bot', content: '' }),
        })}
        isSelected={false}
        onClick={noop}
      />
    );
    // Quoted rendered preview: the fallback line "3 messages" (message_count
    // with a MessageCircle icon). The empty content string falls back to the
    // count line; nothing blank or "undefined" is printed.
    screen.getByText('3 messages');
    expect(screen.queryByText('undefined')).not.toBeInTheDocument();
  });
});
