# Native Chat — Phase 3: Sync Correctness + Idempotency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. This is the largest phase — split into PR-3a (backend) and PR-3b (frontend). Execute 3a first.

**Goal:** Reconnect = delta sync (ไม่ refetch ทั้งก้อน), send = idempotent (ส่งซ้ำไม่ duplicate)

**Architecture:**
- Backend: new SyncController (2 endpoints), IdempotencyService, 1 migration
- Frontend: new syncEngine.ts (cursor management + delta merge), extend useRealtime + useMessageMutations

**Tech Stack:** Laravel 12, PHP 8.4, PHPUnit, React 19, TypeScript, React Query v5, Zustand, Vitest

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 3 + Section 6.3 + Section 7 (EC-1 through EC-4)

**Depends on:** Phase 1 merged ✅. Phase 2 recommended but not blocking.

---

## PR-3a: Backend (Sync endpoints + Idempotency)

### Pre-Flight

- [ ] Create branch: `feat/chat-sync-backend`
- [ ] Read current `app/Http/Controllers/Api/ConversationController.php` to understand existing agent-message endpoint
- [ ] Read `routes/api.php` to find where to add new routes

### File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `backend/app/Http/Controllers/Api/SyncController.php` | **create** | Conversations sync + Messages sync endpoints |
| `backend/app/Services/Chat/IdempotencyService.php` | **create** | Check/store idempotency keys |
| `backend/database/migrations/xxxx_create_idempotency_keys_table.php` | **create** | idempotency_keys schema |
| `backend/app/Console/Commands/CleanIdempotencyKeys.php` | **create** | Scheduled cleanup (>24h) |
| `backend/app/Http/Controllers/Api/ConversationController.php` | modify | Wire idempotency into agent-message |
| `backend/routes/api.php` | modify | Add sync + idempotency routes |
| `backend/tests/Feature/SyncControllerTest.php` | **create** | Sync endpoint tests |
| `backend/tests/Feature/IdempotencyTest.php` | **create** | Idempotency tests |

---

### Task 1: Migration — idempotency_keys table

- [ ] **Step 1.1: Create migration**

```bash
cd backend && php artisan make:migration create_idempotency_keys_table
```

Migration content:

```php
public function up(): void
{
    Schema::create('idempotency_keys', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('endpoint', 255);
        $table->string('body_hash', 64);
        $table->json('response_payload');
        $table->timestamp('created_at')->useCurrent();

        $table->index('created_at');
    });
}

public function down(): void
{
    Schema::dropIfExists('idempotency_keys');
}
```

- [ ] **Step 1.2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 1.3: Commit**

```
feat(db): create idempotency_keys table for send deduplication

UUID primary key, endpoint + body_hash for collision detection,
JSON response_payload for cached responses. Index on created_at
for TTL cleanup.
```

---

### Task 2: IdempotencyService

- [ ] **Step 2.1: Write failing test**

Create `backend/tests/Feature/IdempotencyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Chat\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IdempotencyService();
    }

    public function test_first_request_returns_null(): void
    {
        $result = $this->service->check('test-uuid-1', '/api/test', ['content' => 'hello']);
        $this->assertNull($result);
    }

    public function test_stores_and_retrieves_response(): void
    {
        $key = 'test-uuid-2';
        $endpoint = '/api/test';
        $body = ['content' => 'hello'];
        $response = ['id' => 1, 'content' => 'hello'];

        $this->service->store($key, $endpoint, $body, $response);
        $cached = $this->service->check($key, $endpoint, $body);

        $this->assertNotNull($cached);
        $this->assertEquals($response, $cached);
    }

    public function test_same_key_different_body_returns_conflict(): void
    {
        $key = 'test-uuid-3';
        $endpoint = '/api/test';

        $this->service->store($key, $endpoint, ['content' => 'hello'], ['id' => 1]);

        $this->expectException(\App\Exceptions\IdempotencyConflictException::class);
        $this->service->check($key, $endpoint, ['content' => 'different']);
    }
}
```

- [ ] **Step 2.2: Create IdempotencyService**

Create `backend/app/Services/Chat/IdempotencyService.php`:

```php
<?php

namespace App\Services\Chat;

use App\Exceptions\IdempotencyConflictException;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function check(string $key, string $endpoint, array $body): ?array
    {
        $bodyHash = hash('sha256', json_encode($body));

        $record = DB::table('idempotency_keys')
            ->where('id', $key)
            ->first();

        if (!$record) {
            return null;
        }

        if ($record->body_hash !== $bodyHash || $record->endpoint !== $endpoint) {
            throw new IdempotencyConflictException(
                'Idempotency key reused with different payload'
            );
        }

        return json_decode($record->response_payload, true);
    }

    public function store(string $key, string $endpoint, array $body, array $response): void
    {
        DB::table('idempotency_keys')->insert([
            'id' => $key,
            'endpoint' => $endpoint,
            'body_hash' => hash('sha256', json_encode($body)),
            'response_payload' => json_encode($response),
            'created_at' => now(),
        ]);
    }
}
```

