# Chat Window: Infinite Messages (newest-first) — Design

**Date:** 2026-07-09
**Status:** Approved
**Type:** Bug fix + UX upgrade (approach B — full fix)

## Problem

Conversations with more than 100 messages never show recent messages in the web chat window. Refreshing does not help.

Observed: conversation "MIKKI" (LINE, 200 messages) — sidebar shows yesterday's message ("เงินเข้าแล้ว 1,100.00 บาท"), but the chat window shows messages from 25–26 March 2026 only.

### Root cause (verified in code)

- `frontend/src/components/chat/ChatWindow.tsx:41-45` fetches messages with `useMessages(botId, conversationId, { order: 'asc', perPage: 100 })` — no page param, so always **page 1 ascending = the 100 oldest messages**.
- Backend `MessageService::getMessages()` does exactly what it is told: `orderBy('created_at', $order)->paginate(min($perPage, 100))`.
- Any conversation with > 100 messages therefore never surfaces new messages, and refresh cannot fix it.
- Worse: while the page is open, WebSocket events append new messages to the cache, but the 90s heartbeat refetch (`staleTime: 0`, `refetchInterval`) replaces the cache with the oldest-100 again — new messages visibly disappear.
- The sidebar conversation list uses a different event (`ConversationUpdated`) and updates correctly, which makes the page look self-contradictory.
- `useInfiniteMessages` (desc order, newest first, cursor pagination — T039) already exists in `useMessageQueries.ts` but is **not used by ChatWindow**.

## Goal

- Opening any conversation shows the **newest** messages immediately.
- Scrolling up loads older messages page by page, all the way back to the first message.
- Realtime (WebSocket), agent-sent messages (optimistic updates), and reconnect sync all write to the **same** cache so the heartbeat/refetch can never resurrect stale data.

## Design

**Principle:** the React Query cache stores pages in descending order (newest → oldest, as the API returns them); display flattens and reverses to oldest → newest (`flattenInfiniteMessages` already does this). Every write path targets the single cache key `messageKeys.infinite(botId, conversationId)`.

### Changes (6 areas)

1. **`ChatWindow.tsx`** — replace `useMessages` with `useInfiniteMessages` + `flattenInfiniteMessages`. Pass `onLoadOlder` / `hasOlder` / `isLoadingOlder` down to `MessageList`. Keep the conversation-switch invalidation, pointed at the infinite key.
2. **`MessageList.tsx`** — when the user scrolls near the top, call `onLoadOlder` (guard against repeat calls while a page is loading). **Preserve scroll position** when older messages are prepended so the view does not jump — this is the riskiest part because the list uses `@tanstack/react-virtual`. Show a small loading indicator at the top while fetching. Auto-scroll-to-bottom behavior for new messages stays as is.
3. **`useRealtime.ts`** — `handleRealtimeMessage` writes incoming WebSocket messages into the infinite cache: dedup across **all** pages by message id, then prepend to the first page (desc order). Reconnect handling invalidates `messageKeys.infinite` (the current `messageKeys.list` prefix does not match the infinite key).
4. **`hooks/conversations/useSendAgentMessage.ts`** — optimistic add / replace / rollback operates on the infinite cache (prepend to first page; replace temp message on success; remove on error). This is the send path ChatWindow actually uses (via `useChatActions`).
5. **`lib/syncEngine.ts`** — delta sync on reconnect writes fetched messages into the infinite cache instead of the `{order:'asc', perPage:100}` key.
6. **Cleanup of orphaned asc/100 paths** — update writers/prefetchers still targeting `listWithOptions(botId, conversationId, { order: 'asc', perPage: 100 })` (`useConversationDetails` prefetch, `useMessageMutations` dual-writes) so exactly one message cache remains. `useMessages` hook itself stays (other callers may use it) but ChatWindow no longer does.

### Edge cases

- **Duplicate messages:** WebSocket event may race with optimistic update or refetch — dedup by message id across all cached pages before inserting.
- **Scroll anchoring:** prepending a page of older messages must not move the visible messages; measure and restore scroll offset (or use virtualizer index anchoring) after the page renders.
- **Auto-scroll:** when the user is at the bottom, new realtime messages still auto-scroll; when scrolled up reading history, they must not yank the view down.
- **Conversation switch:** cache is per conversation id; switching rooms starts from page 1 (newest) again.

### Explicitly out of scope

- Backend changes — the paginated endpoint already supports `order=desc` + `page`.
- The quick-fix variant (approach A) — superseded by this design.

## Testing

- **Vitest unit tests** for the three cache-write paths (realtime insert + dedup, optimistic send lifecycle, reconnect invalidation targeting the infinite key).
- **Manual E2E before merge:** open a conversation with > 100 messages (e.g. MIKKI, 200 messages) — verify newest messages show, scroll-up loads history without jumping, a new LINE message appears in realtime and survives the 90s heartbeat.

## Known risk

Scroll-position preservation with the virtualizer needs careful testing; if it stutters, fix within the same PR.
