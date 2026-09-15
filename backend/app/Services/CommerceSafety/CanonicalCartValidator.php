<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Services\VipPricingService;
use Illuminate\Support\Collection;

class CanonicalCartValidator
{
    public function __construct(
        private readonly VipPricingService $pricing,
    ) {}

    public function validate(
        Bot $bot,
        Conversation $conversation,
        array $proposedLines,
        int $claimedTotalMinor,
        bool $lockAuthorityRows = false,
    ): CartValidation {
        $errors = [];
        $conversationQuery = Conversation::query();
        if ($lockAuthorityRows) {
            $conversationQuery->lockForUpdate();
        }
        $currentConversation = $conversation->exists
            ? $conversationQuery->find($conversation->getKey())
            : null;
        if (! $currentConversation || (int) $currentConversation->bot_id !== (int) $bot->getKey()) {
            $errors[] = 'CONVERSATION_MISMATCH';
            $currentConversation = new Conversation(['memory_notes' => []]);
        }

        // VIP authority may live on another conversation for the same customer.
        // Lock every persisted source before pricing reads it so entitlement cannot
        // change between canonical validation and the local settlement commit.
        if ($lockAuthorityRows && $currentConversation->exists && $currentConversation->customer_profile_id) {
            Conversation::query()
                ->where('bot_id', $currentConversation->bot_id)
                ->where('customer_profile_id', $currentConversation->customer_profile_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

        $vip = $this->pricing->isVipConversation($currentConversation);
        $productQuery = ProductStock::query()->orderBy('id');
        if ($lockAuthorityRows) {
            $productQuery->lockForUpdate();
        }
        $products = $productQuery->get();
        $termIndex = $this->termIndex($products);
        $skuIndex = $this->skuIndex($products);
        $aggregated = [];
        $capacity = [];

        if ($proposedLines === []) {
            $errors[] = 'EMPTY_CART';
        }

        foreach ($proposedLines as $line) {
            if (! is_array($line)) {
                $errors[] = 'INVALID_LINE';

                continue;
            }

            $name = $line['name'] ?? null;
            $method = $line['method'] ?? null;
            $qty = $line['qty'] ?? null;
            $priceMinor = $line['price_minor'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                $errors[] = 'UNKNOWN_OR_AMBIGUOUS_PRODUCT';

                continue;
            }
            if (! is_int($qty) || $qty <= 0) {
                $errors[] = 'INVALID_QUANTITY';

                continue;
            }
            if (! is_int($priceMinor) || $priceMinor <= 0) {
                $errors[] = 'INVALID_PRICE';

                continue;
            }

            $matches = $termIndex[$this->normalizeName($name)] ?? [];
            if (count($matches) !== 1) {
                $errors[] = 'UNKNOWN_OR_AMBIGUOUS_PRODUCT';

                continue;
            }

            /** @var ProductStock $product */
            $product = $matches[0];
            if (! is_string($method)
                || ($this->isNolimit($product) && ! in_array($method, ['card', 'topup'], true))) {
                $errors[] = 'SALE_METHOD_REQUIRED';

                continue;
            }
            if (! $this->isNolimit($product) && $method !== 'none') {
                $errors[] = 'SALE_METHOD_INVALID';

                continue;
            }

            $expectedPrice = $this->pricing->effectivePriceMinor($product, $vip);
            if ($expectedPrice === null) {
                $errors[] = 'PRICE_UNKNOWN';

                continue;
            }
            if ($priceMinor !== $expectedPrice) {
                $errors[] = 'PRICE_MISMATCH';
            }
            if ($expectedPrice > intdiv(PHP_INT_MAX, $qty)) {
                $errors[] = 'ARITHMETIC_OVERFLOW';

                continue;
            }

            $sku = trim((string) ($product->stock_code ?: $product->slug));
            if ($sku === '') {
                $errors[] = 'SKU_UNKNOWN';

                continue;
            }
            $skuKey = mb_strtolower($sku);
            $skuProducts = $skuIndex[$skuKey] ?? [];
            if (! $this->hasConsistentSkuRows($skuProducts, $vip)) {
                $errors[] = 'AMBIGUOUS_SKU';

                continue;
            }

            /** @var ProductStock $canonicalProduct */
            $canonicalProduct = $skuProducts[0] ?? $product;
            $deliveryMethod = $canonicalProduct->delivery_method;
            if (! is_string($deliveryMethod) || ! in_array($deliveryMethod, ['stock', 'support_link', 'none'], true)) {
                $errors[] = 'DELIVERY_METHOD_UNKNOWN';

                continue;
            }
            $key = $skuKey.'|'.$method;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product' => $canonicalProduct,
                    'delivery_method' => $deliveryMethod,
                    'product_id' => (int) $canonicalProduct->getKey(),
                    'sku' => trim((string) ($canonicalProduct->stock_code ?: $canonicalProduct->slug)),
                    'name' => (string) $canonicalProduct->name,
                    'method' => $method,
                    'qty' => 0,
                    'price_minor' => $expectedPrice,
                    'line_total_minor' => 0,
                ];
            }

            if ($aggregated[$key]['qty'] > PHP_INT_MAX - $qty) {
                $errors[] = 'ARITHMETIC_OVERFLOW';

                continue;
            }
            $aggregated[$key]['qty'] += $qty;
            if ($expectedPrice > intdiv(PHP_INT_MAX, $aggregated[$key]['qty'])) {
                $errors[] = 'ARITHMETIC_OVERFLOW';

                continue;
            }
            $aggregated[$key]['line_total_minor'] = $expectedPrice * $aggregated[$key]['qty'];

            if (! isset($capacity[$skuKey])) {
                $capacity[$skuKey] = [
                    'product' => $canonicalProduct,
                    'delivery_method' => $deliveryMethod,
                    'qty' => 0,
                ];
            }
            if ($capacity[$skuKey]['qty'] > PHP_INT_MAX - $qty) {
                $errors[] = 'ARITHMETIC_OVERFLOW';

                continue;
            }
            $capacity[$skuKey]['qty'] += $qty;
        }

        $totalMinor = 0;
        $requiresManualHandling = false;
        $maxQty = max(1, (int) config('delivery.max_qty', 20));
        foreach ($aggregated as $entry) {
            if ($totalMinor > PHP_INT_MAX - $entry['line_total_minor']) {
                $errors[] = 'ARITHMETIC_OVERFLOW';
            } else {
                $totalMinor += $entry['line_total_minor'];
            }

            if ($entry['qty'] > $maxQty) {
                $errors[] = 'AUTOMATION_LIMIT_EXCEEDED';
                $requiresManualHandling = true;
            }
        }

        foreach ($capacity as $entry) {
            /** @var ProductStock $product */
            $product = $entry['product'];
            if ($product->manual_off || ! $product->in_stock) {
                $errors[] = 'OUT_OF_STOCK';

                continue;
            }
            if ($entry['delivery_method'] === 'stock') {
                if ($product->available_count === null) {
                    $errors[] = 'STOCK_UNKNOWN';
                } elseif ($entry['qty'] > $product->available_count) {
                    $errors[] = 'INSUFFICIENT_STOCK';
                }
            }
        }

        if ($claimedTotalMinor <= 0) {
            $errors[] = 'INVALID_TOTAL';
        } elseif ($claimedTotalMinor !== $totalMinor) {
            $errors[] = 'TOTAL_MISMATCH';
        }

        $lines = array_values(array_map(function (array $entry): array {
            unset($entry['product'], $entry['delivery_method']);

            return $entry;
        }, $aggregated));
        usort($lines, fn (array $a, array $b): int => [$a['product_id'], $a['method']] <=> [$b['product_id'], $b['method']]);

        $fingerprint = hash('sha256', json_encode([
            'lines' => $lines,
            'total_minor' => $totalMinor,
            'entitlement' => ['vip' => $vip],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $errors = array_values(array_unique($errors));

        return new CartValidation(
            valid: $errors === [],
            errors: $errors,
            lines: $lines,
            totalMinor: $totalMinor,
            vip: $vip,
            fingerprint: $fingerprint,
            requiresManualHandling: $requiresManualHandling,
        );
    }

    /** @param Collection<int, ProductStock> $products */
    private function termIndex(Collection $products): array
    {
        $index = [];
        foreach ($products as $product) {
            foreach (array_merge([$product->name], $product->aliases ?? []) as $term) {
                if (! is_string($term) || trim($term) === '') {
                    continue;
                }
                $index[$this->normalizeName($term)][$product->getKey()] = $product;
            }
        }

        return array_map('array_values', $index);
    }

    /** @param Collection<int, ProductStock> $products */
    private function skuIndex(Collection $products): array
    {
        $index = [];
        foreach ($products as $product) {
            $sku = trim((string) ($product->stock_code ?: $product->slug));
            if ($sku !== '') {
                $index[mb_strtolower($sku)][] = $product;
            }
        }

        return $index;
    }

    /** @param list<ProductStock> $products */
    private function hasConsistentSkuRows(array $products, bool $vip): bool
    {
        $signature = null;
        foreach ($products as $product) {
            $current = [
                'price_minor' => $this->pricing->effectivePriceMinor($product, $vip),
                'delivery_method' => $product->delivery_method,
                'manual_off' => (bool) $product->manual_off,
                'in_stock' => (bool) $product->in_stock,
                'available_count' => $product->available_count,
            ];
            if ($signature !== null && $current !== $signature) {
                return false;
            }
            $signature = $current;
        }

        return true;
    }

    private function isNolimit(ProductStock $product): bool
    {
        $sku = mb_strtolower(trim((string) ($product->stock_code ?: $product->slug)));

        return in_array($sku, ['nlmp', 'nlmbm'], true)
            || str_contains(mb_strtolower((string) $product->name), 'nolimit');
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
