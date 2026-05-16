<?php

namespace App\Services\LineWebhook;

enum GateDecision: string
{
    case ALLOW = 'allow';
    case RATE_LIMITED = 'rate_limited';
    case OUTSIDE_HOURS = 'outside_hours';

    public function isBlocked(): bool
    {
        return $this !== self::ALLOW;
    }
}
