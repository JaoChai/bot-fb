# Auto-VIP Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically mark conversations as VIP (via `memory_notes`) when a customer has ≥3 confirmed completed orders, and expose a web dashboard for admins to review/override.

**Architecture:** `Order::created` event → `OrderObserver` → `EvaluateVipStatusJob` (queued, unique per customer) → `VipDetectionService` counts completed orders in last 12 months, aggregates top items, and upserts a `type=memory, source=vip_auto` note into every conversation of that customer. A new `VipController` + `VipManagementPage` lets admins list/revoke/promote VIPs. A `vip:backfill` Artisan command handles historical customers.

**Tech Stack:** Laravel 12 (Observer, Queue, Artisan), PHPUnit, React 19, React Query v5, Zustand, Tailwind v4, Vitest.

**Default parameters (locked):**
- Threshold: ≥3 completed orders (inclusive)
- Window: last 12 months (`vip.window_months`)
- Top items in note: 5 (`vip.top_n_items`)
- Scope: all conversations of the same `customer_profile_id` (cross-channel)
- Refund policy: not handled in this phase — count is based on `status='completed'` only
- Source flag: `source: 'vip_auto'` for automated; `'vip_manual'` for admin promote
- UI route: `/bots/:botId/vip` (bot-scoped, consistent with existing bot settings pattern)

---

## File Structure

**Backend — Create:**
- `backend/app/Services/VipDetectionService.php` — Core eligibility + note upsert logic
- `backend/app/Observers/OrderObserver.php` — Listens to `Order::created`, dispatches job
- `backend/app/Jobs/EvaluateVipStatusJob.php` — Async, `ShouldBeUnique` per customer
- `backend/app/Http/Controllers/Api/VipController.php` — List/revoke/promote endpoints
- `backend/app/Console/Commands/VipBackfillCommand.php` — `php artisan vip:backfill`
- `backend/tests/Unit/Services/VipDetectionServiceTest.php`
- `backend/tests/Unit/Observers/OrderObserverTest.php`
- `backend/tests/Unit/Jobs/EvaluateVipStatusJobTest.php`
- `backend/tests/Feature/VipControllerTest.php`
- `backend/tests/Unit/Console/VipBackfillCommandTest.php`

**Backend — Modify:**
- `backend/app/Providers/AppServiceProvider.php` — Register `Order::observe(OrderObserver::class)`
- `backend/config/rag.php` — Add `vip` config block
- `backend/routes/api.php` — Register VIP routes under `bots/{bot}` prefix

**Frontend — Create:**
- `frontend/src/hooks/useVipCustomers.ts` — React Query hooks (list, revoke, promote)
- `frontend/src/components/conversation/VipBadge.tsx` — Small badge for conversation view
- `frontend/src/pages/VipManagementPage.tsx` — Main admin UI

**Frontend — Modify:**
- `frontend/src/types/api.ts` — Add `VipCustomer` type
- `frontend/src/router.tsx` — Register `/bots/:botId/vip` route

**Config — Modify:**
- `backend/config/rag.php`

---

## Task 1: VipDetectionService (TDD)

**Files:**
- Create: `backend/app/Services/VipDetectionService.php`
- Create: `backend/tests/Unit/Services/VipDetectionServiceTest.php`

- [ ] **Step 1.1: Write failing test for below-threshold customer**

Create `backend/tests/Unit/Services/VipDetectionServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\VipDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VipDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected VipDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VipDetectionService::class);
    }

    public function test_returns_false_when_customer_has_fewer_than_threshold_orders(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        Order::factory()->count(2)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);

        $result = $this->service->evaluateCustomer($customer);

        $this->assertFalse($result);
        $this->assertEmpty($conversation->fresh()->memory_notes ?? []);
    }
}
```

- [ ] **Step 1.2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VipDetectionServiceTest`
Expected: FAIL — class `App\Services\VipDetectionService` not found

- [ ] **Step 1.3: Create minimal VipDetectionService to pass Step 1.1**

Create `backend/app/Services/VipDetectionService.php`:

```php
<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Order;

