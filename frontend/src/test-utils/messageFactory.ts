import type { Message } from '@/types/api';

/**
 * Test fixture factory for Message objects.
 * Shared by the infinite-messages cache specs so a Message shape change
 * needs exactly one edit.
 */
export function makeMessage(
  id: number,
  createdAt: string,
  overrides: Partial<Message> = {}
): Message {
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
    ...overrides,
  };
}
