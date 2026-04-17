<?php

namespace Tests\Unit\Console;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\CustomerProfile;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VipBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_only_for_customers_at_or_above_threshold(): void
    {
        $qualifyingCustomer = CustomerProfile::factory()->create();
        $belowThresholdCustomer = CustomerProfile::factory()->create();

        // Qualifying customer: 3 completed orders (at threshold)
        Order::factory()->count(3)->create([
            'customer_profile_id' => $qualifyingCustomer->id,
            'status' => 'completed',
            'created_at' => now()->subMonths(3),
        ]);

        // Below-threshold customer: 2 completed orders
        Order::factory()->count(2)->create([
            'customer_profile_id' => $belowThresholdCustomer->id,
            'status' => 'completed',
            'created_at' => now()->subMonths(3),
        ]);

        // Start capturing jobs AFTER seeding, so observer-triggered jobs are excluded
        Queue::fake();

        $this->artisan('vip:backfill')->assertOk();

        Queue::assertPushed(EvaluateVipStatusJob::class, 1);

        Queue::assertPushed(
            EvaluateVipStatusJob::class,
            fn (EvaluateVipStatusJob $job) => $job->customerProfileId === $qualifyingCustomer->id
        );
    }

    public function test_dry_run_does_not_dispatch(): void
    {
        $customer = CustomerProfile::factory()->create();

        Order::factory()->count(3)->create([
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subMonths(3),
        ]);

        // Start capturing jobs AFTER seeding, so observer-triggered jobs are excluded
        Queue::fake();

        $this->artisan('vip:backfill', ['--dry-run' => true])->assertOk();

        Queue::assertNothingPushed();
    }
}
