import { describe, it, expect, beforeEach } from 'vitest';
import { useSyncCursors } from './syncEngine';

// Reset store between tests
beforeEach(() => {
  useSyncCursors.setState({
    lastConvSyncAt: {},
    lastMessageId: {},
  });
});

describe('useSyncCursors', () => {
  it('stores conversation sync timestamp', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-01-01T00:00:00Z');
  });

  it('stores message cursor', () => {
    useSyncCursors.getState().setCursor('1:100', 42);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(42);
  });

  it('stores multiple conversation cursors independently', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    useSyncCursors.getState().setCursor('conv:2', '2026-02-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-01-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[2]).toBe('2026-02-01T00:00:00Z');
  });

  it('stores multiple message cursors independently', () => {
    useSyncCursors.getState().setCursor('1:100', 10);
    useSyncCursors.getState().setCursor('1:200', 20);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(10);
    expect(useSyncCursors.getState().lastMessageId['1:200']).toBe(20);
  });

  it('overwrites existing conversation cursor', () => {
    useSyncCursors.getState().setCursor('conv:1', '2026-01-01T00:00:00Z');
    useSyncCursors.getState().setCursor('conv:1', '2026-06-01T00:00:00Z');
    expect(useSyncCursors.getState().lastConvSyncAt[1]).toBe('2026-06-01T00:00:00Z');
  });

  it('overwrites existing message cursor', () => {
    useSyncCursors.getState().setCursor('1:100', 10);
    useSyncCursors.getState().setCursor('1:100', 99);
    expect(useSyncCursors.getState().lastMessageId['1:100']).toBe(99);
  });

  it('initialises with empty cursors', () => {
    expect(useSyncCursors.getState().lastConvSyncAt).toEqual({});
    expect(useSyncCursors.getState().lastMessageId).toEqual({});
  });
});
