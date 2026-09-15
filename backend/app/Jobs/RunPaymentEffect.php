<?php

namespace App\Jobs;

use App\Services\CommerceSafety\PaymentEffectDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunPaymentEffect implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // The durable row/reconciler owns retries, not the queue driver's retry policy.
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $effectId) {}

    public function handle(PaymentEffectDispatcher $dispatcher): void
    {
        $dispatcher->run($this->effectId);
    }
}
