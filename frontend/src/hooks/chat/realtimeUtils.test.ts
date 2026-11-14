import { describe, it, expect } from 'vitest';
import { createMessageFromEvent } from './realtimeUtils';
import type { MessageSentEvent } from '@/types/realtime';

// Broadcast payload shape (MessageSentPayload): the conversation snapshot is
// consumed elsewhere, so the minimal cast keeps this test about the flag.
const baseEvent = {
  id: 301,
  conversation_id: 10,
  sender: 'bot',
  content: 'ระบบกลับมาใช้งานได้แล้ว',
  type: 'text',
  media_url: null,
  media_type: null,
  created_at: '2026-11-12T09:00:05Z',
  conversation: { id: 10 },
} as unknown as MessageSentEvent;

describe('realtimeUtils outage notice flag', () => {
  it('preserve_notice_flag_from_realtime_and_sync', () => {
    const flagged = createMessageFromEvent({ ...baseEvent, is_outage_notice: true });
    expect(flagged.is_outage_notice).toBe(true);

    // Old payloads carry no flag: absent means false, never undefined.
    const unflagged = createMessageFromEvent(baseEvent);
    expect(unflagged.is_outage_notice).toBe(false);

    const explicitFalse = createMessageFromEvent({ ...baseEvent, is_outage_notice: false });
    expect(explicitFalse.is_outage_notice).toBe(false);
  });
});
