<?php

namespace App\Services;

use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Post-generation guard that validates LLM responses against current stock status.
 *
 * If the LLM tries to sell an out-of-stock product (despite prompt instructions),
 * this guard replaces the response with a stock-out message.
 */
class StockGuardService
{
    /** ราคาที่ห่างจากชื่อสินค้าไม่เกินนี้ (ตัวอักษร) ถือว่าพูดถึงคู่กัน */
    private const PRICE_PROXIMITY = 60;

    public function __construct(
        protected StockInjectionService $stockInjection
    ) {}

    /**
     * Validate a bot response against current stock status.
     *
     * @return array{content: string, blocked: bool, blocked_products: array}
     */
    public function validate(string $response, string $userMessage = ''): array
    {
        if (! config('rag.stock_guard.enabled', true)) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        $outOfStock = $this->stockInjection->getOutOfStockProducts();
        if ($outOfStock->isEmpty()) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        // Cap response length for regex to prevent ReDoS on very long LLM output.
        // Selling keywords (price, cart, payment) always appear in the first portion.
        $responseToCheck = mb_substr($response, 0, 2000);

        // map: ชื่อสินค้า => ปิดการขายจริงไหม (true = ตะกร้า/สรุปยอด/โอนเงิน)
        $violations = $this->detectViolations($responseToCheck, $outOfStock);

        if (empty($violations)) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        $products = array_keys($violations);

        // Check if ALL violations are upsell-only (not main product being sold)
        $allUpsell = $this->areAllViolationsUpsell($responseToCheck, $products, $outOfStock);

        if ($allUpsell) {
            Log::info('StockGuard: stripped upsell for out-of-stock products', [
                'violations' => $products,
                'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
            ]);

            $stripped = $this->stripUpsellBlock($response, $products, $outOfStock);

            return ['content' => $stripped, 'blocked' => false, 'blocked_products' => []];
        }

        // เอ่ยชื่อ/ราคาเฉยๆ ไม่ได้ปิดการขาย → ห้ามทิ้งคำตอบของลูกค้า แค่ต่อท้ายว่าหมด
        // (prompt อนุญาตให้ตอบราคา/รายละเอียดได้ ขอแค่แจ้งว่าหมดชั่วคราวด้วย)
        if (! in_array(true, $violations, true)) {
            Log::info('StockGuard: appended out-of-stock notice', [
                'violations' => $products,
                'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
            ]);

            return [
                'content' => $this->appendStockNotice($response, $products),
                'blocked' => false,
                'blocked_products' => [],
            ];
        }

        Log::warning('StockGuard: blocked out-of-stock sale', [
            'violations' => $products,
            'original_response_preview' => mb_substr($response, 0, 300),
            'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
        ]);

        return [
            'content' => $this->buildReplacementResponse($products),
            'blocked' => true,
            'blocked_products' => $products,
        ];
    }

    /**
     * Detect if response is SELLING (not just mentioning) out-of-stock products.
     *
     * @return array<string, bool> ชื่อสินค้า => ปิดการขายจริงไหม (false = แค่เอ่ยชื่อ/ราคา)
     */
    protected function detectViolations(string $response, Collection $outOfStock): array
    {
        $violations = [];

        // Payment instructions (STEP 4) list products as order line items,
        // not as selling recommendations. Skip guard for those.
        $isPayment = $this->isPaymentInstruction($response);

        foreach ($outOfStock as $product) {
            $names = array_merge([$product->name], $product->aliases ?? []);

            foreach ($names as $name) {
                if (mb_strlen($name) < 2) {
                    continue;
                }

                if (mb_stripos($response, $name) === false) {
                    continue;
                }

                // Product name found — check both refusal and selling contexts
                $isRefused = $this->isRefusingContext($response, $name);
                $isSelling = $this->isSellingContext($response, $name, $product->name);

                // In payment instructions, product names as line items are not violations
                if ($isSelling && $isPayment && $this->isOrderLineItem($response, $name)) {
                    continue;
                }

                // Informational: response mentions price BUT also refuses the same product
                // → allowed (e.g., "BM ราคา 1,100 บาท แต่หมดชั่วคราว")
                // UNLESS there are active selling keywords (cart/order/recommendation)
                if ($isSelling && $isRefused) {
                    if ($this->isActivelySelling($response, $name)) {
                        $violations[$product->name] = true;
                        break;
                    }

                    continue;
                }

                // Selling without refusal → violation
                if ($isSelling) {
                    $violations[$product->name] = $this->isActivelySelling($response, $name);
                    break;
                }

                // Refusal without selling → skip
                if ($isRefused) {
                    continue;
                }
            }
        }

        return $violations;
    }

