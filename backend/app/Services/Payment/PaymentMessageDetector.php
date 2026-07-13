<?php

namespace App\Services\Payment;

/**
 * Detects payment-related message types and parses their data. Pure text/regex logic — no side effects, no model dependencies. Extracted from PaymentFlexService (2026-05-26 Sprint 5).
 */
class PaymentMessageDetector
{
    private const BANK_ACCOUNT = '223-3-24880-3';

    /**
     * Detect if text is a payment message.
     * Must contain both the bank account number AND a total keyword.
     */
    public function isPaymentMessage(string $text): bool
    {
        if (mb_strpos($text, self::BANK_ACCOUNT) === false) {
            return false;
        }

        return (bool) preg_match('/รวมยอดโอน|สรุปยอด|ยอดโอน|ยอดรวม|รวมเป็นเงิน|สรุปรายการ/u', $text);
    }

    /**
     * Total keyword variants shared by parsePaymentData/parseConfirmData's total regex,
     * and by parseItems' fallback lookahead (so a total line is never also read as an item).
     */
    private const TOTAL_KEYWORDS = 'รวมยอดโอน|รวมทั้งสิ้น|สรุปยอด(?:โอน)?|ยอดโอน|ยอดรวม|รวมเป็นเงิน|ยอดสุทธิ|ยอดที่ต้องโอน|ยอดชำระ|ยอดที่ต้องชำระ';

    /**
     * Parse payment data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parsePaymentData(string $text): ?array
    {
        $text = $this->normalize($text);

        // Parse total (required)
        if (! preg_match('/(?:'.self::TOTAL_KEYWORDS.')\s*:?\s*฿?\s*([\d,]+(?:\.\d+)?)\s*(?:บาท|฿)/u', $text, $totalMatch)) {
            return null;
        }

        return [
            'items' => $this->parseItems($text),
            'total' => $totalMatch[1],
        ];
    }

    /**
     * Normalize format drift that isn't specific to items or totals:
     * some chat model outputs join lines with "|||" instead of a newline.
     */
    private function normalize(string $text): string
    {
        return str_replace('|||', "\n", $text);
    }

    /**
     * Parse item lines from an order summary (shared by parsePaymentData/parseConfirmData).
     *
     * Primary: bulleted lines ("1. name (price x qty) = total บาท", "- name total บาท").
     * Fallback: non-bulleted lines anchored on "<sep> total บาท" — LLMs don't always follow
     * the bullet format in the prompt (format drift when the chat model changes).
     */
    private function parseItems(string $text): array
    {
        // Strip a leading order-summary intro ("สรุปรายการ...:", "รายการ...:", "สั่งซื้อ...:")
        // from the start of a line so it never pollutes the item name that follows it.
        $text = preg_replace('/^(?:สรุปรายการ|รายการ|สั่งซื้อ)[^\n:：]*[:：]\s*/mu', '', $text);

        $items = [];
        preg_match_all(
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|[-•]\s*)(.+?)\s*(?:\(([\d,]+(?:\.\d+)?)\s*[x×]\s*(\d+)\)\s*=\s*)?(?:[:=\-]\s*)?([\d,]+(?:\.\d+)?)\s*บาท/u',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );

        foreach ($itemMatches as $match) {
            $this->pushItem($items, trim($match[1]), $match[4], $match[2] ?? '', $match[3] ?? '');
        }

        if ($items !== []) {
            return $items;
        }

        // Fallback: "name [qty] <sep> total บาท" without bullets.
        // Requires "<number> บาท" at the end so account/bank-name lines never match;
        // excludes total/discount/fee lines via the lookahead (narrowed to the exact
        // total phrasings so a product name like "ยอดนิยม Pack" isn't eaten too).
        // Known limitation: matches at most one item per line (fine — one item per line
        // is the only shape the bot produces).
        preg_match_all(
            '/^(?!\s*(?:รวม|สรุป|'.self::TOTAL_KEYWORDS.'|ส่วนลด|ค่าธรรมเนียม))\s*(.+?)\s*(?:(\d+)\s*(?:ตัว|เพจ|ใบ|ชิ้น|อัน)(?:\s*[x×]\s*([\d,]+))?|[x×]\s*(\d+))?\s*[:=\-]\s*([\d,]+(?:\.\d+)?)\s*บาท/mu',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );

        foreach ($itemMatches as $match) {
            // Strip a leading emoji/symbol some chat models prepend to item lines
            // (e.g. "🔹 Nolimit ..."), keeping any Thai/Latin letters or digits.
            $name = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($match[1]));

            // qty: unit-word form ("N ตัว/เพจ/ใบ/ชิ้น/อัน") or "xN" form — mutually exclusive.
            $qty = ($match[2] ?? '') !== '' ? $match[2] : ($match[4] ?? '');
            $price = $match[3] ?? '';

            $this->pushItem($items, $name, $match[5], $price, $qty);
        }

