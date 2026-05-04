# Native Chat Experience — Design Spec

**Date:** 2026-05-03
**Status:** Draft → awaiting user review
**Owner:** JaoChai
**Scope:** ยกระดับหน้า chat ของระบบ admin panel (`/chat`) ให้ทำงานเหมือน LINE OA Manager App — ไม่ค้าง, ส่งสำเร็จมั่นใจ, multi-agent ระดับเล็ก, มี notification

---

## 1. Problem Statement

หน้าแชทใน bot-fb ปัจจุบันมีอาการ:
1. ข้อความใหม่ไม่ขึ้น realtime บางครั้ง
2. ระบบค้างที่ข้อความเก่าๆ ต้อง refresh เอง
3. ไม่รู้ว่า WebSocket หลุดเมื่อไหร่
4. กลับมาที่ tab → ข้อมูลไม่อัพเดททันที

User เคยแก้มาแล้ว ≥6 PR (`fix(realtime): ...`, `fix(websocket): ...`) แต่อาการกลับมาเป็นพักๆ — เป็นสัญญาณว่า resilience layer มีจุดอ่อนเชิงสถาปัตยกรรมที่ยังไม่ได้แก้

## 2. Goal & Non-Goals

### Goals (Definition of "Native")
- **Detect WebSocket drop ใน <40s** (เดิม ~150s)
- **Tab visible → ข้อมูลล่าสุดใน <2s** (เดิม: ค้างจนกว่า reconnect)
- **F5/เปิด tab ใหม่ → render messages ทันที** (เดิม: spinner + API)
- **Reconnect = delta sync ไม่ใช่ refetch ทั้งก้อน** — ไม่ flicker, bandwidth ต่ำ
- **Browser notification + audio cue** เมื่อ tab inactive
- **Multi-agent presence indicator** + soft conversation lock
- **Send message มี idempotency** — กัน duplicate จาก network retry
- **Pending/failed message UX** — เห็น state ชัด, retry ได้

### Non-Goals
- ❌ Customer-facing chat (typing indicator, read receipt ฝั่ง customer)
- ❌ Service Worker / PWA / offline send queue
- ❌ Push Notification (ปิด browser แล้วยังเด้ง)
- ❌ Outbox Pattern (broadcast_events table + dedicated worker)
- ❌ Hard conversation lock (ใช้ soft lock + UI warning เท่านั้น)
- ❌ Service layer refactor ใหญ่
- ❌ ฟีเจอร์ระดับ 5+ admin team (queue routing, SLA tracking, supervisor view)

## 3. Decisions Recap (จาก brainstorming)

| Q | Decision |
|---|----------|
| Reference UX | **A** — LINE OA Manager App (browser-based admin chat) |
| Multi-agent scope | **B** — Small team 2-5 admin, light coordination (presence + soft lock) |
| Notification depth | **B** — In-app + Browser Notification (ไม่มี Service Worker push) |
| Persistence | **B** — IndexedDB + Stale-While-Revalidate |
| Backend reliability | **B** — Audit + migrate queue database→redis (ไม่มี outbox) |
| Sync strategy | **B** — Delta sync with cursor (ไม่ refetch ทั้งก้อน) |
| Rollout | **B** — Phase-by-phase PR, deploy ระหว่างทาง |
| Architecture | **Approach 1** — Minimal Surface (extend existing files, ตามแนว CLAUDE.md "Minimal change") |

## 4. High-Level Architecture

