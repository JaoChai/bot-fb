<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;

final class FinancialOutputDetector
{
    public function detects(Bot $bot, string $text): bool
    {
        // Receipt requests and negations apply only within their own clause.
        $clauses = preg_split('/[\r\n!?;]+|(?<!\d)[.,]|[.,](?!\d)|(?:แต่|แล้ว|จากนั้น|และ)|\b(?:but|then|and)\b/iu', $text);
        $hasFinancialContext = $this->containsFinancialContext($bot, $text);

        foreach ($clauses as $clause) {
            if ($this->detectsClause($bot, trim($clause), $hasFinancialContext)) {
                return true;
            }
        }

        return false;
    }

    private function detectsClause(Bot $bot, string $clause, bool $hasFinancialContext): bool
    {
        preg_match_all('/โอน(?:เงิน)?|ชำระ(?:เงิน)?|จ่าย(?:เงิน)?|\b(?:pay|transfer|remit|make\s+(?:a\s+)?payment)\b/iu', $clause, $actions, PREG_OFFSET_CAPTURE);
        $negated = false;
        foreach ($actions[0] as [$action, $offset]) {
            $prefix = substr($clause, 0, $offset);
            if (preg_match('/(?:ไม่\s*(?:สามารถ|ต้อง|ควร|อาจ|ให้)?|ห้าม|อย่า|งด|\b(?:do\s+not|don[’\']t|can\s*not|can[’\']t|should\s+not|shouldn[’\']t|must\s+not|mustn[’\']t|never|no\s+need\s+to))\s*(?:(?:ทำการ|ดำเนินการ|please|currently|now)\s*)*$/iu', $prefix) === 1) {
                $negated = true;

                continue;
            }

            // Exclude nominal mentions such as "proof of transfer" and "การโอน".
            if (preg_match('/(?:(?<!ทำ)การ|เรื่อง|(?<!ค)รับ|หลักฐาน|\b(?:of|for|about|regarding|bank|how\s+to|(?:policy|support)\b.{0,80}\bto))\s*$/iu', $prefix) === 1) {
                continue;
            }

            $actionText = substr($clause, $offset);
            if ($this->hasNonFinancialObject($bot, $actionText)) {
                continue;
            }

            // Shared context supplies the financial referent, never the syntax.
            if ($this->hasDirectiveSyntax($prefix)
                && ($hasFinancialContext || $this->containsLocalFinancialContext($bot, $actionText))) {
                return true;
            }
        }

        $knownAccount = $this->containsKnownAccount($bot, $clause);

        return $knownAccount && ! $negated && ! $this->isClearlyNonDirective($clause);
    }

    private function hasDirectiveSyntax(string $prefix): bool
    {
        // English imperatives start a clause, optionally with politeness/time or a
        // second-person modal. Whitespace after "you did" is not an imperative.
        if (preg_match('/^(?:(?:please|kindly|now|today|tomorrow)\s+)*(?:you\s+(?:can|should|must|need\s+to)\s+)?$/iu', $prefix) === 1) {
            return true;
        }

        // Thai request/time markers can follow an introduction. ทำการ modifies
        // that directive; by itself inside a proof/capability reference it cannot
        // establish one (e.g. ว่าทำการโอน or สำหรับทำการชำระ).
        return preg_match('/(?:^|กรุณา|โปรด|รบกวน|ช่วย|ให้|สามารถ|ตอนนี้|วันนี้|พรุ่งนี้|ได้เลยครับ|ได้เลยค่ะ)\s*(?:(?:ทำการ|ดำเนินการ)\s*)*$/u', $prefix) === 1;
    }

    private function hasNonFinancialObject(Bot $bot, string $actionText): bool
    {
        if (preg_match('/^pay\s+(?:close\s+)?attention\b/iu', $actionText) === 1) {
            return true;
        }

        if (preg_match('/^transfer\s+(?:(?:the|a|an|your|our|this|these)\s+)?(?:files?|documents?|images?)\b/iu', $actionText) !== 1) {
            return false;
        }

        // Inspect the whole local object/destination, not just its first noun:
        // a file processing fee is money. Unrelated context in another clause
        // must still allow a genuine file/document/image transfer.
        return ! $this->containsLocalFinancialContext($bot, $actionText);
    }

    private function containsLocalFinancialContext(Bot $bot, string $text): bool
    {
        // Generic monetary objects/destinations stay local to the action.
        return $this->containsFinancialContext($bot, $text)
            || preg_match('/\b(?:fees?|amount|bank|account|currency|THB)\b|บัญชี|ธนาคาร|บาท|\p{Sc}/iu', $text) === 1;
    }

    private function containsFinancialContext(Bot $bot, string $text): bool
    {
        return $this->containsKnownAccount($bot, $text)
            || preg_match('/(?:\d[\d,]*(?:\.\d+)?\s*(?:บาท|THB|฿)|(?:THB|฿)\s*\d)/iu', $text) === 1
            || preg_match(
                '/(?:ยอด|จำนวน|บัญชี)\s*(?:(?:เดิม|ก่อนหน้า|ที่ผ่านมา|ปัจจุบัน|นี้)|(?:ที่|ตามที่)\s*ตกลง(?:กัน)?(?:ไว้)?)|(?:(?:the|our|your)\s+)?(?:previous|prior|agreed|current|same|this)\s+(?:amount|account)|(?:amount|account)\s+(?:previously\s+)?agreed/iu',
                $text,
            ) === 1;
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
