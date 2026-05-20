# Graceful Redis Fallback — Design

**Date:** 2026-05-20
**Status:** Approved (design), pending implementation plan
**Trigger:** Production incident 2026-05-20 — a Railway outage took Redis (`redis.railway.internal:6379`) offline; backend returned HTTP 500 on every request because cache, session, and queue all depend on Redis with no fallback and no connection timeout.

## Goal

Keep the web app and the bot operational when Redis is unavailable, by automatically falling back to the PostgreSQL (Neon) database for cache, session, and queue — then automatically switching back when Redis recovers. Failover must not produce duplicate job execution (order/payment safety).

## Non-Goals

- Redis high-availability / clustering (rejected: cost + still a third-party SPOF).
- Permanently running cache/session/queue on the database (too heavy for Neon).
- Changing the broadcasting driver (reverb) — out of scope.

## Background / Current State

- Stack: Laravel 12 backend, React frontend, PostgreSQL (Neon), Redis on Railway.
- `config/cache.php` → `CACHE_STORE` (prod: redis); `config/session.php` → `SESSION_DRIVER` (prod: redis); `config/queue.php` → `QUEUE_CONNECTION` (prod: redis).
- `config/database.php` redis block has **no `timeout` / `read_write_timeout`** → on a dead Redis each request hangs before failing, amplifying the outage into a 500 storm.
- `app/Services/CircuitBreakerService.php` already exists with `execute(service, operation, fallback)` semantics, BUT it stores its own state via `Cache` (= Redis). When Redis is down it cannot read its own state and degrades to "assume closed" → keeps hitting dead Redis. This blind spot must be fixed for the breaker to be useful against Redis failure.
- Queue workers (`backend/Procfile`, `backend/Dockerfile` supervisor): `worker-llm` and `worker-fast` run `queue:work` on the default (redis) connection. Critical job: `app/Jobs/ProcessAggregatedMessages.php` (bot message processing; touches orders).
- **APCu is NOT available** in the production container. Redis-independent state must use the **file** cache store (`Cache::store('file')`), which writes to local disk and needs neither Redis nor APCu.

## Decision: Phased delivery

### Phase 0 — Fail-fast (prerequisite)

`config/database.php` redis connections (`default`, `cache`): add
- `timeout` = 1.0 (connect timeout, seconds)
- `read_write_timeout` = 2.0 (predis read/write timeout, seconds)

Driven by env with safe defaults: `REDIS_TIMEOUT` (default 1.0), `REDIS_READ_WRITE_TIMEOUT` (default 2.0). This ensures a dead Redis fails within ~1–2s instead of hanging.

### Phase 1 — Cache + Session auto-fallback (low risk)

1. **`RedisHealthGate` service** (`app/Services/RedisHealthGate.php`)
   - `isRedisUp(): bool` — performs a cheap `PING` against the redis connection, wrapped in the fail-fast timeout.
   - Result memoized in the **file** cache store for a short TTL (`REDIS_HEALTH_TTL`, default 10s) so we probe at most once per ~10s, not per request. State store is file (not Redis, not APCu).
   - Per-request in-memory memo to avoid repeat checks within a single request.

2. **`RedisFallbackMiddleware`** (global, registered early — before `StartSession`)
   - If `RedisHealthGate::isRedisUp()` is false: at runtime set `config(['cache.default' => 'database', 'session.driver' => 'database'])`.
   - If true: leave as configured (redis). Recovery is automatic on the next probe cycle.
   - **Critical ordering constraint:** the session driver swap must occur before Laravel's `StartSession` middleware reads the session. The middleware must be prepended ahead of `StartSession` in the HTTP kernel/group stack.

3. **Fix CircuitBreaker blind spot (scoped):** the breaker/health state used for the Redis decision must read/write the **file** store, not the default cache. Either point `RedisHealthGate` at the file store directly (preferred, simplest) or configure `circuit-breaker` state to a Redis-independent store. Phase 1 uses a standalone `RedisHealthGate` on the file store; deeper CircuitBreaker rework is out of scope.

### Phase 2 — Queue auto-failover + idempotency (no duplicates)

4. **Dispatch routing:** when `RedisHealthGate` reports down, jobs dispatch to the `database` queue connection instead of redis. Implemented by selecting the connection at dispatch time based on the gate (e.g., a small dispatch helper / overriding the resolved default connection in a service provider). When Redis is up, dispatch to redis as normal.

5. **Always-on database worker:** add a `worker-db` process to `backend/Procfile` and the `backend/Dockerfile` supervisor config:
   `php artisan queue:work database --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160`
   This guarantees jobs landing on the database connection (during a Redis outage) are consumed, while redis workers continue handling the normal path. Cost: light constant DB polling by one extra worker.

6. **Idempotency guard (mandatory — order safety):**
   - New table `processed_job_keys` (`key` unique, `created_at`); migration via the project's safe-migration process.
   - A reusable trait/guard that, at the start of a job's `handle()`, atomically claims a **business idempotency key** (e.g., `process-aggregated:{conversation_id}:{message_window}`, and for order/payment jobs the order reference). The claim uses a DB unique-insert (or `Cache::lock` backed by the **database** store) so it is independent of Redis.
   - If the key is already claimed → the job no-ops (already processed). This guarantees a job dispatched twice across the failover transition runs exactly once.
   - Apply to `ProcessAggregatedMessages` and any order/payment-creating jobs.

## Error Handling & Recovery

- Health gate TTL short (~10s) → recovery within one probe cycle after Redis returns.
- Every failover/recovery transition logs (warning) and records a metric via the existing `ResilienceMetricsService`.
- On Redis recovery: new requests use redis again; jobs already enqueued on the database connection are drained by `worker-db` until empty.
- All gate/probe operations are wrapped so a probe failure never throws into the request path (fail safe → treat as "down", use database).

## Testing

- **Unit:** `RedisHealthGate` returns up/down correctly and memoizes; fallback middleware swaps `cache.default`/`session.driver` only when down.
- **Integration:** point redis host at a dead port → assert (a) no HTTP 500, (b) cache + session resolve to `database`, (c) new dispatches route to the `database` connection.
- **Idempotency:** dispatch the same job (same business key) on both connections → assert the handler body executes exactly once.
- **Recovery:** gate flips back to up after Redis returns → cache/session/dispatch revert to redis.
- **Fail-fast:** simulate unreachable redis → request fails within the configured timeout budget, not a long hang.

## Risks / Notes

- **Session swap timing** is the most delicate part — must run before `StartSession`. Covered by an integration test asserting sessions work on the database during a simulated outage.
- **APCu unavailable** → file cache store is the Redis-independent state store (decided, verified in container).
- **worker-db** adds constant light DB polling; acceptable and only active as a consumer (cheap when the database queue is empty).
- During the brief transition window, the idempotency guard — not ordering — is what protects against duplicates; ordering may differ momentarily, which is acceptable per requirements.

## Out of Scope / Follow-ups

- Converting workers to dynamically switch connections (instead of an always-on db worker) — not needed given the always-on approach.
- Broadcasting (reverb) resilience.
- Deeper rework of `CircuitBreakerService` to be fully Redis-independent across all its uses.