```
┌──────────────────── Frontend ────────────────────┐
│                                                   │
│  ┌─ lib/echo.ts ──────────────────────────────┐  │
│  │ + activityTimeout 30s, pongTimeout 10s     │  │  Phase 1
│  │ + visibilitychange handler                  │  │
│  │ + subscription_error handler                │  │
│  │ + dispatchEvent('echo:resumed')             │  │
│  └─────────────────────────────────────────────┘  │
│                      │                            │
│         ┌────────────┼─────────────┐              │
│         ▼            ▼             ▼              │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐  │
│  │ Connection  │ │ Sync Engine │ │ Notification │  │
│  │  Monitor    │ │  (cursor)   │ │   (Browser)  │  │  Phase 2,3,6
│  │ (Phase 1)   │ │ (Phase 3)   │ │  (Phase 6)   │  │
│  └─────────────┘ └─────────────┘ └──────────────┘  │
│         │              │             │            │
│         ▼              ▼             ▼            │
│  ┌──────────────────────────────────────────────┐ │
│  │   React Query (extended) — IndexedDB cache   │ │  Phase 4
│  │   + ConnectionIndicator UI                   │ │
│  │   + Pending message bubble + Retry           │ │
│  │   + Audio cue + Favicon badge                │ │
│  └──────────────────────────────────────────────┘ │
└───────────────────────────────────────────────────┘
                       │
                       │ HTTP (delta sync) + WS (Reverb)
                       ▼
┌──────────────────── Backend ─────────────────────┐
│                                                   │
│  ┌─ SyncController (NEW) ──────────────────────┐ │  Phase 3
│  │ GET /bots/{id}/conversations/sync           │ │
│  │ GET /bots/{id}/conversations/{cid}/sync     │ │
│  └─────────────────────────────────────────────┘ │
│                                                   │
│  ┌─ ConversationController (extend) ───────────┐ │  Phase 3
│  │ POST agent-message + Idempotency-Key header │ │
│  └─────────────────────────────────────────────┘ │
│                                                   │
│  ┌─ HealthController (extend) ─────────────────┐ │  Phase 5
│  │ GET /health/realtime                        │ │
│  └─────────────────────────────────────────────┘ │
│                                                   │
│  Infra audit (Phase 0):                          │
│  • Reverb container/scaling check                │
│  • QUEUE_CONNECTION → redis (Phase 5)            │
│  • Sentry: queue depth, broadcast errors         │
└───────────────────────────────────────────────────┘
```

### Design Principles

1. **Backwards-compatible** — endpoint ใหม่ไม่แก้ของเก่า; ใช้ feature flag (env-based) ที่จำเป็น
2. **Surgical extension** — แก้ไฟล์เดิมเท่าที่ต้อง, สร้างไฟล์ใหม่เท่าที่ separation จำเป็น
3. **Cursor-based sync** — ใช้ message ID เป็น cursor (positive integer, monotonic), ไม่ใช้ timestamp (clock skew)
4. **Idempotency ทุก mutation** — agent-message ต้องมี `Idempotency-Key` (UUID จาก client) → server cache 24h
5. **Optimistic-first UX** — ทุก action โชว์ผลทันที + rollback ถ้าล้มเหลว + retry

## 5. Component Specs by Phase

### Phase 0 — Infrastructure Audit (~2 ชม., no code)

| Action | Tool | Output |
|--------|------|--------|
| Railway services | Railway MCP | จำนวน Reverb instance, container topology |
| env vars | `railway list-variables` | `BROADCAST_CONNECTION`, `QUEUE_CONNECTION`, `REVERB_SCALING_*` |
| queue worker | Railway logs | running? restart loop? throughput? |
| broadcast errors | Sentry | broadcast() exceptions, queue failures |
| Smoke test | manual | LINE webhook → latency ถึง browser |

**Deliverable:** `docs/superpowers/audits/realtime-audit.md`

### Phase 1 — Resilience (~1 ชม., 1 PR)

**Files modified:**
- `frontend/src/lib/echo.ts` — lower timeouts, visibility handler, subscription_error handler
- `frontend/src/hooks/useConnectionStatus.ts` — listen for `echo:resumed`

**Key changes:**
- `activityTimeout: 120000 → 30000`
- `pongTimeout: 30000 → 10000`
- module-level `visibilitychange` listener → `pusher.connect()` if not connected → dispatch `echo:resumed`
- `pusher:subscription_error` → console.warn + custom event

### Phase 2 — UX Feedback (~3-4 ชม., 1 PR)

**New files:**
- `frontend/src/components/chat/ConnectionIndicator.tsx` — header badge เขียว/เหลือง/แดง
- `frontend/src/lib/notifications.ts` — `requestPermission()`, `showNotification()`, `playPing()`, `setUnreadBadge()`
- `frontend/src/hooks/useBrowserNotification.ts` — wrapper รวมตรรกะ
- `frontend/public/sounds/ping.mp3` — short audio cue

**Files modified:**
- `frontend/src/components/chat/MessageBubble.tsx` — pending state (clock + opacity 60%) เมื่อ `id < 0`; retry button เมื่อ error
- `frontend/src/hooks/chat/useRealtime.ts` — trigger notification + ping เมื่อ tab not visible + sender !== 'agent'
- `frontend/src/stores/uiStore.ts` — toggle `audioEnabled`, `notificationEnabled` (persist)

### Phase 3 — Sync Correctness + Idempotency (~5-6 ชม., 1-2 PR)

**Backend (PR-3a):**
- `app/Http/Controllers/Api/SyncController.php` (new)
  - `GET /api/bots/{bot}/conversations/sync?since={ISO8601}` → conversations updated_at > since + last_message preview
  - `GET /api/bots/{bot}/conversations/{cid}/messages/sync?since_id={int}` → messages id > since_id, asc, limit 200, has_more flag
