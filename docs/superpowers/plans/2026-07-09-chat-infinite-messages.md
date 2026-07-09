# Chat Infinite Messages (newest-first) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Conversations with >100 messages currently show only the 100 *oldest* messages in the web chat window forever. Switch the chat window to the existing newest-first infinite query, add scroll-up history loading, and point every message cache write (WebSocket, optimistic send, reconnect sync) at the single `messageKeys.infinite` cache.

**Architecture:** React Query infinite cache stores pages newest→oldest (as the API returns with `order=desc`); display flattens + reverses to oldest→newest via existing `flattenInfiniteMessages`. A new small module `infiniteMessageCache.ts` holds pure cache-manipulation helpers (dedup/prepend/replace/remove) shared by all three write paths. Both message area components (`ChannelMessageArea` for LINE/Telegram, `MessageList` for the virtualized default) get a scroll-near-top → load-older trigger with scroll-position anchoring.

**Tech Stack:** React 19, TypeScript, @tanstack/react-query v5 (`useInfiniteQuery`), @tanstack/react-virtual, Vitest 4 + @testing-library/react (jsdom), Laravel API (no backend changes).

**Spec:** `docs/superpowers/specs/2026-07-09-chat-infinite-messages-design.md`

## Global Constraints

- Frontend workdir: `frontend/` — all `npm` commands run there (`cd frontend` first).
- No backend changes. The endpoint `GET /bots/{bot}/conversations/{conv}/messages?order=desc&page=N&per_page=50` already works.
- Cache key for ALL message writes after this plan: `messageKeys.infinite(botId, conversationId)` = `['messages', 'infinite', botId, conversationId]`.
- Pages in the infinite cache are ordered newest→oldest; `pages[0].data[0]` is the newest message.
- Verification commands: `npm test` (vitest run), `npx tsc -b`, `npm run lint` — all must pass before every commit.
- Work on branch `fix/chat-infinite-messages` off `main`.
- Do NOT touch pre-existing dead code: `useSendMessage` in `hooks/chat/useMessageMutations.ts` and `usePrefetchConversation` in `hooks/chat/useConversationDetails.ts` have no callers — leave them; mention to the user at the end.
- Commit messages in English, end with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

### Task 0: Branch setup

**Files:** none

- [ ] **Step 1: Create branch**

```bash
cd /Users/jaochai/Code/bot-fb
git checkout main && git pull --ff-only
git checkout -b fix/chat-infinite-messages
```

Expected: `Switched to a new branch 'fix/chat-infinite-messages'`

---

### Task 1: Pure cache helpers `infiniteMessageCache.ts`

**Files:**
- Create: `frontend/src/hooks/chat/infiniteMessageCache.ts`
- Test: `frontend/src/hooks/chat/infiniteMessageCache.test.ts`
- Modify: `frontend/src/hooks/chat/index.ts` (add re-export)

**Interfaces:**
- Consumes: `MessagesResponse` from `./messageKeys`, `Message` from `@/types/api`, `InfiniteData` from `@tanstack/react-query`.
- Produces (used by Tasks 2, 3, 4):
  - `type InfiniteMessages = InfiniteData<MessagesResponse>`
  - `messageExistsInInfinite(data: InfiniteMessages | undefined, messageId: number): boolean`
  - `prependMessagesToInfinite(data: InfiniteMessages, messages: Message[]): InfiniteMessages` — dedups by id against all pages, sorts fresh ones newest-first, prepends to `pages[0]`; returns `data` unchanged if `pages` is empty or nothing fresh.
  - `replaceMessageInInfinite(data: InfiniteMessages, matchId: number, replacement: Message): InfiniteMessages` — replaces the message with id `matchId`; if `replacement.id` already exists elsewhere in the cache (WebSocket beat the API response), removes the `matchId` entry instead of duplicating.
  - `removeMessageFromInfinite(data: InfiniteMessages, messageId: number): InfiniteMessages`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/hooks/chat/infiniteMessageCache.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import type { InfiniteData } from '@tanstack/react-query';
import type { Message } from '@/types/api';
import type { MessagesResponse } from './messageKeys';
import {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
} from './infiniteMessageCache';

function makeMessage(id: number, createdAt: string): Message {
  return {
    id,
    conversation_id: 10,
    sender: 'user',
    content: `msg ${id}`,
    type: 'text',
    media_url: null,
    media_type: null,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: createdAt,
    updated_at: createdAt,
  };
}

const meta = {
  current_page: 1,
  from: 1,
  last_page: 4,
  per_page: 50,
  to: 50,
  total: 200,
};

// Pages are newest→oldest (order=desc): pages[0].data[0] is the newest message.
function makeInfinite(pages: Message[][]): InfiniteData<MessagesResponse> {
  return {
    pages: pages.map((data) => ({ data, meta })),
    pageParams: pages.map((_, i) => i + 1),
  };
}

describe('messageExistsInInfinite', () => {
  it('finds a message on any page', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T10:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    expect(messageExistsInInfinite(data, 6)).toBe(true);
    expect(messageExistsInInfinite(data, 3)).toBe(true);
    expect(messageExistsInInfinite(data, 99)).toBe(false);
  });

  it('returns false for undefined data', () => {
    expect(messageExistsInInfinite(undefined, 1)).toBe(false);
  });
});

describe('prependMessagesToInfinite', () => {
  it('prepends a new message to the first page', () => {
    const data = makeInfinite([[makeMessage(5, '2026-07-09T10:00:00Z')]]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
    ]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6, 5]);
  });

  it('drops messages that already exist on any page (dedup)', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T11:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
      makeMessage(3, '2026-07-08T10:00:00Z'),
    ]);
    expect(result).toBe(data); // unchanged object when nothing fresh
  });

  it('inserts multiple fresh messages newest-first', () => {
    const data = makeInfinite([[makeMessage(5, '2026-07-09T10:00:00Z')]]);
    const result = prependMessagesToInfinite(data, [
      makeMessage(6, '2026-07-09T11:00:00Z'),
      makeMessage(7, '2026-07-09T12:00:00Z'),
    ]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([7, 6, 5]);
  });

  it('returns data unchanged when pages is empty', () => {
    const data: InfiniteData<MessagesResponse> = { pages: [], pageParams: [] };
    const result = prependMessagesToInfinite(data, [
      makeMessage(1, '2026-07-09T10:00:00Z'),
    ]);
    expect(result).toBe(data);
  });
});