Create `backend/app/Exceptions/IdempotencyConflictException.php`:

```php
<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyConflictException extends HttpException
{
    public function __construct(string $message = 'Idempotency key reused with different payload')
    {
        parent::__construct(422, $message);
    }
}
```

- [ ] **Step 2.3: Run test**

```bash
php artisan test --filter=IdempotencyTest
```

- [ ] **Step 2.4: Commit**

```
feat(chat): add IdempotencyService for agent message dedup

Checks UUID key + endpoint + body_hash. Returns cached response on
match. Throws 422 on key reuse with different payload. 24h TTL
cleanup in separate command.
```

---

### Task 3: SyncController — Conversations Sync Endpoint

- [ ] **Step 3.1: Write failing test**

Create `backend/tests/Feature/SyncControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_conversations_sync_returns_updated_since_timestamp(): void
    {
        // Create conversations with different updated_at
        $old = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'updated_at' => now()->subHours(2),
        ]);
        $recent = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'updated_at' => now()->subMinutes(5),
        ]);

        $since = now()->subHour()->toISOString();

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/sync?since={$since}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_conversations_sync_without_since_returns_latest(): void
    {
        Conversation::factory()->count(5)->create(['bot_id' => $this->bot->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/sync");

        $response->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'updated_at']]]);
    }

    public function test_conversations_sync_requires_auth(): void
    {
        $this->getJson("/api/bots/{$this->bot->id}/conversations/sync")
            ->assertUnauthorized();
    }

    public function test_messages_sync_returns_messages_after_since_id(): void
    {
        $conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $msg1 = $conversation->messages()->create([
            'sender' => 'user', 'content' => 'first', 'type' => 'text',
        ]);
        $msg2 = $conversation->messages()->create([
            'sender' => 'bot', 'content' => 'second', 'type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$conversation->id}/messages/sync?since_id={$msg1->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $msg2->id);
    }
}
```

- [ ] **Step 3.2: Create SyncController**

Create `backend/app/Http/Controllers/Api/SyncController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function conversations(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $since = $request->query('since');

        $query = $bot->conversations()
            ->with(['customerProfile', 'lastMessage'])
            ->orderBy('updated_at', 'desc')
            ->limit(50);

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        return response()->json([
            'data' => $query->get(),
            'synced_at' => now()->toISOString(),
        ]);
    }

    public function messages(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);

        $sinceId = $request->integer('since_id', 0);

        $messages = $conversation->messages()
            ->when($sinceId > 0, fn ($q) => $q->where('id', '>', $sinceId))
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get();

        $hasMore = $messages->count() === 200;

        return response()->json([
            'data' => $messages,
            'has_more' => $hasMore,
            'synced_at' => now()->toISOString(),
        ]);
    }
}
```

- [ ] **Step 3.3: Add routes** in `routes/api.php`:

```php
Route::get('bots/{bot}/conversations/sync', [SyncController::class, 'conversations']);
Route::get('bots/{bot}/conversations/{conversation}/messages/sync', [SyncController::class, 'messages']);
```

- [ ] **Step 3.4: Run tests**

```bash
php artisan test --filter=SyncControllerTest
```

- [ ] **Step 3.5: Commit**

```
feat(chat): add delta sync API endpoints

GET /bots/{bot}/conversations/sync?since=ISO8601 — returns conversations
updated after timestamp. GET /bots/{bot}/conversations/{cid}/messages/sync
?since_id=N — returns messages with id > N. Both limit responses to
prevent large payloads.
```

---

### Task 4: Wire Idempotency into Agent Message Endpoint

- [ ] **Step 4.1:** Read existing `sendAgentMessage` in `ConversationController.php`
- [ ] **Step 4.2:** Add check for `Idempotency-Key` header:

```php
$idempotencyKey = $request->header('Idempotency-Key');
if ($idempotencyKey) {
    $idempotencyService = app(IdempotencyService::class);
    $cached = $idempotencyService->check(
        $idempotencyKey,
        $request->path(),
        $request->all()
    );
    if ($cached) {
        return response()->json($cached);
    }
}
```

After successful send, store the response:

```php
if ($idempotencyKey) {
    $idempotencyService->store(
        $idempotencyKey,
        $request->path(),
        $request->all(),
        $responseData
    );
}
```