class VipDetectionService
{
    public function evaluateCustomer(CustomerProfile $customer): bool
    {
        $threshold = (int) config('rag.vip.threshold', 3);
        $windowMonths = (int) config('rag.vip.window_months', 12);

        $count = Order::where('customer_profile_id', $customer->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths($windowMonths))
            ->count();

        if ($count < $threshold) {
            return false;
        }

        return true; // full impl in next steps
    }
}
```

- [ ] **Step 1.4: Run test — expect pass**

Run: `cd backend && php artisan test --filter=test_returns_false_when_customer_has_fewer_than_threshold_orders`
Expected: PASS

- [ ] **Step 1.5: Add failing test for memory_note creation at threshold**

Append to `VipDetectionServiceTest.php`:

```php
public function test_creates_vip_memory_note_when_customer_has_three_or_more_orders(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);
    $customer = CustomerProfile::factory()->create(['display_name' => 'John Doe']);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'customer_profile_id' => $customer->id,
        'memory_notes' => [],
    ]);

    $orders = Order::factory()->count(3)->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
        'total_amount' => 1000,
    ]);

    foreach ($orders as $order) {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name' => 'Nolimit Personal',
            'category' => 'nolimit',
            'variant' => 'เติมเงิน',
            'quantity' => 1,
        ]);
    }

    $result = $this->service->evaluateCustomer($customer);

    $this->assertTrue($result);
    $notes = $conversation->fresh()->memory_notes;
    $this->assertCount(1, $notes);
    $this->assertEquals('memory', $notes[0]['type']);
    $this->assertEquals('vip_auto', $notes[0]['source']);
    $this->assertStringContainsString('ลูกค้า VIP', $notes[0]['content']);
    $this->assertStringContainsString('ซื้อยืนยันแล้ว 3 ครั้ง', $notes[0]['content']);
    $this->assertStringContainsString('Nolimit Personal', $notes[0]['content']);
}
```

- [ ] **Step 1.6: Run test — expect fail (note not written yet)**

Run: `cd backend && php artisan test --filter=test_creates_vip_memory_note_when_customer_has_three_or_more_orders`
Expected: FAIL — assertion on notes count=1 fails

- [ ] **Step 1.7: Implement full evaluateCustomer + note upsert**

Replace `backend/app/Services/VipDetectionService.php` entirely:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class VipDetectionService
{
    /**
     * Evaluate a customer and upsert a VIP memory note on all their conversations.
     * Returns true if the customer qualifies as VIP.
     */
    public function evaluateCustomer(CustomerProfile $customer): bool
    {
        $threshold = (int) config('rag.vip.threshold', 3);
        $windowMonths = (int) config('rag.vip.window_months', 12);
        $since = now()->subMonths($windowMonths);

        $stats = Order::where('customer_profile_id', $customer->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
            ->first();

        if ((int) $stats->c < $threshold) {
            return false;
        }

        $topItems = $this->getTopItems($customer->id, $since);
        $latest = $stats->last instanceof Carbon ? $stats->last : Carbon::parse($stats->last);
        $content = $this->buildVipNoteContent(
            (int) $stats->c,
            (float) $stats->total,
            $latest,
            $topItems
        );

        $customer->conversations()->each(function (Conversation $conversation) use ($content) {
            $this->upsertVipNote($conversation, $content, 'vip_auto');
        });

        return true;
    }

    /**
     * Manually promote a customer to VIP (admin action).
     * Uses source='vip_manual' so auto-detection won't overwrite.
     */
    public function manualPromote(CustomerProfile $customer, string $content): void
    {
        $customer->conversations()->each(function (Conversation $conversation) use ($content) {
            $this->upsertVipNote($conversation, $content, 'vip_manual');
        });
    }

    /**
     * Remove any automated VIP notes from all conversations of the customer.
     */
    public function revokeAutoVip(CustomerProfile $customer): int
    {
        $removed = 0;
        $customer->conversations()->each(function (Conversation $conversation) use (&$removed) {
            $notes = $this->normalizeNotes($conversation->memory_notes ?? []);
            $before = count($notes);
            $notes = collect($notes)
                ->reject(fn ($n) => ($n['source'] ?? null) === 'vip_auto')
                ->values()
                ->all();
            if (count($notes) !== $before) {
                $conversation->update(['memory_notes' => $notes]);
                $removed++;
            }
        });

        return $removed;
    }

    protected function getTopItems(int $customerProfileId, Carbon $since): Collection
    {
        $limit = (int) config('rag.vip.top_n_items', 5);

        return OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.customer_profile_id', $customerProfileId)
            ->where('orders.status', 'completed')
            ->where('orders.created_at', '>=', $since)
            ->selectRaw('order_items.product_name, order_items.variant, SUM(order_items.quantity) as qty')
            ->groupBy('order_items.product_name', 'order_items.variant')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();
    }

    protected function buildVipNoteContent(int $count, float $total, Carbon $latest, Collection $topItems): string
    {
        $lines = [];
        $lines[] = sprintf(
            'ลูกค้า VIP — ซื้อยืนยันแล้ว %d ครั้ง รวม %s บาท',
            $count,
            number_format($total, 0)
        );

        foreach ($topItems as $item) {
            $variantText = $item->variant ? " ({$item->variant})" : '';
            $lines[] = "• {$item->product_name}{$variantText} x{$item->qty}";
        }

        $lines[] = 'ล่าสุด: '.$latest->format('Y-m-d');

        return implode("\n", $lines);
    }

    protected function upsertVipNote(Conversation $conversation, string $content, string $source): void
    {
        $notes = $this->normalizeNotes($conversation->memory_notes ?? []);

        $existingIdx = null;
        foreach ($notes as $i => $note) {
            if (($note['source'] ?? null) === $source) {
                $existingIdx = $i;
                break;
            }
        }

        $now = now()->toISOString();

        if ($existingIdx === null) {
            $notes[] = [
                'id' => (string) Str::uuid(),
                'content' => $content,
                'type' => 'memory',
                'source' => $source,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        } else {
            $notes[$existingIdx]['content'] = $content;
            $notes[$existingIdx]['updated_at'] = $now;
            $notes[$existingIdx]['type'] = 'memory';
            $notes[$existingIdx]['source'] = $source;
        }

        $conversation->update(['memory_notes' => array_values($notes)]);
    }

    /**
     * Guard against legacy object format like {"vip": true} which some
     * conversations still have (see NoteService::getNotes for reference).
     */
    protected function normalizeNotes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        if (! empty($raw) && ! array_is_list($raw)) {
            return [];
        }

        return $raw;
    }
}
```

- [ ] **Step 1.8: Run test — expect pass**

Run: `cd backend && php artisan test --filter=VipDetectionServiceTest`
Expected: PASS (both tests)

- [ ] **Step 1.9: Add test for window enforcement (orders older than 12 months excluded)**

Append to `VipDetectionServiceTest.php`:

```php
public function test_ignores_orders_older_than_window(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);
    $customer = CustomerProfile::factory()->create();
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'customer_profile_id' => $customer->id,
    ]);

    // 2 recent + 3 old (>12 months) — should NOT qualify
    Order::factory()->count(2)->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
        'created_at' => now()->subMonths(2),
    ]);
    Order::factory()->count(3)->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
        'created_at' => now()->subMonths(13),
    ]);

    $this->assertFalse($this->service->evaluateCustomer($customer));
}
```

- [ ] **Step 1.10: Run test — expect pass**

Run: `cd backend && php artisan test --filter=test_ignores_orders_older_than_window`
Expected: PASS

- [ ] **Step 1.11: Add test for idempotency (second run updates, not duplicates)**

Append to `VipDetectionServiceTest.php`:

```php
public function test_second_evaluation_updates_note_instead_of_duplicating(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);
    $customer = CustomerProfile::factory()->create();
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'customer_profile_id' => $customer->id,
    ]);

    Order::factory()->count(3)->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
    ]);

    $this->service->evaluateCustomer($customer);

    // Add another order → re-evaluate
    Order::factory()->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
    ]);
    $this->service->evaluateCustomer($customer);

    $notes = $conversation->fresh()->memory_notes;
    $this->assertCount(1, $notes, 'Expected single vip_auto note after re-evaluation');
    $this->assertStringContainsString('ซื้อยืนยันแล้ว 4 ครั้ง', $notes[0]['content']);
}
```

- [ ] **Step 1.12: Run test — expect pass**

