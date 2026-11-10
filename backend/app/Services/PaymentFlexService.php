<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ProductStock;
use App\Services\Payment\FlexMessageBuilder;
use App\Services\Payment\PaymentMessageDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PaymentFlexService
{
    public function __construct(
        private PaymentMessageDetector $detector,
        private FlexMessageBuilder $builder,
        private VipPricingService $vipPricingService,
    ) {}

    private const MAX_FLEX_SIZE = 30000;

    /**
     * Try to convert payment text to LINE Flex Message.
     * Returns Flex array if payment detected, or original text as fallback.
     *
     * @return string|array Original text or Flex message array
     */
    public function tryConvertToFlex(string $text, ?Conversation $conversation = null): string|array
    {
        // Strip markdown bold before regex parsing (LINE doesn't render markdown)
        $text = str_replace('**', '', $text);

        try {
            $isVip = $this->isVipConversation($conversation);

            // Step 4: Payment message (existing)
            if ($this->isPaymentMessage($text)) {
                $data = $this->parsePaymentData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit($data, $isVip);

                    return $this->safeBuildFlex($text, $this->buildFlexMessage($data, $isVip));
                }
            }

            // Step 2.5: Support delay warning
            if ($this->isSupportDelayMessage($text)) {
                return $this->safeBuildFlex($text, $this->buildSupportDelayFlexMessage($isVip));
            }

            // Step 3: Terms message (always uses fixed TERMS_URL)
            if ($this->isTermsMessage($text)) {
                return $this->safeBuildFlex(
                    $text,
                    $this->buildTermsFlexMessage($this->shouldShowIdvNotice($conversation))
                );
            }

            // Step 5: Verify success message
            if ($this->isVerifySuccessMessage($text)) {
                $data = $this->parseVerifyData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit(
                        $data,
                        $isVip,
                        requireCanonicalItemPrices: false
                    );

                    return $this->safeBuildFlex($text, $this->buildVerifyFlexMessage($data, $isVip));
                }
            }

            // Step 2: Confirm message (least specific — check last)
            if ($this->isConfirmMessage($text)) {
                $data = $this->parseConfirmData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit($data, $isVip);

                    return $this->safeBuildFlex($text, $this->buildConfirmFlexMessage($data, $isVip));
                }
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('PaymentFlexService: fallback to text', [
                'error' => $e->getMessage(),
            ]);

            return $text;
        }
    }

    /**
     * Check the canonical auto/manual VIP note, with legacy-note compatibility.
     */
    public function isVipConversation(?Conversation $conversation): bool
    {
        return $this->vipPricingService->isVipConversation($conversation);
    }

    /**
     * Safely encode Flex to JSON and check size limit.
     * Returns Flex array if OK, or original text as fallback.
     */
    private function safeBuildFlex(string $fallbackText, array $flex): string|array
    {
        $encoded = json_encode($flex);
        if ($encoded === false) {
            return $fallbackText;
        }

        $jsonSize = strlen($encoded);
        if ($jsonSize > self::MAX_FLEX_SIZE) {
            Log::warning('Flex message exceeds size limit', [
                'size' => $jsonSize,
                'limit' => self::MAX_FLEX_SIZE,
            ]);

            return $fallbackText;
        }

        return $flex;
    }

    /**
     * Detect if text is a payment message.
     * Must contain both the bank account number AND a total keyword.
     */
    public function isPaymentMessage(string $text): bool
    {
        return $this->detector->isPaymentMessage($text);
    }

    /**
     * Parse payment data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parsePaymentData(string $text): ?array
    {
        return $this->detector->parsePaymentData($text);
    }

    /**
     * Build LINE Flex Message array from parsed payment data.
     */
    public function buildFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildFlexMessage($data, $isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 2.5: Support Delay Warning
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a support delay warning message.
     * Primary: "[แจ้งเตือน Support]" tag from prompt template.
     * Fallback: content-based detection when LLM omits the tag.
     */
    public function isSupportDelayMessage(string $text): bool
    {
        return $this->detector->isSupportDelayMessage($text);
    }

    /**
     * Build LINE Flex Message for support delay warning.
     * VIP gets gold styling with priority note; normal gets orange with secondary button.
     */
    public function buildSupportDelayFlexMessage(bool $isVip = false): array
    {
        return $this->builder->buildSupportDelayFlexMessage($isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 2: Confirm Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a confirm message (Step 2).
     * Must contain "รวม...บาท" + "ยืนยัน", but NOT bank account, verify tag, or terms keywords.
     */
    public function isConfirmMessage(string $text): bool
    {
        return $this->detector->isConfirmMessage($text);
    }

    /**
     * Parse confirm data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        return $this->detector->parseConfirmData($text);
    }

    /**
     * Build LINE Flex Message for order confirmation (Step 2).
     */
    public function buildConfirmFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildConfirmFlexMessage($data, $isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 3: Terms Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a terms/agreement message (Step 3).
     * Must contain "ยอมรับ" + ("ข้อตกลง" or "เงื่อนไข" or TERMS_URL).
     * Excludes payment, verify messages.
     */
    public function isTermsMessage(string $text): bool
    {
        return $this->detector->isTermsMessage($text);
    }

    /**
     * Build LINE Flex Message for terms/agreement (Step 3).
     * Always uses the fixed TERMS_URL constant.
     */
    public function buildTermsFlexMessage(bool $showIdvNotice = true): array
    {
        return $this->builder->buildTermsFlexMessage($showIdvNotice);
    }

    /**
     * Page/เฟสไก่ cannot run ads — ออเดอร์ล่าสุดที่เป็น Page/เฟสไก่ล้วนไม่ต้องเห็นกล่อง
     * แจ้งยืนยันตัวตน. ทุกกรณีอื่น (ผสม, อ่านรายการไม่ออก, พิสูจน์ว่ายอดไม่ครบทั้งตะกร้า,
     * ไม่มีแชท/ข้อความยืนยัน) คืน true = แสดงต่อ — fail-safe ตามค่าเริ่มของ builder.
     */
    private function shouldShowIdvNotice(?Conversation $conversation): bool
    {
        $order = $this->latestCartOrder($conversation);

        if ($order === null) {
            return true;
        }

        // ทุกรายการต้อง map กลับไปยังสินค้า Page/G3D ในแคตตาล็อกจริง
        // (ชื่อ/alias — เทียบทั้งคำสำหรับ term อังกฤษ, substring สำหรับ term ไทย)
        $catalogue = ProductStock::query()->get();
        $products = [];
        foreach ($order['names'] as $name) {
            $product = $this->catalogueProduct((string) $name, $catalogue);
            if ($product === null || ! in_array($product->slug, ['page', 'g3d'], true)) {
                return true;
            }
            $products[] = $product;
        }

        // ชื่อจาก one-liner ตะกร้าระบุแค่ของที่เพิ่มล่าสุด — ซ่อนได้เมื่อยอด "รวม" ใน
        // ข้อความตรงกับ qty × ราคาแคตตาล็อกของรายการที่ชื่อเท่านั้น (มิฉะนั้นยอดอาจ
        // พ่วงรายการเก่า เช่น upsell "เพิ่ม Page … รวม 1,299" ที่พ่วง Nolimit เดิม)
        if ($order['needs_price_parity']) {
            if ($order['total'] === null) {
                return true;
            }

            $sum = 0.0;
            foreach ($order['names'] as $i => $name) {
                $price = $products[$i]->price;
                $qty = $order['quantities'][$i] ?? null;
                // ราคา/จำนวนขาด = พิสูจน์ไม่ได้ → แสดง
                if ($price === null || $qty === null || $qty < 1) {
                    return true;
                }
                $sum += (float) $price * $qty;
            }

            if (abs($sum - $order['total']) > 0.01) {
                return true;
            }
        }

        return false;
    }

    /**
     * หาสินค้าจริงใน ProductStock จากชื่อรายการที่อ่านได้จากข้อความ — เทียบทั้งชื่อและ
     * alias แล้วเลือก term ที่ยาวที่สุดก่อน (กัน "เฟสไก่ (G3D)" จับด้วย "ไก่" ที่สั้นกว่า
     * ทั้งที่ "G3D" ตรงกว่า). term อังกฤษ/ตัวเลขต้องเป็นคำเต็ม (word boundary) กัน
     * "Webpage" ไปจับ "page"; term ไทยเทียบ substring (ภาษาไทยไม่มีขอบคำใน regex).
     * ยาวสุดเสมอกันหลาย product = กำกวม → ไม่เดา (คืน null → caller แสดง notice).
     *
     * @param  Collection<int, ProductStock>  $catalogue
     */
    private function catalogueProduct(string $name, $catalogue): ?ProductStock
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return null;
        }

        $candidates = [];
        foreach ($catalogue as $product) {
            foreach (array_merge([$product->name], $product->aliases ?? []) as $term) {
                $term = mb_strtolower(trim((string) $term));
                if (mb_strlen($term) < 2 || ! $this->nameContainsTerm($needle, $term)) {
                    continue;
                }
                $candidates[] = ['len' => mb_strlen($term), 'product' => $product];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['len'] <=> $a['len']);

        $maxLen = $candidates[0]['len'];
        $topProductIds = array_unique(array_map(
            fn ($c) => $c['product']->id,
            array_filter($candidates, fn ($c) => $c['len'] === $maxLen),
        ));
        if (count($topProductIds) > 1) {
            return null;
        }

        return $candidates[0]['product'];
    }

    /**
     * needle มี term อยู่ไหม — term ที่เป็นตัวอักษรอังกฤษ/ตัวเลขล้วนต้องเป็นคำเต็ม
     * (ตัวอักษร/ตัวเลขล้อมรอบ = คนละคำ)
     */
    private function nameContainsTerm(string $needle, string $term): bool
    {
        if (preg_match('/^[a-z0-9+&.]+$/', $term) === 1) {
            return preg_match('/(?<![a-z0-9])'.preg_quote($term, '/').'(?![a-z0-9])/', $needle) === 1;
        }

        return mb_strpos($needle, $term) !== false;
    }

    /**
     * อ่านออเดอร์ล่าสุดจากข้อความยืนยัน/ตะกร้าของบอท (เกณฑ์ isConfirmMessage เดียวกับ
     * SlipVerificationService::findExpectedFromConfirmMessage) — แต่ต่างที่: ตัดสินจาก
     * ข้อความยืนยันล่าสุด *เท่านั้น* ถึงข้อความแรกที่เป็นยืนยันแล้วหยุดทันที อ่านไม่ออกก็
     * คืน null (ห้ามไล่ไปข้อความเก่ากว่า — ตะกร้าเก่าไม่ใช่ออเดอร์ปัจจุบัน เช่น เพิ่ม
     * Nolimit ทีหลัง Page). SlipVerification มียอดโอนเป็น guard ไฟล์นี้ไม่มี จึงต้องเข้มกว่า.
     *
     * คืน array{names, needs_price_parity, total, quantities}:
     *  - names: ชื่อรายการทั้งหมดที่อ่านได้
     *  - needs_price_parity: true เมื่อชื่อมาจาก one-liner ตะกร้า (ระบุเฉพาะของที่เพิ่ม)
     *    → caller ต้องพิสูจน์ว่ายอดรวมครบทั้งตะกร้า (total == Σ qty × ราคาแคตตาล็อก)
     *    ก่อนจะซ่อน notice ได้; false เมื่อมาจาก parser หลัก (สรุปยอดทั้งตะกร้า)
     * คืน null = ไม่มีข้อความยืนยัน หรืออ่านรายการไม่ได้ → caller แสดง notice.
     *
     * @return array{names: list<string>, needs_price_parity: bool, total: ?float, quantities: list<int>|null}|null
     */
    private function latestCartOrder(?Conversation $conversation): ?array
    {
        if ($conversation === null) {
            return null;
        }

        $query = $conversation->messages()
            ->where('sender', 'bot')
            ->where('type', 'text');

        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        foreach ($query->latest('id')->take(15)->pluck('content') as $content) {
            $content = (string) $content;

            if (! $this->detector->isConfirmMessage($content)) {
                continue;
            }

            // Primary: canonical parser (bulleted/numbered item lines — สรุปยอดทั้งตะกร้า)
            $data = $this->detector->parseConfirmData($content);
            if ($data !== null && $data['items'] !== []) {
                return [
                    'names' => array_map(
                        fn (array $item): string => (string) ($item['name'] ?? ''),
                        $data['items']
                    ),
                    'needs_price_parity' => false,
                    'total' => null,
                    'quantities' => null,
                ];
            }

            // Cart one-liner ("เพิ่มเฟสไก่ (G3D) 1 ตัว ลงตะกร้าแล้วครับ รวม 50 บาทครับ…"):
            // parseConfirmData reads the total but no item names — the item line carries no
            // "บาท" (known limitation, documented in
            // backend/docs/superpowers/specs/2026-07-31-order-reconstruction-design.md).
            // Anchored on the cart phrase so price-quote confirm messages never match.
            if (mb_strpos($content, 'ลงตะกร้า') !== false) {
                preg_match_all(
                    '/(?:เพิ่ม|และ)\s*([^\n]+?)\s+(\d+)\s*(?:ตัว|เพจ|ใบ|ชิ้น|อัน)/u',
                    $content,
                    $matches,
                );

                if ($matches[1] === []) {
                    // อ่านชื่อ+จำนวนไม่ได้ — อย่าไล่ไปข้อความเก่ากว่า
                    return null;
                }

                if (preg_match('/(?:รวม|ราคา)\s*:?\s*([\d,]+(?:\.\d+)?)\s*บาท/u', $content, $totalMatch) !== 1) {
                    // ยอดรวมอ่านไม่ได้ = พิสูจน์ความครบตะกร้าไม่ได้ → แสดง
                    return null;
                }

                return [
                    'names' => array_map('trim', $matches[1]),
                    'needs_price_parity' => true,
                    'total' => (float) str_replace(',', '', $totalMatch[1]),
                    'quantities' => array_map('intval', $matches[2]),
                ];
            }

            // ข้อความยืนยันล่าสุดแต่อ่านรายการไม่ได้ทั้งสองทาง — หยุดที่ออเดอร์นี้
            return null;
        }

        return null;
    }

    // ────────────────────────────────────────────────────────
    // Step 5: Verify Success Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a verify-success message (Step 5).
     * "เงินเข้าแล้ว X บาท" pattern is sufficient — tag is optional
     * because buildVerifyFlexMessage() hardcodes the tag in altText.
     */
    public function isVerifySuccessMessage(string $text): bool
    {
        return $this->detector->isVerifySuccessMessage($text);
    }

    /**
     * Parse verify-success data from text.
     * Returns null if amount cannot be parsed.
     */
    public function parseVerifyData(string $text): ?array
    {
        return $this->detector->parseVerifyData($text);
    }

    /**
     * Build LINE Flex Message for verify-success (Step 5).
     */
    public function buildVerifyFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildVerifyFlexMessage($data, $isVip);
    }
}