- [ ] **Step 4.3: Write integration test** — send with key → 200, send again with same key → 200 cached
- [ ] **Step 4.4: Run tests:** `php artisan test`
- [ ] **Step 4.5: Commit**

```
feat(chat): wire idempotency into agent-message endpoint

Accepts optional Idempotency-Key header. Returns cached response on
duplicate key+body. Throws 422 on key reuse with different body.
```

---

### Task 5: Cleanup Command + /simplify + PR

- [ ] **Step 5.1:** Create `CleanIdempotencyKeys` artisan command — delete rows older than 24h
- [ ] **Step 5.2:** Register in `Console/Kernel.php` schedule: `$schedule->command('idempotency:clean')->hourly()`
- [ ] **Step 5.3:** Run `/simplify` on all PHP files
- [ ] **Step 5.4:** Push + PR: `feat/chat-sync-backend`

---

## PR-3b: Frontend (Sync Engine + Cursor Management)

### Pre-Flight

- [ ] Merge PR-3a first
- [ ] Create branch: `feat/chat-sync-frontend`

### File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `frontend/src/lib/syncEngine.ts` | **create** | syncBot(), syncConversation(), cursor management |
| `frontend/src/lib/syncEngine.test.ts` | **create** | Unit tests for sync + merge + dedup |
| `frontend/src/hooks/chat/useRealtime.ts` | modify | Replace invalidateQueries with syncEngine calls on reconnect/resume |
| `frontend/src/hooks/chat/useMessageMutations.ts` | modify | Add Idempotency-Key header |
| `frontend/src/hooks/useConversations.ts` | modify | Add Idempotency-Key header to useSendAgentMessage |
| `frontend/src/hooks/chat/realtimeUtils.ts` | modify | Dedup in updateConversationInList |

---

### Task 6: Sync Engine

- [ ] **Step 6.1: Create `frontend/src/lib/syncEngine.ts`**

```typescript
import { api } from '@/lib/api';
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import type { QueryClient, InfiniteData } from '@tanstack/react-query';
import { conversationKeys, type ConversationsResponse } from '@/hooks/chat/useConversationList';
import { messageKeys, type MessagesResponse } from '@/hooks/chat/messageKeys';
import type { Conversation, Message } from '@/types/api';

interface SyncCursors {
  lastConvSyncAt: Record<number, string>; // botId → ISO timestamp
  lastMessageId: Record<string, number>;  // "botId:convId" → max message ID
  setCursor: (key: string, value: string | number) => void;
}

export const useSyncCursors = create<SyncCursors>()(
  persist(
    (set) => ({
      lastConvSyncAt: {},
      lastMessageId: {},
      setCursor: (key, value) =>
        set((state) => {
          if (key.startsWith('conv:')) {
            const botId = parseInt(key.split(':')[1]);
            return { lastConvSyncAt: { ...state.lastConvSyncAt, [botId]: value as string } };
          }
          return { lastMessageId: { ...state.lastMessageId, [key]: value as number } };
        }),
    }),
    { name: 'sync-cursors', storage: createJSONStorage(() => localStorage) }
  )
);

let pendingSync: Promise<void> | null = null;

export async function syncBot(
  botId: number,
  queryClient: QueryClient,
  selectedConversationId?: number | null
): Promise<void> {
  // Coalesce concurrent calls
  if (pendingSync) return pendingSync;

  pendingSync = (async () => {
    try {
      const cursors = useSyncCursors.getState();
      const since = cursors.lastConvSyncAt[botId];

      const params = since ? `?since=${encodeURIComponent(since)}` : '';
      const response = await api.get<{ data: Conversation[]; synced_at: string }>(
        `/bots/${botId}/conversations/sync${params}`
      );

      const delta = response.data;

      // Merge delta into infinite query cache
      if (delta.data.length > 0) {
        queryClient.setQueriesData<InfiniteData<ConversationsResponse>>(
          {
            predicate: (query) => {
              const key = query.queryKey;
              return Array.isArray(key) && key[0] === 'conversations-infinite' && key[1] === botId;
            },
          },
          (old) => {
            if (!old) return old;
            const deltaMap = new Map(delta.data.map((c) => [c.id, c]));
            const updatedPages = old.pages.map((page) => ({
              ...page,
              data: page.data.map((conv) => deltaMap.get(conv.id) ?? conv),
            }));
            return { ...old, pages: updatedPages };
          }
        );
      }

      // Update cursor
      useSyncCursors.getState().setCursor(`conv:${botId}`, delta.synced_at);

      // Sync messages for selected conversation
      if (selectedConversationId) {
        await syncConversation(botId, selectedConversationId, queryClient);
      }
    } finally {
      pendingSync = null;
    }
  })();

  return pendingSync;
}

export async function syncConversation(
  botId: number,
  conversationId: number,
  queryClient: QueryClient
): Promise<void> {
  const cursors = useSyncCursors.getState();
  const cursorKey = `${botId}:${conversationId}`;
  const sinceId = cursors.lastMessageId[cursorKey] || 0;

  const response = await api.get<{ data: Message[]; has_more: boolean; synced_at: string }>(
    `/bots/${botId}/conversations/${conversationId}/messages/sync?since_id=${sinceId}`
  );

  const newMessages = response.data.data;

  if (newMessages.length > 0) {
    const messageOptions = { order: 'asc' as const, perPage: 100 };
    queryClient.setQueryData<MessagesResponse>(
      messageKeys.listWithOptions(botId, conversationId, messageOptions),
      (old) => {
        if (!old) return old;
        const existingIds = new Set(old.data.map((m) => m.id));
        const unique = newMessages.filter((m) => !existingIds.has(m.id));
        return { ...old, data: [...old.data, ...unique] };
      }
    );

    // Update cursor to max ID
    const maxId = Math.max(...newMessages.map((m) => m.id));
    useSyncCursors.getState().setCursor(cursorKey, maxId);
  }
}
```

