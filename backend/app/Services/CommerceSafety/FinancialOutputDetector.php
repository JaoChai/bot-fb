<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;

final class FinancialOutputDetector
{
    public function detects(Bot $bot, string $text): bool
    {
        // Presence is the interlock, not linguistic intent. FAQ/negation false
        // positives deliberately fail closed until the C1 reply-quality work.
        if (preg_match('/โอน|ชำระ|จ่าย|เงินเข้า|ได้รับเงิน|รับเงิน|\b(?:pay|paid|payment|transfer|remit|received\s+money)\b/iu', $text) === 1) {
            return true;
        }
        foreach (array_filter([$bot->settings?->slip_receiver_account, '223-3-24880-3']) as $account) {
            $digits = preg_replace('/\D/', '', $account);
            if ($digits !== '' && str_contains(preg_replace('/[\s-]/u', '', $text), $digits)) {
                return true;
            }
        }

        return false;
    }
}
