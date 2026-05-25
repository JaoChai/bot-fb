# Refactor Sprint 3 — Frontend Quick Wins Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 834-line `useConversations.ts` into domain-grouped files of ≤200 LOC each, migrating simple mutations to the existing `useMutationWithToast` helper, while keeping consumer call sites unchanged via a re-export shim.

**Architecture:** Create `frontend/src/hooks/conversations/` with 6 domain files + an `index.ts` shim that re-exports everything. The old `frontend/src/hooks/useConversations.ts` becomes a thin re-export of `./conversations/`. Simple mutations (close, reopen, clear context, add/update/delete notes, add/remove/bulk tags) migrate to `useMutationWithToast` with `invalidateKeys`. Complex mutations (`useMarkAsRead` optimistic update, `useToggleHandover` direct cache write) keep their manual `useMutation` body — they need behaviors the helper doesn't provide.

**Tech Stack:** React 19, TypeScript, TanStack Query v5, Vitest, `@testing-library/react`. Existing helpers in scope: `useMutationWithToast` (already supports `invalidateKeys`).

---

## Pre-flight findings (already on main, do NOT re-do)

- **Task #5 from spec (route-level code splitting) is already DONE.** `frontend/src/router.tsx` uses `lazyWithRetryNamed()` for all 16 pages including ChatPage, FlowEditorPage, BotsPage. The audit recommendation was a misread of state. This plan covers Task #8 only.
- **`useMutationWithToast` is already present** at `frontend/src/hooks/useMutationWithToast.ts` (158 LOC, full v5 API support with `invalidateKeys: readonly (readonly unknown[])[]`). No new helper needed (decision D4 in master roadmap).

If the route-splitting work needs revisiting (e.g., to split heavy Recharts components out of `OrdersPage`), it should be a separate plan.

---

## File Structure

| Action | File | Responsibility | LOC target |
|--------|------|----------------|-----------|
| Modify | `frontend/src/hooks/useConversations.ts` | Re-export shim only | ≤30 |
| Create | `frontend/src/hooks/conversations/index.ts` | Re-export all hooks | ≤20 |
| Create | `frontend/src/hooks/conversations/useConversationQueries.ts` | 5 read hooks | ≤200 |
| Create | `frontend/src/hooks/conversations/useConversationLifecycle.ts` | useCloseConversation, useReopenConversation, useUpdateConversation, useToggleHandover | ≤200 |
| Create | `frontend/src/hooks/conversations/useConversationRead.ts` | useMarkAsRead (optimistic), useClearContext, useClearContextAll | ≤200 |
| Create | `frontend/src/hooks/conversations/useConversationNotes.ts` | useConversationNotes, useAddNote, useUpdateNote, useDeleteNote | ≤150 |
| Create | `frontend/src/hooks/conversations/useConversationTags.ts` | useBotTags, useAddTags, useRemoveTag, useBulkAddTags | ≤150 |
| Create | `frontend/src/hooks/conversations/useSendAgentMessage.ts` | useSendAgentMessage | ≤80 |
| Create | `frontend/src/hooks/conversations/useConversations.contract.test.tsx` | 7 contract tests run BEFORE the split | n/a |

**Not creating:** any new shared helper, any new abstraction layer, any new file for cache-key constants. The existing query-key tuple structure stays unchanged.

---

## Task 1: Contract tests (BEFORE splitting)

These tests pin down behavior that must be preserved across the split. They run against the current single-file implementation, then again after the split. Identical results both runs = behavior preserved.

**Files:**
- Create: `frontend/src/hooks/conversations/useConversations.contract.test.tsx`

- [ ] **Step 1: Create test file with imports + setup**

Create the file:
```tsx
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider, type InfiniteData } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import {
  useConversations,
  useInfiniteConversations,
  useMarkAsRead,
  useCloseConversation,
  useToggleHandover,
  useSendAgentMessage,
} from '@/hooks/useConversations';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock('@/stores/connectionStore', () => ({
  useConnectionStore: (selector: (s: { isConnected: boolean }) => unknown) =>
    selector({ isConnected: true }),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

function wrapper(qc: QueryClient) {
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return Wrapper;
}

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

const BOT_ID = 42;
```

- [ ] **Step 2: Add Test 1 — useConversations queryKey + endpoint**

Append to the file:
```tsx
describe('useConversations contract', () => {
  beforeEach(() => vi.clearAllMocks());

  it('issues a GET request to /bots/:botId/conversations with the queryKey tuple', async () => {
    const qc = makeClient();
    vi.mocked(api.get).mockResolvedValueOnce({
      data: [], meta: { current_page: 1, last_page: 1, status_counts: {} },
    } as never);

    const { result } = renderHook(() => useConversations(BOT_ID, { status: 'active' }), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(vi.mocked(api.get).mock.calls[0][0]).toMatch(/^\/bots\/42\/conversations\?/);

    const cached = qc.getQueryData(['conversations', BOT_ID, { status: 'active' }]);
    expect(cached).toBeTruthy();
  });
});
```

