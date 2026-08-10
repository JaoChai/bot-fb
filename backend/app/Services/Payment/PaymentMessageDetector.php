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

    /** ยอดในข้อความยืนยันขั้น 2 — ใช้ร่วมกัน isConfirmMessage/parseConfirmData กัน drift */
    private const CONFIRM_TOTAL_PATTERN = '/(?:รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?|ราคา)\s*:?\s*([\d,]+)\s*บาท/u';

    /** รูปแบบเจตนายืนยันขั้น 2 จริง (3 รูป) — เหตุผล/รายละเอียดที่ hasConfirmIntent() */
    private const CONFIRM_INTENT_PATTERN = '/(?:พิมพ์|กด)[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}]+ยืนยัน|ถูกต้องไหม|ใช่ไหม/u';

    /** เฉพาะรูป "รวม..." — ต้องลองก่อน "ราคา" เสมอ เพราะยอดรวมชนะราคาต่อชิ้น */
    private const CONFIRM_SUM_PATTERN = '/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*([\d,]+)\s*บาท/u';

    /** คำหน่วยจำนวนสินค้า ("N ตัว") — ใช้ร่วมกันทั้ง primary post-process และ fallback regex กัน drift */
    private const UNIT_WORDS = 'ตัว|เพจ|ใบ|ชิ้น|อัน';

    /**
     * ชื่อสินค้าในบรรทัดรายการ: อักขระทั่วไป หรือวงเล็บที่ "ปิดครบ" เท่านั้น
     * (เช่น "(ผูกบัตร)" ยังอยู่ในชื่อได้ตามเดิม) — ห้ามให้ชื่อจบด้วยวงเล็บเปิดค้าง
     * ไม่อย่างนั้น lazy match จะหยุดที่ "บาท" ตัวแรกในวงเล็บราคา แล้วกลืน qty หายทั้งก้อน
     * (เคสจริง 10 ส.ค. 2026: "1. G3D (50 บาท x 20)" → ชื่อ "G3D (" ราคา 50 qty หาย
     * → ลูกค้าจ่าย 1,000 ได้ของ 1 ตัวจาก 20)
     */
    private const ITEM_NAME = '((?:[^\n(]|\([^)\n]*\))+?)';

    /**
     * รูป "(ราคา x จำนวน)" ที่พรอมต์สั่งไว้ — ยอมให้มีหน่วยปนได้ทั้งฝั่งราคา ("50 บาท", "50฿")
     * และฝั่งจำนวน ("20 ตัว") เพราะโมเดลแชท drift ใส่หน่วยเองเป็นระยะ
     */
    private const PAREN_PRICE_QTY = '(?:\(\s*([\d,]+(?:\.\d+)?)\s*(?:บาท|฿)?\s*[x×]\s*(\d+)\s*(?:'.self::UNIT_WORDS.')?\s*\)\s*=\s*)?';

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
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|[-•]\s*)'.self::ITEM_NAME.'\s*'
                .self::PAREN_PRICE_QTY.'(?:[:=\-]\s*)?([\d,]+(?:\.\d+)?)\s*บาท/u',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );

        foreach ($itemMatches as $match) {
            $name = trim($match[1]);
            $qty = $match[3] ?? '';

            // LLM มัก drift เขียน qty ต่อท้ายชื่อ ("G3D x20", "G3D 20 ตัว") แทนรูป "(ราคา x จำนวน)"
            // ถ้าไม่ดึงออก qty จะหายเงียบ → ระบบส่งของให้ลูกค้าแค่ 1 ชิ้น (เคสจริง delivery #35)
            if ($qty === '' && preg_match('/^(.+?)\s*(?:[x×]\s*(\d+)|(\d+)\s*(?:'.self::UNIT_WORDS.'))$/u', $name, $qtyMatch)) {
                $name = trim($qtyMatch[1]);
                $qty = ($qtyMatch[2] ?? '') !== '' ? $qtyMatch[2] : $qtyMatch[3];
            }

            $this->pushItem($items, $name, $match[4], $match[2] ?? '', $qty);
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
            '/^(?!\s*(?:รวม|สรุป|'.self::TOTAL_KEYWORDS.'|ส่วนลด|ค่าธรรมเนียม))\s*(.+?)\s*(?:(\d+)\s*(?:'.self::UNIT_WORDS.')(?:\s*[x×]\s*([\d,]+))?|[x×]\s*(\d+))?\s*[:=\-]\s*([\d,]+(?:\.\d+)?)\s*บาท/mu',
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

    /**
     * รูปแบบ summary รายการสินค้าตัวเดียวของระบบ — ทุกที่ที่ summary ไหลออก
     * (ข้อความยืนยันรับเงิน, การ์ด Telegram, order_items) ใช้รูปนี้เท่านั้น:
     * สั่งเกิน 1 ติด "xN" ต่อท้ายชื่อ ไม่งั้นใช้ชื่อล้วน คั่นแต่ละรายการด้วย ", ".
     * ห้ามกรองรายการในเมธอดนี้ — ผู้เรียกบางรายกรองของแถมราคา 0 มาก่อนแล้ว กรองซ้ำจะเปลี่ยนพฤติกรรม.
     *
     * @param  array<int, array{name: string, qty?: int}>  $items
     */
    public static function formatItemSummary(array $items): string
    {
        return implode(', ', array_map(
            fn (array $item) => ((int) ($item['qty'] ?? 1)) > 1
                ? "{$item['name']} x{$item['qty']}"
                : $item['name'],
            $items,
        ));
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
     * Must contain "รวม...บาท"/"ราคา...บาท" + "ยืนยัน", but NOT bank account, verify tag, or terms keywords.
     *
     * เกณฑ์นี้หลวมโดยตั้งใจ — มีไว้แปลงข้อความบอทเป็นการ์ด Flex ให้ลูกค้าเห็น (PaymentFlexService)
     * ซึ่งตัดสินผิดก็แค่แสดงผลไม่สวย ไม่มีผลทางการเงิน. ส่วนเส้นทางที่เอาข้อความไปตัดสินเรื่องเงิน
     * (SlipVerificationService) ต้องเช็ค hasConfirmIntent() เป็นด่านเสริม — ดูเหตุผลที่เมธอดนั้น.
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
        // "ราคา ... บาท" ก็นับด้วย — LLM มัก drift เขียนข้อความยืนยันขั้น 2 แบบ
        // "เพิ่ม X 1 ตัว ราคา 1,100 บาท ... พิมพ์ยืนยัน" (เคสจริงแชท #92 31 ก.ค.)
        if (! preg_match(self::CONFIRM_TOTAL_PATTERN, $text)) {
            return false;
        }

        return mb_strpos($text, 'ยืนยัน') !== false;
    }

    /**
     * เจตนายืนยันขั้น 2 จริง — ไม่ใช่แค่มีคำว่า "ยืนยัน" โผล่ในประโยค.
     *
     * ใช้เฉพาะเส้นทางที่เอาข้อความไปตัดสินเรื่องเงิน/ส่งของอัตโนมัติ
     * (SlipVerificationService::findExpectedFromConfirmMessage) — ไม่ใช่ตอนแปลงข้อความเป็น Flex
     * การ์ด ซึ่งใช้ isConfirmMessage() ที่หลวมกว่าเพราะไม่มีผลทางการเงิน.
     *
     * ข้อความขายมักพูดว่า "ไม่ต้องยืนยันตัวตนเพิ่ม" ซึ่งเดิมผ่านด่าน mb_strpos(text,'ยืนยัน')
     * ทำให้สลิปผ่านเองแล้วส่งของทั้งที่ลูกค้ายังไม่ได้สั่ง จึงต้องจับเฉพาะที่บอท
     * "ขอให้ลูกค้ายืนยัน" จริง 3 รูปเท่านั้น:
     *   (ก) พิมพ์/กด ตามด้วยช่องว่างหรือเครื่องหมายคำพูด แล้วตามด้วย "ยืนยัน"
     *       (ครอบคลุม ASCII quote และ curly quote ไทย เช่น พิมพ์ "ยืนยัน", พิมพ์ ยืนยัน)
     *   (ข) ถูกต้องไหม
     *   (ค) ใช่ไหม
     */
    public function hasConfirmIntent(string $text): bool
    {
        return (bool) preg_match(self::CONFIRM_INTENT_PATTERN, $text);
    }

    /**
     * Parse confirm data from text.
     * ลองรูป "รวม X บาท" ก่อนเสมอ — ข้อความที่มีทั้งราคาต่อชิ้น (upsell "Page ราคา 199 บาท")
     * และยอดรวม ต้องได้ยอดรวม ไม่ใช่ราคาชิ้นแรกที่เจอ
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        $text = $this->normalize($text);

        if (! preg_match(self::CONFIRM_SUM_PATTERN, $text, $totalMatch)
            && ! preg_match(self::CONFIRM_TOTAL_PATTERN, $text, $totalMatch)) {
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
