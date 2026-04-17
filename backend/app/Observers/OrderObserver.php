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