- `app/Http/Controllers/Api/ConversationController.php` (extend)
  - `agent-message` รับ `Idempotency-Key` header
- `app/Services/Chat/IdempotencyService.php` (new)
  - check + store key (cache_key = sha256(uuid + endpoint + body_hash))
  - 422 ถ้า key reuse กับ payload ต่าง
- Migration `idempotency_keys` table (id uuid PK, endpoint, body_hash, response_payload json, created_at indexed)
- Cleanup job: drop rows created_at < now-24h (scheduled hourly)

**Frontend (PR-3b):**
- `frontend/src/lib/syncEngine.ts` (new)
  - `syncBot(botId)`, `syncConversation(botId, cid)`
  - cursor management (Zustand persist)
  - singleton promise dedup (concurrent calls coalesce)
- `frontend/src/hooks/chat/useRealtime.ts` (extend) — `echo:reconnected`, `echo:resumed` → `syncEngine.syncBot()` instead of `invalidateQueries`
- `frontend/src/hooks/chat/useMessageMutations.ts` (extend) — generate `crypto.randomUUID()` ใน onMutate, ส่ง header
- `frontend/src/hooks/chat/realtimeUtils.ts` (extend) — dedup ใน `updateConversationInList`

### Phase 4 — IndexedDB + SWR (~2-3 ชม., 1 PR)

**Files modified:**
- `frontend/src/lib/query.ts`
  - swap `createSyncStoragePersister` (localStorage) → `createAsyncStoragePersister` + `idb-keyval`
  - remove conversation/messages keys from `NON_PERSISTENT_KEYS`
  - `staleTime: 5 * 60 * 1000 → 30 * 1000` (SWR window)
  - cache key prefix `BOTJAO_QUERY_CACHE_v2` (avoid mixing with old)
  - `maxAge: 7 days` eviction
- one-time migration: detect old localStorage → clear

**New deps:** `idb-keyval`, `@tanstack/query-async-storage-persister`

### Phase 5 — Backend Reliability (~2-3 ชม., 1 PR)

- env: `QUEUE_CONNECTION=redis` on Railway (verify Redis add-on)
- `app/Http/Controllers/HealthController.php` (extend) — `GET /api/health/realtime` returns broadcasting/queue/reverb status JSON
- Sentry alerts (config-only): queue depth > 100 (5min), job fail rate > 1%, broadcast exception spike

### Phase 6 — Multi-agent Light (~3-4 ชม., 1 PR)

- activate `useBotPresence` ใน `ChatPage.tsx`
- `frontend/src/components/chat/AgentPresenceBadge.tsx` (new) — แสดง avatar + count
- whisper event `viewing` ผ่าน presence channel เมื่อ open conversation
- `frontend/src/hooks/chat/useConversationViewers.ts` (new) — เก็บ viewers per conversation
- soft toast เมื่อ open conversation ที่ agent อื่นเปิดอยู่
- ไม่มี migration

## 6. Data Flow Diagrams

### 6.1 Send Message (Idempotency + Optimistic + Retry)

```
USER clicks Send
    ↓
[useChatActions.handleSendMessage]
    ↓
[useSendMessage.mutate]
    ├─ onMutate:
    │   ├─ idempotencyKey = crypto.randomUUID()
    │   ├─ optimisticId = -Date.now()
    │   ├─ insert optimistic message
    │   └─ MessageBubble shows clock + opacity 60%
    ↓
POST /api/bots/{id}/conversations/{cid}/agent-message
  Header: Idempotency-Key: {uuid}
    ↓
Backend: ConversationController@sendAgentMessage
    ├─ check idempotency_keys for {uuid}
    │   ├─ HIT → return cached response (no double send)
    │   └─ MISS:
    │       ├─ MessageService.sendAgentMessage(...)
    │       ├─ broadcast(MessageSent)->toOthers()
    │       └─ store {uuid, body_hash, response} in idempotency_keys
    ↓
Frontend response handler:
    ├─ onSuccess: replace optimistic with real message
    └─ onError:
        ├─ rollback optimistic
        ├─ MessageBubble shows ❌ + "ส่งซ้ำ" button
        └─ retry: re-mutate with SAME idempotencyKey
```

**Invariant:** ส่ง 1 ครั้ง = 1 message ใน DB ไม่ว่า network จะ retry กี่ครั้ง

### 6.2 Receive Message