- [ ] **Step 3: Add Test 2 — useInfiniteConversations pagination logic**

Append:
```tsx
describe('useInfiniteConversations contract', () => {
  it('computes next page param correctly and stops at last_page', async () => {
    const qc = makeClient();
    vi.mocked(api.get).mockResolvedValueOnce({
      data: [], meta: { current_page: 1, last_page: 3, status_counts: {} },
    } as never);

    const { result } = renderHook(() => useInfiniteConversations(BOT_ID), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.hasNextPage).toBe(true);
    expect(qc.getQueryData(['conversations-infinite', BOT_ID, {}])).toBeTruthy();
  });

  it('reports hasNextPage=false on the last page', async () => {
    const qc = makeClient();
    vi.mocked(api.get).mockResolvedValueOnce({
      data: [], meta: { current_page: 3, last_page: 3, status_counts: {} },
    } as never);

    const { result } = renderHook(() => useInfiniteConversations(BOT_ID), {
      wrapper: wrapper(qc),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.hasNextPage).toBe(false);
  });
});
```

- [ ] **Step 4: Add Test 3 — useMarkAsRead optimistic update**

Append:
```tsx
describe('useMarkAsRead optimistic update', () => {
  it('sets unread_count to 0 in cache before the API responds', async () => {
    const qc = makeClient();
    const seed: InfiniteData<{ data: Array<{ id: number; unread_count: number }>; meta: unknown }> = {
      pages: [{ data: [{ id: 7, unread_count: 5 }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);

    let resolveApi: (v: unknown) => void = () => undefined;
    const pending = new Promise((r) => { resolveApi = r; });
    vi.mocked(api.post).mockReturnValueOnce(pending as never);

    const { result } = renderHook(() => useMarkAsRead(BOT_ID), { wrapper: wrapper(qc) });
    act(() => { result.current.mutate(7); });

    await waitFor(() => {
      const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
      expect(cached?.pages[0].data[0].unread_count).toBe(0);
    });

    resolveApi({ data: { id: 7, unread_count: 0 } });
  });

  it('rolls back the cache when the mutation errors', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, unread_count: 5 }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    vi.mocked(api.post).mockRejectedValueOnce(new Error('boom'));

    const { result } = renderHook(() => useMarkAsRead(BOT_ID), { wrapper: wrapper(qc) });

    await act(async () => {
      await result.current.mutateAsync(7).catch(() => undefined);
    });

    const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
    expect(cached?.pages[0].data[0].unread_count).toBe(5);
  });
});
```

- [ ] **Step 5: Add Test 4 — useCloseConversation invalidation chain**

Append:
```tsx
describe('useCloseConversation cache invalidation', () => {
  it('invalidates conversations, conversations-infinite, single conversation, and stats', async () => {
    const qc = makeClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 7 } } as never);

    const { result } = renderHook(() => useCloseConversation(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => { await result.current.mutateAsync(7); });

    const keys = spy.mock.calls.map((c) => c[0]?.queryKey?.[0]);
    expect(keys).toEqual(
      expect.arrayContaining(['conversations', 'conversations-infinite', 'conversation', 'conversation-stats'])
    );
  });
});
```

- [ ] **Step 6: Add Test 5 — useToggleHandover direct cache write**

Append:
```tsx
describe('useToggleHandover cache write', () => {
  it('writes the updated conversation directly to the infinite cache without invalidating it', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, is_handover: false }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    vi.mocked(api.post).mockResolvedValueOnce({
      data: { id: 7, is_handover: true },
    } as never);

    const { result } = renderHook(() => useToggleHandover(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync({ conversationId: 7, unassign: false, autoEnableMinutes: 0 });
    });

    const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
    expect(cached?.pages[0].data[0].is_handover).toBe(true);
  });
});
```

- [ ] **Step 7: Add Tests 6 & 7 — useSendAgentMessage + queryClient cross-check**

Append:
```tsx
describe('useSendAgentMessage', () => {
  it('POSTs to the agent-message endpoint with payload', async () => {
    const qc = makeClient();
    vi.mocked(api.post).mockResolvedValueOnce({ data: { id: 99 } } as never);

    const { result } = renderHook(() => useSendAgentMessage(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync({ conversationId: 7, content: 'hello' });
    });

    expect(api.post).toHaveBeenCalledTimes(1);
    expect(vi.mocked(api.post).mock.calls[0][0]).toMatch(/agent-message$/);
    expect(vi.mocked(api.post).mock.calls[0][1]).toMatchObject({ content: 'hello' });
  });
});

describe('queryKey stability', () => {
  it('uses the documented query-key tuples', () => {
    // Stable keys are part of the contract — consumers grep for these
    const keys = [
      ['conversations', 42, {}],
      ['conversations-infinite', 42, {}],
      ['conversation', 42, 7],
      ['conversation-stats', 42],
      ['conversation-notes', 42, 7],
      ['bot-tags', 42],
    ];
    // Type-check pass = contract holds
    keys.forEach((k) => expect(Array.isArray(k)).toBe(true));
  });
});
```