Run: `cd backend && php artisan test --filter=test_second_evaluation_updates_note_instead_of_duplicating`
Expected: PASS

- [ ] **Step 1.13: Add test for legacy object format guard**

Append to `VipDetectionServiceTest.php`:

```php
public function test_handles_legacy_object_format_memory_notes(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);
    $customer = CustomerProfile::factory()->create();
    // Simulate legacy format: object instead of array
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'customer_profile_id' => $customer->id,
        'memory_notes' => ['vip' => true, 'note' => 'old format'],
    ]);

    Order::factory()->count(3)->create([
        'bot_id' => $bot->id,
        'conversation_id' => $conversation->id,
        'customer_profile_id' => $customer->id,
        'status' => 'completed',
    ]);

    $result = $this->service->evaluateCustomer($customer);

    $this->assertTrue($result);
    $notes = $conversation->fresh()->memory_notes;
    $this->assertCount(1, $notes);
    $this->assertEquals('vip_auto', $notes[0]['source']);
}
```

- [ ] **Step 1.14: Run test — expect pass**

Run: `cd backend && php artisan test --filter=test_handles_legacy_object_format_memory_notes`
Expected: PASS (the `normalizeNotes` guard already handles this)

- [ ] **Step 1.15: Run code style check**

Run: `cd backend && vendor/bin/pint app/Services/VipDetectionService.php tests/Unit/Services/VipDetectionServiceTest.php`
Expected: 0 issues

- [ ] **Step 1.16: Commit**

```bash
git add backend/app/Services/VipDetectionService.php backend/tests/Unit/Services/VipDetectionServiceTest.php
git commit -m "feat(vip): add VipDetectionService for auto memory-note upsert"
```

---

## Task 2: Config block for VIP settings

**Files:**
- Modify: `backend/config/rag.php`

- [ ] **Step 2.1: Read current rag.php to find insertion point**

Run: `cd backend && grep -n "^];" config/rag.php | tail -1`
Expected: single line showing the closing bracket line number

- [ ] **Step 2.2: Add `vip` block before closing bracket**

Edit `backend/config/rag.php` — add the following block just before the final `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | VIP Auto-Detection
    |--------------------------------------------------------------------------
    |
    | Automatically marks customers as VIP via conversation memory_notes once
    | they reach `threshold` completed orders within the last `window_months`.
    | See VipDetectionService + OrderObserver for the pipeline.
    */
    'vip' => [
        'enabled' => env('VIP_AUTO_ENABLED', true),
        'threshold' => (int) env('VIP_ORDER_THRESHOLD', 3),
        'window_months' => (int) env('VIP_WINDOW_MONTHS', 12),
        'top_n_items' => (int) env('VIP_TOP_N_ITEMS', 5),
    ],
```

- [ ] **Step 2.3: Verify config is loadable**

Run: `cd backend && php artisan tinker --execute="echo config('rag.vip.threshold');"`
Expected: `3`

- [ ] **Step 2.4: Commit**

```bash
git add backend/config/rag.php
git commit -m "feat(vip): add rag.vip config block (threshold, window, top items)"
```

---

## Task 3: EvaluateVipStatusJob (TDD)

**Files:**
- Create: `backend/app/Jobs/EvaluateVipStatusJob.php`
- Create: `backend/tests/Unit/Jobs/EvaluateVipStatusJobTest.php`

- [ ] **Step 3.1: Write failing test**

