import { describe, it, expect, beforeEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { dehydrate } from '@tanstack/react-query';
import { persistQueryClientSave, persistQueryClientRestore } from '@tanstack/query-persist-client-core';
import { get } from 'idb-keyval';
import { queryClient, persister, shouldDehydrateQuery, queryKeys, PERSISTENCE_KEY } from './query';
import { clearSessionQueries } from './sessionCache';

// jsdom has no IndexedDB: fake only the storage driver, keep the real
// persister + persist-query logic untouched.
vi.mock('idb-keyval', async () => {
  const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
  return idbKeyvalMock;
});

describe('clearSessionQueries', () => {
  beforeEach(async () => {
    queryClient.clear();
    const { idbKeyvalMock } = await import('@/test/mocks/idbKeyval');
    idbKeyvalMock.reset();
  });

  it('empties the in-memory cache and removes the persisted copy', async () => {
    queryClient.setQueryData(['messages', 'list', 1], { from: 'user A' });
    queryClient.setQueryData(queryKeys.models.decisions(), [{ model_id: 'x' }]);
    // Simulate the payload the app persisted (private roots included, as the
    // old denylist build did).
    await persister.persistClient({
      buster: 'v5',
      timestamp: Date.now(),
      clientState: dehydrate(queryClient),
    });
    expect(await get(PERSISTENCE_KEY)).not.toBeNull();

    await clearSessionQueries();

    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
    expect(queryClient.getQueryData(['messages', 'list', 1])).toBeUndefined();
    expect(await get(PERSISTENCE_KEY)).toBeNull();
  });

  it('a late in-flight response for user A does not re-populate the cache', async () => {
    let resolveLate: (value: unknown) => void = () => {};
    const queryFn = vi.fn(
      () =>
        new Promise<unknown>((resolve) => {
          resolveLate = resolve;
        })
    );
    void queryClient.prefetchQuery({ queryKey: ['messages', 'list', 1], queryFn });
    await vi.waitFor(() => expect(queryFn).toHaveBeenCalledTimes(1));

    await clearSessionQueries();

    resolveLate({ from: 'user A' });
    // Give the detached query a chance to wrongly write into the cache.
    await new Promise((resolve) => setTimeout(resolve, 20));

    expect(queryClient.getQueryData(['messages', 'list', 1])).toBeUndefined();
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
  });

  it('a stored v4 payload is not restored under buster v5 and is removed', async () => {
    queryClient.setQueryData(['messages', 'list', 1], { from: 'user A' });
    // Old build persisted private roots without a filter — reproduce that payload.
    await persister.persistClient({
      buster: 'v4',
      timestamp: Date.now(),
      clientState: dehydrate(queryClient),
    });
    // Fresh page load: nothing in memory before the restore attempt.
    queryClient.clear();

    await persistQueryClientRestore({ queryClient, persister, buster: 'v5' });

    expect(queryClient.getQueryData(['messages', 'list', 1])).toBeUndefined();
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
    expect(await get(PERSISTENCE_KEY)).toBeNull();
  });

  it('a stored v5 payload still restores (control against over-blocking)', async () => {
    queryClient.setQueryData(queryKeys.models.decisions(), [{ model_id: 'x' }]);
    await persistQueryClientSave({
      queryClient,
      persister,
      buster: 'v5',
      dehydrateOptions: { shouldDehydrateQuery },
    });
    queryClient.clear();

    await persistQueryClientRestore({ queryClient, persister, buster: 'v5' });

    expect(queryClient.getQueryData(queryKeys.models.decisions())).toEqual([{ model_id: 'x' }]);
  });

  it('main.tsx persists with buster v5 so stored v4 payloads are dropped on upgrade', () => {
    const mainSource = readFileSync(
      path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'main.tsx'),
      'utf-8'
    );
    expect(mainSource).toMatch(/buster:\s*'v5'/);
  });
});