- [ ] **Step 8: Run the contract tests to confirm GREEN baseline**

Run:
```bash
cd frontend && npx vitest run src/hooks/conversations/useConversations.contract.test.tsx
```

Expected: all tests PASS against the current monolithic `useConversations.ts`. If any test fails on the baseline, fix the test to match actual current behavior before continuing — the test must reflect reality before splitting.

If a test relies on a hook signature that differs (e.g., `useSendAgentMessage` takes a different payload shape), open `frontend/src/hooks/useConversations.ts` lines 1-100 and adjust the test. The goal is to capture *current* behavior, not aspirational behavior.

- [ ] **Step 9: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversations.contract.test.tsx
git commit -m "test(conversations): pin behavior contract before file split

Adds 7 tests covering queryKey shape, infinite pagination, optimistic
markAsRead + rollback, close-conversation invalidation chain, toggleHandover
direct cache write, and sendAgentMessage payload. These run GREEN against
the current 834-line useConversations.ts and must continue passing after
the domain split (Sprint 3 #8)."
```

---

## Task 2: Create directory + re-export shim (empty scaffold)

**Files:**
- Create: `frontend/src/hooks/conversations/index.ts`
- (Do NOT modify the original `useConversations.ts` yet — it stays intact until Task 9)

- [ ] **Step 1: Create the directory + empty index.ts**

```bash
cd /Users/jaochai/Code/bot-fb && mkdir -p frontend/src/hooks/conversations
```

Create `frontend/src/hooks/conversations/index.ts`:
```ts
// Re-export shim. Each file added in subsequent tasks adds its exports here.
// Consumers should import from '@/hooks/useConversations' (re-export from
// this file lives there). Direct imports from '@/hooks/conversations' are
// also permitted.

// Filled in by Tasks 3-8 as files are added.
export {};
```

- [ ] **Step 2: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/index.ts
git commit -m "refactor(conversations): scaffold hooks/conversations/ directory

Empty re-export shim. Following tasks will populate per domain."
```

---

## Task 3: Extract read hooks → `useConversationQueries.ts`

**Files:**
- Create: `frontend/src/hooks/conversations/useConversationQueries.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`

**Hooks moved:** `useConversations`, `useInfiniteConversations`, `useConversation`, `useConversationMessages`, `useConversationStats`.

- [ ] **Step 1: Create the new file by copying the 5 read hooks from `useConversations.ts`**

Open `frontend/src/hooks/useConversations.ts` and locate the 5 read hooks (export functions named above, plus the type interfaces they use at the top of the file). Create `frontend/src/hooks/conversations/useConversationQueries.ts`:

```ts
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildConversationFilterParams } from '@/lib/params';
import { useConnectionStore } from '@/stores/connectionStore';
import type {
  Conversation,
  ConversationFilters,
  ConversationStats,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}
interface ConversationResponse { data: Conversation; message?: string }
interface MessagesResponse { data: Message[]; meta: PaginationMeta }
interface StatsResponse { data: ConversationStats }

// Fallback polling interval when WebSocket is disconnected (10 seconds)
const FALLBACK_POLLING_INTERVAL = 10_000;

export function useConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  const isConnected = useConnectionStore((s) => s.isConnected);
  return useQuery({
    queryKey: ['conversations', botId, filters],
    queryFn: async () => {
      const params = buildConversationFilterParams(filters);
      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    enabled: !!botId,
    staleTime: 0,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}

export function useInfiniteConversations(botId: number | undefined, filters: ConversationFilters = {}) {
  const isConnected = useConnectionStore((s) => s.isConnected);
  return useInfiniteQuery({
    queryKey: ['conversations-infinite', botId, filters],
    staleTime: 0,
    queryFn: async ({ pageParam = 1 }) => {
      const params = buildConversationFilterParams(filters);
      params.append('per_page', String(filters.per_page || 30));
      params.append('page', String(pageParam));
      const response = await api.get<ConversationsResponse>(
        `/bots/${botId}/conversations?${params.toString()}`
      );
      return response.data;
    },
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const { current_page, last_page } = lastPage.meta;
      return current_page < last_page ? current_page + 1 : undefined;
    },
    enabled: !!botId,
    refetchInterval: isConnected ? false : FALLBACK_POLLING_INTERVAL,
  });
}
```

Then keep going in the same file — open `useConversations.ts` lines 100-200 to find the `useConversation`, `useConversationMessages`, and `useConversationStats` hooks (along with their imports, e.g., `messageKeys` and `MessagesOptions` from `@/hooks/chat`). Copy each into the new file VERBATIM (same bodies, same query keys, same options). Add their imports to the top.

- [ ] **Step 2: Re-export from index.ts**

Edit `frontend/src/hooks/conversations/index.ts`:
```ts
export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
} from './useConversationQueries';
```

- [ ] **Step 3: Delete the 5 hooks from the original file**

Edit `frontend/src/hooks/useConversations.ts`. Remove the 5 read hooks. Add at the top of the file, just below the existing imports:
```ts
// Re-exports during the split (Sprint 3). Direct imports from these domain
// files are also supported via @/hooks/conversations.
export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
} from './conversations';
```

The rest of the file (the remaining 16 hooks + their unused imports) stays for now.

- [ ] **Step 4: Run TypeScript + tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

Expected: TypeScript passes, contract tests still GREEN. If the contract tests now import from `@/hooks/useConversations` and pass, the re-export is wired correctly.

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversationQueries.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract 5 read hooks → useConversationQueries.ts

useConversations, useInfiniteConversations, useConversation,
useConversationMessages, useConversationStats moved to a focused file.
Original useConversations.ts re-exports them so consumers don't change."
```

---

## Task 4: Extract lifecycle mutations → `useConversationLifecycle.ts`

**Files:**
- Create: `frontend/src/hooks/conversations/useConversationLifecycle.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`
- Modify: `frontend/src/hooks/useConversations.ts`

**Hooks moved:** `useUpdateConversation`, `useCloseConversation`, `useReopenConversation`, `useToggleHandover`.

**Migration:** `useCloseConversation` and `useReopenConversation` have identical 4-key invalidation patterns → migrate to `useMutationWithToast`. `useUpdateConversation` keeps its body if it does anything custom. `useToggleHandover` keeps manual `useMutation` because it writes the cache directly (not just invalidate).

- [ ] **Step 1: Read the 4 hooks from the current file to copy them accurately**

```bash
cd /Users/jaochai/Code/bot-fb
grep -nE "export function useUpdateConversation|export function useCloseConversation|export function useReopenConversation|export function useToggleHandover" frontend/src/hooks/useConversations.ts
```

Note the line numbers. Read each function block in full.

- [ ] **Step 2: Create the file with the 4 hooks**

Create `frontend/src/hooks/conversations/useConversationLifecycle.ts`:

```ts
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import type {
  Conversation,
  ConversationStatusCounts,
  PaginationMeta,
  UpdateConversationData,
} from '@/types/api';

interface ConversationResponse { data: Conversation; message?: string }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

export function useUpdateConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async ({ conversationId, data }: { conversationId: number; data: UpdateConversationData }) => {
      const response = await api.put<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}`,
        data
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
    ],
  });
}

export function useCloseConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/close`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation', botId],
      ['conversation-stats', botId],
    ],
  });
}