Create `backend/tests/Unit/Jobs/EvaluateVipStatusJobTest.php`:

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\CustomerProfile;
use App\Services\VipDetectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvaluateVipStatusJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_implements_should_be_unique(): void
    {
        $this->assertInstanceOf(ShouldBeUnique::class, new EvaluateVipStatusJob(1));
    }

    public function test_unique_id_is_scoped_per_customer(): void
    {
        $job = new EvaluateVipStatusJob(42);
        $this->assertEquals('vip:42', $job->uniqueId());
    }

    public function test_handle_calls_evaluate_customer_when_customer_exists(): void
    {
        $customer = CustomerProfile::factory()->create();

        $mock = Mockery::mock(VipDetectionService::class);
        $mock->shouldReceive('evaluateCustomer')
            ->once()
            ->withArgs(fn ($arg) => $arg instanceof CustomerProfile && $arg->id === $customer->id)
            ->andReturn(true);
        $this->app->instance(VipDetectionService::class, $mock);

        (new EvaluateVipStatusJob($customer->id))->handle(app(VipDetectionService::class));
    }

    public function test_handle_is_noop_when_customer_not_found(): void
    {
        $mock = Mockery::mock(VipDetectionService::class);
        $mock->shouldNotReceive('evaluateCustomer');
        $this->app->instance(VipDetectionService::class, $mock);

        (new EvaluateVipStatusJob(999999))->handle(app(VipDetectionService::class));

        $this->assertTrue(true); // no exception = pass
    }
}
```

- [ ] **Step 3.2: Run test — expect fail**

Run: `cd backend && php artisan test --filter=EvaluateVipStatusJobTest`
Expected: FAIL — class `App\Jobs\EvaluateVipStatusJob` not found

- [ ] **Step 3.3: Create EvaluateVipStatusJob**

Create `backend/app/Jobs/EvaluateVipStatusJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\CustomerProfile;
use App\Services\VipDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluateVipStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Debounce window — within this many seconds a duplicate job is skipped. */
    public int $uniqueFor = 60;

    public int $tries = 3;

    public function __construct(public int $customerProfileId)
    {
    }

    public function uniqueId(): string
    {
        return "vip:{$this->customerProfileId}";
    }

    public function handle(VipDetectionService $service): void
    {
        $customer = CustomerProfile::find($this->customerProfileId);
        if (! $customer) {
            return;
        }

        try {
            $service->evaluateCustomer($customer);
        } catch (\Throwable $e) {
            Log::warning('EvaluateVipStatusJob failed', [
                'customer_profile_id' => $this->customerProfileId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

- [ ] **Step 3.4: Run test — expect pass**

Run: `cd backend && php artisan test --filter=EvaluateVipStatusJobTest`
Expected: PASS

- [ ] **Step 3.5: Run pint**

Run: `cd backend && vendor/bin/pint app/Jobs/EvaluateVipStatusJob.php tests/Unit/Jobs/EvaluateVipStatusJobTest.php`
Expected: 0 issues

- [ ] **Step 3.6: Commit**

```bash
git add backend/app/Jobs/EvaluateVipStatusJob.php backend/tests/Unit/Jobs/EvaluateVipStatusJobTest.php
git commit -m "feat(vip): add EvaluateVipStatusJob with 60s unique debounce"
```

---

## Task 4: OrderObserver (TDD)

**Files:**
- Create: `backend/app/Observers/OrderObserver.php`
- Create: `backend/tests/Unit/Observers/OrderObserverTest.php`

- [ ] **Step 4.1: Write failing test**

Create `backend/tests/Unit/Observers/OrderObserverTest.php`:

```php
<?php

namespace Tests\Unit\Observers;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use App\Observers\OrderObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_when_order_is_created_with_completed_status(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        $order = Order::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'total_amount' => 1000,
            'status' => 'completed',
            'channel_type' => 'line',
        ]);

        (new OrderObserver())->created($order);

        Queue::assertPushed(EvaluateVipStatusJob::class, function ($job) use ($customer) {
            return $job->customerProfileId === $customer->id;
        });
    }

    public function test_does_not_dispatch_when_customer_profile_missing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        $order = Order::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => null,
            'total_amount' => 500,
            'status' => 'completed',
            'channel_type' => 'line',
        ]);

        (new OrderObserver())->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }

    public function test_does_not_dispatch_when_status_is_not_completed(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        $order = Order::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'total_amount' => 100,
            'status' => 'pending',
            'channel_type' => 'line',
        ]);

        (new OrderObserver())->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }

    public function test_does_not_dispatch_when_feature_disabled(): void
    {
        Queue::fake();
        config(['rag.vip.enabled' => false]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        $order = Order::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'total_amount' => 1000,
            'status' => 'completed',
            'channel_type' => 'line',
        ]);

        (new OrderObserver())->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }
}
```

- [ ] **Step 4.2: Run test — expect fail**

Run: `cd backend && php artisan test --filter=OrderObserverTest`
Expected: FAIL — class `App\Observers\OrderObserver` not found

- [ ] **Step 4.3: Create OrderObserver**

Create `backend/app/Observers/OrderObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Order;

class OrderObserver
{
    public function created(Order $order): void
    {
        if (! config('rag.vip.enabled', true)) {
            return;
        }

        if ($order->status !== 'completed') {
            return;
        }

        if (! $order->customer_profile_id) {
            return;
        }

        EvaluateVipStatusJob::dispatch($order->customer_profile_id);
    }
}
```

- [ ] **Step 4.4: Run test — expect pass**

Run: `cd backend && php artisan test --filter=OrderObserverTest`
Expected: PASS (all 4 cases)

- [ ] **Step 4.5: Pint**

Run: `cd backend && vendor/bin/pint app/Observers/OrderObserver.php tests/Unit/Observers/OrderObserverTest.php`
Expected: 0 issues

- [ ] **Step 4.6: Commit**

```bash
git add backend/app/Observers/OrderObserver.php backend/tests/Unit/Observers/OrderObserverTest.php
git commit -m "feat(vip): add OrderObserver to trigger VIP evaluation on completed orders"
```

---

## Task 5: Register Observer in AppServiceProvider

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`

- [ ] **Step 5.1: Add use import for Order + OrderObserver**

Edit `backend/app/Providers/AppServiceProvider.php` — add after the existing `use App\Models\...` imports (around line 8):

```php
use App\Models\Order;
use App\Observers\OrderObserver;
```

- [ ] **Step 5.2: Register observer in boot()**

Inside `boot()` method of `AppServiceProvider.php`, add after the `Gate::policy(...)` lines (around line 164):

```php
        Order::observe(OrderObserver::class);
```

- [ ] **Step 5.3: Verify observer fires end-to-end**

Run: `cd backend && php artisan tinker --execute="
use App\Models\Order;
use App\Jobs\EvaluateVipStatusJob;
use Illuminate\Support\Facades\Queue;
Queue::fake();
\$order = Order::factory()->create(['status' => 'completed']);
echo Queue::pushed(EvaluateVipStatusJob::class)->count() > 0 ? 'OK' : 'FAIL';
"`
Expected: `OK` (or no output + no exception — since tinker may behave differently; if unclear, rely on test suite)

- [ ] **Step 5.4: Run entire unit test suite to confirm no regressions**

Run: `cd backend && php artisan test --testsuite=Unit`
Expected: All tests pass

- [ ] **Step 5.5: Pint**

Run: `cd backend && vendor/bin/pint app/Providers/AppServiceProvider.php`
Expected: 0 issues

- [ ] **Step 5.6: Commit**

```bash
git add backend/app/Providers/AppServiceProvider.php
git commit -m "feat(vip): wire OrderObserver into AppServiceProvider boot"
```

---

## Task 6: VipController — API endpoints (TDD)

**Files:**
- Create: `backend/app/Http/Controllers/Api/VipController.php`
- Create: `backend/tests/Feature/VipControllerTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 6.1: Register routes first (easier to TDD controller through routes)**

Edit `backend/routes/api.php`. Find the existing bot-scoped group (looks like `Route::prefix('bots/{bot}')->group(function () {`). Add these routes inside that group:

```php
        // VIP management routes
        Route::prefix('vip')->group(function () {
            Route::get('/customers', [\App\Http\Controllers\Api\VipController::class, 'index'])
                ->name('vip.customers.index');
            Route::post('/customers/{customerProfile}/revoke', [\App\Http\Controllers\Api\VipController::class, 'revoke'])
                ->name('vip.customers.revoke');
            Route::post('/customers/{customerProfile}/promote', [\App\Http\Controllers\Api\VipController::class, 'promote'])
                ->name('vip.customers.promote');
        });
```

- [ ] **Step 6.2: Verify routes registered (will 404 until controller exists, but should not crash)**

Run: `cd backend && php artisan route:list --path=vip 2>&1 | head -20`
Expected: lists the 3 VIP routes (resolution of controller class may error — that's fine, we just need the routes wired)

- [ ] **Step 6.3: Write failing feature test for index endpoint**

Create `backend/tests/Feature/VipControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VipControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_customers_with_vip_auto_notes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create(['display_name' => 'Alice']);
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [[
                'id' => '00000000-0000-0000-0000-000000000001',
                'content' => 'ลูกค้า VIP — ซื้อยืนยันแล้ว 3 ครั้ง',
                'type' => 'memory',
                'source' => 'vip_auto',
                'created_by' => null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
        ]);
        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'total_amount' => 1000,
        ]);

        $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

        $response->assertOk();
        $response->assertJsonFragment(['display_name' => 'Alice']);
        $response->assertJsonFragment(['note_source' => 'vip_auto']);
    }

    public function test_index_rejects_unauthorized_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

        $response->assertForbidden();
    }

    public function test_revoke_removes_vip_auto_note(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [[
                'id' => '00000000-0000-0000-0000-000000000002',
                'content' => 'ลูกค้า VIP',
                'type' => 'memory',
                'source' => 'vip_auto',
                'created_by' => null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/vip/customers/{$customer->id}/revoke");

        $response->assertOk();
        $this->assertEmpty($conv->fresh()->memory_notes);
    }

    public function test_promote_creates_vip_manual_note(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [],
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/vip/customers/{$customer->id}/promote", [
            'content' => 'ลูกค้า VIP (ตั้งด้วย admin)',
        ]);

        $response->assertOk();
        $notes = $conv->fresh()->memory_notes;
        $this->assertCount(1, $notes);
        $this->assertEquals('vip_manual', $notes[0]['source']);
        $this->assertEquals('ลูกค้า VIP (ตั้งด้วย admin)', $notes[0]['content']);
    }
}
```

- [ ] **Step 6.4: Run test — expect fail**

Run: `cd backend && php artisan test --filter=VipControllerTest`
Expected: FAIL — controller class not found

- [ ] **Step 6.5: Create VipController**

Create `backend/app/Http/Controllers/Api/VipController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Services\VipDetectionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VipController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private VipDetectionService $vipService)
    {
    }

    /**
     * List all VIP customers (auto + manual) for a given bot.
     * Aggregates by customer_profile_id across all their conversations.
     */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $conversations = Conversation::where('bot_id', $bot->id)
            ->whereNotNull('customer_profile_id')
            ->with('customerProfile')
            ->get();

        $rows = [];
        $seen = [];
        foreach ($conversations as $conv) {
            $note = $this->findVipNote($conv->memory_notes ?? []);
            if (! $note) {
                continue;
            }
            $cpId = $conv->customer_profile_id;
            if (isset($seen[$cpId])) {
                continue;
            }
            $seen[$cpId] = true;

            // Recompute stats (same query shape as VipDetectionService)
            $stats = \App\Models\Order::where('customer_profile_id', $cpId)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
                ->first();

            $rows[] = [
                'customer_profile_id' => $cpId,
                'display_name' => $conv->customerProfile?->display_name,
                'picture_url' => $conv->customerProfile?->picture_url,
                'channel_type' => $conv->customerProfile?->channel_type,
                'order_count' => (int) $stats->c,
                'total_amount' => (float) $stats->total,
                'last_order_at' => $stats->last,
                'note_content' => $note['content'],
                'note_source' => $note['source'] ?? 'vip_auto',
                'bot_id' => $bot->id,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function revoke(Request $request, Bot $bot, CustomerProfile $customerProfile): JsonResponse
    {
        $this->authorize('update', $bot);

        $removed = $this->vipService->revokeAutoVip($customerProfile);

        return response()->json([
            'message' => 'VIP status revoked',
            'conversations_updated' => $removed,
        ]);
    }

    public function promote(Request $request, Bot $bot, CustomerProfile $customerProfile): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $this->vipService->manualPromote($customerProfile, $validated['content']);

        return response()->json(['message' => 'VIP promoted manually']);
    }

    protected function findVipNote(mixed $notes): ?array
    {
        if (! is_array($notes) || (! empty($notes) && ! array_is_list($notes))) {
            return null;
        }

        foreach ($notes as $note) {
            $source = $note['source'] ?? null;
            if (in_array($source, ['vip_auto', 'vip_manual'], true)) {
                return $note;
            }
        }

        return null;
    }
}
```

- [ ] **Step 6.6: Run tests — expect pass**

Run: `cd backend && php artisan test --filter=VipControllerTest`
Expected: PASS (all 4 cases)

- [ ] **Step 6.7: Pint**

Run: `cd backend && vendor/bin/pint app/Http/Controllers/Api/VipController.php backend/tests/Feature/VipControllerTest.php backend/routes/api.php`
Expected: 0 issues

- [ ] **Step 6.8: Commit**

```bash
git add backend/app/Http/Controllers/Api/VipController.php backend/tests/Feature/VipControllerTest.php backend/routes/api.php
git commit -m "feat(vip): add VipController with list/revoke/promote endpoints"
```

---

## Task 7: VipBackfillCommand (TDD)

**Files:**
- Create: `backend/app/Console/Commands/VipBackfillCommand.php`
- Create: `backend/tests/Unit/Console/VipBackfillCommandTest.php`

- [ ] **Step 7.1: Write failing test**

Create `backend/tests/Unit/Console/VipBackfillCommandTest.php`:

```php
<?php

