# Track 1 — Frontend Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One conversation hook system (`src/hooks/chat/`), zero knip findings, one Radix package, and the React Compiler enabled — with no user-visible behavior change.

**Architecture:** Two PRs on one branch. PR-A (Tasks 1–3) folds the four legacy-only hooks into `hooks/chat/`, repoints five consumers, and deletes `hooks/conversations/` plus the `useConversations.ts` shim. PR-B (Tasks 4–6) applies knip findings, rewrites 17 Radix imports to the `radix-ui` monolith and drops the 17 `@radix-ui/*` packages, and enables the React Compiler through `@rolldown/plugin-babel`. Every task ends with lint + tsc + vitest + build green.

**Tech Stack:** React 19.2, Vite 8.2 (Rolldown), `@vitejs/plugin-react` 6.1 (`reactCompilerPreset`), TanStack Query 5.102, Vitest 4.1, knip 6, `radix-ui` 1.6 monolith, `babel-plugin-react-compiler`, `@rolldown/plugin-babel`.

**Spec:** `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §5 (Track 1)

## Global Constraints

- Dependency policy: **minor/patch + security only** (spec D2). The two new dev deps (`babel-plugin-react-compiler`, `@rolldown/plugin-babel`) are additions, not bumps. Never change a `^major` in `package.json`.
- Verbatim-move rule (spec §8): hook bodies are copied, not rewritten. Only import paths and file locations change in PR-A.
- Every commit: `npm run lint` 0 errors, `npx tsc --noEmit` clean, `npm test` green, `npm run build` succeeds (all run from `frontend/`).
- Branch: `refactor/track1-frontend-cleanup` from `main` (create via `superpowers:using-git-worktrees` at execution start). Independent of PR #251 (Track 0) — no shared files.
- Commit message footer (required):
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
  ```
- **Deviation from spec §5.2 (recorded in Task 6):** the 24 `react-hooks/*` compiler warnings are **not** fixed in this plan. Each needs a site-specific rewrite across 13 files (12 `set-state-in-effect`, 6 `refs`, 5 `exhaustive-deps`, 1 `incompatible-library`); they get their own plan (PR-C) after the compiler is on. The five rules stay at `warn` until then.

## Baseline (2026-09-05, main @ `d44e0e7`)

| Check | Result |
|---|---|
| `npm test` | 30 files / 154 tests passed |
| `npm run lint` | 0 errors, 24 warnings |
| `npx knip --no-progress` | 1 unused file, 70 unused exports, 27 unused types, 7 config hints (exit 1) |
| `npm run build` | 492.25 kB gzip total JS; `vendor-radix` 139.14 kB raw |
| Query persister | `buster: 'v3'` in `src/main.tsx:52` |

Key facts verified for this plan:
- Query keys are shape-identical across both hook systems: `['conversation-notes', botId, cid]`, `['bot-tags', botId]`, `['conversation', botId, cid]`, `['conversation-stats', botId]`, `['conversations-infinite', botId, filters]`. Moving hooks does not change cache behavior.
- `useNotes` / `useAddNote` / `useUpdateNote` / `useDeleteNote` / `useBotTags` / `useAddTags` / `useRemoveTag` in `hooks/chat` accept the **same arguments and mutate payloads** as the legacy versions (`{ conversationId, data }`, `{ conversationId, tag }`, `{ conversationId, noteId }`). The chat versions add optimistic updates and do not fire `toast.error`; `NotesPanel` and `TagsPanel` already show their own toasts in `try/catch`, so nothing is lost.
- Only five files import the legacy system: `pages/ChatPage.tsx` (`useClearContextAll`), `hooks/useChatActions.ts` (`useSendAgentMessage`, `useToggleHandover`, `useClearContext`), `components/chat/BotControl.tsx` (`useToggleHandover`), `components/conversation/TagsPanel.tsx` (`useBotTags`, `useAddTags`, `useRemoveTag`), `components/conversation/NotesPanel.tsx` (`useConversationNotes`, `useAddNote`, `useUpdateNote`, `useDeleteNote`).
- Legacy hooks with **no** consumer outside tests (deleted, not moved): `useConversations`, `useInfiniteConversations`, `useConversation`, `useConversationMessages`, `useConversationStats`, `useUpdateConversation`, `useCloseConversation`, `useReopenConversation`, legacy `useMarkAsRead`, `useBulkAddTags`.
- `radix-ui` 1.6 exports every namespace needed: `Tabs AlertDialog Slider Dialog Label ScrollArea Tooltip Switch Avatar Separator Slot Select Collapsible DropdownMenu VisuallyHidden Popover` (verified with `node -e "import('radix-ui')"`). `Slot` namespace has `Root`; `VisuallyHidden` has `Root`.
- `@vitejs/plugin-react` 6.1.1 exports `reactCompilerPreset` (verified).

---

## PR-A — one conversation hook system

### Task 1: Move `useToggleHandover`, `useClearContext`, `useClearContextAll` into `hooks/chat/`

