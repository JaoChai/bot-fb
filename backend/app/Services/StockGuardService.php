<?php

namespace App\Services;

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

        $violations = $this->detectViolations($responseToCheck, $outOfStock);

        if (empty($violations)) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        Log::warning('StockGuard: blocked out-of-stock sale', [
            'violations' => $violations,
            'original_response_preview' => mb_substr($response, 0, 300),
            'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
        ]);

        $allStocks = $this->stockInjection->getStockStatus();
        $replacement = $this->buildReplacementResponse($violations, $allStocks);

        return [
            'content' => $replacement,
            'blocked' => true,
            'blocked_products' => $violations,
        ];
    }

    /**
     * Detect if response is SELLING (not just mentioning) out-of-stock products.
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
                $isSelling = $this->isSellingContext($response, $name);

                // In payment instructions, product names as line items are not violations
                if ($isSelling && $isPayment && $this->isOrderLineItem($response, $name)) {
                    continue;
                }

                // If selling context detected, it's a violation even if refusal is nearby
                // (prevents bypass: "BM หมดชั่วคราว แต่ Page ราคา 199 บาท" — Page is selling)
                if ($isSelling) {
                    $violations[] = $product->name;
                    break;
                }

                // Only skip if refusal detected WITHOUT any selling context
                if ($isRefused) {
                    continue;
                }
            }
        }

        return array_unique($violations);
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
    protected function isSellingContext(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $sellingPatterns = [
            // Price near product name
            "/{$quotedName}.{0,60}(\\d{3,}|บาท|฿)/iu",
            "/(\\d{3,}|บาท|฿).{0,60}{$quotedName}/iu",
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
     * Build a replacement response when violation is detected.
     */
    protected function buildReplacementResponse(array $violations, Collection $allStocks): string
    {
        $inStock = $allStocks->where('in_stock', true);
        $violationList = implode(', ', $violations);

        $response = "ขออภัยครับ ขณะนี้ {$violationList} หมด stock ชั่วคราว ไม่สามารถสั่งซื้อได้ครับ";

        if ($inStock->isNotEmpty()) {
            $availableList = $inStock->pluck('name')->implode(', ');
            $response .= "\n\nสินค้าที่พร้อมให้บริการตอนนี้: {$availableList} ครับ";
        }

        $response .= "\n\nหากสนใจสินค้าอื่น หรือต้องการให้แจ้งเมื่อสินค้ากลับมา สามารถบอกได้เลยครับ";

        return $response;
    }
}
