<?php

namespace Tests\Unit\Observers;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Order;
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

        $order = Order::factory()->completed()->create();

        (new OrderObserver)->created($order);

        Queue::assertPushed(EvaluateVipStatusJob::class, fn ($job) => $job->customerProfileId === $order->customer_profile_id);
    }

    public function test_does_not_dispatch_when_customer_profile_missing(): void
    {
        Queue::fake();

        $order = Order::factory()->completed()->create(['customer_profile_id' => null]);

        (new OrderObserver)->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }

    public function test_does_not_dispatch_when_status_is_not_completed(): void
    {
        Queue::fake();

        $order = Order::factory()->pending()->create();

        (new OrderObserver)->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }

    public function test_does_not_dispatch_when_feature_disabled(): void
    {
        Queue::fake();

        config(['rag.vip.enabled' => false]);

        $order = Order::factory()->completed()->create();

        (new OrderObserver)->created($order);

        Queue::assertNotPushed(EvaluateVipStatusJob::class);
    }
}