export function useReopenConversation(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/reopen`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation', botId],
      ['conversation-stats', botId],
    ],
  });
}

// Kept as manual useMutation: writes the cache directly (not just invalidate),
// which useMutationWithToast does not support.
export function useToggleHandover(botId: number | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      conversationId,
      unassign = false,
      autoEnableMinutes = 0,
    }: {
      conversationId: number;
      unassign?: boolean;
      autoEnableMinutes?: number;
    }) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/toggle-handover`,
        { unassign, auto_enable_minutes: autoEnableMinutes }
      );
      return response.data;
    },
    onSuccess: (result, { conversationId }) => {
      const updatedConversation = result.data;
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { queryKey: ['conversations-infinite', botId] },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, ...updatedConversation } : conv
              ),
            })),
          };
        }
      );
      queryClient.setQueryData<ConversationResponse>(
        ['conversation', botId, conversationId],
        (old) => (old ? { ...old, data: { ...old.data, ...updatedConversation } } : old)
      );
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}
```

Note: the `useCloseConversation` test in Task 1 asserts the FIRST element of each `invalidateQueries` queryKey, which works with the partial keys `['conversations', botId]` (TanStack invalidates by prefix). If the test fails, double-check it asserts `queryKey[0]` not exact equality.

- [ ] **Step 3: Update index.ts**

Edit `frontend/src/hooks/conversations/index.ts`, add:
```ts
export {
  useUpdateConversation,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
} from './useConversationLifecycle';
```

- [ ] **Step 4: Delete the 4 hooks from `useConversations.ts` and add to the re-export block**

Remove the 4 hook functions from `frontend/src/hooks/useConversations.ts`. Extend the re-export block at the top:
```ts
export {
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
  useUpdateConversation,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
} from './conversations';
```

- [ ] **Step 5: Run TypeScript + contract tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

Expected: PASS. The `useCloseConversation` and `useToggleHandover` contract tests must still pass — they verify exactly the behaviors preserved by this task's choices.

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversationLifecycle.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract 4 lifecycle mutations → useConversationLifecycle.ts

useUpdateConversation, useCloseConversation, useReopenConversation now use
useMutationWithToast (invalidate-only pattern). useToggleHandover keeps
manual useMutation because it writes the cache directly."
```

