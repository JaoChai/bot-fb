// In-memory stand-in for the real IndexedDB driver (idb-keyval).
// jsdom ships no IndexedDB, so unit tests fake ONLY this storage driver —
// the production persister/persist-query logic runs untouched.
const store = new Map<string, string>();

export const idbKeyvalMock = {
  get: async (key: string) => store.get(key) ?? null,
  set: async (key: string, value: string) => {
    store.set(key, value);
  },
  del: async (key: string) => {
    store.delete(key);
  },
  reset: () => {
    store.clear();
  },
};