**Files:**
- Create: `frontend/src/hooks/chat/useConversationActions.ts`
- Create: `frontend/src/hooks/chat/useConversationActions.test.tsx`
- Modify: `frontend/src/hooks/chat/index.ts` (add export block)
- Source (read-only in this task): `frontend/src/hooks/conversations/useConversationLifecycle.ts:66-113`, `frontend/src/hooks/conversations/useConversationRead.ts:100-144`

**Interfaces:**
- Consumes: `useMutationWithToast` from `@/hooks/useMutationWithToast` (existing; signature `useMutationWithToast({ mutationFn, invalidateKeys })`).
- Produces (used by Task 3):
  - `useToggleHandover(botId: number | undefined)` → `UseMutationResult`; `mutate({ conversationId: number; unassign?: boolean; autoEnableMinutes?: number })`
  - `useClearContext(botId: number | undefined)` → mutation over `conversationId: number`
  - `useClearContextAll(botId: number | undefined)` → mutation over `undefined`
  All exported from `@/hooks/chat`.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/hooks/chat/useConversationActions.test.tsx`:

```tsx
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { useToggleHandover, useClearContext } from './useConversationActions';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
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

describe('useToggleHandover cache write', () => {
  beforeEach(() => vi.clearAllMocks());

  it('writes the updated conversation directly to the infinite cache without invalidating it', async () => {
    const qc = makeClient();
    const seed = {
      pages: [{ data: [{ id: 7, is_handover: false }], meta: { current_page: 1 } }],
      pageParams: [1],
    };
    qc.setQueryData(['conversations-infinite', BOT_ID, {}], seed);
    vi.mocked(api.post).mockResolvedValueOnce({
      data: { data: { id: 7, is_handover: true } },
    } as never);

    const { result } = renderHook(() => useToggleHandover(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync({ conversationId: 7, unassign: false, autoEnableMinutes: 0 });
    });

    const cached = qc.getQueryData<typeof seed>(['conversations-infinite', BOT_ID, {}]);
    expect(cached?.pages[0].data[0].is_handover).toBe(true);
  });
});

