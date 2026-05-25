# Graceful Redis Fallback — Implementation Plan (Phase 0 + Phase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When Redis is unreachable, fail fast and automatically serve cache + session from the PostgreSQL database, then auto-recover when Redis returns — so the API stops returning HTTP 500 during a Redis outage.

**Architecture:** A `RedisHealthGate` probes Redis at most once per ~10s and memoizes the result in the **file** cache store (Redis-independent; APCu is unavailable in prod). A globally-prepended `RedisFallbackMiddleware` reads the gate and, when Redis is down, swaps `cache.default` and `session.driver` to `database` at runtime for that request.

**Tech Stack:** Laravel 12, PHP 8.4, PHPUnit 11, predis, PostgreSQL (Neon).

**Scope:** Phase 0 (fail-fast timeouts) + Phase 1 (cache/session fallback). Phase 2 (queue auto-failover + idempotency) is a separate plan written after this ships. Spec: `docs/superpowers/specs/2026-05-20-graceful-redis-fallback-design.md`.

**Working directory for all paths:** `backend/`. Run all commands from `backend/`.

---

## Task 1: Redis fail-fast timeouts (Phase 0)

**Files:**
- Modify: `config/database.php` (redis `default` and `cache` connection arrays)

- [ ] **Step 1: Add timeout params to the `default` redis connection**

In `config/database.php`, inside `'redis' => [ 'default' => [ ... ] ]`, add two lines after `'database' => env('REDIS_DB', '0'),`:

```php
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1.0),
            'read_write_timeout' => (float) env('REDIS_READ_WRITE_TIMEOUT', 2.0),
```

- [ ] **Step 2: Add the same to the `cache` redis connection**

In the `'cache' => [ ... ]` array, after `'database' => env('REDIS_CACHE_DB', '0'),`:

```php
            'database' => env('REDIS_CACHE_DB', '0'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1.0),
            'read_write_timeout' => (float) env('REDIS_READ_WRITE_TIMEOUT', 2.0),
```

- [ ] **Step 3: Verify config parses**

Run: `php artisan config:show database.redis.default`
Expected: output includes `timeout => 1.0` and `read_write_timeout => 2.0`.

- [ ] **Step 4: Commit**

```bash
git add config/database.php
git commit -m "feat(redis): add connect/read fail-fast timeouts"
```

---

## Task 2: RedisHealthGate service (Phase 1)

**Files:**
- Create: `app/Services/RedisHealthGate.php`
- Test: `tests/Unit/RedisHealthGateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RedisHealthGateTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\RedisHealthGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisHealthGateTest extends TestCase
{
    public function test_reports_up_when_ping_succeeds(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->andReturn('PONG');

        $gate = new RedisHealthGate();

        $this->assertTrue($gate->isRedisUp());
    }

    public function test_reports_down_when_ping_throws(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')
            ->andThrow(new \RuntimeException('Connection timed out'));

        $gate = new RedisHealthGate();

        $this->assertFalse($gate->isRedisUp());
    }

    public function test_memoizes_within_request(): void
    {
        Cache::store('file')->forget('redis_health:up');
        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');

        $gate = new RedisHealthGate();

        $gate->isRedisUp();
        $gate->isRedisUp();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RedisHealthGateTest`