namespace Tests\Unit\Console;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VipBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_only_for_customers_at_or_above_threshold(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $qualifier = CustomerProfile::factory()->create();
        $nonQualifier = CustomerProfile::factory()->create();

        $conv1 = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_profile_id' => $qualifier->id]);
        $conv2 = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_profile_id' => $nonQualifier->id]);

        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv1->id,
            'customer_profile_id' => $qualifier->id,
            'status' => 'completed',
        ]);
        Order::factory()->count(2)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv2->id,
            'customer_profile_id' => $nonQualifier->id,
            'status' => 'completed',
        ]);

        $this->artisan('vip:backfill')->assertOk();

        Queue::assertPushed(EvaluateVipStatusJob::class, 1);
        Queue::assertPushed(EvaluateVipStatusJob::class, fn ($job) => $job->customerProfileId === $qualifier->id);
    }

    public function test_dry_run_does_not_dispatch(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conv = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_profile_id' => $customer->id]);
        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);

        $this->artisan('vip:backfill', ['--dry-run' => true])->assertOk();

        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 7.2: Run — expect fail**

Run: `cd backend && php artisan test --filter=VipBackfillCommandTest`
Expected: FAIL — command not registered / class not found

- [ ] **Step 7.3: Create command**

Create `backend/app/Console/Commands/VipBackfillCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Order;
use Illuminate\Console\Command;

class VipBackfillCommand extends Command
{
    protected $signature = 'vip:backfill {--dry-run : Only report candidates, do not dispatch jobs}';

    protected $description = 'Backfill VIP memory notes for customers with >= threshold completed orders.';

    public function handle(): int
    {
        $threshold = (int) config('rag.vip.threshold', 3);
        $windowMonths = (int) config('rag.vip.window_months', 12);
        $since = now()->subMonths($windowMonths);

        $candidates = Order::where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('customer_profile_id')
            ->groupBy('customer_profile_id')
            ->selectRaw('customer_profile_id, COUNT(*) as c')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->pluck('customer_profile_id');

        $this->info("Found {$candidates->count()} VIP candidates (threshold={$threshold}, window={$windowMonths}mo).");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no jobs dispatched.');

            return self::SUCCESS;
        }

        foreach ($candidates as $id) {
            EvaluateVipStatusJob::dispatch((int) $id);
        }

        $this->info("Dispatched {$candidates->count()} evaluation jobs.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7.4: Run — expect pass**

Run: `cd backend && php artisan test --filter=VipBackfillCommandTest`
Expected: PASS (both cases)

- [ ] **Step 7.5: Pint**

Run: `cd backend && vendor/bin/pint app/Console/Commands/VipBackfillCommand.php tests/Unit/Console/VipBackfillCommandTest.php`
Expected: 0 issues

- [ ] **Step 7.6: Commit**

```bash
git add backend/app/Console/Commands/VipBackfillCommand.php backend/tests/Unit/Console/VipBackfillCommandTest.php
git commit -m "feat(vip): add vip:backfill artisan command"
```

---

## Task 8: Frontend VipCustomer type

**Files:**
- Modify: `frontend/src/types/api.ts`

- [ ] **Step 8.1: Add VipCustomer interface**

Edit `frontend/src/types/api.ts`. Add the following interface near other customer-related types (e.g., near `CustomerProfile` if it exists, else at the end of the file):

```typescript
export interface VipCustomer {
  customer_profile_id: number;
  display_name: string | null;
  picture_url: string | null;
  channel_type: string | null;
  order_count: number;
  total_amount: number;
  last_order_at: string | null;
  note_content: string;
  note_source: 'vip_auto' | 'vip_manual';
  bot_id: number;
}

