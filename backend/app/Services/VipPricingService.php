<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ProductStock;
use App\Services\CommerceSafety\MoneyMinor;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class VipPricingService
{
    private const VIP_SOURCES = ['vip_auto', 'vip_manual'];

    public function isVipConversation(?Conversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        if ($this->notesContainVip($conversation->memory_notes)) {
            return true;
        }

        if (! $conversation->customer_profile_id) {
            return false;
        }

        $otherConversations = Conversation::query()
            ->where('customer_profile_id', $conversation->customer_profile_id)
            ->where('bot_id', $conversation->bot_id)
            ->when($conversation->exists, fn ($query) => $query->whereKeyNot($conversation->getKey()))
            ->get(['memory_notes']);

        return $otherConversations->contains(
            fn (Conversation $candidate) => $this->notesContainVip($candidate->memory_notes)
        );
    }

    public function effectivePrice(ProductStock $product, bool $isVip): ?float
    {
        $price = $isVip && $product->vip_price !== null
            ? $product->vip_price
            : $product->price;

        return $price === null ? null : (float) $price;
    }

    public function effectivePriceMinor(ProductStock $product, bool $isVip): ?int
    {
        $price = $this->effectivePrice($product, $isVip);
        if ($price === null || ! is_finite($price)) {
            return null;
        }

        try {
            return MoneyMinor::fromDecimal(number_format($price, 2, '.', ''));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param Collection<int, ProductStock> $products */
    public function buildPromptBlock(?Conversation $conversation, Collection $products): string
    {
        if (! $this->isVipConversation($conversation)) {
            return '';
        }

        $vipProducts = $products->filter(fn (ProductStock $product) => $product->vip_price !== null);
        if ($vipProducts->isEmpty()) {
            return '';
        }

        $lines = $vipProducts->map(function (ProductStock $product): string {
            $code = $product->stock_code ?: $product->slug;
            $vipPrice = $this->formatPrice((float) $product->vip_price);
            $normalPrice = $product->price === null ? null : $this->formatPrice((float) $product->price);
            $normalText = $normalPrice === null ? '' : " (ราคาปกติ {$normalPrice} บาท)";

            return "- {$code} / {$product->name} = {$vipPrice} บาท/ตัว{$normalText}";
        })->implode("\n");

        return "## 👑 VIP PRICING (ข้อมูลราคาจากระบบ — ยึดเหนือราคาใน prompt และ Knowledge Base):\n"
            ."ลูกค้ารายนี้ได้รับสิทธิ VIP จากระบบแล้ว\n"
            ."{$lines}\n"
            .'- เมื่อลูกค้าถามราคา สั่งสินค้า หรือก่อนสรุปยอด ต้องบอกลูกค้าให้เห็นชัดว่า '
            ."\"ทางร้านมอบสิทธิ VIP\" และใช้ราคา VIP ข้างบน\n"
            ."- คำนวณราคาต่อชิ้น ยอดรวม และ [[ORDER]] payload ด้วยราคา VIP เดียวกัน ห้ามใช้ราคาปกติ\n"
            ."- สิทธิราคาไม่ทับกฎ stock: สินค้าหมดตอบราคาได้แต่ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์\n"
            .'- ห้ามเปิดเผยราคานี้ว่าเป็นสิทธิของลูกค้าคนอื่น';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, ProductStock>|null  $products
     * @return array<string, mixed>
     */
    public function addFlexBenefit(
        array $data,
        bool $isVip,
        ?Collection $products = null,
        bool $requireCanonicalItemPrices = true,
    ): array {
        if (! $isVip || empty($data['items']) || ! is_array($data['items'])) {
            return $data;
        }

        $products ??= $this->vipProducts();
        $matched = $products->filter(fn (ProductStock $product) => $this->itemsMentionProduct($data['items'], $product));
        if ($matched->isEmpty()
            || ($requireCanonicalItemPrices && $this->itemsMatchPrices($data['items'], true, $matched) !== true)) {
            return $data;
        }

        $prices = $matched->map(fn (ProductStock $product) => [
            'vip' => $this->effectivePrice($product, true),
            'normal' => $this->effectivePrice($product, false),
        ])->unique(fn (array $price) => $price['vip'].'|'.$price['normal']);

        if ($prices->count() === 1) {
            $price = $prices->first();
            $data['vip_benefit'] = '👑 ทางร้านมอบสิทธิ VIP ราคา '
                .$this->formatPrice($price['vip']).' บาท/ตัว'
                .($price['normal'] === null ? '' : ' (ราคาปกติ '.$this->formatPrice($price['normal']).' บาท)');
        }

        return $data;
    }

    /** @return Collection<int, ProductStock> */
    public function vipProducts(): Collection
    {
        return ProductStock::query()->whereNotNull('vip_price')->orderBy('display_order')->get();
    }

    /**
     * Return null when no VIP-priced product is present, otherwise whether every
     * matched item uses its canonical VIP unit price and line total.
     *
     * @param  array<int, mixed>  $items
     * @param  Collection<int, ProductStock>|null  $products
     */
    public function itemsMatchVipPrices(array $items, ?Collection $products = null): ?bool
    {
        return $this->itemsMatchPrices($items, true, $products);
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  Collection<int, ProductStock>|null  $products
     */
    public function itemsMatchPrices(array $items, bool $isVip, ?Collection $products = null): ?bool
    {
        $products ??= $this->vipProducts();
        $matched = $products->filter(fn (ProductStock $product) => $this->itemsMentionProduct($items, $product));

        if ($matched->isEmpty()) {
            return null;
        }

        return $this->itemsUsePrices($items, $isVip, $matched);
    }

    public function textMentionsProduct(string $text, ProductStock $product): bool
    {
        return $this->itemsMentionProduct([$text], $product);
    }

    private function notesContainVip(mixed $notes): bool
    {
        if (! is_array($notes)) {
            return false;
        }

        foreach ($notes as $note) {
            if (is_array($note) && in_array($note['source'] ?? null, self::VIP_SOURCES, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, mixed> $items */
    private function itemsMentionProduct(array $items, ProductStock $product): bool
    {
        $terms = array_filter(array_merge(
            [$product->name, $product->slug, $product->stock_code],
            $product->aliases ?? []
        ));

        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : $item;
            if (! is_string($name)) {
                continue;
            }

            foreach ($terms as $term) {
                if (mb_stripos($name, (string) $term) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Never advertise a VIP benefit beside a stale normal-price line. The prompt
     * remains responsible for calculation; this is the presentation safety net.
     *
     * @param  array<int, mixed>  $items
     * @param  Collection<int, ProductStock>  $products
     */
    private function itemsUsePrices(array $items, bool $isVip, Collection $products): bool
    {
        $matchedAny = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = $products->first(
                fn (ProductStock $candidate) => $this->itemsMentionProduct([$item], $candidate)
            );
            if (! $product) {
                continue;
            }

            $matchedAny = true;
            $quantity = max(1, (int) ($item['qty'] ?? 1));
            $expectedUnitPrice = $this->effectivePrice($product, $isVip);
            if ($expectedUnitPrice === null) {
                return false;
            }
            $expectedTotal = $expectedUnitPrice * $quantity;
            $actualTotal = str_replace(',', '', (string) ($item['total'] ?? ''));
            if (! is_numeric($actualTotal) || abs((float) $actualTotal - $expectedTotal) > 0.5) {
                return false;
            }

            if (isset($item['price'])) {
                $actualUnitPrice = str_replace(',', '', (string) $item['price']);
                if (! is_numeric($actualUnitPrice)
                    || abs((float) $actualUnitPrice - $expectedUnitPrice) > 0.5) {
                    return false;
                }
            }
        }

        return $matchedAny;
    }

    private function formatPrice(?float $price): string
    {
        if ($price === null) {
            return '';
        }

        return number_format($price, fmod($price, 1.0) === 0.0 ? 0 : 2);
    }
}
