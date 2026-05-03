import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { useConnectionStatus } from './useConnectionStatus';

describe('useConnectionStatus', () => {
  let queryClient: QueryClient;
  let invalidateSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    queryClient = new QueryClient();
    invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries').mockImplementation(() => Promise.resolve());
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  it('invalidates conversation/messages queries when echo:resumed fires', () => {
    renderHook(() => useConnectionStatus(), { wrapper });

    window.dispatchEvent(new CustomEvent('echo:resumed'));

    expect(invalidateSpy).toHaveBeenCalledTimes(1);

    const callArg = invalidateSpy.mock.calls[0][0] as { predicate: (q: { queryKey: readonly unknown[] }) => boolean };
    expect(callArg).toHaveProperty('predicate');

    // Verify the predicate function targets the right query keys
    const matchedKeys = [
      ['conversations-infinite', 1],
      ['messages', 'list', 1, 2],
      ['conversation', 1],
      ['conversation-detail', 1, 2],
      ['conversation-stats', 1],
    ];
    for (const key of matchedKeys) {
      expect(callArg.predicate({ queryKey: key })).toBe(true);
    }

    const unmatchedKeys = [
      ['bots'],
      ['flows', 1],
      ['quick-replies'],
    ];
    for (const key of unmatchedKeys) {
      expect(callArg.predicate({ queryKey: key })).toBe(false);
    }
  });

  it('also invalidates queries when echo:reconnected fires (same handler)', () => {
    renderHook(() => useConnectionStatus(), { wrapper });

    window.dispatchEvent(new CustomEvent('echo:reconnected'));

    expect(invalidateSpy).toHaveBeenCalledTimes(1);
  });
});
