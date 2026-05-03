import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { useConnectionStatus } from './useConnectionStatus';

describe('useConnectionStatus', () => {
  let queryClient: QueryClient;
  let invalidateSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    queryClient = new QueryClient();
    invalidateSpy = vi.fn();
    queryClient.invalidateQueries = invalidateSpy as unknown as QueryClient['invalidateQueries'];
  });

  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  it('invalidates conversation/messages queries when echo:resumed fires', () => {
    renderHook(() => useConnectionStatus(), { wrapper });

    window.dispatchEvent(new CustomEvent('echo:resumed'));

    expect(invalidateSpy).toHaveBeenCalledTimes(1);
    const call = invalidateSpy.mock.calls[0][0];
    expect(call).toHaveProperty('predicate');
  });
});
