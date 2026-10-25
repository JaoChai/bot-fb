import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { dehydrate } from '@tanstack/react-query';
import { MemoryRouter, useLocation } from 'react-router';
import React from 'react';
import { get } from 'idb-keyval';
import { queryClient, persister, queryKeys, PERSISTENCE_KEY } from '@/lib/query';
import { clearSessionQueries } from '@/lib/sessionCache';
import { useAuthStore } from '@/stores/authStore';
import { useAuth } from './useAuth';
import type { User, AuthResponse, LoginCredentials } from '@/types/api';

// jsdom has no IndexedDB: fake only the storage driver.
vi.mock('idb-keyval', async () => {
  const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
  return idbKeyvalMock;
});
vi.mock('@/lib/echo', () => ({
  disconnectEcho: vi.fn(),
  reconnectEcho: vi.fn(),
}));
// Hook tests go through this seam instead of the axios stack.
vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
  apiGet: vi.fn(),
  apiPut: vi.fn(),
  apiDelete: vi.fn(),
  getErrorMessage: (error: unknown) => String(error),
}));
// Wrap the real cleanup so tests can assert it ran while still executing it.
vi.mock('@/lib/sessionCache', async (importOriginal) => {
  const mod = await importOriginal<typeof import('@/lib/sessionCache')>();
  return { ...mod, clearSessionQueries: vi.fn(mod.clearSessionQueries) };
});

const userA: User = {
  id: 1,
  name: 'User A',
  email: 'a@test.dev',
  role: 'owner',
  email_verified_at: null,
  created_at: '',
  updated_at: '',
};
const userB: User = {
  id: 2,
  name: 'User B',
  email: 'b@test.dev',
  role: 'owner',
  email_verified_at: null,
  created_at: '',
  updated_at: '',
};
const loginResponseB: AuthResponse = { user: userB, token: 'token-b' };
const messagesKey = ['messages', 'list', 1] as const;

function seedUserASession() {
  queryClient.setQueryData(messagesKey, { from: 'user A' });
  queryClient.setQueryData(queryKeys.auth.user(), userA);
  useAuthStore.setState({
    user: userA,
    token: 'token-a',
    isAuthenticated: true,
    isLoading: false,
  });
  localStorage.setItem('auth_token', 'token-a');
  // Persisted copy as the running app would have written it.
  return persister.persistClient({
    buster: 'v5',
    timestamp: Date.now(),
    clientState: dehydrate(queryClient),
  });
}

describe('useAuth session cleanup', () => {
  let navPath: string;

  beforeEach(async () => {
    vi.clearAllMocks();
    queryClient.clear();
    const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
    idbKeyvalMock.reset();
    navPath = '';
  });

  const wrapper = ({ children }: { children: React.ReactNode }) => {
    const LocationProbe = () => {
      const location = useLocation();
      navPath = location.pathname;
      return null;
    };
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/dashboard']}>
          <LocationProbe />
          {children}
        </MemoryRouter>
      </QueryClientProvider>
    );
  };

  const mockedClear = vi.mocked(clearSessionQueries);

  async function settleClearCalls(count: number) {
    // Mutations retry once with a 1s backoff, so give the settled path time.
    await waitFor(() => expect(mockedClear).toHaveBeenCalledTimes(count), { timeout: 3000 });
    const results = mockedClear.mock.results.slice(-count);
    for (const result of results) {
      if (result.type === 'return') await result.value;
    }
  }

  it('explicit logout clears queries and the persisted copy even when the logout request fails', async () => {
    await seedUserASession();
    const { apiPost } = await import('@/lib/api');
    // Both the initial attempt and the one mutation retry must fail.
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('logout endpoint down'));
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('logout endpoint down'));

    const { result } = renderHook(() => useAuth(), { wrapper });
    act(() => {
      result.current.logout();
    });

    await settleClearCalls(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(localStorage.getItem('auth_token')).toBeNull();
    expect(queryClient.getQueryData(messagesKey)).toBeUndefined();
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
    expect(await get(PERSISTENCE_KEY)).toBeNull();
    expect(navPath).toBe('/login');
  });

  it('explicit logout clears queries and the persisted copy on success', async () => {
    await seedUserASession();
    const { apiPost } = await import('@/lib/api');
    vi.mocked(apiPost).mockResolvedValueOnce(undefined);

    const { result } = renderHook(() => useAuth(), { wrapper });
    act(() => {
      result.current.logout();
    });

    await settleClearCalls(1);
    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(queryClient.getQueryData(messagesKey)).toBeUndefined();
    expect(await get(PERSISTENCE_KEY)).toBeNull();
    expect(navPath).toBe('/login');
  });

  it('login as another user after logout shows none of the previous user data', async () => {
    await seedUserASession();
    const { apiPost } = await import('@/lib/api');
    vi.mocked(apiPost).mockResolvedValueOnce(undefined); // /auth/logout
    vi.mocked(apiPost).mockResolvedValueOnce(loginResponseB); // /auth/login

    const { result } = renderHook(() => useAuth(), { wrapper });

    act(() => {
      result.current.logout();
    });
    await settleClearCalls(1);

    await act(async () => {
      await result.current.loginAsync({ email: 'b@test.dev' } as LoginCredentials);
    });
    await settleClearCalls(2);

    expect(queryClient.getQueryData(messagesKey)).toBeUndefined();
    expect(queryClient.getQueryData(queryKeys.auth.user())).toEqual(userB);
    expect(queryClient.getQueryCache().getAll()).toHaveLength(1); // only B's auth entry
    expect(await get(PERSISTENCE_KEY)).toBeNull();
    expect(navPath).toBe('/dashboard');
  });
});