- [ ] **Step 6.2: Write tests** for syncBot, syncConversation, cursor management, dedup
- [ ] **Step 6.3: Verify tsc**
- [ ] **Step 6.4: Commit**

```
feat(chat): add sync engine with cursor-based delta sync

syncBot() fetches conversations updated since last sync timestamp.
syncConversation() fetches messages with id > last known. Both merge
into React Query cache with dedup. Cursors persist in Zustand/localStorage.
Concurrent calls coalesced via singleton promise.
```

---

### Task 7: Replace invalidateQueries with syncEngine in useRealtime

- [ ] **Step 7.1:** In `useRealtime.ts`, import `syncBot` from syncEngine
- [ ] **Step 7.2:** In the `echo:reconnected` handler, replace `invalidateQueries` with:

```typescript
const handleReconnect = () => {
  const currentBotId = botIdRef.current;
  if (!currentBotId) return;
  syncBot(currentBotId, queryClient, selectedConversationIdRef.current);
};
```

- [ ] **Step 7.3:** Feature flag: read `VITE_FEATURE_DELTA_SYNC` env. If false, fall back to old invalidateQueries.

```typescript
const useDeltaSync = import.meta.env.VITE_FEATURE_DELTA_SYNC === 'true';

const handleReconnect = () => {
  const currentBotId = botIdRef.current;
  if (!currentBotId) return;

  if (useDeltaSync) {
    syncBot(currentBotId, queryClient, selectedConversationIdRef.current);
  } else {
    // Legacy: full invalidation
    queryClient.invalidateQueries({ ... });
  }
};
```

- [ ] **Step 7.4:** Verify tests + tsc
- [ ] **Step 7.5: Commit**

```
feat(chat): use delta sync on reconnect instead of full invalidation

Replaces invalidateQueries storm with targeted syncBot() call.
Feature-flagged via VITE_FEATURE_DELTA_SYNC env. Fallback to old
behavior when flag is off.
```

---

### Task 8: Add Idempotency-Key to Send Mutations

- [ ] **Step 8.1:** In `useConversations.ts` `useSendAgentMessage`, in `mutationFn`:

```typescript
const idempotencyKey = crypto.randomUUID();
const response = await api.post<AgentMessageResponse>(
  `/bots/${botId}/conversations/${conversationId}/agent-message`,
  data,
  { headers: { 'Idempotency-Key': idempotencyKey } }
);
```

- [ ] **Step 8.2:** Also update `useMessageMutations.ts` `useSendMessage` with same pattern
- [ ] **Step 8.3:** Verify tsc + tests
- [ ] **Step 8.4: Commit**

```
feat(chat): send Idempotency-Key header with agent messages

Generates crypto.randomUUID() per mutation. Server returns cached
response on duplicate key, preventing double sends from network retries.
```

---

### Task 9: /simplify + Push + PR

- [ ] Run `/simplify` on all changed files
- [ ] Push: `feat/chat-sync-frontend`
- [ ] PR: `feat(chat): delta sync engine + idempotent send`

---

## Definition of Done

- [ ] PR-3a: Backend tests pass + endpoints working
- [ ] PR-3b: Frontend tests pass + delta sync replaces invalidation
- [ ] Reconnect → 1 sync request (not 30 invalidates)
- [ ] Network tab: sync payload <20KB (vs ~500KB old)
- [ ] Send message twice rapidly → only 1 message in DB
- [ ] Feature flag `VITE_FEATURE_DELTA_SYNC=false` → old behavior works