describe('useClearContext', () => {
  beforeEach(() => vi.clearAllMocks());

  it('invalidates the 4 expected prefix keys on success', async () => {
    const qc = makeClient();
    const spy = vi.spyOn(qc, 'invalidateQueries');
    vi.mocked(api.post).mockResolvedValueOnce({ data: { data: { id: 7 } } } as never);

    const { result } = renderHook(() => useClearContext(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => { await result.current.mutateAsync(7); });

    const invalidatedKeys = spy.mock.calls.map((c) => c[0]?.queryKey);
    expect(invalidatedKeys).toEqual(
      expect.arrayContaining([
        ['conversations', BOT_ID],
        ['conversations-infinite', BOT_ID],
        ['conversation', BOT_ID],
        ['conversation-stats', BOT_ID],
      ])
    );
  });

  it('fires toast.error with the server message when the mutation fails', async () => {
    const qc = makeClient();
    vi.mocked(api.post).mockRejectedValueOnce(new Error('Server exploded'));

    const { result } = renderHook(() => useClearContext(BOT_ID), { wrapper: wrapper(qc) });
    await act(async () => {
      await result.current.mutateAsync(7).catch(() => undefined);
    });

    expect(toast.error).toHaveBeenCalledTimes(1);
    expect(vi.mocked(toast.error).mock.calls[0][0]).toBe('Server exploded');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/hooks/chat/useConversationActions.test.tsx`
Expected: FAIL — `Failed to resolve import "./useConversationActions"`.

- [ ] **Step 3: Create the hook file (verbatim bodies from the legacy files)**

Create `frontend/src/hooks/chat/useConversationActions.ts`. The three function bodies are copied **byte-for-byte** from `useConversationLifecycle.ts:71-113` (`useToggleHandover`) and `useConversationRead.ts:108-144` (`useClearContext`, `useClearContextAll`); only the header (imports + local interfaces) is new:

```ts
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useMutationWithToast } from '@/hooks/useMutationWithToast';
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

interface ClearContextAllResponse {
  data: { updated_count: number };
  message: string;
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

/**
 * Hook to clear bot context for a conversation
 * Bot will not reference messages before the cleared timestamp
 */
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

/**
 * Hook to clear bot context for ALL active/handover conversations
 * Bot will start fresh with all open conversations
 */
export function useClearContextAll(botId: number | undefined) {
  return useMutationWithToast({
    mutationFn: async () => {
      if (!botId) throw new Error('Bot ID is required');
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

Prove the bodies are verbatim:

```bash
cd frontend && diff <(sed -n '71,113p' src/hooks/conversations/useConversationLifecycle.ts) <(sed -n '/^export function useToggleHandover/,/^}/p' src/hooks/chat/useConversationActions.ts) && echo TOGGLE_VERBATIM
diff <(sed -n '108,144p' src/hooks/conversations/useConversationRead.ts) <(sed -n '/^export function useClearContext(/,$p' src/hooks/chat/useConversationActions.ts) && echo CLEAR_VERBATIM
```

Expected: `TOGGLE_VERBATIM` and `CLEAR_VERBATIM` (no diff lines). If a diff shows only the leading docblock comment lines, that is acceptable; any difference inside a function body is not.

- [ ] **Step 4: Export from the barrel**

Append to `frontend/src/hooks/chat/index.ts` (after the `// Tags (T035)` export line):

```ts
// Conversation actions (moved from hooks/conversations in Track 1)
export { useToggleHandover, useClearContext, useClearContextAll } from './useConversationActions';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/hooks/chat/useConversationActions.test.tsx && npx tsc --noEmit && echo TSC_OK`
Expected: 3 tests pass; `TSC_OK`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/hooks/chat/useConversationActions.ts frontend/src/hooks/chat/useConversationActions.test.tsx frontend/src/hooks/chat/index.ts
git commit -m "refactor(hooks): ย้าย useToggleHandover/useClearContext/useClearContextAll เข้า hooks/chat (verbatim)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 2: Move `useSendAgentMessage` (file + its test) into `hooks/chat/`

**Files:**
- Move: `frontend/src/hooks/conversations/useSendAgentMessage.ts` → `frontend/src/hooks/chat/useSendAgentMessage.ts`
- Move: `frontend/src/hooks/conversations/useSendAgentMessage.test.tsx` → `frontend/src/hooks/chat/useSendAgentMessage.test.tsx`
- Modify: `frontend/src/hooks/chat/useSendAgentMessage.ts:1-11` (imports only), `frontend/src/hooks/chat/useSendAgentMessage.test.tsx:6` (import only)
- Modify: `frontend/src/hooks/chat/index.ts` (add export)

**Interfaces:**
- Consumes: `messageKeys` (`./messageKeys`), `isInfiniteConversationsQuery` (`./realtimeUtils`), `messageExistsInInfinite` / `prependMessagesToInfinite` / `replaceMessageInInfinite` / `removeMessageFromInfinite` / `InfiniteMessages` (`./infiniteMessageCache`).
- Produces (used by Task 3): `useSendAgentMessage(botId: number | undefined)`; `mutate({ conversationId: number; data: { content: string; type?: 'text' | 'image' | 'video' | 'audio' | 'file'; media_url?: string } })`. Exported from `@/hooks/chat`.

- [ ] **Step 1: Move both files with git so history follows**

```bash
cd frontend && git mv src/hooks/conversations/useSendAgentMessage.ts src/hooks/chat/useSendAgentMessage.ts \
  && git mv src/hooks/conversations/useSendAgentMessage.test.tsx src/hooks/chat/useSendAgentMessage.test.tsx
```

- [ ] **Step 2: Run the moved test to see it fail on the circular barrel import**

Run: `cd frontend && npx vitest run src/hooks/chat/useSendAgentMessage.test.tsx`
Expected: FAIL or a circular-import warning — the hook imports from `@/hooks/chat` (the barrel), which after Task 2 Step 4 will re-export this very file. (If it happens to pass, continue; Step 3 is still required to remove the cycle.)

- [ ] **Step 3: Replace the barrel import with sibling imports (imports only — bodies untouched)**

In `frontend/src/hooks/chat/useSendAgentMessage.ts` replace lines 3–11:

```ts
import {
  messageKeys,
  isInfiniteConversationsQuery,
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
  type InfiniteMessages,
} from '@/hooks/chat';
```

with:

```ts
import { messageKeys } from './messageKeys';
import { isInfiniteConversationsQuery } from './realtimeUtils';
import {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
  type InfiniteMessages,
} from './infiniteMessageCache';
```

In `frontend/src/hooks/chat/useSendAgentMessage.test.tsx` line 6, `import { messageKeys, type InfiniteMessages } from '@/hooks/chat';` stays as is (tests may use the barrel). Line 7 `from './useSendAgentMessage'` already resolves after the move.

Verify the body is untouched:

```bash
cd frontend && git diff -M --stat HEAD -- src/hooks/chat/useSendAgentMessage.ts && git diff -M HEAD -- src/hooks/chat/useSendAgentMessage.ts | grep -E "^[-+]" | grep -vE "^(\+\+\+|---)" | grep -vE "^[-+](import|  [a-zA-Z]|  type|\} from|  messageKeys,|  isInfiniteConversationsQuery,)" ; echo "(no lines above the echo = only imports changed)"
```

- [ ] **Step 4: Export from the barrel**

Append to `frontend/src/hooks/chat/index.ts` after the Task 1 block:

```ts
export { useSendAgentMessage } from './useSendAgentMessage';
```

- [ ] **Step 5: Run the test and type check**

Run: `cd frontend && npx vitest run src/hooks/chat/useSendAgentMessage.test.tsx && npx tsc --noEmit && echo TSC_OK`
Expected: 2 tests pass; `TSC_OK`.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src/hooks/chat/useSendAgentMessage.ts frontend/src/hooks/chat/useSendAgentMessage.test.tsx frontend/src/hooks/conversations/useSendAgentMessage.ts frontend/src/hooks/conversations/useSendAgentMessage.test.tsx frontend/src/hooks/chat/index.ts
git commit -m "refactor(hooks): ย้าย useSendAgentMessage เข้า hooks/chat (import ภายในโฟลเดอร์ กัน circular)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 3: Repoint the five consumers, delete the legacy system, bump the persister buster

**Files:**
- Modify: `frontend/src/pages/ChatPage.tsx:14-18` (merge into the `@/hooks/chat` import)
- Modify: `frontend/src/hooks/useChatActions.ts:6-10`
- Modify: `frontend/src/components/chat/BotControl.tsx:5`
- Modify: `frontend/src/components/conversation/TagsPanel.tsx:4-8`
- Modify: `frontend/src/components/conversation/NotesPanel.tsx` (import block + one hook name at line 63)
- Modify: `frontend/src/main.tsx:52` (`buster: 'v3'` → `'v4'`)
- Delete: `frontend/src/hooks/conversations/` (entire directory: `index.ts`, `useConversationQueries.ts`, `useConversationLifecycle.ts`, `useConversationRead.ts`, `useConversationNotes.ts`, `useConversationTags.ts`, `useConversations.contract.test.tsx`), `frontend/src/hooks/useConversations.ts`

**Interfaces:**
- Consumes: Task 1 and Task 2 exports from `@/hooks/chat`; existing `useNotes`, `useAddNote`, `useUpdateNote`, `useDeleteNote`, `useBotTags`, `useAddTags`, `useRemoveTag` from `@/hooks/chat`.
- Produces: no module under `@/hooks/conversations` or `@/hooks/useConversations` exists.

- [ ] **Step 1: Write the guard test that fails while the legacy module exists**

Create `frontend/src/hooks/chat/noLegacyImports.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync, existsSync } from 'node:fs';
import { join } from 'node:path';

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx)$/.test(name)) out.push(p);
  }
  return out;
}

describe('legacy conversation hooks are gone', () => {
  const src = join(__dirname, '..', '..');

  it('no file imports @/hooks/useConversations or @/hooks/conversations', () => {
    const offenders = walk(src).filter((f) =>
      /@\/hooks\/(useConversations|conversations)\b/.test(readFileSync(f, 'utf8'))
    );
    expect(offenders).toEqual([]);
  });

  it('the legacy modules no longer exist', () => {
    expect(existsSync(join(src, 'hooks', 'useConversations.ts'))).toBe(false);
    expect(existsSync(join(src, 'hooks', 'conversations'))).toBe(false);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/hooks/chat/noLegacyImports.test.ts`
Expected: FAIL — offenders list contains the five consumer files; `existsSync` assertions fail.

- [ ] **Step 3: Repoint the consumers**

`frontend/src/pages/ChatPage.tsx` — replace lines 14–18:

```ts
import {
  useInfiniteConversationList,
  useRealtime,
  useMarkAsRead,
} from '@/hooks/chat';
import { useClearContextAll } from '@/hooks/useConversations';
```

with:

```ts
import {
  useInfiniteConversationList,
  useRealtime,
  useMarkAsRead,
  useClearContextAll,
} from '@/hooks/chat';
```

`frontend/src/hooks/useChatActions.ts` — replace lines 6–10:

```ts
import {
  useSendAgentMessage,
  useToggleHandover,
  useClearContext,
} from '@/hooks/useConversations';
```

with:

```ts
import {
  useSendAgentMessage,
  useToggleHandover,
  useClearContext,
} from '@/hooks/chat';
```

`frontend/src/components/chat/BotControl.tsx` line 5: `from '@/hooks/useConversations';` → `from '@/hooks/chat';`

`frontend/src/components/conversation/TagsPanel.tsx` lines 4–8: change only the module — `from '@/hooks/useConversations';` → `from '@/hooks/chat';` (the three names `useBotTags, useAddTags, useRemoveTag` are unchanged).

`frontend/src/components/conversation/NotesPanel.tsx` — replace the import block:

```ts
import {
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
} from '@/hooks/useConversations';
```

with:

```ts
import {
  useNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
} from '@/hooks/chat';
```

and line 63 `const { data: notes, isLoading } = useConversationNotes(botId, conversationId);` → `const { data: notes, isLoading } = useNotes(botId, conversationId);`

- [ ] **Step 4: Delete the legacy system and bump the buster**

```bash
cd frontend && git rm -r -q src/hooks/conversations src/hooks/useConversations.ts
```

In `frontend/src/main.tsx` line 52 change `buster: 'v3',` to `buster: 'v4',` (hook modules moved; a stale IndexedDB snapshot from the old build must not be rehydrated).

- [ ] **Step 5: Run the guard test, full suite, lint, types**

Run: `cd frontend && npx vitest run src/hooks/chat/noLegacyImports.test.ts && npm test 2>&1 | grep -E "Tests |Test Files" && npm run lint 2>&1 | grep problems && npx tsc --noEmit && echo TSC_OK`
Expected: guard test 2 passed; suite: `Test Files 31 passed`, `Tests 152 passed` (154 − 8 deleted contract tests + 3 Task 1 + 2 moved + 2 guard − ...; the exact count is whatever vitest prints with **0 failed**); lint `0 errors`; `TSC_OK`.

- [ ] **Step 6: Manual smoke (Playwright MCP or browser) — record in the PR**

With backend + `npm run dev` running: open Chat → pick a conversation → add a note, edit it, delete it → add a tag, remove it → toggle the bot switch (handover) → click "clear context" → send an agent message. Expected: each action updates the UI without a full reload and no console error.

- [ ] **Step 7: Commit and open PR-A**

```bash
git add -A frontend/src
git commit -m "refactor(hooks): เหลือ hook สนทนาชุดเดียวที่ hooks/chat — ลบ hooks/conversations + shim useConversations

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
git push -u origin refactor/track1-frontend-cleanup
gh pr create --base main --title "refactor(frontend): single conversation hook system (Track 1 PR-A)" --body "$(cat <<'EOF'
## Summary
- Move `useToggleHandover`, `useClearContext`, `useClearContextAll`, `useSendAgentMessage` into `src/hooks/chat/` (verbatim bodies)
- Repoint ChatPage, useChatActions, BotControl, TagsPanel, NotesPanel to `@/hooks/chat`
- Delete `src/hooks/conversations/` and the `useConversations.ts` shim (10 hooks with no consumer removed)
- Bump React Query persister `buster` v3 → v4

Behavior note: notes/tags mutations now use the `hooks/chat` versions (optimistic updates); panels keep their own success/error toasts.

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §5.1
Plan: `docs/superpowers/plans/2026-09-05-track1-frontend-cleanup.md` Tasks 1–3

## Test plan
- [x] vitest (incl. new `useConversationActions.test.tsx`, moved `useSendAgentMessage.test.tsx`, `noLegacyImports.test.ts`)
- [x] lint 0 errors, tsc clean, build
- [x] manual smoke: note add/edit/delete, tag add/remove, handover toggle, clear context, agent message

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

---

## PR-B — dead code, Radix, React Compiler (continue on the same branch after PR-A merges, or on `refactor/track1-frontend-cleanup-b` from PR-A's head)

### Task 4: knip to zero

**Files:**
- Modify: `frontend/knip.config.ts`
- Delete: `frontend/src/components/flow-editor/index.ts`
- Modify: every file knip lists (see Step 2 for the mechanical rule)

**Interfaces:**
- Consumes: nothing
- Produces: `npx knip --no-progress` exits 0

- [ ] **Step 1: Fix the config per knip's own hints**

Replace `frontend/knip.config.ts` with:

```ts
import type { KnipConfig } from 'knip';

const config: KnipConfig = {
  entry: ['src/main.tsx'],
  project: ['src/**/*.{ts,tsx}'],
  ignoreDependencies: ['autoprefixer'],
};

export default config;
```

(Removes the stale `src/App.tsx` entry, the `src/vite-env.d.ts` and `src/components/ui/**` ignores, and the `@types/*` / `tailwindcss` ignoreDependencies — knip reported all of them as unnecessary. `autoprefixer` stays only if knip still flags it as unused after removal; if knip is silent about it, drop the line too.)

- [ ] **Step 2: Get the current list and apply the mechanical rule**

Run: `cd frontend && npx knip --no-progress`

For each finding:
- **Unused file** → `git rm` it (`src/components/flow-editor/index.ts`).
- **Unused export** in a barrel `index.ts` (`components/chat/adapters/index.ts`, `components/common/index.ts`, `components/dashboard/index.ts`, `components/flows/index.ts`, `hooks/chat/index.ts`) → delete that re-export line. Do **not** delete the underlying symbol unless knip also lists it in its own file.
- **Unused export** in a non-barrel file → if the symbol is used inside that file, delete only the `export` keyword; if it is not used anywhere, delete the symbol.
- **Unused exported type/interface** → same rule as exports (`types/api.ts` entries: `UserRole`, `FlowKnowledgeBase`, `CostSummary`, `CostByModel`, `CostByBot`, `DashboardSummary`, `OrderItem`, `OrderSummary`, `SlipSummary`, `SlipMeta` and `types/realtime.ts` `AdminNotificationEvent` are deleted outright — they have no consumer).
- `src/components/ui/**` files knip now scans: apply the same rule (shadcn variants that nothing imports are deleted).

Expected after Task 3 (hooks cleanup) the list is shorter than the baseline 70/27; work from the live output, not the baseline.

- [ ] **Step 3: Iterate until zero**

Run: `cd frontend && npx knip --no-progress; echo "knip exit=$?"`
Expected: no findings, `knip exit=0`. Re-run after each batch of deletions — removing an export can orphan another symbol.

- [ ] **Step 4: Gate**

Run: `cd frontend && npm run lint 2>&1 | grep problems && npx tsc --noEmit && npm test 2>&1 | grep -E "Tests " && npm run build 2>&1 | grep "built in"`
Expected: `0 errors`; tsc silent; all tests pass; build OK.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src frontend/knip.config.ts
git commit -m "chore(frontend): ลบ export/type/ไฟล์ที่ไม่ได้ใช้ตาม knip จนเหลือ 0

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 5: One Radix package

**Files:**
- Modify (import line only unless noted): `frontend/src/components/ui/{tabs,alert-dialog,slider,sheet,label,scroll-area,tooltip,switch,avatar,separator,select,collapsible,dropdown-menu}.tsx`, `frontend/src/components/ui/{button,badge}.tsx` (import + one usage line each), `frontend/src/components/layout/Header.tsx:11`, `frontend/src/pages/ChatPage.tsx:24`
- Modify: `frontend/vite.config.ts:29`
- Modify: `frontend/package.json` (remove 17 deps), `frontend/package-lock.json`

**Interfaces:**
- Consumes: `radix-ui` 1.6.x already in `dependencies`.
- Produces: no `@radix-ui/*` package in `package.json`.

- [ ] **Step 1: Write the guard test**

Create `frontend/src/components/ui/radixSingleSource.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx)$/.test(name)) out.push(p);
  }
  return out;
}

describe('Radix comes only from the radix-ui monolith', () => {
  it('no source file imports @radix-ui/*', () => {
    const src = join(__dirname, '..', '..');
    const offenders = walk(src).filter((f) => /from ['"]@radix-ui\//.test(readFileSync(f, 'utf8')));
    expect(offenders).toEqual([]);
  });

  it('package.json has no @radix-ui/* dependency', () => {
    const pkg = JSON.parse(readFileSync(join(__dirname, '..', '..', '..', 'package.json'), 'utf8'));
    const names = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies });
    expect(names.filter((n) => n.startsWith('@radix-ui/'))).toEqual([]);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd frontend && npx vitest run src/components/ui/radixSingleSource.test.ts`
Expected: FAIL — 17 offender files; 17 `@radix-ui/*` names.

- [ ] **Step 3: Rewrite the 17 import lines**

Namespace imports (`import * as XPrimitive from "@radix-ui/react-x"`) become named-namespace imports from the monolith — the local identifier is unchanged so no other line in the file moves:

| File | Old line | New line |
|---|---|---|
| `ui/tabs.tsx:4` | `import * as TabsPrimitive from "@radix-ui/react-tabs"` | `import { Tabs as TabsPrimitive } from "radix-ui"` |
| `ui/alert-dialog.tsx:4` | `import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog"` | `import { AlertDialog as AlertDialogPrimitive } from "radix-ui"` |
| `ui/slider.tsx:2` | `import * as SliderPrimitive from "@radix-ui/react-slider"` | `import { Slider as SliderPrimitive } from "radix-ui"` |
| `ui/sheet.tsx:4` | `import * as SheetPrimitive from "@radix-ui/react-dialog"` | `import { Dialog as SheetPrimitive } from "radix-ui"` |
| `ui/label.tsx:2` | `import * as LabelPrimitive from "@radix-ui/react-label"` | `import { Label as LabelPrimitive } from "radix-ui"` |
| `ui/scroll-area.tsx:2` | `import * as ScrollAreaPrimitive from "@radix-ui/react-scroll-area"` | `import { ScrollArea as ScrollAreaPrimitive } from "radix-ui"` |
| `ui/tooltip.tsx:4` | `import * as TooltipPrimitive from "@radix-ui/react-tooltip"` | `import { Tooltip as TooltipPrimitive } from "radix-ui"` |
| `ui/switch.tsx:2` | `import * as SwitchPrimitive from "@radix-ui/react-switch"` | `import { Switch as SwitchPrimitive } from "radix-ui"` |
| `ui/avatar.tsx:2` | `import * as AvatarPrimitive from "@radix-ui/react-avatar"` | `import { Avatar as AvatarPrimitive } from "radix-ui"` |
| `ui/separator.tsx:4` | `import * as SeparatorPrimitive from "@radix-ui/react-separator"` | `import { Separator as SeparatorPrimitive } from "radix-ui"` |
| `ui/select.tsx:2` | `import * as SelectPrimitive from "@radix-ui/react-select"` | `import { Select as SelectPrimitive } from "radix-ui"` |
| `ui/collapsible.tsx:1` | `import * as CollapsiblePrimitive from "@radix-ui/react-collapsible"` | `import { Collapsible as CollapsiblePrimitive } from "radix-ui"` |
| `ui/dropdown-menu.tsx:2` | `import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu"` | `import { DropdownMenu as DropdownMenuPrimitive } from "radix-ui"` |
| `layout/Header.tsx:11` | `import * as VisuallyHidden from '@radix-ui/react-visually-hidden';` | `import { VisuallyHidden } from 'radix-ui';` |
| `pages/ChatPage.tsx:24` | `import * as VisuallyHidden from '@radix-ui/react-visually-hidden';` | `import { VisuallyHidden } from 'radix-ui';` |

`Slot` is a namespace in the monolith, so `button.tsx` and `badge.tsx` need the import **and** one usage line:

| File | Old | New |
|---|---|---|
| `ui/button.tsx:3` | `import { Slot } from "@radix-ui/react-slot"` | `import { Slot } from "radix-ui"` |
| `ui/button.tsx:60` | `const Comp = asChild ? Slot : "button"` | `const Comp = asChild ? Slot.Root : "button"` |
| `ui/badge.tsx:3` | `import { Slot } from "@radix-ui/react-slot"` | `import { Slot } from "radix-ui"` |
| `ui/badge.tsx:45` | `const Comp = asChild ? Slot : "span"` | `const Comp = asChild ? Slot.Root : "span"` |

(`VisuallyHidden.Root` usages in Header/ChatPage are unchanged — the monolith namespace has the same `Root`.)

- [ ] **Step 4: Drop the 17 packages and fix the chunk group**

```bash
cd frontend && npm uninstall @radix-ui/react-alert-dialog @radix-ui/react-avatar @radix-ui/react-collapsible @radix-ui/react-dialog @radix-ui/react-dropdown-menu @radix-ui/react-label @radix-ui/react-scroll-area @radix-ui/react-select @radix-ui/react-separator @radix-ui/react-slider @radix-ui/react-slot @radix-ui/react-switch @radix-ui/react-tabs @radix-ui/react-tooltip @radix-ui/react-visually-hidden
```

(The `radix-ui` monolith itself depends on `@radix-ui/react-*` internally — those stay in `node_modules` as transitive deps; only the direct entries leave `package.json`.)

In `frontend/vite.config.ts` line 29 change:

```ts
            { name: "vendor-radix", test: /[/\\]node_modules[/\\]@radix-ui[/\\]/ },
```

to:

```ts
            { name: "vendor-radix", test: /[/\\]node_modules[/\\](radix-ui|@radix-ui)[/\\]/ },
```

- [ ] **Step 5: Run the guard test, gate, and compare the chunk**

Run: `cd frontend && npx vitest run src/components/ui/radixSingleSource.test.ts && npm run lint 2>&1 | grep problems && npx tsc --noEmit && npm test 2>&1 | grep -E "Tests " && npm run build 2>&1 | grep -E "vendor-radix|built in"`
Expected: guard 2 passed; lint 0 errors; tsc silent; tests pass; build prints the `vendor-radix` line — record its kB (baseline 139.14 kB raw). If it grew, the monolith pulled primitives no component uses; that is acceptable only if total gzip stays ≤ 492 kB, otherwise report before committing.

- [ ] **Step 6: Commit**

```bash
git add -A frontend/src frontend/package.json frontend/package-lock.json frontend/vite.config.ts
git commit -m "chore(frontend): ใช้ radix-ui monolith ตัวเดียว ถอด @radix-ui/* 17 แพ็กเกจ

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 6: Enable the React Compiler; record the PR-C deviation; open PR-B

**Files:**
- Modify: `frontend/vite.config.ts:1-8` (imports + plugins)
- Modify: `frontend/package.json` devDependencies (+2), `frontend/package-lock.json`
- Modify: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §5.2 item 3

**Interfaces:**
- Consumes: `reactCompilerPreset` from `@vitejs/plugin-react` (verified exported in 6.1.1).
- Produces: production bundle compiled with React Compiler; a compiled component can be identified by the `_c(` / `useMemoCache` helper in built output.

- [ ] **Step 1: Install the two dev deps (exact latest at install time; caret ranges are fine)**

Run: `cd frontend && npm install -D babel-plugin-react-compiler @rolldown/plugin-babel && npm ls babel-plugin-react-compiler @rolldown/plugin-babel --depth=0`
Expected: both listed, no `ERR`.

- [ ] **Step 2: Wire the preset (per react.dev "React Compiler → Installation → Vite", `@vitejs/plugin-react` ≥ 6)**

In `frontend/vite.config.ts` replace lines 1–8:

```ts
import path from "path"
import tailwindcss from "@tailwindcss/vite"
import react from "@vitejs/plugin-react"
import { defineConfig } from "vite"

// https://vite.dev/config/
export default defineConfig({
  plugins: [tailwindcss(), react()],
```

with:

```ts
import path from "path"
import tailwindcss from "@tailwindcss/vite"
import react, { reactCompilerPreset } from "@vitejs/plugin-react"
import babel from "@rolldown/plugin-babel"
import { defineConfig } from "vite"

// https://vite.dev/config/
export default defineConfig({
  // React Compiler via @rolldown/plugin-babel (plugin-react >= 6 no longer takes a `babel` option)
  plugins: [tailwindcss(), react(), babel({ presets: [reactCompilerPreset()] })],
```

- [ ] **Step 3: Prove the compiler ran**

Run: `cd frontend && npm run build 2>&1 | grep -E "built in|error" && grep -l "useMemoCache\|_c(" dist/assets/*.js | wc -l`
Expected: build OK; the count is ≥ 1 (compiled components reference React's memo cache; `0` means the preset did not apply — stop and check the plugin order).

- [ ] **Step 4: Gate (tests run through the same Vite pipeline, so the compiler is exercised in vitest too)**

Run: `cd frontend && npm run lint 2>&1 | grep problems && npx tsc --noEmit && npm test 2>&1 | grep -E "Tests " && npx vite build 2>&1 | grep -oE "[0-9.]+ kB gzip" | awk '{s+=$1} END{print s " kB gzip total"}'`
Expected: lint `0 errors, 24 warnings` (unchanged — PR-C fixes them); tsc silent; tests pass; total gzip ≤ 492 kB (compiler adds small per-component overhead; if it exceeds 492 kB, report the number in the PR — it is not a rollback trigger by itself).

- [ ] **Step 5: Smoke in the browser**

`npm run dev` → open Dashboard, Chat (scroll the virtualized message list — `MessageList.tsx` is skipped by the compiler as "incompatible library", it must still scroll), Flow editor tabs, Bots page. Expected: no console errors, no `Cannot read properties of undefined` from memoized values.

- [ ] **Step 6: Record the deviation in the spec**

In `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §5.2 replace item 3's last two sentences (`Fix all 24 ... rather than downgraded lint rules.`) with:

```markdown
   The 24 `react-hooks/*` compiler warnings are fixed in a separate PR-C with its own plan (`docs/superpowers/plans/<date>-track1c-compiler-warnings.md`): 12 `set-state-in-effect`, 6 `refs`, 5 `exhaustive-deps`, 1 `incompatible-library` across 13 files, each needing a site-specific rewrite. The five rules stay at `warn` until PR-C lands, then move to `error`. Components the compiler cannot optimize (e.g. the `useVirtualizer` site in `MessageList.tsx`) are already skipped automatically ("Compilation Skipped") — no `"use no memo"` needed.
```

- [ ] **Step 7: Commit and open PR-B**

```bash
git add frontend/vite.config.ts frontend/package.json frontend/package-lock.json docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md
git commit -m "perf(frontend): เปิด React Compiler ผ่าน @rolldown/plugin-babel + reactCompilerPreset

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
git push
gh pr create --base main --title "chore(frontend): knip zero, single radix-ui package, React Compiler (Track 1 PR-B)" --body "$(cat <<'EOF'
## Summary
- knip: 0 unused files/exports/types (config cleaned per knip hints)
- Radix: all 17 `@radix-ui/*` imports moved to the `radix-ui` monolith; 17 direct deps removed; `vendor-radix` chunk <before> → <after> kB
- React Compiler enabled (`reactCompilerPreset` + `@rolldown/plugin-babel`); the 24 compiler-rule lint warnings are deferred to PR-C (spec §5.2 updated)

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §5.2
Plan: `docs/superpowers/plans/2026-09-05-track1-frontend-cleanup.md` Tasks 4–6

## Test plan
- [x] `npx knip` exit 0
- [x] lint 0 errors / tsc / vitest (incl. `radixSingleSource.test.ts`) / build
- [x] built output contains compiler memo-cache helpers
- [x] manual smoke: Dashboard, Chat (virtualized list), Flow editor, Bots
- Bundle: <total> kB gzip (baseline 492.25)

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

Fill `<before>`, `<after>`, `<total>` with the numbers from Task 5 Step 5 and Task 6 Step 4 before running.

---

## Appendix — PR-C input (not executed here): the 24 compiler-rule warnings

| Rule | Count | Sites |
|---|---|---|
| `react-hooks/set-state-in-effect` | 12 | `QuickReplyAutocomplete.tsx:30`, `PluginSection.tsx:104`, `ui/avatar.tsx:32`, `useConnectionForm.ts:54`, `BotSettingsPage.tsx:124,164`, `FlowEditorPage.tsx:120`, `SettingsPage.tsx:63,85,89`, `VipManagementPage.tsx:36,68` |
| `react-hooks/refs` | 6 | `useEcho.ts:29,64,78,99`, `useStreamingChat.ts:199,202` |
| `react-hooks/exhaustive-deps` | 5 | `MessageList.tsx:50`, `useChannelInfo.ts:126`, `BotsPage.tsx:87`, `ChatPage.tsx:58`, `VipManagementPage.tsx:30` |
| `react-hooks/incompatible-library` | 1 | `MessageList.tsx:107` (`useVirtualizer` — compiler skips the component; informational) |

Note: Task 5 rewrites `ui/avatar.tsx`'s import; its `set-state-in-effect` warning at line 32 is untouched by this plan.

## Self-review

- **Spec coverage (§5):** 5.1 steps 1–4 → Tasks 1–3 (guard test enforces the exit criterion; buster bump included); 5.2 item 1 (knip) → Task 4; item 2 (Radix + chunk regex) → Task 5; item 3 (compiler) → Task 6 with the warning-fix portion explicitly deferred and the spec amended; item 4 exit criteria → Task 5 Step 5 / Task 6 Steps 3–5. Rollback (spec §5 last paragraph) is covered by the buster bump.
- **Placeholders:** none. PR-B body has three `<...>` slots that Step 7 says to fill from measured numbers — they are fill-ins, not unknowns.
- **Consistency:** hook names in Task 3 match Task 1/2 exports (`useToggleHandover`, `useClearContext`, `useClearContextAll`, `useSendAgentMessage`); `useNotes` matches the existing `hooks/chat/useNotes.ts` export; Radix identifiers in Task 5 match each file's existing local name; `reactCompilerPreset` matches the verified export.
