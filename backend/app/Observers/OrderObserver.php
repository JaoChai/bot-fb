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

    public function updated(Order $order): void
    {
        if (! config('rag.vip.enabled', true)) {
            return;
        }

        // Only act when this update transitioned the row into 'completed'.
        if ($order->status !== 'completed') {
            return;
        }
        if ($order->getOriginal('status') === 'completed') {
            return; // already completed before; nothing new
        }
        if (! $order->customer_profile_id) {
            return;
        }

        EvaluateVipStatusJob::dispatch($order->customer_profile_id);
    }
}
