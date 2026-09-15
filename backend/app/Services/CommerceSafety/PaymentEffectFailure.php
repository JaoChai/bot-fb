<?php

namespace App\Services\CommerceSafety;

/** Only fixed, safe codes cross the effect audit boundary. Never provider exception text. */
class PaymentEffectFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $ambiguous = false)
    {
        parent::__construct($errorCode);
    }
}