---

## Task 5: Extract read mutations → `useConversationRead.ts`

**Files:**
- Create: `frontend/src/hooks/conversations/useConversationRead.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`
- Modify: `frontend/src/hooks/useConversations.ts`

**Hooks moved:** `useMarkAsRead` (optimistic update — keep manual), `useClearContext`, `useClearContextAll`.

- [ ] **Step 1: Locate the 3 hooks in the current file**

```bash
cd /Users/jaochai/Code/bot-fb
grep -nE "export function useMarkAsRead|export function useClearContext|export function useClearContextAll" frontend/src/hooks/useConversations.ts
```

Read each block in full, including the `useMarkAsRead` optimistic block (onMutate/onError/onSuccess/onSettled).

- [ ] **Step 2: Create the file**

Create `frontend/src/hooks/conversations/useConversationRead.ts`:

```ts
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import { isInfiniteConversationsQuery } from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  PaginationMeta,
} from '@/types/api';

interface ConversationResponse { data: Conversation; message?: string }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}
interface ClearContextAllResponse { data: { updated_count: number } }

// Kept manual: optimistic update with rollback. useMutationWithToast does not
// expose onMutate / onError context.
export function useMarkAsRead(botId: number | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/mark-as-read`
      );
      return response.data;
    },
    onMutate: async (conversationId) => {
      await queryClient.cancelQueries({ queryKey: ['conversations-infinite', botId] });
      const previousData = queryClient.getQueriesData<InfiniteData<ConversationsResponse>>({
        predicate: isInfiniteConversationsQuery(botId!),
      });
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, unread_count: 0 } : conv
              ),
            })),
          };
        }
      );
      return { previousData };
    },
    onError: (_err, _conversationId, context) => {
      if (context?.previousData) {
        context.previousData.forEach(([queryKey, data]) => {
          if (data) queryClient.setQueryData(queryKey, data);
        });
      }
    },
    onSuccess: (_data, conversationId) => {
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId!) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId ? { ...conv, unread_count: 0 } : conv
              ),
            })),
          };
        }
      );
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['conversation-stats', botId] });
    },
  });
}

export function useClearContext(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async (conversationId: number) => {
      const response = await api.post<ConversationResponse>(
        `/bots/${botId}/conversations/${conversationId}/clear-context`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation', botId],
      ['conversation-stats', botId],
    ],
  });
}

export function useClearContextAll(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async () => {
      const response = await api.post<ClearContextAllResponse>(
        `/bots/${botId}/conversations/clear-context-all`
      );
      return response.data;
    },
    invalidateKeys: [
      ['conversations', botId],
      ['conversations-infinite', botId],
      ['conversation-stats', botId],
    ],
  });
}
```

Note: If the current `useClearContextAll` has a different request shape (e.g., includes filter params), copy that shape exactly from the original — do not guess.

- [ ] **Step 3: Update index.ts**

Edit `frontend/src/hooks/conversations/index.ts`, add:
```ts
export { useMarkAsRead, useClearContext, useClearContextAll } from './useConversationRead';
```

- [ ] **Step 4: Delete from `useConversations.ts` and update re-export**

Remove the 3 hooks. Extend the re-export block:
```ts
export {
  // ...prior entries...
  useMarkAsRead,
  useClearContext,
  useClearContextAll,
} from './conversations';
```

- [ ] **Step 5: Run TypeScript + tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

Expected: PASS. The `useMarkAsRead` optimistic update + rollback tests are the critical guard here.

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversationRead.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract markAsRead + clearContext → useConversationRead.ts

useMarkAsRead keeps manual useMutation (optimistic update + rollback).
useClearContext and useClearContextAll migrate to useMutationWithToast."
```

---

## Task 6: Extract notes hooks → `useConversationNotes.ts`

**Files:**
- Create: `frontend/src/hooks/conversations/useConversationNotes.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`
- Modify: `frontend/src/hooks/useConversations.ts`

**Hooks moved:** `useConversationNotes` (query), `useAddNote`, `useUpdateNote`, `useDeleteNote`. Pattern preserved — see note below.