```
LINE webhook arrives → ProcessLINEWebhook (queue)
    ├─ Save to DB
    ├─ broadcast(MessageSent)
    └─ broadcast(ConversationUpdated)
    ↓
Reverb → WebSocket → Browser
    ↓
useBotChannel.onMessage → useRealtime.handleRealtimeMessage
    ├─ check m.id === event.id ? skip
    ├─ insert into messages cache
    ├─ updateConversationInList (move to top, +unread)
    └─ IF tab not visible AND sender !== 'agent':
        ├─ playPing()
        ├─ showNotification(title, body)
        └─ setUnreadBadge(++count)
```

### 6.3 Delta Sync (Reconnect or Tab Resume)

**Old (current):**
```
echo:reconnected → invalidateQueries(predicate)
    → Refetch ALL: 30 conversations × 50 messages = burst
    → spinner + flicker + lost scroll
```

**New (Phase 3):**
```
echo:reconnected | echo:resumed → syncEngine.syncBot(botId)
    ├─ cursor = zustand.get('lastConvSyncAt')
    ├─ GET /conversations/sync?since={cursor}
    │   └─ Server returns only changes since cursor
    └─ For each conversation in delta:
        ├─ merge into infinite query cache
        ├─ IF selected conversation:
        │   ├─ cursor_msg = zustand.get(`lastMsgId:{cid}`)
        │   ├─ GET /messages/sync?since_id={cursor_msg}
        │   └─ append to messages cache
        └─ update cursors
```

**Network:** 1 request, payload usually <20KB
**UI:** no spinner, smooth append, scroll preserved

### 6.4 Tab Visibility

```
User leaves tab → browser may throttle/kill WS silently
User returns → visibilitychange → 'visible'
    ├─ pusher.connection.state === 'connected'?
    │   ├─ NO → pusher.connect() → echo:reconnected → syncEngine
    │   └─ YES → dispatch echo:resumed → syncEngine (delta)
    └─ User sees fresh state in <2s
```

### 6.5 Multi-Agent Presence

```
Agent A opens conversation #42
    └─ echo.join('bot.{botId}.presence').whisper('viewing', { conversationId: 42 })
       ↓
       Pusher Presence → other agents subscribed
       ↓
Agent B (viewing list) → useConversationViewers updates
    └─ ConversationList badge "👤 1" on conv #42
       Agent B opens #42 → soft toast "Agent A กำลังดูเคสนี้อยู่"
```

## 7. Edge Cases

| ID | Scenario | Defense |
|----|----------|---------|
| EC-1 | Optimistic ขัด broadcast | X-Socket-ID header + frontend dedup `m.id === event.id` |
| EC-2 | Idempotency key reuse ต่าง payload | hash(uuid + endpoint + body_hash); 422 ถ้า reuse |
| EC-3 | Delta sync race กับ WS event | merge by Map<id>, cursor = max(...) |
| EC-4 | Visibility flap | debounce 200ms + singleton promise |
| EC-5 | IndexedDB quota | catch + memory-only fallback + 7-day eviction |
| EC-6 | Auth token expired | subscription_error → refresh token → reconnect |
| EC-7 | Notification denied | hide toggle + favicon/title fallback |
| EC-8 | Multiple tabs (same user) | BroadcastChannel + Web Locks (leader tab) — listed as future work, not in current scope |
| EC-9 | Reverb restart | Pusher backoff + Sentry alert if downtime > 30s |
| EC-10 | Cursor desync | version cache schema, auto full-sync on mismatch |

## 8. Testing Strategy

### Test Pyramid
- **Unit (Vitest/PHPUnit):** syncEngine, notifications, idempotency logic
- **Integration:** useRealtime + Echo mock, SyncController PHPUnit
- **E2E (Playwright):** 3-5 critical paths per phase

### Per-Phase Verification (Definition of Done)
| Phase | Pass Criteria |
|-------|--------------|
| 0 | Audit doc + 3+ actionable recommendations |
| 1 | E2E: tab background 5min → return → message <2s |
| 2 | E2E: optimistic + retry visible; notification when hidden |
| 3 | PHPUnit pass; reconnect = 1 sync request (not 30); idempotency 100% |
| 4 | E2E: F5 → messages visible <100ms (no spinner) |
| 5 | Health endpoint valid; Sentry rules deployed |
| 6 | Playwright 2-browser presence test passes |

### Coverage Targets
| Component | Target |
|-----------|--------|
| `syncEngine.ts` | 90% |
| `lib/echo.ts` | 70% |
| `lib/notifications.ts` | 80% |
| `SyncController` | 100% line |
| `IdempotencyService` | 100% |

## 9. Migration & Rollout

