import { queryClient, persister } from './query';

/**
 * Drop every trace of the current session's cached queries.
 *
 * 1. Cancel in-flight queries first, so a late response for the previous
 *    user cannot re-populate the cache after the clear.
 * 2. Empty the in-memory QueryClient.
 * 3. Remove the persisted IndexedDB copy.
 *
 * Called on explicit logout, forced logout on 401, and account replacement
 * (login as another user).
 */
export async function clearSessionQueries(): Promise<void> {
  await queryClient.cancelQueries();
  queryClient.clear();
  await persister.removeClient();
}