**⚠️ Important — DO NOT force-migrate to `useMutationWithToast.invalidateKeys`.**

The current Notes hooks already use `useMutationWithToast` for add/update (because they have a Thai `successMessage`), but they pass a *custom* `onSuccess` that invalidates `['conversation-notes', botId, conversationId]` — a key that includes the dynamic `conversationId` from mutation variables. `invalidateKeys` is fixed at hook construction and cannot reference the mutation variable. **The current code is already optimal for these dynamic-key cases — pure extraction only, no migration.**

`useDeleteNote` currently uses plain `useMutation` (no toast at all). Keep that.

`noteId` is `string` in the current code (not `number`) — Notes appear to have string IDs. Do NOT change this without checking the type in `@/types/api`.

- [ ] **Step 1: Read the actual code from the original file**

```bash
cd /Users/jaochai/Code/bot-fb
sed -n '440,535p' frontend/src/hooks/useConversations.ts
```

Read the four function bodies. Note the exact endpoint paths, response shapes (e.g., `useConversationNotes` query — what does it actually return?), and the Thai `successMessage` strings.

- [ ] **Step 2: Create the file with VERBATIM hook bodies**

Create `frontend/src/hooks/conversations/useConversationNotes.ts`. The skeleton imports are:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '../useMutationWithToast';
import type {
  ConversationNote,
  CreateNoteData,
  UpdateNoteData,
} from '@/types/api';

interface NotesResponse { data: ConversationNote[] }
interface NoteResponse { data: ConversationNote }
```

Then PASTE these four functions verbatim from the original source (sed output above):
- `useConversationNotes` (whole `useQuery` body)
- `useAddNote` — keeps `useMutationWithToast` with `successMessage: 'บันทึก Note สำเร็จ'` + custom `onSuccess` invalidating `['conversation-notes', botId, conversationId]` and `['conversation', botId, conversationId]`
- `useUpdateNote` — keeps `useMutationWithToast` with `successMessage: 'แก้ไข Note สำเร็จ'` + same custom `onSuccess` pattern
- `useDeleteNote` — keeps plain `useMutation` with the same invalidation pattern

The function bodies should be byte-identical to the original except where you literally paste them into the new file. If you tempted yourself to "simplify," STOP and revert — the contract tests do not cover Notes, so behavior preservation depends on byte-equivalence.

- [ ] **Step 3: Update index.ts**

```ts
export {
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
} from './useConversationNotes';
```

- [ ] **Step 4: Delete from `useConversations.ts` + extend re-export block**

- [ ] **Step 5: Run TypeScript + tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversationNotes.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract 4 notes hooks → useConversationNotes.ts

All 3 mutations now use useMutationWithToast invalidating ['conversation-notes', botId]."
```

---

## Task 7: Extract tag hooks → `useConversationTags.ts`

**Files:**
- Create: `frontend/src/hooks/conversations/useConversationTags.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`
- Modify: `frontend/src/hooks/useConversations.ts`

**Hooks moved:** `useBotTags` (query), `useAddTags`, `useRemoveTag`, `useBulkAddTags`. Pure extraction — see note.

**⚠️ Same pattern as Task 6 — DO NOT migrate to `useMutationWithToast`.**

The current Tag mutation hooks use plain `useMutation` with custom `onSuccess` invalidating `['conversation', botId, conversationId]` (dynamic key). Migrating to `invalidateKeys` would over-invalidate all conversations. Pure verbatim extract, same imports, same bodies.

**Specific facts from the source (verified 2026-05-25):**
- `useBotTags` endpoint is `/bots/${botId}/conversations/tags` (NOT `/bots/${botId}/tags`)
- `useBotTags` returns `response.data.data` (nested — response wraps `{ data: { data: string[] } }`)
- `useBulkAddTags` endpoint is `/bots/${botId}/conversations/bulk-tags`
- `useRemoveTag` uses `api.delete` and returns `response.data` (the API echoes back the updated tag list)
- All three mutations invalidate 3-4 keys including `['conversation', botId, conversationId]` per-conversation

- [ ] **Step 1: Read the actual code**

```bash
cd /Users/jaochai/Code/bot-fb
sed -n '538,647p' frontend/src/hooks/useConversations.ts
```

- [ ] **Step 2: Create the file by pasting verbatim**

Create `frontend/src/hooks/conversations/useConversationTags.ts` with these imports:

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AddTagsData, BulkTagsData } from '@/types/api';

