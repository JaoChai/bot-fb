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
