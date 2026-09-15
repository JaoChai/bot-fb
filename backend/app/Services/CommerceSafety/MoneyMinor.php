<?php

namespace App\Services\CommerceSafety;

use InvalidArgumentException;

class MoneyMinor
{
    public static function fromDecimal(string $amount): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Amount must be a nonnegative plain decimal with at most two fractional digits.');
        }

        $whole = $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');
        $maxWhole = (string) intdiv(PHP_INT_MAX - $fraction, 100);

        if (strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new InvalidArgumentException('Amount exceeds the supported minor-unit range.');
        }

        return ((int) $whole * 100) + $fraction;
    }
}
