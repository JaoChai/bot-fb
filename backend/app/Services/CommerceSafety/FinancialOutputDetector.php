<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;

final class FinancialOutputDetector
{
    public function detects(Bot $bot, string $text): bool
    {
        $directive = preg_match(
            '/(?:^|[\r\n.!?]\s*|(?:กรุณา|โปรด|รบกวน|ช่วย|ให้|สามารถ|คุณสามารถ|ลูกค้าสามารถ|ทำการ)\s*)(?:โอน(?:เงิน)?|ชำระ(?:เงิน)?|จ่าย(?:เงิน)?)|(?:^|[\r\n.!?]\s*|(?:(?:please|kindly|you\s+(?:can|may|should|must)|go\s+ahead\s+and)\s+)+)(?:(?:pay|transfer|remit)\b|make\s+(?:a\s+)?payment\b)/iu',
            $text,
        ) === 1;

        if ($this->containsKnownAccount($bot, $text)) {
            $negated = preg_match(
                '/(?:ยัง\s*)?(?:ไม่ต้อง|ห้าม|อย่า|งด|ไม่ควร)\s*(?:โอน(?:เงิน)?|ชำระ(?:เงิน)?|จ่าย(?:เงิน)?)|\b(?:do\s+not|don[’\']t|should\s+not|shouldn[’\']t|must\s+not|mustn[’\']t|never|no\s+need\s+to)\s+(?:pay|transfer|remit)\b/iu',
                $text,
            ) === 1;

            if ($directive || (! $negated && ! $this->isClearlyNonDirective($text))) {
                return true;
            }
        }

        if (! $directive) {
            return false;
        }

        $amount = preg_match('/(?:\d[\d,]*(?:\.\d+)?\s*(?:บาท|THB|฿)|(?:THB|฿)\s*\d)/iu', $text);
        $destination = preg_match('/(?:บัญชี|ธนาคาร|\b(?:bank|account)\b)/iu', $text);
        $contextualReference = preg_match(
            '/(?:ยอด|จำนวน|บัญชี)\s*(?:(?:เดิม|ก่อนหน้า|ที่ผ่านมา|ปัจจุบัน|นี้)|(?:ที่|ตามที่)\s*ตกลง(?:กัน)?(?:ไว้)?)|(?:(?:the|our|your)\s+)?(?:previous|prior|agreed|current|same|this)\s+(?:amount|account)|(?:amount|account)\s+(?:previously\s+)?agreed/iu',
            $text,
        );

        return $amount === 1 || $destination === 1 || $contextualReference === 1;
    }

    private function containsKnownAccount(Bot $bot, string $text): bool
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

        return false;
    }

    private function isClearlyNonDirective(string $text): bool
    {
        return preg_match(
            '/(?:นโยบาย.{0,40}(?:ธนาคาร|บัญชี|โอน)|(?:ธนาคาร|บัญชี|โอน).{0,40}นโยบาย|\bpolicy\b.{0,40}\b(?:bank|account|transfer)\b|\b(?:bank|account|transfer)\b.{0,40}\bpolicy\b|(?:ส่ง|แนบ|อัปโหลด|ขอ).{0,40}(?:สลิป|หลักฐาน|ใบเสร็จ)|\b(?:send|attach|upload|request)\b.{0,40}\b(?:receipt|proof)\b|(?:ติดต่อ|สอบถาม).{0,40}(?:support|ซัพพอร์ต|ฝ่าย)|(?:support|ซัพพอร์ต|ฝ่าย).{0,40}(?:ติดต่อ|สอบถาม)|(?:ไม่มี|ยังไม่มี).{0,20}QR)/iu',
            $text,
        ) === 1;
    }
}
