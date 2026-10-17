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
     * @return array{content: string, order_payload: ?array, corrected: bool, reason: ?array}
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
        // เหตุผลที่จับได้ — ส่งให้ caller log ไว้ย้อนตรวจ false positive (ข้อความเดิมจะถูกทับ)
        $reason = match (true) {
            $invalidVisibleOrder => ['type' => 'visible_order'],
            $invalidPayload => ['type' => 'order_payload'],
            $visiblePriceStatus === null => $this->findIncorrectInformationalPrice($content, $products, $isVip),
            default => null,
        };

        if ($reason !== null) {
            return [
                'content' => $this->correctiveMessage($products, $isVip),
                'order_payload' => null,
                'corrected' => true,
                'reason' => $reason,
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

    /**
     * @param  Collection<int, ProductStock>  $products
     * @return array{type: string, line: string, amounts: array<int, float>, expected: float}|null
     */
    private function findIncorrectInformationalPrice(
        string $content,
        Collection $products,
        bool $isVip,
    ): ?array {
        $lines = preg_split('/\R/u', $content) ?: [$content];
        $allProducts = ProductStock::query()->get();

        foreach ($lines as $line) {
            foreach ($products as $product) {
                if (! $this->pricing->textMentionsProduct($line, $product)) {
                    continue;
                }

                preg_match_all('/(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(?:บาท|฿|\.\-)/u', $line, $matches, PREG_OFFSET_CAPTURE);
                $amounts = [];
                foreach ($matches[1] ?? [] as [$amount, $offset]) {
                    $value = (float) str_replace(',', '', $amount);
                    if ($this->isAdSpendLimitAmount($line, $offset)
                        || $this->isAnotherMentionedProductPrice($line, $value, $products, $allProducts)
                        || $this->isMultiProductTotal($line, $offset, $value, $products, $allProducts, $isVip)) {
                        continue;
                    }
                    $amounts[] = $value;
                }
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

                return ['type' => 'informational_price', 'line' => $line, 'amounts' => $amounts, 'expected' => $expected];
            }
        }

        return null;
    }

    private const LIMIT_KEYWORD_PATTERN = '/(?<![a-z])limit|ลิมิต|วงเงิน/iu';

    private const TOTAL_KEYWORD_PATTERN = '/รวม|ยอด|ทั้งหมด|เป็น/u';

    private const QUANTITY_PATTERN = '/[x×]\s*(\d+)|(\d+)\s*(?:แอคเคานต์|บัญชี|ตัว|เพจ|ชิ้น|อัน|แอค)/iu';

    /**
     * Ad-account spending limits (วงเงิน/Limit) are not product prices. The
     * keyword may sit anywhere within the previous 60 characters of the same
     * line, but a ราคา between the keyword and the amount means the amount is
     * a real price again ("วงเงินเริ่ม 1,600 ราคา 1,100 บาท").
     */
    private function isAdSpendLimitAmount(string $line, int $amountOffset): bool
    {
        $window = mb_substr(substr($line, 0, $amountOffset), -60);
        $keywordCount = preg_match_all(self::LIMIT_KEYWORD_PATTERN, $window, $matches, PREG_OFFSET_CAPTURE);
        if ($keywordCount === false || $keywordCount === 0) {
            return false;
        }

        foreach ($matches[0] as [$keyword, $keywordOffset]) {
            $betweenKeywordAndAmount = substr($window, $keywordOffset + strlen($keyword));
            if (mb_stripos($betweenKeywordAndAmount, 'ราคา') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A grand total over several products named on one line ("Personal 2 ตัว
     * และเพจ 2 เพจ รวม 2,598 บาท") is consistent when a total keyword precedes
     * the amount and the amount equals Σ stated quantity × effective price
     * over the products mentioned on that line (guarded products use their
     * VIP/normal effective price, other products their normal price).
     *
     * @param  Collection<int, ProductStock>  $guardedProducts
     * @param  Collection<int, ProductStock>  $allProducts
     */
    private function isMultiProductTotal(
        string $line,
        int $amountOffset,
        float $amount,
        Collection $guardedProducts,
        Collection $allProducts,
        bool $isVip,
    ): bool {
        $beforeAmount = mb_substr(substr($line, 0, $amountOffset), -20);
        if (preg_match(self::TOTAL_KEYWORD_PATTERN, $beforeAmount) !== 1) {
            return false;
        }

        $sum = 0.0;
        foreach ($allProducts as $product) {
            $mention = $this->firstProductMention($line, $product);
            if ($mention === null) {
                continue;
            }
            [$mentionOffset, $term] = $mention;
            $quantity = $this->statedQuantityAfter($line, $mentionOffset + mb_strlen($term));
            $isGuarded = $guardedProducts->contains(fn (ProductStock $guarded) => $guarded->is($product));
            $price = $isGuarded
                ? $this->pricing->effectivePrice($product, $isVip)
                : ($product->price === null ? null : (float) $product->price);
            if ($price === null) {
                return false;
            }
            $sum += $quantity * $price;
        }

        return $sum > 0 && abs($sum - $amount) <= 0.5;
    }

    /**
     * Earliest occurrence of the product's name/slug/code/aliases on the line
     * (a product named through several aliases counts once).
     *
     * @return array{int, string}|null [character offset, matched term]
     */
    private function firstProductMention(string $line, ProductStock $product): ?array
    {
        $terms = array_filter(array_merge(
            [$product->name, $product->slug, $product->stock_code],
            $product->aliases ?? []
        ));
        $best = null;
        foreach ($terms as $term) {
            if (! is_string($term) || $term === '') {
                continue;
            }
            $position = mb_stripos($line, $term);
            if ($position !== false && ($best === null || $position < $best[0])) {
                $best = [$position, $term];
            }
        }

        return $best;
    }

    /**
     * First quantity stated within 25 characters after a product mention
     * ("N ตัว|บัญชี|แอคเคานต์|แอค|เพจ|ชิ้น|อัน" or "x N" / "×N"); 1 if none.
     */
    private function statedQuantityAfter(string $line, int $mentionEndOffset): int
    {
        $window = mb_substr($line, $mentionEndOffset, 25);
        if (preg_match(self::QUANTITY_PATTERN, $window, $match) === 1) {
            return (int) (($match[1] ?? '') !== '' ? $match[1] : $match[2]);
        }

        return 1;
    }

    /** @param Collection<int, ProductStock> $guardedProducts
     * @param  Collection<int, ProductStock>  $allProducts
     */
    private function isAnotherMentionedProductPrice(
        string $line,
        float $amount,
        Collection $guardedProducts,
        Collection $allProducts,
    ): bool {
        foreach ($allProducts as $otherProduct) {
            if ($guardedProducts->contains(fn (ProductStock $guardedProduct) => $guardedProduct->is($otherProduct))
                || ! $this->pricing->textMentionsProduct($line, $otherProduct)
                || $otherProduct->price === null) {
                continue;
            }

            if (abs($amount - (float) $otherProduct->price) <= 0.5) {
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

    /** @return array{content: string, order_payload: ?array, corrected: false, reason: null} */
    private function unchanged(string $content, ?array $orderPayload): array
    {
        return ['content' => $content, 'order_payload' => $orderPayload, 'corrected' => false, 'reason' => null];
    }
}
