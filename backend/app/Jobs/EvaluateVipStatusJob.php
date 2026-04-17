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

    public function __construct(public int $customerProfileId) {}

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
