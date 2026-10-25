import { describe, it, expect, beforeEach, vi } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { dehydrate } from '@tanstack/react-query';
import { get } from 'idb-keyval';
import { api } from './api';
import { queryClient, persister, PERSISTENCE_KEY } from './query';
import { clearSessionQueries } from '@/lib/sessionCache';
import { useAuthStore } from '@/stores/authStore';

// jsdom has no IndexedDB: fake only the storage driver.
vi.mock('idb-keyval', async () => {
  const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
  return idbKeyvalMock;
});
vi.mock('@/lib/echo', () => ({
  disconnectEcho: vi.fn(),
  reconnectEcho: vi.fn(),
}));
// Wrap the real cleanup so the test asserts it ran AND still executes it.
vi.mock('@/lib/sessionCache', async (importOriginal) => {
  const mod = await importOriginal<typeof import('@/lib/sessionCache')>();
  return { ...mod, clearSessionQueries: vi.fn(mod.clearSessionQueries) };
});

describe('api 401 forced logout', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    queryClient.clear();
    const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
    idbKeyvalMock.reset();
    // Simulate a logged-in session with private cached data + persisted copy.
    queryClient.setQueryData(['messages', 'list', 1], { from: 'user A' });
    useAuthStore.setState({
      user: {
        id: 1,
        name: 'User A',
        email: 'a@test.dev',
        role: 'owner',
        email_verified_at: null,
        created_at: '',
        updated_at: '',
      },
      token: 'token-a',
      isAuthenticated: true,
      isLoading: false,
    });
    localStorage.setItem('auth_token', 'token-a');
    await persister.persistClient({
      buster: 'v5',
      timestamp: Date.now(),
      clientState: dehydrate(queryClient),
    });
  });

  it('a 401 response clears the session cache, not only the token', async () => {
    // A custom adapter must reject for non-2xx itself (axios does not
    // re-check validateStatus after a custom adapter), like the real
    // XHR adapter does.
    api.defaults.adapter = async (config) => {
      const response = {
        data: { message: 'Unauthenticated.' },
        status: 401,
        statusText: 'Unauthorized',
        headers: new AxiosHeaders(),
        config,
      };
      throw new AxiosError(
        'Request failed with status code 401',
        AxiosError.ERR_BAD_REQUEST,
        config,
        {},
        response
      );
    };

    await api.get('/messages').catch(() => {});

    const mockedClear = vi.mocked(clearSessionQueries);
    await vi.waitFor(() => expect(mockedClear).toHaveBeenCalledTimes(1));
    const result = mockedClear.mock.results[0];
    if (result.type === 'return') await result.value;

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(localStorage.getItem('auth_token')).toBeNull();
    expect(queryClient.getQueryData(['messages', 'list', 1])).toBeUndefined();
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
    expect(await get(PERSISTENCE_KEY)).toBeNull();
  });
});