Expected: FAIL — class `App\Services\RedisHealthGate` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/RedisHealthGate.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisHealthGate
{
    private const CACHE_KEY = 'redis_health:up';

    private ?bool $requestMemo = null;

    public function isRedisUp(): bool
    {
        if ($this->requestMemo !== null) {
            return $this->requestMemo;
        }

        $ttl = (int) config('redis-fallback.health_ttl', 10);

        $up = Cache::store('file')->remember(self::CACHE_KEY, $ttl, function (): bool {
            return $this->probe();
        });

        return $this->requestMemo = $up;
    }

    private function probe(): bool
    {
        try {
            return Redis::connection()->ping() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
```

- [ ] **Step 4: Add the config file**

Create `config/redis-fallback.php`:

```php
<?php

return [
    'health_ttl' => (int) env('REDIS_HEALTH_TTL', 10),
];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RedisHealthGateTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/RedisHealthGate.php config/redis-fallback.php tests/Unit/RedisHealthGateTest.php
git commit -m "feat(redis): add RedisHealthGate with file-cache memoized probe"
```

---

## Task 3: RedisFallbackMiddleware (Phase 1)

**Files:**
- Create: `app/Http/Middleware/RedisFallbackMiddleware.php`
- Test: `tests/Feature/RedisFallbackMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RedisFallbackMiddlewareTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\RedisFallbackMiddleware;
use App\Services\RedisHealthGate;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class RedisFallbackMiddlewareTest extends TestCase
{
    public function test_swaps_to_database_when_redis_down(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();

        (new RedisFallbackMiddleware($gate))->handle(Request::create('/'), fn ($r) => response('ok'));

        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
    }

    public function test_keeps_redis_when_up(): void
    {
        config(['cache.default' => 'redis', 'session.driver' => 'redis']);

        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnTrue();

        (new RedisFallbackMiddleware($gate))->handle(Request::create('/'), fn ($r) => response('ok'));

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RedisFallbackMiddlewareTest`
Expected: FAIL — class `App\Http\Middleware\RedisFallbackMiddleware` not found.

- [ ] **Step 3: Write minimal implementation**

Create `app/Http/Middleware/RedisFallbackMiddleware.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RedisFallbackMiddleware
{
    public function __construct(private RedisHealthGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('cache.default') === 'redis' && ! $this->gate->isRedisUp()) {
            config([
                'cache.default' => 'database',
                'session.driver' => 'database',
            ]);

            Log::warning('Redis unavailable — falling back to database for cache/session');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RedisFallbackMiddlewareTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/RedisFallbackMiddleware.php tests/Feature/RedisFallbackMiddlewareTest.php
git commit -m "feat(redis): add RedisFallbackMiddleware to swap cache/session to database"
```

---

## Task 4: Register the middleware globally (Phase 1)

**Files:**
- Modify: `bootstrap/app.php:30-35` (the `$middleware->prepend([...])` block)

- [ ] **Step 1: Add the middleware to the global prepend list**

In `bootstrap/app.php`, add `RedisFallbackMiddleware::class` as the FIRST entry in the existing `$middleware->prepend([...])` array so it runs before `StartSession` and before any cache/session use:

```php
        $middleware->prepend([
            \App\Http\Middleware\RedisFallbackMiddleware::class,
            CacheHeaders::class,
            TrustProxies::class,
            HandleCors::class,
            SecurityHeaders::class,
        ]);
```

- [ ] **Step 2: Verify the app boots and routes resolve**

Run: `php artisan route:list --path=up`
Expected: lists the `/up` route with no errors (confirms middleware class resolves).

- [ ] **Step 3: Commit**

```bash
git add bootstrap/app.php
git commit -m "feat(redis): register RedisFallbackMiddleware in global stack"
```

---

## Task 5: Integration test — no 500 when Redis is down (Phase 1)

**Files:**
- Test: `tests/Feature/RedisOutageIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RedisOutageIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\RedisHealthGate;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RedisOutageIntegrationTest extends TestCase
{
    public function test_health_endpoint_uses_database_and_does_not_500_when_redis_down(): void
    {
        $gate = Mockery::mock(RedisHealthGate::class);
        $gate->shouldReceive('isRedisUp')->andReturnFalse();
        $this->app->instance(RedisHealthGate::class, $gate);

        config(['cache.default' => 'redis']);

        $response = $this->getJson('/api/health');

        $this->assertNotSame(500, $response->status());
        $this->assertSame('database', config('cache.default'));
        $this->assertTrue(Cache::store('database')->set('probe_key', 'ok', 5));
    }
}
```

- [ ] **Step 2: Run test to verify behavior**

Run: `php artisan test --filter=RedisOutageIntegrationTest`
Expected: PASS — the request resolves without a 500 and `cache.default` is `database`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/RedisOutageIntegrationTest.php
git commit -m "test(redis): integration test for cache fallback during Redis outage"
```

---

## Task 6: Full suite + static checks

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests pass (no regressions).

- [ ] **Step 2: Run Pint (project formatter)**

Run: `./vendor/bin/pint --dirty`
Expected: files formatted, no errors.

- [ ] **Step 3: Commit any formatting**

```bash
git add -A
git commit -m "style: pint formatting for redis fallback"
```

---

## Deployment notes (after merge)

- New env vars (optional, have safe defaults): `REDIS_TIMEOUT=1.0`, `REDIS_READ_WRITE_TIMEOUT=2.0`, `REDIS_HEALTH_TTL=10`.
- `database` cache store requires the `cache` + `cache_locks` tables (already present). `database` session requires the `sessions` table (already present).
- Deploy normally (the web start command runs `config:cache`, which bakes the new config).

## Self-Review

- **Spec coverage:** Phase 0 (Task 1) ✓; RedisHealthGate on file store (Task 2) ✓; middleware swap before StartSession via global prepend (Tasks 3–4) ✓; integration + fail-fast behavior (Task 5) ✓. Phase 2 (queue/idempotency) explicitly deferred to its own plan ✓. CircuitBreaker blind-spot: addressed by using a standalone gate on the file store (per spec, deeper CB rework out of scope) ✓.
- **Placeholders:** none — every code/command step has concrete content.
- **Type consistency:** `RedisHealthGate::isRedisUp()` used identically in Tasks 2, 3, 5; `config('redis-fallback.health_ttl')` matches `config/redis-fallback.php`.
