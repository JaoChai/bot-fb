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
    ): CartValidation {
        $errors = [];
        $currentConversation = $conversation->exists
            ? Conversation::query()->find($conversation->getKey())
            : $conversation;
        if (! $currentConversation || (int) $currentConversation->bot_id !== (int) $bot->getKey()) {
            $errors[] = 'CONVERSATION_MISMATCH';
            $currentConversation = new Conversation(['memory_notes' => []]);
        }

        $vip = $this->pricing->isVipConversation($currentConversation);
        $products = ProductStock::query()->orderBy('id')->get();
        $termIndex = $this->termIndex($products);
        $aggregated = [];

        if ($proposedLines === []) {
            $errors[] = 'EMPTY_CART';
        }

        foreach ($proposedLines as $line) {
            if (! is_array($line)) {
                $errors[] = 'INVALID_LINE';

                continue;
            }

            $name = $line['name'] ?? null;
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

            $method = $product->delivery_method;
            if (! is_string($method) || ! in_array($method, ['stock', 'support_link', 'none'], true)) {
                $errors[] = 'DELIVERY_METHOD_UNKNOWN';

                continue;
            }
            $key = $product->getKey().'|'.$method;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'product' => $product,
                    'product_id' => (int) $product->getKey(),
                    'sku' => (string) ($product->stock_code ?: $product->slug),
                    'name' => (string) $product->name,
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
        }

        $totalMinor = 0;
        $requiresManualHandling = false;
        $maxQty = max(1, (int) config('delivery.max_qty', 20));
        foreach ($aggregated as $entry) {
            /** @var ProductStock $product */
            $product = $entry['product'];
            if ($totalMinor > PHP_INT_MAX - $entry['line_total_minor']) {
                $errors[] = 'ARITHMETIC_OVERFLOW';
            } else {
                $totalMinor += $entry['line_total_minor'];
            }

            if ($entry['qty'] > $maxQty) {
                $errors[] = 'AUTOMATION_LIMIT_EXCEEDED';
                $requiresManualHandling = true;
            }
            if ($product->manual_off || ! $product->in_stock) {
                $errors[] = 'OUT_OF_STOCK';

                continue;
            }
            if ($entry['method'] === 'stock') {
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
            unset($entry['product']);

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

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
