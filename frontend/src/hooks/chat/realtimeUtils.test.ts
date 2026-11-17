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

describe('realtimeUtils follow-up flag', () => {
  it('realtime_flag_survives_normalization_and_is_not_metadata', () => {
    const flagged = createMessageFromEvent({ ...baseEvent, is_follow_up: true });
    expect(flagged.is_follow_up).toBe(true);

    // Any non-true value becomes exactly false, never undefined.
    expect(
      createMessageFromEvent({ ...baseEvent, is_follow_up: false }).is_follow_up,
    ).toBe(false);
    expect(createMessageFromEvent(baseEvent).is_follow_up).toBe(false);
    expect(
      createMessageFromEvent({ ...baseEvent, is_follow_up: null as unknown as boolean })
        .is_follow_up,
    ).toBe(false);
    expect(
      createMessageFromEvent({ ...baseEvent, is_follow_up: 'true' as unknown as boolean })
        .is_follow_up,
    ).toBe(false);
    expect(
      createMessageFromEvent({ ...baseEvent, is_follow_up: 1 as unknown as boolean })
        .is_follow_up,
    ).toBe(false);

    // The outage flag of the same event is unchanged by the follow-up copy.
    const both = createMessageFromEvent({
      ...baseEvent,
      is_outage_notice: true,
      is_follow_up: true,
    });
    expect(both.is_outage_notice).toBe(true);
    expect(both.is_follow_up).toBe(true);
    const outageOnly = createMessageFromEvent({ ...baseEvent, is_outage_notice: true });
    expect(outageOnly.is_outage_notice).toBe(true);
    expect(outageOnly.is_follow_up).toBe(false);

    // Raw metadata never leaks through normalization.
    const keys = Object.keys(flagged);
    expect(keys).not.toContain('metadata');
    expect(keys).not.toContain('follow_up_log_id');
  });
});