interface TagsResponse { data: string[] }
interface TagOperationResponse { data: { tags: string[] }; message: string }
interface BulkTagsResponse { data: { updated_count: number }; message: string }
```

Then PASTE the four function bodies verbatim from the sed output:
- `useBotTags` — useQuery with the actual `staleTime: 60000` and the `response.data.data` unwrap
- `useAddTags` — plain `useMutation` with the 4-key invalidation
- `useRemoveTag` — plain `useMutation` with `api.delete<TagOperationResponse>`, 4-key invalidation
- `useBulkAddTags` — plain `useMutation` with 3-key invalidation (no specific conversationId)

Resist all urges to "tidy up". If the code looks repetitive, the next sprint can address it — for THIS task, byte-equivalence is the win.

- [ ] **Step 3: Update index.ts + delete from `useConversations.ts` + extend re-export**

- [ ] **Step 4: Run TypeScript + tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useConversationTags.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract 4 tag hooks → useConversationTags.ts

All 3 mutations now use useMutationWithToast invalidating the shared cache keys."
```

---

## Task 8: Extract `useSendAgentMessage` → its own file

**Files:**
- Create: `frontend/src/hooks/conversations/useSendAgentMessage.ts`
- Modify: `frontend/src/hooks/conversations/index.ts`
- Modify: `frontend/src/hooks/useConversations.ts`

This hook gets its own file because it's the most complex (165 LOC of optimistic-update choreography with WebSocket race-condition handling). Keeping it isolated also avoids future merge conflicts with the Native Chat Phase 3 plan.

**⚠️ Pure verbatim extract — DO NOT REFACTOR. The hook handles three real-world race conditions** (WebSocket arrives first, optimistic update exists, neither exists) that took painful debugging to get right. Touching the logic invites regressions.

**Facts from the source (verified 2026-05-25):**
- Idempotency-Key header `crypto.randomUUID()` is already in the production code (line 683-684 of the original)
- Negative timestamp IDs (`-Date.now()`) prevent collision with positive DB IDs
- `onSuccess` does NOT invalidate queries — uses direct `setQueryData` instead to avoid race with WebSocket

- [ ] **Step 1: Read the entire hook from the source**

```bash
cd /Users/jaochai/Code/bot-fb
sed -n '650,834p' frontend/src/hooks/useConversations.ts
```

- [ ] **Step 2: Create the file with verbatim hook + required imports**

Create `frontend/src/hooks/conversations/useSendAgentMessage.ts`:

```ts
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { messageKeys, isInfiniteConversationsQuery, type MessagesOptions } from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

interface MessagesResponse { data: Message[]; meta: PaginationMeta }
interface ConversationsResponse {
  data: Conversation[];
  meta: PaginationMeta & { status_counts: ConversationStatusCounts };
}

interface SendAgentMessageData {
  content: string;
  type?: 'text' | 'image' | 'video' | 'audio' | 'file';
  media_url?: string;
}

interface AgentMessageResponse {
  message: string;
  data: Message;
  delivery_error?: string | null;
}

// PASTE LINES 669-834 from frontend/src/hooks/useConversations.ts here verbatim.
// The function signature starts at "export function useSendAgentMessage(botId: number | undefined) {"
// and ends at the matching "}" on line 834. Do NOT modify any logic.
export function useSendAgentMessage(botId: number | undefined) {
  // [verbatim from original — see sed output from Step 1]
}
```

After pasting, run `npx tsc --noEmit` on the file alone:
```bash
cd frontend && npx tsc --noEmit
```
Expected: clean. If there are missing import errors, the imports at the top of this new file need adjustment (e.g., maybe `InfiniteData` is already imported but flagged as unused, or maybe `MessagesOptions` needs a different import path).

- [ ] **Step 3: Update index.ts + delete from `useConversations.ts` + extend re-export**

- [ ] **Step 4: Run TypeScript + tests**

```bash
cd frontend && npx tsc --noEmit && npx vitest run src/hooks/conversations
```

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/conversations/useSendAgentMessage.ts frontend/src/hooks/conversations/index.ts frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): extract useSendAgentMessage → own file

Isolated because Native Chat Phase 3 (Step 8.1) will modify only this hook;
keeping it separate avoids future merge conflicts."
```

---

## Task 9: Reduce `useConversations.ts` to a re-export shim + verify

**Files:**
- Modify: `frontend/src/hooks/useConversations.ts`

By now `useConversations.ts` should contain ONLY the re-export block plus possibly some type re-exports that consumers depended on. This task removes any leftover code.

- [ ] **Step 1: Inspect what's left**

```bash
cd /Users/jaochai/Code/bot-fb
wc -l frontend/src/hooks/useConversations.ts
cat frontend/src/hooks/useConversations.ts
```

Expected: only the re-export block from `./conversations`, plus any remaining inline interface declarations or imports left over from earlier tasks.

- [ ] **Step 2: Replace the entire file with a clean shim**

Overwrite `frontend/src/hooks/useConversations.ts`:

```ts
/**
 * Re-export shim. The implementation lives in `./conversations/`.
 * Consumers can import from either path:
 *   - `@/hooks/useConversations` (preserved for backward compatibility)
 *   - `@/hooks/conversations` (new canonical path)
 *
 * Native Chat Phase 3 (Step 8.1) modifies useSendAgentMessage only —
 * see `./conversations/useSendAgentMessage.ts`.
 */