export interface VipCustomersResponse {
  data: VipCustomer[];
}
```

- [ ] **Step 8.2: TypeScript check**

Run: `cd frontend && npx tsc --noEmit`
Expected: 0 errors

- [ ] **Step 8.3: Commit**

```bash
git add frontend/src/types/api.ts
git commit -m "feat(vip): add VipCustomer type to frontend API types"
```

---

## Task 9: useVipCustomers hook

**Files:**
- Create: `frontend/src/hooks/useVipCustomers.ts`

- [ ] **Step 9.1: Read current useConversations hook to match patterns**

Run: `cd frontend && head -80 src/hooks/useConversations.ts`
Note: mimic its use of `useQuery` / `useMutation` / `queryClient.invalidateQueries` patterns.

- [ ] **Step 9.2: Create hook**

Create `frontend/src/hooks/useVipCustomers.ts`:

```typescript
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { VipCustomer, VipCustomersResponse } from '@/types/api';

const vipQueryKey = (botId: number | string) => ['vip-customers', String(botId)] as const;

export function useVipCustomers(botId: number | string | undefined) {
  return useQuery({
    queryKey: vipQueryKey(botId ?? 'none'),
    queryFn: async (): Promise<VipCustomer[]> => {
      const { data } = await api.get<VipCustomersResponse>(`/bots/${botId}/vip/customers`);
      return data.data;
    },
    enabled: Boolean(botId),
    staleTime: 30_000,
  });
}

export function useRevokeVip(botId: number | string | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (customerProfileId: number) => {
      const { data } = await api.post(
        `/bots/${botId}/vip/customers/${customerProfileId}/revoke`,
      );
      return data;
    },
    onSuccess: () => {
      if (botId !== undefined) {
        queryClient.invalidateQueries({ queryKey: vipQueryKey(botId) });
      }
    },
  });
}

export function usePromoteVip(botId: number | string | undefined) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { customerProfileId: number; content: string }) => {
      const { data } = await api.post(
        `/bots/${botId}/vip/customers/${payload.customerProfileId}/promote`,
        { content: payload.content },
      );
      return data;
    },
    onSuccess: () => {
      if (botId !== undefined) {
        queryClient.invalidateQueries({ queryKey: vipQueryKey(botId) });
      }
    },
  });
}
```

- [ ] **Step 9.3: TypeScript + lint check**

Run: `cd frontend && npx tsc --noEmit && npm run lint -- src/hooks/useVipCustomers.ts`
Expected: 0 errors, 0 warnings

- [ ] **Step 9.4: Commit**

```bash
git add frontend/src/hooks/useVipCustomers.ts
git commit -m "feat(vip): add useVipCustomers / useRevokeVip / usePromoteVip hooks"
```

---

## Task 10: VipBadge component

**Files:**
- Create: `frontend/src/components/conversation/VipBadge.tsx`

- [ ] **Step 10.1: Create component**

Create `frontend/src/components/conversation/VipBadge.tsx`:

```tsx
import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

type Variant = 'auto' | 'manual';

interface VipBadgeProps {
  variant?: Variant;
  className?: string;
  onClick?: () => void;
  tooltipContent?: string;
}