### Phase Order
```
Phase 0 (audit) → Phase 1 (resilience) → Phase 2 (UX)
   → Phase 4 (IndexedDB) ∥ Phase 3 (Sync+Idempotency)
   → Phase 5 (Backend) → Phase 6 (Multi-agent, optional)
```

### PR Strategy
| PR | Branch | Phase | Risk |
|----|--------|-------|------|
| 1 | `chore/realtime-audit` | 0 | none (doc only) |
| 2 | `fix/realtime-resilience` | 1 | low |
| 3 | `feat/chat-ux-feedback` | 2 | low |
| 4a | `feat/chat-sync-backend` | 3 (BE) | medium |
| 4b | `feat/chat-sync-frontend` | 3 (FE) | medium |
| 5 | `feat/chat-indexeddb-cache` | 4 | low-medium |
| 6 | `chore/queue-redis-migration` | 5 | medium |
| 7 | `feat/chat-multi-agent-presence` | 6 | low |

### Pre-Commit Requirement
ทุก PR ที่เปลี่ยน code (Phase 1-6) ต้องรัน `/simplify` (code-simplifier skill) บน changed files ก่อน commit
(Phase 0 = doc only, ไม่ต้องรัน simplify)

### Migrations
- Phase 3: `idempotency_keys` table — additive only, drop ได้ใน rollback
- Phase 4: cache key prefix `_v2` กัน mix กับเก่า; one-time clear localStorage

### Feature Flags (env-based)
- `VITE_FEATURE_DELTA_SYNC` (Phase 3)
- `VITE_FEATURE_INDEXEDDB_CACHE` (Phase 4)
- `VITE_FEATURE_BROWSER_NOTIFICATION` (Phase 2 — default off จนทดสอบครบ)

แต่ละ flag = `if (flag) { newPath } else { oldPath }` ใน hook entry

### Rollback
| Phase | Strategy |
|-------|----------|
| 1, 2 | revert PR (no state change) |
| 3 | revert FE first; BE drop migration ได้ |
| 4 | revert PR; user หา cache (refresh from server) |
| 5 | env redis→database; restart workers |
| 6 | revert PR (presence ปลอดภัย) |

## 10. Risks & Mitigations

| Risk | Impact | Probability | Mitigation |
|------|--------|------------|-----------|
| Phase 5 Redis migration ล้ม | High | Low | Test on Railway preview env ก่อน |
| Phase 3 sync endpoint คืน partial ผิด | Medium | Medium | strict PHPUnit + manual smoke |
| Phase 4 cache stale ข้าม version | Low | Low | version key + auto-clear |
| Phase 1 tighter timeout = false-positive disconnect | Medium | Medium | Sentry monitor reconnect rate |
| Phase 2 audio น่ารำคาญ | Low | High | toggle off, default opt-in |
| Phase 6 presence flap | Low | Low | debounce 2s |

## 11. Success Metrics

| Metric | Baseline | Target |
|--------|----------|--------|
| Time to detect WS drop | ~150s | <40s |
| Reconnect success rate | (Phase 0 measure) | >98% |
| Bytes per reconnect | ~500KB (refetch all) | <20KB (delta) |
| Send success rate | (Phase 0 measure) | >99.5% |
| Duplicate message rate | (Phase 0 measure) | <0.01% |
| Time-to-first-message after F5 | ~800ms | <100ms (cache) |
| User-reported "stuck" issues | current | 0/week |

วัดผ่าน: Sentry custom metrics, manual user feedback, browser DevTools Network tab

## 12. Open Questions / Future Work

- **EC-8 multiple tabs:** อาจต้อง BroadcastChannel + Web Locks ในอนาคต ถ้ามี user complaint
- **Outbox Pattern:** ถ้า scale ใหญ่ขึ้น (>1000 broadcasts/min) อาจคุ้ม
- **Service Worker / Push:** ถ้า user ใช้มือถือเป็นหลัก อาจคุ้ม Phase 7
- **Hard conversation lock:** ถ้า team โต > 5 admin อาจจำเป็น
- **Read receipts:** LINE protocol จำกัด — รออ้างอิงเปิด API ในอนาคต

## 13. References

- Existing realtime fix commits (`fix(realtime):`, `fix(websocket):`, `perf:`)
- `frontend/src/lib/echo.ts:60-147` — Echo singleton + state monitoring
- `frontend/src/hooks/chat/useRealtime.ts` — current event handlers
- `backend/app/Events/MessageSent.php`, `ConversationUpdated.php` — broadcast events
- `backend/routes/channels.php` — channel auth
- Pusher.js docs — `activityTimeout`, `pongTimeout`, presence channels
- Laravel Reverb docs — scaling, ping_interval, allowed_origins