    /**
     * Check if the response is correctly REFUSING to sell a SPECIFIC product.
     *
     * Refusal keywords must appear within proximity of the product name,
     * not just anywhere in the response — prevents bypass when LLM refuses
     * one product but sells another in the same message.
     */
    protected function isRefusingContext(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $refusalPatterns = [
            // Refusal keyword near product name (within 40 chars)
            "/{$quotedName}.{0,40}(หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|out.of.stock)/iu",
            "/(หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|out.of.stock).{0,40}{$quotedName}/iu",
            // "สินค้าXXXหมด" pattern
            "/สินค้า.{0,10}{$quotedName}.{0,10}(หมด|ไม่มี)/iu",
            "/{$quotedName}.{0,10}หมด(ชั่วคราว|stock|สต็อก|แล้ว)/iu",
        ];

        foreach ($refusalPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the response is actively SELLING the product.
     *
     * Selling = product name appears WITH price/payment/cart/recommendation keywords.
     */
    protected function isSellingContext(string $response, string $productName, string $ownerName): bool
    {
        $quotedName = preg_quote($productName, '/');

        // ราคาที่อยู่ใกล้ชื่อ ต้องเป็นราคาของสินค้าตัวนี้จริง ไม่ใช่ราคาของตัวอื่นที่บังเอิญ
        // อยู่ในประโยคเดียวกัน (เช่น "G3D ราคา 50 บาท ... ไม่ใช่บัญชียิงแอดแบบ BM")
        if ($this->hasOwnPriceNearby($response, $productName, $ownerName)) {
            return true;
        }

        $sellingPatterns = [
            // Cart/order keywords
            '/(เพิ่ม|ลง).{0,20}(ตะกร้า|cart)/iu',
            "/{$quotedName}.{0,40}(x\\d|จำนวน)/iu",
            // Payment keywords near product
            "/(โอน|ชำระ|จ่าย|เลขบัญชี|QR|พร้อมเพย์).{0,60}{$quotedName}/iu",
            "/{$quotedName}.{0,60}(โอน|ชำระ|จ่าย|เลขบัญชี|QR|พร้อมเพย์)/iu",
            // Recommendation
            "/(แนะนำ|สนใจ).{0,30}{$quotedName}/iu",
            "/รับ.{0,10}{$quotedName}.{0,10}(ไหม|มั้ย|ด้วย)/iu",
            "/{$quotedName}.{0,30}(ด้วยไหม|ดีไหม|สนใจไหม|เพิ่มไหม)/iu",
            // Order summary
            "/(สรุป|รวม|ยอด).{0,40}{$quotedName}/iu",
            "/{$quotedName}.{0,40}(สรุป|รวม[:=])/iu",
        ];

        foreach ($sellingPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ราคาที่อยู่ใกล้ชื่อสินค้า นับเป็น "กำลังขาย" ต่อเมื่อราคานั้นเป็นของสินค้าตัวนี้จริง —
     * ถ้ามีชื่อสินค้าตัวอื่นอยู่ใกล้ราคานั้นมากกว่า แปลว่าราคาเป็นของตัวอื่น
     */
    protected function hasOwnPriceNearby(string $response, string $name, string $ownerName): bool
    {
        $namePositions = $this->positionsOf($response, $name);
        if (empty($namePositions)) {
            return false;
        }

        $nameLength = mb_strlen($name);

        foreach ($this->pricePositions($response) as $pricePos) {
            foreach ($namePositions as $namePos) {
                if ($this->gap($namePos, $nameLength, $pricePos) > self::PRICE_PROXIMITY) {
                    continue;
                }

                if ($this->nearestProductAt($response, $pricePos) === $ownerName) {
                    return true;
                }
            }
        }

        return false;
    }

    /** ตำแหน่ง (นับเป็นตัวอักษร) ของทุกครั้งที่เจอ $needle */
    protected function positionsOf(string $haystack, string $needle): array
    {
        $positions = [];
        $offset = 0;

        while (($pos = mb_stripos($haystack, $needle, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + 1;
        }

        return $positions;
    }

    /** ตำแหน่งของราคาทุกจุดในข้อความ (ตัวเลข 3 หลักขึ้นไป หรือหน่วยเงิน) */
    protected function pricePositions(string $response): array
    {
        if (! preg_match_all('/\d{3,}|บาท|฿/u', $response, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_map(
            fn ($hit) => mb_strlen(substr($response, 0, $hit[1])),
            $matches[0]
        );
    }

    /** ระยะห่างระหว่างชื่อสินค้ากับราคา (0 = ราคาอยู่ในชื่อ) */
    protected function gap(int $namePos, int $nameLength, int $pricePos): int
    {
        if ($pricePos >= $namePos && $pricePos <= $namePos + $nameLength) {
            return 0;
        }

        return $pricePos > $namePos
            ? $pricePos - ($namePos + $nameLength)
            : $namePos - $pricePos;
    }

    /** สินค้าที่ชื่อ/alias อยู่ใกล้ตำแหน่งนี้ที่สุด (ดูสินค้าทุกตัว ไม่ใช่แค่ตัวที่หมด) */
    protected function nearestProductAt(string $response, int $position): ?string
    {
        $nearest = null;
        $shortest = PHP_INT_MAX;

        foreach ($this->stockInjection->getStockStatus() as $product) {
            foreach (array_merge([$product->name], $product->aliases ?? []) as $name) {
                if (mb_strlen($name) < 2) {
                    continue;
                }

                foreach ($this->positionsOf($response, $name) as $namePos) {
                    $distance = $this->gap($namePos, mb_strlen($name), $position);
                    if ($distance < $shortest) {
                        $shortest = $distance;
                        $nearest = $product->name;
                    }
                }
            }
        }

        return $nearest;
    }

    /**
     * Check if the response has ACTIVE selling intent (cart/order/recommendation),
     * not just a price mention. Used when both selling and refusal contexts are detected
     * to distinguish informational responses from actual selling attempts.
     */
    protected function isActivelySelling(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $activePatterns = [
            // Cart/order keywords
            '/(เพิ่ม|ลง).{0,20}(ตะกร้า|cart)/iu',
            "/{$quotedName}.{0,40}(x\\d|จำนวน)/iu",
            // Payment processing near product
            "/(โอน|ชำระ|จ่าย|เลขบัญชี|QR|พร้อมเพย์).{0,60}{$quotedName}/iu",
            "/{$quotedName}.{0,60}(โอน|ชำระ|จ่าย|เลขบัญชี|QR|พร้อมเพย์)/iu",
            // Active recommendation/upsell — เชียร์ของที่ไม่มีขาย = ต้องบล็อก ไม่ใช่แค่เตือน
            "/(แนะนำ|เสนอ).{0,30}{$quotedName}/iu",
            "/รับ.{0,10}{$quotedName}.{0,10}(ไหม|มั้ย|ด้วย)/iu",
            "/{$quotedName}.{0,30}(ด้วยไหม|ดีไหม|สนใจไหม|เพิ่มไหม)/iu",
            // Order summary
            "/(สรุป|รวม|ยอด).{0,40}{$quotedName}/iu",
            "/{$quotedName}.{0,40}(สรุป|รวม[:=])/iu",
        ];

        foreach ($activePatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the response is a payment instruction (STEP 4).
     *
     * Payment instructions contain bank account details and are finalizing
     * an already-confirmed order — product names here are line items, not sales.
     */
    protected function isPaymentInstruction(string $response): bool
    {
        return mb_strpos($response, '223-3-24880-3') !== false
            || mb_strpos($response, '2233248803') !== false;
    }

    /**
     * Check if the product name appears as an order line item,
     * not as a standalone selling recommendation.
     */
    protected function isOrderLineItem(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $lineItemPatterns = [
            // Numbered list: "2. บริการเสริม Page 199 บาท"
            '/(?:^|\n)\s*\d+[\.\)]\s*(?:.*?)'.$quotedName.'/imu',
            // Bulleted list: "- Page 199 บาท"
            '/(?:^|\n)\s*[-•]\s*(?:.*?)'.$quotedName.'/imu',
            // Zero-price: "Page = 0 บาท"
            '/'.$quotedName.'\s*[=:]\s*0\s*บาท/iu',
        ];

        foreach ($lineItemPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if ALL violations are in upsell-only context (not main cart items).
     */
    protected function areAllViolationsUpsell(string $response, array $violations, Collection $outOfStock): bool
    {
        foreach ($violations as $productName) {
            $product = $outOfStock->firstWhere('name', $productName);
            if (! $product || ! $this->isUpsellContext($response, $product)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an out-of-stock product appears ONLY in an upsell question,
     * not as a main cart item or direct sale.
     */
    protected function isUpsellContext(string $response, ProductStock $product): bool
    {
        $names = array_merge([$product->name], $product->aliases ?? []);

        foreach ($names as $name) {
            if (mb_strlen($name) < 2 || mb_stripos($response, $name) === false) {
                continue;
            }

            $quotedName = preg_quote($name, '/');

            // Must match upsell question patterns
            $upsellPatterns = [
                "/รับ.{0,20}{$quotedName}.{0,30}(?:เพิ่ม|ด้วย)/iu",
                "/{$quotedName}.{0,20}(?:เพิ่มไหม|ด้วยไหม|สนใจไหม)/iu",
            ];

            $hasUpsell = false;
            foreach ($upsellPatterns as $pattern) {
                if (preg_match($pattern, $response)) {
                    $hasUpsell = true;
                    break;
                }
            }

            if (! $hasUpsell) {
                return false;
            }

            // Must NOT appear as a main cart line item
            $cartPattern = '/(?:^|\n)\s*[-•]\s*'.$quotedName.'\s*x?\d/imu';
            if (preg_match($cartPattern, $response)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip upsell block for out-of-stock products from response,
     * preserving the main cart content.
     */
    protected function stripUpsellBlock(string $response, array $violations, Collection $outOfStock): string
    {
        $stripped = $response;

        foreach ($violations as $productName) {
            $product = $outOfStock->firstWhere('name', $productName);
            $names = array_merge([$productName], $product->aliases ?? []);
            $quotedNames = array_map(fn ($n) => preg_quote($n, '/'), array_filter($names, fn ($n) => mb_strlen($n) >= 2));
            $namePattern = implode('|', $quotedNames);

            if (empty($namePattern)) {
                continue;
            }

            // Remove "รับ [product] เพิ่มไหม..." line(s) through end or "พอแล้ว" line
            $stripped = preg_replace(
                '/\n*รับ.{0,20}(?:'.$namePattern.').+(?:\n(?!(?:ตะกร้า|เพิ่ม\s|สรุป|รวม|📌|\d+\.)).+)*/iu',
                '',
                $stripped
            ) ?? $stripped;
        }

        // Remove leftover "ถ้าไม่รับพิมพ์ 'พอแล้ว'" line
        $stripped = preg_replace('/\n*.*?(?:ถ้าไม่รับ|ไม่รับพิมพ์).*?พอแล้ว.*$/imu', '', $stripped) ?? $stripped;

        $stripped = rtrim($stripped);

        // Append a closing prompt if response now ends abruptly (no question/period)
        if (! preg_match('/(ครับ|ค่ะ|เลย|ได้|ไหม|มั้ย|[?？])\s*$/u', $stripped)) {
            $stripped .= "\n\nถูกต้องไหมครับ? พิมพ์ 'ยืนยัน' ได้เลยครับ";
        }

        return $stripped;
    }

    /** ต่อท้ายว่าสินค้าหมด โดยไม่ทิ้งคำตอบเดิมที่ลูกค้าถามมา */
    protected function appendStockNotice(string $response, array $products): string
    {
        return rtrim($response)."\n\nตอนนี้ ".implode(', ', $products).' หมดสต็อกชั่วคราวครับ';
    }

    /**
     * Build a replacement response when violation is detected.
     *
     * ไม่เสนอสินค้าทดแทนเอง — สินค้าตัวไหนใช้แทนกันได้เป็นเรื่องที่ prompt รู้ ไม่ใช่ guard
     * (เคยเสนอ "ทุกตัวที่มีของ" แล้วไปเชียร์ G3D ให้ลูกค้าที่จะยิงแอด ซึ่ง G3D ยิงแอดไม่ได้)
     */
    protected function buildReplacementResponse(array $violations): string
    {
        $violationList = implode(', ', $violations);

        return "ขออภัยครับ ขณะนี้ {$violationList} หมด stock ชั่วคราว ไม่สามารถสั่งซื้อได้ครับ"
            ."\n\nหากสนใจสินค้าอื่น หรือต้องการให้แจ้งเมื่อสินค้ากลับมา สามารถบอกได้เลยครับ";
    }
}