describe('replaceMessageInInfinite', () => {
  it('replaces the optimistic message with the real one', () => {
    const optimistic = makeMessage(-1720500000000, '2026-07-09T10:00:00Z');
    const data = makeInfinite([[optimistic, makeMessage(5, '2026-07-09T09:00:00Z')]]);
    const real = makeMessage(6, '2026-07-09T10:00:01Z');
    const result = replaceMessageInInfinite(data, optimistic.id, real);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6, 5]);
  });

  it('removes the optimistic message when the real one already arrived via WebSocket', () => {
    const optimistic = makeMessage(-1720500000000, '2026-07-09T10:00:00Z');
    const real = makeMessage(6, '2026-07-09T10:00:01Z');
    const data = makeInfinite([[real, optimistic]]);
    const result = replaceMessageInInfinite(data, optimistic.id, real);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6]);
  });
});

describe('removeMessageFromInfinite', () => {
  it('removes a message by id from any page', () => {
    const data = makeInfinite([
      [makeMessage(6, '2026-07-09T11:00:00Z')],
      [makeMessage(3, '2026-07-08T10:00:00Z')],
    ]);
    const result = removeMessageFromInfinite(data, 3);
    expect(result.pages[1].data).toEqual([]);
    expect(result.pages[0].data.map((m) => m.id)).toEqual([6]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/jaochai/Code/bot-fb/frontend
npm test -- src/hooks/chat/infiniteMessageCache.test.ts
```

Expected: FAIL — `Cannot find module './infiniteMessageCache'` (or similar resolve error).

- [ ] **Step 3: Write the implementation**

Create `frontend/src/hooks/chat/infiniteMessageCache.ts`:

```ts
/**
 * Pure helpers for the infinite messages cache.
 *
 * The cache (`messageKeys.infinite`) stores pages newest→oldest as returned
 * by the API with order=desc: pages[0].data[0] is the newest message.
 * All realtime / optimistic / sync write paths go through these helpers so
 * dedup and ordering rules live in exactly one place.
 */
import type { InfiniteData } from '@tanstack/react-query';
import type { Message } from '@/types/api';
import type { MessagesResponse } from './messageKeys';

export type InfiniteMessages = InfiniteData<MessagesResponse>;

export function messageExistsInInfinite(
  data: InfiniteMessages | undefined,
  messageId: number
): boolean {
  if (!data) return false;
  return data.pages.some((page) => page.data.some((m) => m.id === messageId));
}

/**
 * Dedup against every page, then insert the remaining messages newest-first
 * at the front of the first page. Returns the input object unchanged when
 * there is nothing to insert (lets React Query skip the update).
 */
export function prependMessagesToInfinite(
  data: InfiniteMessages,
  messages: Message[]
): InfiniteMessages {
  if (data.pages.length === 0) return data;

  const fresh = messages.filter((m) => !messageExistsInInfinite(data, m.id));
  if (fresh.length === 0) return data;

  const freshDesc = [...fresh].sort(
    (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
  );

  return {
    ...data,
    pages: data.pages.map((page, i) =>
      i === 0 ? { ...page, data: [...freshDesc, ...page.data] } : page
    ),
  };
}

/**
 * Replace the message with id `matchId` (usually a negative optimistic id)
 * with `replacement`. If `replacement.id` already exists elsewhere (WebSocket
 * echoed the real message before the API response), remove the matchId entry
 * instead of creating a duplicate.
 */
export function replaceMessageInInfinite(
  data: InfiniteMessages,
  matchId: number,
  replacement: Message
): InfiniteMessages {
  const replacementExists =
    replacement.id !== matchId && messageExistsInInfinite(data, replacement.id);

  return {
    ...data,
    pages: data.pages.map((page) => ({
      ...page,
      data: replacementExists
        ? page.data.filter((m) => m.id !== matchId)
        : page.data.map((m) => (m.id === matchId ? replacement : m)),
    })),
  };
}

export function removeMessageFromInfinite(
  data: InfiniteMessages,
  messageId: number
): InfiniteMessages {
  return {
    ...data,
    pages: data.pages.map((page) => ({
      ...page,
      data: page.data.filter((m) => m.id !== messageId),
    })),
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
npm test -- src/hooks/chat/infiniteMessageCache.test.ts
```

Expected: PASS (9 tests).

- [ ] **Step 5: Re-export from the chat hooks barrel**

In `frontend/src/hooks/chat/index.ts`, add alongside the other exports:

```ts
export {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
  type InfiniteMessages,
} from './infiniteMessageCache';
```

- [ ] **Step 6: Full verification + commit**

```bash
npm test && npx tsc -b && npm run lint
git add src/hooks/chat/infiniteMessageCache.ts src/hooks/chat/infiniteMessageCache.test.ts src/hooks/chat/index.ts
git commit -m "feat(chat): pure cache helpers for infinite messages cache

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green, commit created.

---

### Task 2: `useRealtime` writes WebSocket messages to the infinite cache

**Files:**
- Modify: `frontend/src/hooks/chat/useRealtime.ts:66-124` (handleRealtimeMessage) and `:268-294` (reconnect invalidation)

**Interfaces:**
- Consumes: `messageExistsInInfinite`, `prependMessagesToInfinite`, `type InfiniteMessages` from `./infiniteMessageCache`; `messageKeys.infinite` from `./messageKeys`; `createMessageFromEvent` from `./realtimeUtils` (unchanged).
- Produces: no API change — same `useRealtime(botId, filters, options)` hook; it now updates `messageKeys.infinite(botId, conversationId)` instead of `messageKeys.listWithOptions(botId, conversationId, { order: 'asc', perPage: 100 })`.

No unit test for this task: the cache logic is fully covered by Task 1's tests, and `useRealtime` needs a mocked Echo stack that doesn't exist in this repo. Verification = typecheck + full suite + the manual E2E in Task 8.

- [ ] **Step 1: Replace the asc-cache write in `handleRealtimeMessage`**

In `frontend/src/hooks/chat/useRealtime.ts`, replace the body of `handleRealtimeMessage` (currently lines 66-124) so the dedup check and the write both target the infinite cache. The notification block and `updateConversationInList` call stay exactly as they are:

```ts
  // T042: Stable callback that reads from refs
  const handleRealtimeMessage = useCallback(
    (event: MessageSentEvent) => {
      const currentBotId = botIdRef.current;
      if (!currentBotId) return;

      const infiniteKey = messageKeys.infinite(currentBotId, event.conversation_id);

      // Check if message already exists to avoid duplicate updates
      const existingMessages = queryClient.getQueryData<InfiniteMessages>(infiniteKey);
      if (messageExistsInInfinite(existingMessages, event.id)) {
        // Message already exists, skip update
        return;
      }

      // Add message to cache (newest-first: prepend to first page)
      queryClient.setQueryData<InfiniteMessages>(infiniteKey, (old) => {
        if (!old) return old;
        return prependMessagesToInfinite(old, [createMessageFromEvent(event)]);
      });

      // Update conversation in list — filter-agnostic, refs supply selection state
      updateConversationInList(
        queryClient,
        currentBotId,
        event.conversation_id,
        selectedConversationIdRef.current,
        event
      );

      // Notify when tab is hidden and message is from user (not bot/agent)
      if (document.visibilityState === 'hidden' && event.sender === 'user') {
        unreadCountRef.current++;
        setUnreadBadge(unreadCountRef.current);

        if (audioEnabledRef.current) {
          playPing();
        }
        if (notificationEnabledRef.current) {
          showBrowserNotification('ข้อความใหม่', {
            body: event.content?.substring(0, 100) || 'มีข้อความใหม่เข้ามา',
          });
        }
      }
    },
    [queryClient] // Only queryClient as dependency since we use refs
  );
```

- [ ] **Step 2: Update imports in the same file**

Replace the import of `messageKeys` (currently `import { messageKeys, type MessagesResponse } from './messageKeys';`) with:

```ts
import { messageKeys } from './messageKeys';
import {
  messageExistsInInfinite,
  prependMessagesToInfinite,
  type InfiniteMessages,
} from './infiniteMessageCache';
```

(`MessagesResponse` was only used by the old asc-cache code; remove it. `InfiniteData` at the top import stays — `handleConversationUpdate` still uses it.)

- [ ] **Step 3: Point the reconnect invalidation at the infinite key**

In the same file, inside `handleReconnect` (currently lines 268-294), replace:

```ts
          queryClient.invalidateQueries({
            queryKey: messageKeys.list(currentBotId, currentSelectedId),
          });
```

with:

```ts
          queryClient.invalidateQueries({
            queryKey: messageKeys.infinite(currentBotId, currentSelectedId),
          });
```

(The `messageKeys.list` prefix is `['messages','list',...]` and never matched the infinite key `['messages','infinite',...]` — this was a latent bug.)

- [ ] **Step 4: Verify + commit**

```bash
cd /Users/jaochai/Code/bot-fb/frontend
npm test && npx tsc -b && npm run lint
git add src/hooks/chat/useRealtime.ts
git commit -m "fix(chat): realtime WebSocket messages write to infinite cache

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 3: `useSendAgentMessage` optimistic updates target the infinite cache

**Files:**
- Modify: `frontend/src/hooks/conversations/useSendAgentMessage.ts`
- Test: `frontend/src/hooks/conversations/useSendAgentMessage.test.tsx` (create)

**Interfaces:**
- Consumes: `messageKeys.infinite`, `prependMessagesToInfinite`, `replaceMessageInInfinite`, `removeMessageFromInfinite`, `messageExistsInInfinite`, `type InfiniteMessages` from `@/hooks/chat`.
- Produces: same hook signature `useSendAgentMessage(botId)`; mutation context becomes `{ previousInfinite: InfiniteMessages | undefined, optimisticId: number }`.

- [ ] **Step 1: Confirm the only consumer is useChatActions**

```bash
cd /Users/jaochai/Code/bot-fb/frontend
grep -rn "useSendAgentMessage" src --include="*.ts" --include="*.tsx" | grep -v "useSendAgentMessage\."
```

Expected output: only `hooks/useChatActions.ts`, `hooks/useConversations.ts` (re-export), `hooks/conversations/index.ts` (re-export). If a page component appears here, STOP and report.

- [ ] **Step 2: Write the failing test**

Create `frontend/src/hooks/conversations/useSendAgentMessage.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import type { Message } from '@/types/api';
import { messageKeys, type InfiniteMessages } from '@/hooks/chat';
import { useSendAgentMessage } from './useSendAgentMessage';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { post: vi.fn(), get: vi.fn() },
}));

const BOT_ID = 1;
const CONV_ID = 10;

function makeMessage(id: number, createdAt: string, sender: Message['sender'] = 'user'): Message {
  return {
    id,
    conversation_id: CONV_ID,
    sender,
    content: `msg ${id}`,
    type: 'text',
    media_url: null,
    media_type: null,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: createdAt,
    updated_at: createdAt,
  };
}

const meta = { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 2, total: 2 };

function makeInfinite(messages: Message[]): InfiniteMessages {
  return { pages: [{ data: messages, meta }], pageParams: [1] };
}

function setup() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  queryClient.setQueryData(
    messageKeys.infinite(BOT_ID, CONV_ID),
    makeInfinite([makeMessage(5, '2026-07-09T10:00:00Z')])
  );
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  const { result } = renderHook(() => useSendAgentMessage(BOT_ID), { wrapper });
  return { queryClient, result };
}

function getCached(queryClient: QueryClient): InfiniteMessages {
  return queryClient.getQueryData<InfiniteMessages>(messageKeys.infinite(BOT_ID, CONV_ID))!;
}

beforeEach(() => {
  vi.mocked(api.post).mockReset();
});

describe('useSendAgentMessage — infinite cache', () => {
  it('prepends an optimistic message, then replaces it with the server message', async () => {
    const serverMessage = makeMessage(6, '2026-07-09T11:00:00Z', 'agent');
    let resolvePost!: (value: unknown) => void;
    vi.mocked(api.post).mockReturnValue(
      new Promise((resolve) => {
        resolvePost = resolve;
      }) as never
    );

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    // Optimistic message (negative id) lands at the front of page 1
    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toHaveLength(2);
      expect(ids[0]).toBeLessThan(0);
    });

    resolvePost({ data: { message: 'sent', data: serverMessage } });

    // Server message replaces the optimistic one
    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toEqual([6, 5]);
    });
  });

  it('removes the optimistic message on error', async () => {
    vi.mocked(api.post).mockRejectedValue(new Error('network down'));

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    await waitFor(() => {
      expect(result.current.isError).toBe(true);
    });
    const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
    expect(ids).toEqual([5]);
  });

  it('drops the optimistic message when WebSocket already delivered the real one', async () => {
    const serverMessage = makeMessage(6, '2026-07-09T11:00:00Z', 'agent');
    let resolvePost!: (value: unknown) => void;
    vi.mocked(api.post).mockReturnValue(
      new Promise((resolve) => {
        resolvePost = resolve;
      }) as never
    );

    const { queryClient, result } = setup();
    result.current.mutate({ conversationId: CONV_ID, data: { content: 'hello' } });

    await waitFor(() => {
      expect(getCached(queryClient).pages[0].data).toHaveLength(2);
    });

    // Simulate the WebSocket event landing before the API response
    queryClient.setQueryData<InfiniteMessages>(
      messageKeys.infinite(BOT_ID, CONV_ID),
      (old) => old && { ...old, pages: [{ ...old.pages[0], data: [serverMessage, ...old.pages[0].data] }] }
    );

    resolvePost({ data: { message: 'sent', data: serverMessage } });

    await waitFor(() => {
      const ids = getCached(queryClient).pages[0].data.map((m) => m.id);
      expect(ids).toEqual([6, 5]); // no duplicate 6, optimistic gone
    });
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

```bash
npm test -- src/hooks/conversations/useSendAgentMessage.test.tsx
```

Expected: FAIL — the current implementation writes to the asc key, so the infinite cache never receives the optimistic message (first `waitFor` times out).

- [ ] **Step 4: Rewrite the mutation callbacks**

Replace the entire contents of `frontend/src/hooks/conversations/useSendAgentMessage.ts` with:

```ts
import { useMutation, useQueryClient, type InfiniteData } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  messageKeys,
  isInfiniteConversationsQuery,
  messageExistsInInfinite,
  prependMessagesToInfinite,
  replaceMessageInInfinite,
  removeMessageFromInfinite,
  type InfiniteMessages,
} from '@/hooks/chat';
import type {
  Conversation,
  ConversationStatusCounts,
  Message,
  PaginationMeta,
} from '@/types/api';

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

/**
 * Hook to send a message from agent to customer (HITL mode)
 * Includes optimistic updates for instant UI feedback
 */
export function useSendAgentMessage(botId: number | undefined) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      conversationId,
      data,
    }: {
      conversationId: number;
      data: SendAgentMessageData;
    }) => {
      const response = await api.post<AgentMessageResponse>(
        `/bots/${botId}/conversations/${conversationId}/agent-message`,
        data,
        { headers: { 'Idempotency-Key': crypto.randomUUID() } }
      );
      return response.data;
    },
    onMutate: async ({ conversationId, data }) => {
      if (!botId) return;

      const infiniteKey = messageKeys.infinite(botId, conversationId);

      // Cancel any outgoing refetches to avoid overwriting optimistic update
      await queryClient.cancelQueries({ queryKey: infiniteKey });

      // Use negative timestamp to guarantee no collision with DB IDs (always positive)
      const optimisticId = -Date.now();

      const optimisticMessage: Message = {
        id: optimisticId,
        conversation_id: conversationId,
        sender: 'agent',
        content: data.content,
        type: data.type || 'text',
        media_url: data.media_url || null,
        media_type: null,
        media_metadata: null,
        model_used: null,
        prompt_tokens: null,
        completion_tokens: null,
        cost: null,
        external_message_id: null,
        reply_to_message_id: null,
        sentiment: null,
        intents: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      };

      queryClient.setQueryData<InfiniteMessages>(infiniteKey, (old) => {
        if (!old) return old;
        return prependMessagesToInfinite(old, [optimisticMessage]);
      });

      return { optimisticId };
    },
    onError: (_err, { conversationId }, context) => {
      if (!botId || !context?.optimisticId) return;

      // Rollback: remove only the failed optimistic message
      queryClient.setQueryData<InfiniteMessages>(
        messageKeys.infinite(botId, conversationId),
        (old) => (old ? removeMessageFromInfinite(old, context.optimisticId) : old)
      );
    },
    onSuccess: (response, { conversationId }, context) => {
      if (!botId) return;

      const optimisticId = context?.optimisticId;

      // Replace the optimistic message with the real one. The helpers handle
      // the WebSocket-arrived-first case (real id already cached) without
      // duplicating, which avoids invalidateQueries race conditions.
      queryClient.setQueryData<InfiniteMessages>(
        messageKeys.infinite(botId, conversationId),
        (old) => {
          if (!old) return old;
          if (optimisticId && messageExistsInInfinite(old, optimisticId)) {
            return replaceMessageInInfinite(old, optimisticId, response.data);
          }
          return prependMessagesToInfinite(old, [response.data]);
        }
      );

      // WebSocket uses toOthers() and doesn't echo back to the sender, so we must
      // patch needs_response = false locally for the agent who just replied.
      queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
        { predicate: isInfiniteConversationsQuery(botId) },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            pages: old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) =>
                conv.id === conversationId
                  ? {
                      ...conv,
                      needs_response: false, // Agent replied = no longer needs response
                      last_message_at: new Date().toISOString(),
                      message_count: conv.message_count + 1,
                    }
                  : conv
              ),
            })),
          };
        }
      );

      // Note: We intentionally do NOT invalidate queries here to prevent race conditions
      // WebSocket handles real-time updates, and refetch happens on reconnect
    },
  });
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
npm test -- src/hooks/conversations/useSendAgentMessage.test.tsx
```

Expected: PASS (3 tests).

- [ ] **Step 6: Full verification + commit**

```bash
npm test && npx tsc -b && npm run lint
git add src/hooks/conversations/useSendAgentMessage.ts src/hooks/conversations/useSendAgentMessage.test.tsx
git commit -m "fix(chat): agent-message optimistic updates target infinite cache

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 4: `syncEngine` delta sync writes to the infinite cache

**Files:**
- Modify: `frontend/src/lib/syncEngine.ts:87-117` (syncConversation)
- Test: `frontend/src/lib/syncEngine.test.ts` (extend)

**Interfaces:**
- Consumes: `prependMessagesToInfinite`, `type InfiniteMessages` from `@/hooks/chat/infiniteMessageCache`; `messageKeys` from `@/hooks/chat/messageKeys`.
- Produces: no API change — `syncBot(botId, queryClient, selectedConversationId)` unchanged.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/lib/syncEngine.test.ts` (keep the existing imports/tests; add the new imports at the top and merge with existing ones):

```ts
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { syncBot, useSyncCursors } from './syncEngine';
import { messageKeys } from '@/hooks/chat/messageKeys';
import type { InfiniteMessages } from '@/hooks/chat/infiniteMessageCache';
import type { Message } from '@/types/api';
import { api } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}));

function makeMessage(id: number, createdAt: string): Message {
  return {
    id,
    conversation_id: 10,
    sender: 'user',
    content: `msg ${id}`,
    type: 'text',
    media_url: null,
    media_type: null,
    media_metadata: null,
    model_used: null,
    prompt_tokens: null,
    completion_tokens: null,
    cost: null,
    external_message_id: null,
    reply_to_message_id: null,
    sentiment: null,
    intents: null,
    created_at: createdAt,
    updated_at: createdAt,
  };
}

describe('syncConversation via syncBot', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    useSyncCursors.setState({ lastConvSyncAt: {}, lastMessageId: {} });
  });

  it('prepends synced messages to the infinite cache newest-first', async () => {
    const queryClient = new QueryClient();
    const meta = { current_page: 1, from: 1, last_page: 1, per_page: 50, to: 1, total: 1 };
    const initial: InfiniteMessages = {
      pages: [{ data: [makeMessage(5, '2026-07-09T10:00:00Z')], meta }],
      pageParams: [1],
    };
    queryClient.setQueryData(messageKeys.infinite(1, 10), initial);

    vi.mocked(api.get)
      // 1st call: conversations delta sync
      .mockResolvedValueOnce({ data: { data: [], synced_at: '2026-07-09T12:00:00Z' } } as never)
      // 2nd call: messages sync for selected conversation
      .mockResolvedValueOnce({
        data: {
          data: [
            makeMessage(6, '2026-07-09T11:00:00Z'),
            makeMessage(7, '2026-07-09T11:30:00Z'),
          ],
          has_more: false,
          synced_at: '2026-07-09T12:00:00Z',
        },
      } as never);

    await syncBot(1, queryClient, 10);

    const cached = queryClient.getQueryData<InfiniteMessages>(messageKeys.infinite(1, 10))!;
    expect(cached.pages[0].data.map((m) => m.id)).toEqual([7, 6, 5]);
    expect(useSyncCursors.getState().lastMessageId['1:10']).toBe(7);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
npm test -- src/lib/syncEngine.test.ts
```

Expected: the new test FAILS — cache ids stay `[5]` because the current code writes to the asc key. (Existing cursor tests still pass.)

- [ ] **Step 3: Update `syncConversation`**

In `frontend/src/lib/syncEngine.ts`, replace the import line

```ts
import { messageKeys, type MessagesResponse } from '@/hooks/chat/messageKeys';
```

with:

```ts
import { messageKeys } from '@/hooks/chat/messageKeys';
import { prependMessagesToInfinite, type InfiniteMessages } from '@/hooks/chat/infiniteMessageCache';
```

and replace the cache write inside `syncConversation` (the `if (newMessages.length > 0) { ... }` block) with:

```ts
  if (newMessages.length > 0) {
    queryClient.setQueryData<InfiniteMessages>(
      messageKeys.infinite(botId, conversationId),
      (old) => (old ? prependMessagesToInfinite(old, newMessages) : old)
    );

    const maxId = Math.max(...newMessages.map((m) => m.id));
    useSyncCursors.getState().setCursor(cursorKey, maxId);
  }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
npm test -- src/lib/syncEngine.test.ts
```

Expected: PASS (all tests in file).

- [ ] **Step 5: Full verification + commit**

```bash
npm test && npx tsc -b && npm run lint
git add src/lib/syncEngine.ts src/lib/syncEngine.test.ts
git commit -m "fix(chat): reconnect delta sync writes to infinite cache

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 5: `ChannelMessageArea` — load older on scroll-up with scroll anchoring (LINE/Telegram path)

**Files:**
- Modify: `frontend/src/components/chat/ChannelMessageArea.tsx`

**Interfaces:**
- Consumes: nothing new from earlier tasks.
- Produces (used by Task 7): three new optional props on `ChannelMessageAreaProps`:
  - `hasOlder?: boolean`
  - `isLoadingOlder?: boolean`
  - `onLoadOlder?: () => void`

No jsdom unit test: scroll geometry (`scrollHeight`/`scrollTop`) is always 0 in jsdom, so the behavior is verified manually in Task 8. Keep the change purely additive so existing rendering is untouched when the new props are absent.

- [ ] **Step 1: Implement**

Replace the entire contents of `frontend/src/components/chat/ChannelMessageArea.tsx` with:

```tsx
/**
 * Channel-specific message area component
 * Handles Telegram and LINE message rendering
 * Extracted from ChatWindow.tsx
 */
import { useState, useRef, useCallback, useEffect, useLayoutEffect } from 'react';
import { format, isValid } from 'date-fns';
import { th } from 'date-fns/locale';
import { ChevronDown, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { TelegramMessageBubble } from '@/components/telegram/TelegramMessageBubble';
import { LINEMessageBubble } from '@/components/line/LINEMessageBubble';
import type { Message, Conversation } from '@/types/api';

interface ChannelMessageAreaProps {
  messages: Message[];
  conversation: Conversation;
  isLoading: boolean;
  channelType: 'telegram' | 'line';
  hasOlder?: boolean;
  isLoadingOlder?: boolean;
  onLoadOlder?: () => void;
}

export function ChannelMessageArea({
  messages,
  conversation,
  isLoading,
  channelType,
  hasOlder = false,
  isLoadingOlder = false,
  onLoadOlder,
}: ChannelMessageAreaProps) {
  const [autoScroll, setAutoScroll] = useState(true);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollViewportRef = useRef<HTMLDivElement>(null);

  // Scroll anchoring for load-older: captured when the fetch is triggered,
  // applied after the older page is prepended so the view doesn't jump.
  const loadOlderAnchorRef = useRef<{ scrollTop: number; scrollHeight: number } | null>(null);
  const prevFirstIdRef = useRef<number | null>(null);

  // Auto scroll to bottom when messages change
  useEffect(() => {
    if (autoScroll && messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, autoScroll]);

  // Restore scroll position after older messages are prepended
  useLayoutEffect(() => {
    const firstId = messages[0]?.id ?? null;
    const anchor = loadOlderAnchorRef.current;
    const viewport = scrollViewportRef.current;

    if (anchor && viewport && prevFirstIdRef.current !== null && firstId !== prevFirstIdRef.current) {
      viewport.scrollTop = anchor.scrollTop + (viewport.scrollHeight - anchor.scrollHeight);
      loadOlderAnchorRef.current = null;
    } else if (anchor && !isLoadingOlder && firstId === prevFirstIdRef.current) {
      // Fetch settled without new messages (error / empty page) — release the anchor
      loadOlderAnchorRef.current = null;
    }

    prevFirstIdRef.current = firstId;
  }, [messages, isLoadingOlder]);

  // Handle scroll: bottom detection for auto-scroll + top detection for load-older
  const handleScroll = useCallback(
    (e: React.UIEvent<HTMLDivElement>) => {
      const target = e.currentTarget;
      const isAtBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 50;

      if (isAtBottom !== autoScroll) {
        setAutoScroll(isAtBottom);
      }

      // Near the top and the user is actively reading history (!autoScroll
      // guards against the initial smooth-scroll-to-bottom passing the top).
      if (
        target.scrollTop < 100 &&
        !autoScroll &&
        hasOlder &&
        !isLoadingOlder &&
        !loadOlderAnchorRef.current &&
        onLoadOlder
      ) {
        loadOlderAnchorRef.current = {
          scrollTop: target.scrollTop,
          scrollHeight: target.scrollHeight,
        };
        onLoadOlder();
      }
    },
    [autoScroll, hasOlder, isLoadingOlder, onLoadOlder]
  );

  // Handle scroll to bottom button click
  const handleScrollToBottom = useCallback(() => {
    setAutoScroll(true);
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  const createdDate = new Date(conversation.created_at);
  const isCreatedDateValid = isValid(createdDate);

  const renderMessages = () => {
    if (channelType === 'telegram') {
      return messages.map((message, index) => (
        <TelegramMessageBubble
          key={message.id}
          message={message}
          previousMessage={index > 0 ? messages[index - 1] : undefined}
        />
      ));
    }

    return messages.map((message, index) => (
      <LINEMessageBubble
        key={message.id}
        message={message}
        previousMessage={index > 0 ? messages[index - 1] : undefined}
      />
    ));
  };

  return (
    <div className="flex-1 relative min-h-0">
      <ScrollArea
        className="h-full p-4"
        viewportRef={scrollViewportRef}
        onScroll={handleScroll}
      >
        <div className="space-y-4 max-w-3xl mx-auto overflow-x-hidden">
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : messages.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              No messages in this conversation yet
            </div>
          ) : (
            <>
              {isLoadingOlder && (
                <div className="flex items-center justify-center py-2">
                  <Loader2 className="size-4 animate-spin text-muted-foreground" />
                </div>
              )}
              {isCreatedDateValid && !hasOlder && (
                <div className="text-center text-sm text-muted-foreground py-2">
                  <span className="bg-muted px-3 py-1 rounded-full text-xs">
                    Started {format(createdDate, 'PPp', { locale: th })}
                  </span>
                </div>
              )}
              {renderMessages()}
            </>
          )}

          {/* Scroll anchor */}
          <div ref={messagesEndRef} />
        </div>
      </ScrollArea>

      {!autoScroll && (
        <Button
          variant="secondary"
          size="sm"
          className="absolute bottom-4 left-1/2 -translate-x-1/2 shadow-lg z-20"
          onClick={handleScrollToBottom}
        >
          <ChevronDown className="size-4 mr-2" />
          New messages
        </Button>
      )}
    </div>
  );
}
```

Behavioral notes (all intentional):
- The "Started …" badge now shows only when `hasOlder` is false — i.e. the true beginning of the conversation has been loaded.
- A small spinner renders at the top while an older page loads.
- Without the new props everything behaves exactly as before (`hasOlder` defaults to `false`).

- [ ] **Step 2: Verify + commit**

```bash
cd /Users/jaochai/Code/bot-fb/frontend
npm test && npx tsc -b && npm run lint
git add src/components/chat/ChannelMessageArea.tsx
git commit -m "feat(chat): scroll-up loads older messages in channel message area

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 6: `MessageList` — load older on scroll-up with virtualizer anchoring (default path)

**Files:**
- Modify: `frontend/src/components/chat/MessageList.tsx`

**Interfaces:**
- Consumes: nothing new from earlier tasks.
- Produces (used by Task 7): three new optional props on `MessageListProps`, identical names to Task 5:
  - `hasOlder?: boolean`
  - `isLoadingOlder?: boolean`
  - `onLoadOlder?: () => void`

- [ ] **Step 1: Add props and anchoring logic**

In `frontend/src/components/chat/MessageList.tsx`:

1. Extend the import at line 5 to include `useLayoutEffect`:

```tsx
import { useRef, useEffect, useLayoutEffect, useCallback, useMemo, memo } from 'react';
```

2. Extend `MessageListProps`:

```tsx
export interface MessageListProps {
  messages: Message[];
  isLoading?: boolean;
  contextClearedAt?: string | null;
  conversationCreatedAt?: string;
  autoScroll?: boolean;
  onAutoScrollChange?: (autoScroll: boolean) => void;
  hasOlder?: boolean;
  isLoadingOlder?: boolean;
  onLoadOlder?: () => void;
}
```

3. Extend the destructuring in the component signature:

```tsx
export function MessageList({
  messages,
  isLoading = false,
  contextClearedAt,
  conversationCreatedAt,
  autoScroll: externalAutoScroll,
  onAutoScrollChange,
  hasOlder = false,
  isLoadingOlder = false,
  onLoadOlder,
}: MessageListProps) {
```

4. After the `internalAutoScroll` ref declaration, add the anchor refs:

```tsx
  // Scroll anchoring for load-older (virtualizer total size delta)
  const loadOlderAnchorRef = useRef<{ scrollTop: number; totalSize: number } | null>(null);
  const prevFirstIdRef = useRef<number | null>(null);
```

5. After the existing auto-scroll `useEffect` (the one watching `messages.length`), add:

```tsx
  // Restore scroll position after older messages are prepended.
  // New items above the viewport grow getTotalSize(); shifting scrollTop by
  // the same delta keeps the previously visible messages in place.
  useLayoutEffect(() => {
    const firstId = messages[0]?.id ?? null;
    const anchor = loadOlderAnchorRef.current;
    const viewport = scrollViewportRef.current;

    if (anchor && viewport && prevFirstIdRef.current !== null && firstId !== prevFirstIdRef.current) {
      const delta = virtualizer.getTotalSize() - anchor.totalSize;
      viewport.scrollTop = anchor.scrollTop + delta;
      loadOlderAnchorRef.current = null;
    } else if (anchor && !isLoadingOlder && firstId === prevFirstIdRef.current) {
      // Fetch settled without new messages (error / empty page) — release the anchor
      loadOlderAnchorRef.current = null;
    }

    prevFirstIdRef.current = firstId;
  }, [messages, isLoadingOlder, virtualizer]);
```

6. Replace `handleScroll` with:

```tsx
  // Handle scroll: bottom detection for auto-scroll + top detection for load-older
  const handleScroll = useCallback(
    (e: React.UIEvent<HTMLDivElement>) => {
      const target = e.currentTarget;
      const isAtBottom = target.scrollHeight - target.scrollTop - target.clientHeight < 50;

      if (isAtBottom !== autoScroll) {
        internalAutoScroll.current = isAtBottom;
        onAutoScrollChange?.(isAtBottom);
      }

      // Near the top and the user is actively reading history (!autoScroll
      // guards against the initial smooth-scroll-to-bottom passing the top).
      if (
        target.scrollTop < 100 &&
        !autoScroll &&
        hasOlder &&
        !isLoadingOlder &&
        !loadOlderAnchorRef.current &&
        onLoadOlder
      ) {
        loadOlderAnchorRef.current = {
          scrollTop: target.scrollTop,
          totalSize: virtualizer.getTotalSize(),
        };
        onLoadOlder();
      }
    },
    [autoScroll, onAutoScrollChange, hasOlder, isLoadingOlder, onLoadOlder, virtualizer]
  );
```

7. In the JSX, gate the "Started …" indicator on `!hasOlder` and add the top spinner. Replace:

```tsx
          {/* Conversation start indicator */}
          {conversationCreatedAt && isValid(new Date(conversationCreatedAt)) && (
```

with:

```tsx
          {/* Older-page loading indicator */}
          {isLoadingOlder && (
            <div className="flex items-center justify-center py-2">
              <Loader2 className="size-4 animate-spin text-muted-foreground" />
            </div>
          )}

          {/* Conversation start indicator */}
          {!hasOlder && conversationCreatedAt && isValid(new Date(conversationCreatedAt)) && (
```

- [ ] **Step 2: Verify + commit**

```bash
npm test && npx tsc -b && npm run lint
git add src/components/chat/MessageList.tsx
git commit -m "feat(chat): scroll-up loads older messages in virtualized message list

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 7: `ChatWindow` switches to `useInfiniteMessages`; remove orphaned `useMessages`

**Files:**
- Modify: `frontend/src/components/chat/ChatWindow.tsx:6-55, 106-123`
- Modify: `frontend/src/hooks/chat/useMessageQueries.ts` (delete `useMessages`)
- Modify: `frontend/src/hooks/chat/index.ts` (drop `useMessages` export)

**Interfaces:**
- Consumes: `useInfiniteMessages(botId, conversationId)` and `flattenInfiniteMessages(data)` from `@/hooks/chat` (already exist); the `hasOlder`/`isLoadingOlder`/`onLoadOlder` props from Tasks 5–6.
- Produces: `ChatWindow` public props unchanged.

- [ ] **Step 1: Switch the data source in ChatWindow**

In `frontend/src/components/chat/ChatWindow.tsx`, replace:

```tsx
import { useMessages, messageKeys } from '@/hooks/chat';
```

with:

```tsx
import { useInfiniteMessages, flattenInfiniteMessages, messageKeys } from '@/hooks/chat';
```

Replace the messages query block (currently lines 40-55):

```tsx
  // Messages query - use useMessages for consistent query keys with WebSocket updates
  const { data: messagesResponse, isLoading: isLoadingMessages, isFetching: isFetchingMessages } = useMessages(
    botId,
    conversation.id,
    { order: 'asc', perPage: 100 }
  );
  const messages = messagesResponse?.data || conversation.messages || [];
  const showMessagesLoading = (isLoadingMessages || isFetchingMessages) && messages.length === 0;

  // Invalidate messages query when switching conversations to force refetch
  const queryClient = useQueryClient();
  useEffect(() => {
    queryClient.invalidateQueries({
      queryKey: messageKeys.list(botId, conversation.id),
    });
  }, [conversation.id, botId, queryClient]);
```

with:

```tsx
  // Messages query - newest-first infinite query; WebSocket/optimistic/sync
  // paths all write to the same messageKeys.infinite cache
  const {
    data: messagesData,
    isLoading: isLoadingMessages,
    isFetching: isFetchingMessages,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useInfiniteMessages(botId, conversation.id);
  const messages = messagesData
    ? flattenInfiniteMessages(messagesData)
    : conversation.messages || [];
  const showMessagesLoading = (isLoadingMessages || isFetchingMessages) && messages.length === 0;
  const handleLoadOlder = () => {
    void fetchNextPage();
  };

  // Reset to the newest page when switching conversations. resetQueries (not
  // invalidateQueries) drops previously loaded older pages, so returning to a
  // conversation refetches one page instead of every page ever scrolled to.
  const queryClient = useQueryClient();
  useEffect(() => {
    queryClient.resetQueries({
      queryKey: messageKeys.infinite(botId, conversation.id),
    });
  }, [conversation.id, botId, queryClient]);
```

- [ ] **Step 2: Pass the load-older props to both message areas**

In the same file, replace the messages-area JSX (currently lines 107-123):

```tsx
      {useCustomBubbles ? (
        <ChannelMessageArea
          messages={messages}
          conversation={conversation}
          isLoading={showMessagesLoading}
          channelType={isTelegram ? 'telegram' : 'line'}
        />
      ) : (
        <MessageList
          messages={messages}
          isLoading={showMessagesLoading}
          contextClearedAt={conversation.context_cleared_at}
          conversationCreatedAt={conversation.created_at}
          autoScroll={autoScroll}
          onAutoScrollChange={setAutoScroll}
        />
      )}
```

with:

```tsx
      {useCustomBubbles ? (
        <ChannelMessageArea
          key={conversation.id}
          messages={messages}
          conversation={conversation}
          isLoading={showMessagesLoading}
          channelType={isTelegram ? 'telegram' : 'line'}
          hasOlder={hasNextPage}
          isLoadingOlder={isFetchingNextPage}
          onLoadOlder={handleLoadOlder}
        />
      ) : (
        <MessageList
          key={conversation.id}
          messages={messages}
          isLoading={showMessagesLoading}
          contextClearedAt={conversation.context_cleared_at}
          conversationCreatedAt={conversation.created_at}
          autoScroll={autoScroll}
          onAutoScrollChange={setAutoScroll}
          hasOlder={hasNextPage}
          isLoadingOlder={isFetchingNextPage}
          onLoadOlder={handleLoadOlder}
        />
      )}
```

(`key={conversation.id}` remounts the message area per conversation so scroll anchors and auto-scroll state can never leak between rooms.)

- [ ] **Step 3: Remove the now-orphaned `useMessages` hook**

Our change removes the last caller of `useMessages`. In `frontend/src/hooks/chat/useMessageQueries.ts`, delete the entire `useMessages` function (lines 27-61) and its now-unused imports: remove `useQuery` from the `@tanstack/react-query` import, remove `buildFilterParams` import, remove `messageKeys`? — NO, keep `messageKeys` (still used by `useInfiniteMessages`). Also remove `HEARTBEAT_INTERVAL` and `MessagesOptions` from the `./messageKeys` import in this file if they become unused. The remaining file keeps `useInfiniteMessages` and `flattenInfiniteMessages` untouched.

In `frontend/src/hooks/chat/index.ts`, remove `useMessages` from the re-export list (keep `useInfiniteMessages` and `flattenInfiniteMessages`).

Verify nothing else references it:

```bash
grep -rn "useMessages\b" src --include="*.ts" --include="*.tsx" | grep -v "useInfiniteMessages\|useMessageQueries.ts"
```

Expected: no output (comments mentioning `useMessages` in other files may remain; update the stale comment in `useConversationList.ts:93` from "useMessages heartbeat" to "infinite messages query" while you're there ONLY if grep shows it — it's a comment-only change).

Note: `messageKeys.listWithOptions`, `MessagesOptions`, `HEARTBEAT_INTERVAL` in `messageKeys.ts` are still referenced by pre-existing dead code (`useSendMessage`, `usePrefetchConversation`) — leave `messageKeys.ts` untouched.

- [ ] **Step 4: Verify + commit**

```bash
npm test && npx tsc -b && npm run lint
git add src/components/chat/ChatWindow.tsx src/hooks/chat/useMessageQueries.ts src/hooks/chat/index.ts
git commit -m "fix(chat): chat window uses newest-first infinite messages query

Rooms with >100 messages showed only the oldest 100 forever because
ChatWindow fetched page 1 ascending. Closes the stale-history bug.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: all green.

---

### Task 8: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Full local gate**

```bash
cd /Users/jaochai/Code/bot-fb/frontend
npm test && npx tsc -b && npm run lint && npm run build
```

Expected: everything passes, production build succeeds.

- [ ] **Step 2: Manual E2E against the real app (REQUIRED before merge)**

Start the dev environment the project normally uses, log in, and open the Chat page for the LINE bot (bot 26). Verify each item against a conversation with **more than 100 messages** (e.g. "MIKKI", 200 messages):

1. Opening the room shows the **newest** messages at the bottom (yesterday's "เงินเข้าแล้ว 1,100.00 บาท" visible), not March history.
2. Scroll up near the top → spinner appears → older page loads → the messages you were reading **do not jump**.
3. Keep scrolling up repeatedly → eventually reach the "Started …" badge at the true first message; no further loads trigger.
4. Send a real LINE message to the bot from a phone (or trigger a test message) → it appears at the bottom in realtime.
5. Wait ≥ 2 minutes with the room open → the new message is still there (no heartbeat wiping it).
6. Click Take Over and send an agent reply from the web → it appears instantly (optimistic), stays after the server confirms, and no duplicate appears.
7. Switch to another conversation and back → still opens at the newest messages.
8. Check a Telegram room and a Facebook room (non-LINE paths) briefly for the same open-at-bottom behavior.

If any item fails, debug with superpowers:systematic-debugging before proceeding.

- [ ] **Step 3: Report and hand off**

Summarize results to the user, then use superpowers:finishing-a-development-branch to decide merge/PR. Suggested PR title: `fix(chat): web chat shows newest messages — rooms >100 messages were stuck on oldest page`.

Also mention to the user (do NOT act on these): `useSendMessage` (hooks/chat/useMessageMutations.ts) and `usePrefetchConversation` (hooks/chat/useConversationDetails.ts) are pre-existing dead code with no callers, and `hooks/conversations/useConversationQueries.ts` still builds asc-cache keys for a separate hook family — candidates for a later cleanup PR.
