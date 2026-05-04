# Native Chat — Phase 4: IndexedDB + Stale-While-Revalidate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** เปิด tab/F5 → เห็นข้อความทันที (<100ms) จาก IndexedDB cache, แล้ว background refetch ข้อมูลใหม่

**Architecture:** Swap React Query persister จาก localStorage sync → IndexedDB async (idb-keyval) + เปลี่ยน NON_PERSISTENT_KEYS ให้เก็บ messages/conversations + ตั้ง staleTime 30s สำหรับ SWR

**Tech Stack:** @tanstack/query-async-storage-persister, idb-keyval, React Query v5

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 4

**Depends on:** Phase 1 merged ✅, Phase 2 merged (recommended but not blocking)

---

## Pre-Flight

- [ ] Create branch: `feat/chat-indexeddb-cache`
- [ ] Install deps: `cd frontend && npm install idb-keyval @tanstack/query-async-storage-persister`

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `frontend/src/lib/query.ts` | modify | Swap persister, update NON_PERSISTENT_KEYS, staleTime, cache version |
| `frontend/src/lib/query.test.ts` | **create** | Test shouldDehydrateQuery with new keys |

---

## Task 1: Install Dependencies

- [ ] `cd frontend && npm install idb-keyval @tanstack/query-async-storage-persister`
- [ ] Verify: `npx tsc --noEmit`
- [ ] Commit: `chore: add idb-keyval and async storage persister deps`

## Task 2: Swap Persister to IndexedDB

**File:** `frontend/src/lib/query.ts`

- [ ] **Step 2.1:** Read current `frontend/src/lib/query.ts` to understand current persister setup

- [ ] **Step 2.2:** Replace imports:
```typescript
// Remove:
import { createSyncStoragePersister } from '@tanstack/query-sync-storage-persister';
// Add:
import { experimental_createPersister } from '@tanstack/query-persist-client-core';
import { get, set, del } from 'idb-keyval';
```

- [ ] **Step 2.3:** Update NON_PERSISTENT_KEYS — remove messages/conversations from the exclude list:
```typescript
const NON_PERSISTENT_KEYS = [
  'bots',
  'bot-tags',
  // conversations, messages, etc. NOW persisted in IndexedDB
];
```

- [ ] **Step 2.4:** Update staleTime for SWR:
```typescript
staleTime: 30 * 1000,  // 30s — show cached data immediately, refetch in background
```

- [ ] **Step 2.5:** Replace persister:
```typescript
export const persister = experimental_createPersister({
  storage: {
    getItem: async (key) => await get(key) ?? null,
    setItem: async (key, value) => await set(key, value),
    removeItem: async (key) => await del(key),
  },
  maxAge: 7 * 24 * 60 * 60 * 1000, // 7 days
  prefix: 'BOTJAO_QUERY_CACHE_v2',
});
```

Note: `prefix` v2 prevents mixing with old localStorage cache.

- [ ] **Step 2.6:** Add one-time migration to clear old localStorage cache:
```typescript
if (typeof window !== 'undefined' && window.localStorage.getItem('BOTJAO_QUERY_CACHE')) {
  window.localStorage.removeItem('BOTJAO_QUERY_CACHE');
}
```

- [ ] **Step 2.7:** Verify build + tests: `cd frontend && npx tsc --noEmit && npm run test`

- [ ] **Step 2.8:** Commit:
```
feat(chat): swap query persister to IndexedDB with SWR

Replace localStorage sync persister with IndexedDB async persister
(idb-keyval). Messages and conversations now persist across page
reloads. staleTime 30s enables stale-while-revalidate pattern.
Cache version bumped to v2 to avoid mixing with old format.
```

## Task 3: Update shouldDehydrateQuery Test

- [ ] **Step 3.1:** Write test verifying messages/conversations ARE now persisted
- [ ] **Step 3.2:** Verify bots/bot-tags still NOT persisted
- [ ] **Step 3.3:** Commit: `test(chat): verify IndexedDB persistence scope`

## Task 4: /simplify + Push + PR

- [ ] Run `/simplify`
- [ ] Push: `feat/chat-indexeddb-cache`
- [ ] PR: `feat(chat): IndexedDB cache with stale-while-revalidate`

## Definition of Done
- [ ] F5 → messages render from cache (<100ms, no spinner)
- [ ] Background refetch updates cache
- [ ] Old localStorage cache auto-cleared
- [ ] 7-day eviction works
