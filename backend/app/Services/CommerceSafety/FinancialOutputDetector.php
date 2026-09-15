<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;

final class FinancialOutputDetector
{
    public function detects(Bot $bot, string $text): bool
    {
        foreach (array_filter([$bot->settings?->slip_receiver_account, '223-3-24880-3']) as $account) {
            if (str_contains($text, $account)) {
                return true;
            }
            $digits = preg_replace('/\D/', '', $account);
            if ($digits !== '' && str_contains(preg_replace('/[\s-]/u', '', $text), $digits)) {
                return true;
            }
        }

        $instruction = preg_match('/(?:โอน|ชำระ|จ่าย|\bpay\b|\btransfer\b)/iu', $text);
        $amount = preg_match('/(?:\d[\d,]*(?:\.\d+)?\s*(?:บาท|THB|฿)|(?:THB|฿)\s*\d)/iu', $text);

        return $instruction === 1 && $amount === 1;
    }
}