        return $items;
    }

    /**
     * บรรทัดราคา 0 (เช่น "บริการเสริม Page = 0 บาท") เป็นของแถม/ประดับในสรุปยอด ไม่ใช่การสั่งซื้อ.
     * กติกากลางใช้ร่วมกันทุก consumer (summary + delivery) กัน drift; parse ราคาไม่ได้ = false (fail-open เก็บ item ไว้).
     */
    public static function isZeroPriceItem(array $item): bool
    {
        $total = str_replace(',', '', (string) ($item['total'] ?? ''));

        return is_numeric($total) && (float) $total <= 0;
    }

    private function pushItem(array &$items, string $name, string $total, string $price, string $qty): void
    {
        if ($name === '') {
            return;
        }

        $item = ['name' => $name, 'total' => $total];

        if ($price !== '') {
            $item['price'] = $price;
        }

        if ($qty !== '') {
            $item['qty'] = (int) $qty;
        }

        $items[] = $item;
    }

    /**
     * Detect if text is a support delay warning message.
     * Primary: "[แจ้งเตือน Support]" tag from prompt template.
     * Fallback: content-based detection when LLM omits the tag.
     */
    public function isSupportDelayMessage(string $text): bool
    {
        // Primary: tag from prompt template
        if (mb_strpos($text, '[แจ้งเตือน Support]') !== false) {
            return true;
        }

        // Fallback: content-based — must mention support delay + ask for "ตกลง"
        $hasSupportDelay = (bool) preg_match('/(?:ซัพพอร์ต|Support)[\s\S]*?(?:นานกว่าปกติ|ล่าช้า|รอคิว)/iu', $text);
        $hasAcceptKeyword = mb_strpos($text, 'ตกลง') !== false;

        return $hasSupportDelay && $hasAcceptKeyword;
    }

    /**
     * Detect if text is a confirm message (Step 2).
     * Must contain "รวม...บาท" + "ยืนยัน", but NOT bank account, verify tag, or terms keywords.
     */
    public function isConfirmMessage(string $text): bool
    {
        // Exclude Step 4 (payment - has bank account)
        if (mb_strpos($text, self::BANK_ACCOUNT) !== false) {
            return false;
        }
        // Exclude Step 5 (verify - has เงินเข้าแล้ว)
        if (mb_strpos($text, 'เงินเข้าแล้ว') !== false) {
            return false;
        }
        // Exclude Step 5 (verify - has tag)
        if (mb_strpos($text, '[ยืนยันชำระเงิน]') !== false) {
            return false;
        }
        // Exclude Step 3 (terms)
        if (mb_strpos($text, 'ข้อตกลง') !== false) {
            return false;
        }
        // Exclude Step 3 (terms fallback keyword)
        if (mb_strpos($text, 'เงื่อนไข') !== false) {
            return false;
        }

        // Must have total pattern: รวม...บาท (รองรับ รวม, รวมทั้งหมด, รวมยอด, รวมเป็นเงิน)
        if (! preg_match('/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*[\d,]+\s*บาท/u', $text)) {
            return false;
        }

        // Must have "ยืนยัน"
        return mb_strpos($text, 'ยืนยัน') !== false;
    }

    /**
     * Parse confirm data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        // Parse total (required) — รองรับ รวม, รวมทั้งหมด, รวมยอด, รวมเป็นเงิน
        if (! preg_match('/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*([\d,]+)\s*บาท/u', $text, $totalMatch)) {
            return null;
        }

        return [
            'items' => $this->parseItems($text),
            'total' => $totalMatch[1],
        ];
    }

    /**
     * Detect if text is a terms/agreement message (Step 3).
     * Must contain "ยอมรับ" + ("ข้อตกลง" or "เงื่อนไข" or TERMS_URL).
     * Excludes payment, verify messages.
     */
    public function isTermsMessage(string $text): bool
    {
        // Exclude Step 4 (payment) and Step 5 (verify)
        if (mb_strpos($text, self::BANK_ACCOUNT) !== false) {
            return false;
        }
        if (mb_strpos($text, 'เงินเข้าแล้ว') !== false) {
            return false;
        }
        if (mb_strpos($text, '[ยืนยันชำระเงิน]') !== false) {
            return false;
        }

        // Must have "ยอมรับ" keyword
        if (mb_strpos($text, 'ยอมรับ') === false) {
            return false;
        }

        // Primary: "ข้อตกลง"
        if (mb_strpos($text, 'ข้อตกลง') !== false) {
            return true;
        }

        // Fallback: TERMS_URL present (very reliable signal)
        return mb_strpos($text, 'canva.site/ads-vance') !== false;
    }

    /**
     * Detect if text is a verify-success message (Step 5).
     * "เงินเข้าแล้ว X บาท" pattern is sufficient — tag is optional
     * because buildVerifyFlexMessage() hardcodes the tag in altText.
     */
    public function isVerifySuccessMessage(string $text): bool
    {
        return (bool) preg_match('/เงินเข้าแล้ว\s*[\d,.]+\s*บาท/u', $text);
    }

    /**
     * Parse verify-success data from text.
     * Returns null if amount cannot be parsed.
     */
    public function parseVerifyData(string $text): ?array
    {
        // Parse amount (required)
        if (! preg_match('/เงินเข้าแล้ว\s*([\d,.]+)\s*บาท/u', $text, $amountMatch)) {
            return null;
        }

        $amount = $amountMatch[1];

        // Parse items (optional): "• item" or "- item" at start of line
        $items = [];
        if (preg_match_all('/^[•\-]\s*(.+)/mu', $text, $itemMatches)) {
            $items = array_map('trim', $itemMatches[1]);
        }

        // Parse delivery info (optional)
        $delivery = null;
        if (preg_match('/ส่งใน\s*(.+?)(?:\n|$)/u', $text, $deliveryMatch)) {
            $delivery = trim($deliveryMatch[1]);
        }

        return [
            'amount' => $amount,
            'items' => $items,
            'delivery' => $delivery,
        ];
    }
}
