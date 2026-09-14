<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ProductStock;
use App\Services\Payment\PaymentMessageDetector;
use Illuminate\Support\Collection;

class VipPriceGuardService
{
    public function __construct(
        private readonly VipPricingService $pricing,
        private readonly PaymentMessageDetector $detector,
    ) {}

    /**
     * Fail closed when an LLM response or hidden order payload uses a
     * non-canonical price for NLMP/NLMBM.
     *
     * @param  array{items?: array<int, mixed>, total?: float}|null  $orderPayload
     * @return array{content: string, order_payload: ?array, corrected: bool}
     */
    public function enforce(string $content, ?array $orderPayload, ?Conversation $conversation): array
    {
        $isVip = $this->pricing->isVipConversation($conversation);
        $products = $this->pricing->vipProducts();
        if ($products->isEmpty()) {
            return $this->unchanged($content, $orderPayload);
        }

        $visibleOrder = $this->detector->parsePaymentData($content)
            ?? $this->detector->parseConfirmData($content);
        $visiblePriceStatus = is_array($visibleOrder) && isset($visibleOrder['items'])
            ? $this->pricing->itemsMatchPrices($visibleOrder['items'], $isVip, $products)
            : null;

        $invalidVisibleOrder = $this->orderDataIsInvalid($visibleOrder, $isVip, $products);
        $invalidPayload = $this->orderDataIsInvalid($orderPayload, $isVip, $products);
        $invalidInformationalPrice = $visiblePriceStatus === null
            && $this->containsIncorrectInformationalPrice($content, $products, $isVip);

        if ($invalidVisibleOrder || $invalidPayload || $invalidInformationalPrice) {
            return [
                'content' => $this->correctiveMessage($products, $isVip),
                'order_payload' => null,
                'corrected' => true,
            ];
        }

        return $this->unchanged($content, $orderPayload);
    }

    /**
     * @param  array{items?: array<int, mixed>, total?: mixed}|null  $data
     * @param  Collection<int, ProductStock>  $products
     */
    private function orderDataIsInvalid(?array $data, bool $isVip, Collection $products): bool
    {
        if (! isset($data['items']) || ! is_array($data['items'])) {
            return false;
        }

        $priceStatus = $this->pricing->itemsMatchPrices($data['items'], $isVip, $products);
        if ($priceStatus === false) {
            return true;
        }
        $normalizedTotal = isset($data['total'])
            ? str_replace(',', '', (string) $data['total'])
            : '';
        if ($priceStatus !== true || ! is_numeric($normalizedTotal)) {
            return false;
        }

        return PaymentMessageDetector::itemsMatchTotal($data['items'], (float) $normalizedTotal) === false;
    }

    /** @param Collection<int, ProductStock> $products */
    private function containsIncorrectInformationalPrice(
        string $content,
        Collection $products,
        bool $isVip,
    ): bool {
        $lines = preg_split('/\R/u', $content) ?: [$content];

        foreach ($lines as $line) {
            foreach ($products as $product) {
                if (! $this->pricing->textMentionsProduct($line, $product)) {
                    continue;
                }

                preg_match_all('/(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(?:บาท|฿|\.\-)/u', $line, $matches);
                $amounts = array_map(
                    fn (string $amount): float => (float) str_replace(',', '', $amount),
                    $matches[1] ?? []
                );
                if ($amounts === []) {
                    continue;
                }

                $expected = $this->pricing->effectivePrice($product, $isVip);
                if ($expected === null) {
                    continue;
                }

                if ($this->amountsContainExpectedPrice($line, $amounts, $expected)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /** @param array<int, float> $amounts */
    private function amountsContainExpectedPrice(string $line, array $amounts, float $expected): bool
    {
        foreach ($amounts as $amount) {
            if (abs($amount - $expected) <= 0.5) {
                return true;
            }
        }

        if (preg_match('/(?:[x×]\s*(\d+)|(\d+)\s*(?:ตัว|ชิ้น|อัน|ใบ))/u', $line, $quantityMatch)) {
            $quantity = (int) (($quantityMatch[1] ?? '') !== '' ? $quantityMatch[1] : $quantityMatch[2]);
            foreach ($amounts as $amount) {
                if ($quantity > 1 && abs($amount - ($expected * $quantity)) <= 0.5) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param Collection<int, ProductStock> $products */
    private function correctiveMessage(Collection $products, bool $isVip): string
    {
        $codes = $products->map(fn (ProductStock $product) => $product->stock_code ?: $product->slug)->implode(' และ ');
        $prices = $products->map(fn (ProductStock $product) => $this->pricing->effectivePrice($product, $isVip))->unique();
        $price = $prices->count() === 1 ? number_format((float) $prices->first()) : null;

        if ($isVip && $price !== null) {
            $normalPrices = $products->map(fn (ProductStock $product) => $this->pricing->effectivePrice($product, false))->unique();
            $normal = $normalPrices->count() === 1 ? number_format((float) $normalPrices->first()) : null;
            $normalText = $normal === null ? '' : " (ราคาปกติ {$normal} บาท)";
            $pricingText = "👑 ทางร้านมอบสิทธิ VIP สำหรับ {$codes} ราคา {$price} บาท/ตัว{$normalText}ครับ";
        } elseif ($price !== null) {
            $pricingText = "ราคาปกติสำหรับ {$codes} คือ {$price} บาท/ตัวครับ";
        } else {
            $pricingText = "ขออภัยครับ ระบบพบว่าราคา {$codes} ในรายการไม่ถูกต้อง";
        }

        return $pricingText."\nรบกวนพี่พิมพ์รายการและจำนวนอีกครั้ง เดี๋ยวสรุปยอดใหม่ให้ถูกต้องและตรวจสต็อกให้ครับ";
    }

    /** @return array{content: string, order_payload: ?array, corrected: false} */
    private function unchanged(string $content, ?array $orderPayload): array
    {
        return ['content' => $content, 'order_payload' => $orderPayload, 'corrected' => false];
    }
}