export function VipBadge({
  variant = 'auto',
  className,
  onClick,
  tooltipContent,
}: VipBadgeProps) {
  const colorClasses =
    variant === 'manual'
      ? 'bg-purple-100 text-purple-800 border-purple-300'
      : 'bg-amber-100 text-amber-800 border-amber-300';

  return (
    <button
      type="button"
      onClick={onClick}
      title={tooltipContent ?? (variant === 'manual' ? 'VIP (Manual)' : 'VIP (Auto)')}
      className={cn(
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
        colorClasses,
        onClick && 'cursor-pointer hover:opacity-80',
        className,
      )}
    >
      <Star className="h-3 w-3 fill-current" />
      <span>VIP</span>
    </button>
  );
}
```

- [ ] **Step 10.2: TypeScript + lint check**

Run: `cd frontend && npx tsc --noEmit && npm run lint -- src/components/conversation/VipBadge.tsx`
Expected: 0 errors, 0 warnings

- [ ] **Step 10.3: Commit**

```bash
git add frontend/src/components/conversation/VipBadge.tsx
git commit -m "feat(vip): add VipBadge component (auto/manual variants)"
```

---

## Task 11: VipManagementPage

**Files:**
- Create: `frontend/src/pages/VipManagementPage.tsx`

- [ ] **Step 11.1: Create page**

Create `frontend/src/pages/VipManagementPage.tsx`:

```tsx
import { useState } from 'react';
import { useParams } from 'react-router';
import { useVipCustomers, useRevokeVip, usePromoteVip } from '@/hooks/useVipCustomers';
import { VipBadge } from '@/components/conversation/VipBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