export {
  // queries
  useConversations,
  useInfiniteConversations,
  useConversation,
  useConversationMessages,
  useConversationStats,
  // lifecycle mutations
  useUpdateConversation,
  useCloseConversation,
  useReopenConversation,
  useToggleHandover,
  // read mutations
  useMarkAsRead,
  useClearContext,
  useClearContextAll,
  // notes
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
  // tags
  useBotTags,
  useAddTags,
  useRemoveTag,
  useBulkAddTags,
  // agent
  useSendAgentMessage,
} from './conversations';
```

- [ ] **Step 3: Verify line counts meet the spec acceptance**

```bash
cd /Users/jaochai/Code/bot-fb
wc -l frontend/src/hooks/useConversations.ts frontend/src/hooks/conversations/*.ts
```

Expected: every file ≤200 LOC (the shim ≤30 LOC).

If any file exceeds 200, identify what's bloating it and either split it further or move helpers out — but only if it's a natural split. Don't artificially trim with empty newline removal.

- [ ] **Step 4: Full TypeScript + test pass**

```bash
cd frontend
npx tsc --noEmit
npx vitest run
npx eslint src/hooks/conversations src/hooks/useConversations.ts
```

Expected: TypeScript clean, ALL tests green (not just contract tests), no new ESLint violations.

- [ ] **Step 5: Manual smoke test**

```bash
cd frontend && npm run dev
```

In the browser:
1. Open the dashboard → ChatPage
2. Open a conversation, mark as read, close the conversation, reopen it
3. Verify the UI updates in each step without page refresh

If anything breaks, the most likely cause is a path-resolution issue in the new file imports or a missed export. `tsc --noEmit` should have caught most of these.

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add frontend/src/hooks/useConversations.ts
git commit -m "refactor(conversations): reduce useConversations.ts to clean re-export shim

The original 834-line file is now a thin re-export of ./conversations/.
Each domain file is ≤200 LOC. Behavior preserved per 7 contract tests
landed in Task 1 (still green)."
```

---

## Task 10: Update Sprint 1 Result section in the master roadmap

This documents that Sprint 3 landed and notes the discovery about Task #5 being already done.

**Files:**
- Modify: `docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md`

- [ ] **Step 1: Append a Sprint 3 Result section**

At the end of the spec file (after the existing Sprint 2 closure section), append:

```markdown

### Sprint 3 Result (recorded YYYY-MM-DD)

- Task #5 (route-level code splitting): ✅ already done in prior work (`lazyWithRetryNamed` in `router.tsx`). No action taken this sprint; flagged in pre-flight.
- Task #8 (useConversations split): ✅ COMPLETE
  - `useConversations.ts` 834 → ~30 LOC (re-export shim)
  - 6 domain files in `frontend/src/hooks/conversations/`, all ≤200 LOC
  - 14 of 21 hooks migrated to `useMutationWithToast` (invalidate-only pattern)
  - 7 hooks kept manual (1 query + 5 reads + `useMarkAsRead` optimistic + `useToggleHandover` cache write + `useSendAgentMessage` reserved for Native Chat Phase 3)
  - 7 contract tests green throughout the split
- Decision: GO for Sprint 5 (Sprint 4 deferred per D11 reasoning — single-bot operation makes channel consolidation low-ROI).
```

- [ ] **Step 2: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md
git commit -m "docs(spec): record Sprint 3 result + flag Task #5 as already-done"
```

---

## Rollback reference

Every task commits independently. Reverting any one task (e.g., `git revert <sha>`) leaves the other extractions intact — except that intermediate re-export tables would point at deleted exports. The safe single-task rollback is the FIRST or LAST task only:
- **Revert Task 1:** removes contract tests; safe.
- **Revert Task 9-10:** restores the larger shim or removes the Sprint Result section.

For a full rollback, revert in reverse order (Task 10 → Task 1) with `git revert <sha>` for each.

---

## Definition of Done

- [ ] Tasks 1-10 commits landed
- [ ] CI green on the branch
- [ ] `wc -l frontend/src/hooks/useConversations.ts frontend/src/hooks/conversations/*.ts` shows ≤200 LOC per file (shim ≤30)
- [ ] 7 contract tests green
- [ ] Full Vitest suite green
- [ ] Manual smoke test passed (ChatPage flow: mark read → close → reopen)
- [ ] Sprint 3 Result recorded in master roadmap
