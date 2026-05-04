# Native Chat — Phase 5: Backend Reliability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** ย้าย queue จาก database → Redis เพื่อเพิ่มความเร็ว + เพิ่ม health check endpoint สำหรับ monitoring

**Architecture:** เปลี่ยน env `QUEUE_CONNECTION=redis` บน Railway + สร้าง GET /api/health/realtime endpoint ใน HealthController + ตั้ง Sentry alerts

**Tech Stack:** Laravel 12, Redis, PHPUnit

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 5

**Depends on:** Phase 0 audit completed (verify Redis available on Railway)

---

## Pre-Flight

- [ ] Verify Redis add-on exists on Railway: `mcp__railway__list-variables` — look for `REDIS_URL`
- [ ] If no Redis: add Redis add-on on Railway first
- [ ] Create branch: `chore/queue-redis-migration`

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `backend/.env.example` | modify | Document QUEUE_CONNECTION=redis |
| `backend/app/Http/Controllers/HealthController.php` | modify or create | GET /api/health/realtime endpoint |
| `backend/routes/api.php` | modify | Add health route |
| `backend/tests/Feature/HealthControllerTest.php` | create | Test health endpoint |

---

## Task 1: Health Check Endpoint

- [ ] **Step 1.1: Write failing test**

Create `backend/tests/Feature/HealthControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    public function test_realtime_health_returns_json(): void
    {
        $response = $this->getJson('/api/health/realtime');
        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'checks' => [
                'broadcasting' => ['ok'],
                'queue' => ['ok', 'depth', 'failed'],
            ],
        ]);
    }
}
```

- [ ] **Step 1.2: Run test to verify fails:** `php artisan test --filter=HealthControllerTest`

- [ ] **Step 1.3: Create or extend HealthController**

Check if `app/Http/Controllers/HealthController.php` exists. If not, create it. Add:

```php
public function realtime(): JsonResponse
{
    $queueDepth = DB::table('jobs')->where('available_at', '<=', now()->timestamp)->count();
    $failedCount = DB::table('failed_jobs')->count();

    $broadcastOk = config('broadcasting.default') !== 'null';

    return response()->json([
        'status' => ($broadcastOk && $failedCount < 10) ? 'healthy' : 'degraded',
        'checks' => [
            'broadcasting' => [
                'ok' => $broadcastOk,
                'driver' => config('broadcasting.default'),
            ],
            'queue' => [
                'ok' => $queueDepth < 100,
                'depth' => $queueDepth,
                'failed' => $failedCount,
                'connection' => config('queue.default'),
            ],
        ],
    ]);
}
```

- [ ] **Step 1.4: Add route** in `routes/api.php`:
```php
Route::get('health/realtime', [HealthController::class, 'realtime']);
```

- [ ] **Step 1.5: Run test to verify passes**

- [ ] **Step 1.6: Commit:**
```
feat(monitoring): add GET /api/health/realtime endpoint

Returns broadcasting driver status, queue depth, failed job count.
Status is 'healthy' when broadcasting is enabled and failed < 10.
```

## Task 2: Queue Migration to Redis — DEFERRED (data-driven decision)

**Status:** Deferred 2026-05-04. Re-evaluate 2026-05-18 (after 2 weeks of `/api/health/realtime` metrics).

**Why deferred:**
- `backend/.env.example` already documents Redis as "not recommended due to quota limits"
- Railway has no Redis service (services: reverb, backend, frontend, scheduler)
- LLM call latency (1-5s) dominates total response time — DB queue polling overhead (0-3s) is secondary
- No production measurement showing DB queue is a real bottleneck → migrating now = premature optimization
- Project owner already deliberately chose `database` queue once

**Re-evaluation criteria (2026-05-18):**
- IF sustained `queue.depth > 50` OR `queue.failed >= 10` for >7 days → proceed with original migration steps below
- IF `queue.depth < 10` consistently → close this task permanently and update `.env.example` to remove Redis section

**Original migration steps (kept for re-evaluation):**

- [ ] **Step 2.1:** Update `.env.example`: `QUEUE_CONNECTION=redis`
- [ ] **Step 2.2:** On Railway: add Redis add-on, then set `QUEUE_CONNECTION=redis` via `mcp__railway__set-variables`
- [ ] **Step 2.3:** Restart queue worker on Railway
- [ ] **Step 2.4:** Monitor: health endpoint shows `queue.connection: redis`
- [ ] **Step 2.5:** Commit:
```
chore: migrate queue connection to Redis

Redis queue is faster and more reliable than database driver for
real-time broadcasting. Requires REDIS_URL env on Railway.
```

## Task 3: Sentry Monitoring (Config Only)

- [ ] Set up Sentry alert rules:
  - Queue depth > 100 sustained 5min → alert
  - Job failure rate > 1% → alert
  - Broadcast exception spike → alert
- [ ] Document in audit findings

## Task 4: /simplify + Push + PR

- [ ] Run `/simplify` on changed PHP files
- [ ] Push: `chore/queue-redis-migration`
- [ ] PR: `chore: Redis queue migration + realtime health endpoint`

## Rollback Plan
- Railway: set `QUEUE_CONNECTION=database`, restart workers
- Health endpoint stays (harmless)

## Definition of Done
- [ ] Health endpoint returns valid JSON
- [ ] Queue using Redis in production
- [ ] Sentry alerts configured
- [ ] Webhook → broadcast latency measured (before/after)
