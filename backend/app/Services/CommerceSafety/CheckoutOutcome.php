<?php

namespace App\Services\CommerceSafety;

use App\Models\CheckoutSession;

final readonly class CheckoutOutcome
{
    public function __construct(
        public string $action,
        public ?CheckoutSession $checkout,
        public ?string $customerText = null,
    ) {}
}
