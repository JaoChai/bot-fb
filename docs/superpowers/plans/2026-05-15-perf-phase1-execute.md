# Performance Phase 1 — Execution Plan (Items 2, 3, 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Implement Items 2-4 from `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`. Items 1 + 5 are out of scope (Item 1 already fixed by prior PRs, Item 5 deferred to next session).

**Architecture:** Three independent code changes — DI fix (backend), dead-code removal (backend), HTML skeleton (frontend). All low-risk, no schema changes, no deploy-coupling.

**Tech Stack:** PHP 8.4 / Laravel 12, React 19 / Vite, Pest tests

**Reference spec:** `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`

**Investigation findings overriding the spec:**
- Spec Item 1 (analytics/dashboard error fix) is **already resolved** — Sentry 24h shows 0 failures (was fixed by commit 193178e + #153 deploy).
- Spec Item 3 (remove CostTrackingService) is **confirmed safe** — service injected in `StreamController` constructor but never called (`grep` for `$this->costTracking` after assignment returns empty).

---

## Task 1: Restore CircuitBreaker → Sentry visibility (Item 2)

**Files:**
- Modify: `backend/app/Services/CircuitBreakerService.php:21,23`
- Modify: `backend/app/Services/ResilienceMetricsService.php:11`
- Test: `backend/tests/Unit/ResilienceMetricsServiceTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/ResilienceMetricsServiceTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Services\ResilienceMetricsService;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;

class ResilienceMetricsServiceTest extends TestCase
{
    public function test_constructor_requires_hub_interface(): void
    {
        $reflection = new \ReflectionClass(ResilienceMetricsService::class);
        $constructor = $reflection->getConstructor();
        $param = $constructor->getParameters()[0];

        $this->assertSame('sentry', $param->getName());
        $this->assertFalse($param->allowsNull(), 'sentry should be non-nullable for Laravel DI auto-resolve');
        $this->assertFalse($param->isDefaultValueAvailable(), 'sentry should be required (no default value)');
    }

    public function test_circuit_breaker_constructor_requires_metrics(): void
    {
        $reflection = new \ReflectionClass(\App\Services\CircuitBreakerService::class);
        $constructor = $reflection->getConstructor();
        $param = $constructor->getParameters()[0];

        $this->assertSame('metrics', $param->getName());
        $this->assertFalse($param->allowsNull(), 'metrics should be non-nullable');
        $this->assertFalse($param->isDefaultValueAvailable(), 'metrics should be required');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
cd backend && vendor/bin/pest --filter=ResilienceMetricsServiceTest
```

Expected: FAILURES — both assertions fail because params are currently nullable with defaults.

- [ ] **Step 3: Fix CircuitBreakerService — make metrics required**

Edit `backend/app/Services/CircuitBreakerService.php`:

Find:
```php
    protected ?ResilienceMetricsService $metrics;

    public function __construct(?ResilienceMetricsService $metrics = null)
    {
        $this->cachePrefix = config('circuit-breaker.cache_prefix', 'circuit_breaker');
        $this->enabled = config('circuit-breaker.enabled', true);
        $this->metrics = $metrics;
    }
```

Replace with:
```php
    public function __construct(protected ResilienceMetricsService $metrics)
    {
        $this->cachePrefix = config('circuit-breaker.cache_prefix', 'circuit_breaker');
        $this->enabled = config('circuit-breaker.enabled', true);
    }
```

Also remove the now-redundant property declaration at line 21 (`protected ?ResilienceMetricsService $metrics;`).

- [ ] **Step 4: Fix ResilienceMetricsService — make sentry hub required**

Edit `backend/app/Services/ResilienceMetricsService.php`:

Find:
```php
    public function __construct(
        protected ?HubInterface $sentry = null
    ) {}
```

Replace with:
```php
    public function __construct(
        protected HubInterface $sentry
    ) {}
```

- [ ] **Step 5: Verify any null-check on $this->metrics or $this->sentry is removed if redundant**

```
cd backend && grep -nE '\$this->metrics\?\?|\$this->metrics === null|\$this->sentry\?\?|\$this->sentry === null' app/Services/CircuitBreakerService.php app/Services/ResilienceMetricsService.php
```

Expected: no results (both classes use `$this->isSentryEnabled()` or unconditional calls).

- [ ] **Step 6: Run test to verify pass**

```
cd backend && vendor/bin/pest --filter=ResilienceMetricsServiceTest
```

Expected: 2 tests pass.

- [ ] **Step 7: Verify Laravel can resolve the chain**

```
cd backend && php artisan tinker --execute="app(\App\Services\CircuitBreakerService::class); echo 'OK';"
```

Expected: `OK`. If error mentions `HubInterface`, check Sentry SDK registration in `config/app.php` providers.

- [ ] **Step 8: Commit**

```
git add backend/app/Services/CircuitBreakerService.php \
        backend/app/Services/ResilienceMetricsService.php \
        backend/tests/Unit/ResilienceMetricsServiceTest.php
git commit -m "fix(resilience): non-nullable DI for CB metrics + Sentry hub"
```

---

## Task 2: Remove dead CostTrackingService + AgentCostUsage model (Item 3)

**Files:**
- Delete: `backend/app/Services/CostTrackingService.php`
- Delete: `backend/app/Models/AgentCostUsage.php`
- Modify: `backend/app/Http/Controllers/Api/StreamController.php` (remove dead injection)
- Test: confirm no test references either class

- [ ] **Step 1: Confirm zero callers (sanity check before delete)**

```
cd backend && grep -rnE "CostTrackingService|AgentCostUsage" app/ tests/ database/ 2>/dev/null | grep -v "app/Services/CostTrackingService.php" | grep -v "app/Models/AgentCostUsage.php"
```

Expected output (current state has these only):
```
app/Http/Controllers/Api/StreamController.php:10:use App\Services\CostTrackingService;
app/Http/Controllers/Api/StreamController.php:39:    protected CostTrackingService $costTracking;
app/Http/Controllers/Api/StreamController.php:70:        CostTrackingService $costTracking,
app/Http/Controllers/Api/StreamController.php:78:        $this->costTracking = $costTracking;
```

If anything else appears (e.g., a real usage of methods on `$this->costTracking`), STOP and report — do not delete.

- [ ] **Step 2: Remove dead injection from StreamController**

Edit `backend/app/Http/Controllers/Api/StreamController.php`:

Remove line 10: `use App\Services\CostTrackingService;`

Remove line 39: `    protected CostTrackingService $costTracking;`

Remove line 70 from the constructor signature: the `CostTrackingService $costTracking,` parameter.

Remove line 78: `$this->costTracking = $costTracking;`

After edit, run:
```
cd backend && grep -nE "CostTrackingService|costTracking" app/Http/Controllers/Api/StreamController.php
```
Expected: empty.

- [ ] **Step 3: Delete the service**

```
git rm backend/app/Services/CostTrackingService.php
```

- [ ] **Step 4: Delete the model**

```
git rm backend/app/Models/AgentCostUsage.php
```

- [ ] **Step 5: Verify no broken references**

```
cd backend && grep -rnE "CostTrackingService|AgentCostUsage" app/ tests/ database/ config/ 2>/dev/null
```
Expected: empty.

- [ ] **Step 6: Run full backend test suite**

```
cd backend && php artisan test --parallel=1 2>&1 | tail -20
```

Expected: all green. If any test fails because of missing class, restore the model file and investigate (the test may have lingering reference).

- [ ] **Step 7: Verify StreamController still instantiates correctly**

```
cd backend && php artisan tinker --execute="app(\App\Http\Controllers\Api\StreamController::class); echo 'OK';"
```

Expected: `OK`.

- [ ] **Step 8: Commit**

```
git add backend/app/Http/Controllers/Api/StreamController.php
git commit -m "refactor: remove dead CostTrackingService + AgentCostUsage model"
```

(The `git rm` from Steps 3-4 is already staged.)

---

## Task 3: Frontend static HTML skeleton (Item 4)

**Files:**
- Modify: `frontend/index.html`

- [ ] **Step 1: Read current index.html**

```
cat frontend/index.html
```

Note the existing `<body>` content (likely just `<div id="root"></div>` + script tag for Vite).

- [ ] **Step 2: Insert skeleton inside #root**

Edit `frontend/index.html`. Inside `<div id="root">`, add the skeleton content **before** Vite swaps it out at React mount:

```html
    <div id="root">
      <div style="min-height: 100vh; display: flex; flex-direction: column; background: #f9fafb; font-family: system-ui, sans-serif;">
        <header style="height: 56px; background: #ffffff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; padding: 0 24px;">
          <div style="height: 24px; width: 140px; background: #e5e7eb; border-radius: 4px;"></div>
        </header>
        <main style="flex: 1; padding: 24px; max-width: 1280px; margin: 0 auto; width: 100%;">
          <div style="height: 28px; width: 240px; background: #e5e7eb; border-radius: 4px; margin-bottom: 20px;"></div>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div style="height: 140px; background: #ffffff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"></div>
            <div style="height: 140px; background: #ffffff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"></div>
            <div style="height: 140px; background: #ffffff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);"></div>
          </div>
        </main>
      </div>
    </div>
```

- [ ] **Step 3: Verify Vite dev build still mounts**

```
cd frontend && npm run build 2>&1 | tail -10
```

Expected: build succeeds. `dist/index.html` should contain the skeleton inline.

- [ ] **Step 4: Optional — Lighthouse check on built artifact**

```
cd frontend && npx --yes serve dist -l 4173 &
SERVE_PID=$!
sleep 2
npx --yes lighthouse http://localhost:4173/ --output=json --quiet --only-categories=performance --chrome-flags="--headless --no-sandbox" --max-wait-for-load=30000 2>&1 | tail -3
kill $SERVE_PID
```

Expected: FCP lower than baseline 4.1s (target < 2.0s on local serve).

If serve/lighthouse not available, skip — production CI will verify.

- [ ] **Step 5: Commit**

```
git add frontend/index.html
git commit -m "perf(ui): static HTML skeleton for instant FCP"
```

---

## Task 4: Final Verification + PR

- [ ] **Step 1: Local check — backend tests pass**

```
cd backend && php artisan test 2>&1 | tail -10
```

Expected: PASS or note the worktree-specific PHP version limitations (CI will be authoritative).

- [ ] **Step 2: Local check — frontend build + lint pass**

```
cd frontend && npm run build 2>&1 | tail -3
cd frontend && npm run lint 2>&1 | tail -5
```

Expected: build PASS, lint warnings ≤ baseline (33).

- [ ] **Step 3: Push and create PR**

```
git push -u origin worktree-phase-1-perf-2026-05-15
gh api -X POST repos/JaoChai/bot-fb/pulls -f title="perf: Phase 1 quick wins (CB Sentry + dead code + FCP skeleton)" -f head=worktree-phase-1-perf-2026-05-15 -f base=main -f body="..."
```

(Use multi-line body via gh pr create — see commit for full body.)

- [ ] **Step 4: Wait for CI green**

```
gh pr checks $(gh pr view --json number -q .number) --watch
```

- [ ] **Step 5: Merge via API**

```
PR=$(gh pr view --json number -q .number)
gh api -X PUT repos/JaoChai/bot-fb/pulls/$PR/merge -f merge_method=squash
```

- [ ] **Step 6: Wait for Railway redeploy + verify metrics**

After ~5min, verify:
- Sentry: new CB events tagged `type=circuit_breaker` start appearing (or absence = system healthy)
- Lighthouse on https://www.botjao.com/: FCP improved vs 4.1s baseline

If FCP doesn't improve, the static skeleton isn't actually rendering — investigate Vite mount timing.