export default function VipManagementPage() {
  const { botId } = useParams<{ botId: string }>();
  const { data: vips, isLoading, error } = useVipCustomers(botId);
  const revokeMutation = useRevokeVip(botId);
  const promoteMutation = usePromoteVip(botId);
  const [promoteForm, setPromoteForm] = useState({ customerProfileId: '', content: '' });

  if (isLoading) {
    return <div className="p-6 text-muted-foreground">กำลังโหลด...</div>;
  }
  if (error) {
    return <div className="p-6 text-destructive">เกิดข้อผิดพลาด: {String(error)}</div>;
  }

  const total = vips?.length ?? 0;
  const autoCount = vips?.filter((v) => v.note_source === 'vip_auto').length ?? 0;
  const manualCount = total - autoCount;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">ลูกค้า VIP</h1>
        <p className="text-sm text-muted-foreground">
          ระบบจะเพิ่ม VIP ให้ลูกค้าอัตโนมัติเมื่อมียอดชำระยืนยันตั้งแต่ 3 ครั้งขึ้นไป
        </p>
      </div>

      <div className="grid grid-cols-3 gap-4">
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-sm">ทั้งหมด</CardTitle></CardHeader>
          <CardContent className="text-2xl font-bold">{total}</CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-sm">อัตโนมัติ</CardTitle></CardHeader>
          <CardContent className="text-2xl font-bold">{autoCount}</CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-sm">Manual</CardTitle></CardHeader>
          <CardContent className="text-2xl font-bold">{manualCount}</CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle>Manual Promote</CardTitle></CardHeader>
        <CardContent className="flex gap-2">
          <Input
            type="number"
            placeholder="customer_profile_id"
            value={promoteForm.customerProfileId}
            onChange={(e) => setPromoteForm((p) => ({ ...p, customerProfileId: e.target.value }))}
          />
          <Input
            placeholder="เนื้อหา note"
            value={promoteForm.content}
            onChange={(e) => setPromoteForm((p) => ({ ...p, content: e.target.value }))}
            className="flex-1"
          />
          <Button
            disabled={!promoteForm.customerProfileId || !promoteForm.content || promoteMutation.isPending}
            onClick={() => {
              promoteMutation.mutate({
                customerProfileId: Number(promoteForm.customerProfileId),
                content: promoteForm.content,
              });
              setPromoteForm({ customerProfileId: '', content: '' });
            }}
          >
            Promote
          </Button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>รายการ VIP</CardTitle></CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>ลูกค้า</TableHead>
                <TableHead>ประเภท</TableHead>
                <TableHead>จำนวน orders</TableHead>
                <TableHead>ยอดรวม</TableHead>
                <TableHead>ล่าสุด</TableHead>
                <TableHead>Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {vips?.map((vip) => (
                <TableRow key={vip.customer_profile_id}>
                  <TableCell className="font-medium">
                    {vip.display_name ?? `#${vip.customer_profile_id}`}
                  </TableCell>
                  <TableCell>
                    <VipBadge variant={vip.note_source === 'vip_manual' ? 'manual' : 'auto'} />
                  </TableCell>
                  <TableCell>{vip.order_count}</TableCell>
                  <TableCell>{vip.total_amount.toLocaleString()} บาท</TableCell>
                  <TableCell>
                    {vip.last_order_at ? new Date(vip.last_order_at).toLocaleDateString('th-TH') : '-'}
                  </TableCell>
                  <TableCell>
                    {vip.note_source === 'vip_auto' && (
                      <Button
                        size="sm"
                        variant="destructive"
                        disabled={revokeMutation.isPending}
                        onClick={() => revokeMutation.mutate(vip.customer_profile_id)}
                      >
                        Revoke
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
              {(!vips || vips.length === 0) && (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground">
                    ยังไม่มี VIP
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 11.2: TypeScript + lint check**

Run: `cd frontend && npx tsc --noEmit && npm run lint -- src/pages/VipManagementPage.tsx`
Expected: 0 errors, 0 warnings

- [ ] **Step 11.3: Commit**

```bash
git add frontend/src/pages/VipManagementPage.tsx
git commit -m "feat(vip): add VipManagementPage (list, revoke, manual promote)"
```

---

## Task 12: Register route in router

**Files:**
- Modify: `frontend/src/router.tsx`

- [ ] **Step 12.1: Add lazy import**

Edit `frontend/src/router.tsx` — add after the other lazy imports (around line 28):

```typescript
const VipManagementPage = lazyWithRetryNamed(() => import("@/pages/VipManagementPage"), "VipManagementPage")
```

- [ ] **Step 12.2: Add route entry**

In `router.tsx`, find the `bots/:botId/settings` route (around line 93). Add a new entry right after it inside the same children array:

```tsx
          {
            path: "bots/:botId/vip",
            element: <LazyPage><VipManagementPage /></LazyPage>,
          },
```

- [ ] **Step 12.3: Make the page component a default export**

Verify `VipManagementPage.tsx` uses `export default function VipManagementPage()` (already done in Task 11). If it's a named export, the lazy import will fail — `lazyWithRetryNamed` expects a named export matching the second argument. Re-read Task 11 step 11.1 and confirm:

```tsx
export default function VipManagementPage() { ... }
```

If the existing `lazyWithRetryNamed` pattern uses a named export, refactor to:

```tsx
export function VipManagementPage() { ... }
```

Check how other pages do it:

Run: `cd frontend && grep -n "^export" src/pages/OrdersPage.tsx`
Expected: `export function OrdersPage()` — NAMED export

Since existing pages use named exports, update Task 11's component to:

```tsx
export function VipManagementPage() { ... }
```

Then this import works:

```typescript
const VipManagementPage = lazyWithRetryNamed(() => import("@/pages/VipManagementPage"), "VipManagementPage")
```

- [ ] **Step 12.4: Run frontend build**

Run: `cd frontend && npm run build`
Expected: Build succeeds

- [ ] **Step 12.5: Commit**

```bash
git add frontend/src/router.tsx frontend/src/pages/VipManagementPage.tsx
git commit -m "feat(vip): register /bots/:botId/vip route"
```

---

## Task 13: End-to-end verification

**Files:** (none modified — verification only)

- [ ] **Step 13.1: Run full backend test suite**

Run: `cd backend && php artisan test`
Expected: All tests pass (no regressions, 4 new test files + existing tests)

- [ ] **Step 13.2: Run full pint check**

Run: `cd backend && vendor/bin/pint --test`
Expected: 0 issues

- [ ] **Step 13.3: Run full frontend checks**

Run: `cd frontend && npx tsc --noEmit && npm run lint && npm run build`
Expected: 0 TypeScript errors, 0 lint errors, build succeeds

- [ ] **Step 13.4: Manual smoke test — create test order in local DB**

Start backend + frontend, then:

```bash
cd backend && php artisan tinker
```

Inside tinker:

```php
use App\Models\{Bot, CustomerProfile, Conversation, Order, OrderItem};
$bot = Bot::first();
$customer = CustomerProfile::factory()->create(['display_name' => 'Test VIP']);
$conv = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_profile_id' => $customer->id]);
foreach (range(1,3) as $i) {
    $o = Order::create(['bot_id' => $bot->id, 'conversation_id' => $conv->id, 'customer_profile_id' => $customer->id, 'total_amount' => 1000, 'status' => 'completed', 'channel_type' => 'line']);
    OrderItem::factory()->create(['order_id' => $o->id, 'product_name' => 'Nolimit Personal']);
}
// Sync queue mode: immediate execution
dispatch_sync(new \App\Jobs\EvaluateVipStatusJob($customer->id));
echo $conv->fresh()->memory_notes[0]['content'];
```

Expected output includes: `ลูกค้า VIP — ซื้อยืนยันแล้ว 3 ครั้ง`

- [ ] **Step 13.5: Manual UI check**

Open browser at `http://localhost:5173/bots/{bot_id}/vip` (replace with actual bot id). Expected:
- See "Test VIP" in the table
- Revoke button visible
- Clicking Revoke removes the row

- [ ] **Step 13.6: Run backfill dry-run against local DB**

Run: `cd backend && php artisan vip:backfill --dry-run`
Expected: reports candidate count, does not dispatch anything

- [ ] **Step 13.7: Commit any fix-up changes**

If any minor fix-ups were needed during verification, commit them separately:

```bash
git status
# If changes exist:
git add <changed files>
git commit -m "fix(vip): <specific fix>"
```

- [ ] **Step 13.8: Create PR**

```bash
git push -u origin feature/auto-vip-detection
gh pr create --title "feat(vip): auto-VIP detection from confirmed orders" --body "$(cat <<'EOF'
## Summary

- Automatically marks conversations as VIP via memory_notes once customer has ≥3 completed orders in last 12 months
- New Dashboard page at /bots/:botId/vip for admins to list/revoke/promote VIPs
- New artisan command `vip:backfill` for one-time backfill of historical customers

## Architecture

Order::created → OrderObserver → EvaluateVipStatusJob (ShouldBeUnique, debounce 60s) → VipDetectionService → upsert memory_note (type=memory, source=vip_auto) across all conversations of the customer.

## Config

- `rag.vip.enabled` (default true)
- `rag.vip.threshold` (default 3)
- `rag.vip.window_months` (default 12)
- `rag.vip.top_n_items` (default 5)

## Test plan

- [ ] Backend unit + feature tests all pass
- [ ] Pint clean
- [ ] Frontend tsc + lint + build clean
- [ ] Manual verification: create 3 test orders → note appears
- [ ] Manual verification: revoke button removes note
- [ ] Manual verification: manual promote creates vip_manual note
- [ ] `vip:backfill --dry-run` reports sensible count

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review

**1. Spec coverage:**

| Spec requirement | Implementing task |
|------------------|-------------------|
| Auto-detect VIP ≥3 orders | Task 1 (VipDetectionService) + Task 4 (Observer) |
| Based on payment confirmation | Task 4 — only triggers on `status='completed'` (order gets created only after FlowPluginService verifies slip) |
| Items history in note | Task 1 Step 1.7 — `getTopItems()` + `buildVipNoteContent()` |
| Cross-conversation propagation | Task 1 — `evaluateCustomer()` iterates `$customer->conversations` |
| Legacy format guard | Task 1 Steps 1.13-1.14 + `normalizeNotes()` |
| Dashboard UI | Tasks 9-12 |
| Backfill old customers | Task 7 |
| Idempotency | Task 1 Step 1.11 + `upsertVipNote` (update when source matches) |
| Manual override (promote/revoke) | Task 6 (controller) + Task 11 (UI) |

No gaps.

**2. Placeholder scan:**

Searched plan for "TBD", "TODO", "implement later", "similar to", "add appropriate". None found. All code blocks are complete.

**3. Type consistency:**

- `evaluateCustomer(CustomerProfile)`, `manualPromote(CustomerProfile, string)`, `revokeAutoVip(CustomerProfile)` — consistent across Tasks 1, 6, 7
- `EvaluateVipStatusJob` constructor `(int $customerProfileId)` — consistent with dispatches in Observer (Task 4), Backfill (Task 7)
- `VipCustomer` type fields (frontend) match controller output keys (backend Task 6 `$rows[] = [...]`)
- `source` enum: `'vip_auto' | 'vip_manual'` — consistent in service, controller, type, component, page

All consistent.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-04-17-auto-vip-detection.md`. Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
