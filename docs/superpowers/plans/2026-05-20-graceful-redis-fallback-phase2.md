# Graceful Redis Fallback — Implementation Plan (Phase 2)

**Goal:** When Redis is unreachable, queued jobs still run (on the database connection) and the bot does not double-reply or duplicate orders, then everything reverts to Redis on recovery.

**Architecture:** Dispatches route to the `database` connection via `QueueRouter::connection()` while the `RedisHealthGate` reports down. An always-on `worker-db` drains that connection. Workers never pass through the HTTP middleware, so a `Queue::looping` hook re-applies the same cache/session fallback (`RedisFallbackSwitch`) before every job — making the existing per-conversation cache locks + `OrderService` DB dedup function during an outage.

**Tech Stack:** Laravel 12, PHP 8.4, Pest/PHPUnit 11, predis, PostgreSQL (Neon).

**Scope:** Phase 2 only. Phase 0+1 (fail-fast timeouts, gate, HTTP cache/session fallback) already shipped. No new `processed_job_keys` table: order duplication is already prevented by `OrderService::create()` dedup on `(conversation_id, message_id)` (Redis-independent), and a job-level conversation key would break `ProcessAggregatedMessages`' intentional self-redispatch.

**Working directory for all paths:** `backend/`.

---

## Task 1: Dispatch routing via gate

**Files:**
- Modify: `app/Support/QueueRouter.php`
- Modify dispatch sites that chain `->onQueue(QueueRouter::llmQueue())`:
  - `app/Http/Controllers/Webhook/{LINE,Facebook,Telegram}WebhookController.php`
  - `app/Jobs/ProcessLINEWebhook.php`, `app/Jobs/ProcessAggregatedMessages.php`
  - `app/Services/MultipleBubblesService.php`, `app/Services/LineWebhook/LineWebhookContextService.php`
- Test: `tests/Unit/QueueRouterConnectionTest.php`

- [x] **Step 1:** Add `QueueRouter::connection(): ?string` → `'database'` when `RedisHealthGate::isRedisUp()` is false, else `null` (use default).
- [x] **Step 2:** Prepend `->onConnection(QueueRouter::connection())` before `->onQueue(...)` at all 9 LLM-bound dispatch sites.
- [x] **Step 3:** `QueueRouterConnectionTest` — database when down, null when up.

---

## Task 2: Extract RedisFallbackSwitch

**Files:**
- Create: `app/Services/RedisFallbackSwitch.php`
- Modify: `app/Http/Middleware/RedisFallbackMiddleware.php`
- Test: `tests/Unit/RedisFallbackSwitchTest.php`

- [x] **Step 1:** `RedisFallbackSwitch::apply()` — independent guards (swap `cache.default`/`session.driver` to `database` only if currently `redis`), fail-open, logs warning. Single source for the swap logic.
- [x] **Step 2:** Middleware delegates to `(new RedisFallbackSwitch($this->gate))->apply()`; constructor keeps `RedisHealthGate` so existing middleware tests pass unchanged.

---

## Task 3: Worker cache/session fallback

**Files:**
- Modify: `app/Services/RedisFallbackSwitch.php`
- Modify: `app/Providers/AppServiceProvider.php:boot()`
- Test: `tests/Feature/WorkerRedisFallbackTest.php`

- [x] **Step 1:** `RedisFallbackSwitch::refresh($cacheBaseline, $sessionBaseline)` — reset to baseline drivers, then `apply()`; gives auto-recovery in a long-lived worker.
- [x] **Step 2:** `RedisFallbackSwitch::registerWorkerHook()` — capture baseline once, register `Queue::looping` to `app(static::class)->refresh(...)` per job (fresh gate each loop; gate is non-singleton so the file-cache TTL throttles probes).
- [x] **Step 3:** Call `RedisFallbackSwitch::registerWorkerHook()` in `AppServiceProvider::boot()`.
- [x] **Step 4:** `WorkerRedisFallbackTest` — `Looping` event swaps to database when down, restores redis after recovery.

---

## Task 4: Always-on database worker

**Files:**
- Modify: `backend/Procfile`
- Modify: `backend/Dockerfile` (supervisor block)

- [x] **Step 1:** Add `worker-db: php artisan queue:work database --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160` to `Procfile`.
- [x] **Step 2:** Add the matching `[program:queue-worker-db]` supervisor block to `Dockerfile`.

---

## Task 5: Full suite + static checks

- [x] **Step 1:** `php -d memory_limit=1G vendor/bin/pest` — all pass (`artisan test` default 128M OOMs at Pest shutdown; raise memory).
- [x] **Step 2:** `./vendor/bin/pint --dirty`.

---

## Deployment notes (after merge)

- No new env vars, no new migrations.
- `worker-db` is a new Railway process consuming the `database` queue connection; light constant DB polling, only does work during a Redis outage.
- If prod `QUEUE_CONNECTION=database` (not redis), routing is a no-op and `worker-db` overlaps existing workers harmlessly.

## Self-Review

- **Order safety:** unchanged — `OrderService::create()` already dedups on `(conversation_id, message_id)` via DB; no new table needed.
- **Double-reply safety during outage:** worker `Queue::looping` swaps `cache.default` to `database`, so `ai_response` / `msg_agg` locks + aggregation state work without Redis.
- **Recovery:** gate file-cache TTL (~10s) + per-loop `refresh()` revert workers to redis; HTTP reverts per request.
- **Self-redispatch preserved:** no job-level idempotency key, so `ProcessAggregatedMessages` line ~314 retry loop still works.
