<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\ProductStock;
use App\Services\VipPricingService;

class CheckoutConsentPolicy
{
    private const VIP_SOURCES = ['vip_auto', 'vip_manual'];

    public function __construct(
        private readonly VipPricingService $vipPricing,
    ) {}

    /**
     * @param  list<array{product_id:int,sku:string,name:string,method:string,qty:int,price_minor:int,line_total_minor:int}>  $canonicalItems
     * @return array{topup_ack:bool,support_delay:bool,terms:bool,sources:array{vip_note_ids:list<string>,vip_conversation_ids:list<int>,completed_order_id:?int}}
     */
    public function requirements(Bot $bot, Conversation $conversation, array $canonicalItems): array
    {
        $vip = $this->vipPricing->isVipConversation($conversation);
        $vipSources = $vip ? $this->vipSources($bot, $conversation) : [[], []];
        $completedOrderId = $this->completedOrderId($bot, $conversation);
        $hasNolimit = $this->hasNolimit($canonicalItems);

        return [
            'topup_ack' => collect($canonicalItems)->contains(
                fn (array $item): bool => ($item['method'] ?? null) === 'topup'
            ),
            'support_delay' => $hasNolimit && ! $vip,
            'terms' => ! $vip && $completedOrderId === null,
            'sources' => [
                'vip_note_ids' => $vipSources[0],
                'vip_conversation_ids' => $vipSources[1],
                'completed_order_id' => $completedOrderId,
            ],
        ];
    }

    /** @return array{list<string>,list<int>} */
    private function vipSources(Bot $bot, Conversation $conversation): array
    {
        $query = Conversation::query()->where('bot_id', $bot->getKey());
        if ($conversation->customer_profile_id) {
            $query->where('customer_profile_id', $conversation->customer_profile_id);
        } else {
            $query->whereKey($conversation->getKey());
        }

        $noteIds = [];
        $conversationIds = [];
        foreach ($query->get(['id', 'memory_notes']) as $candidate) {
            foreach ($candidate->memory_notes ?? [] as $note) {
                if (! is_array($note) || ! in_array($note['source'] ?? null, self::VIP_SOURCES, true)) {
                    continue;
                }

                $conversationIds[] = (int) $candidate->getKey();
                if (is_string($note['id'] ?? null) && $note['id'] !== '') {
                    $noteIds[] = $note['id'];
                }
            }
        }

        return [array_values(array_unique($noteIds)), array_values(array_unique($conversationIds))];
    }

    private function completedOrderId(Bot $bot, Conversation $conversation): ?int
    {
        $query = Order::query()
            ->where('bot_id', $bot->getKey())
            ->where('status', 'completed');

        if ($conversation->customer_profile_id) {
            $query->where('customer_profile_id', $conversation->customer_profile_id);
        } else {
            $query->where('conversation_id', $conversation->getKey());
        }

        $id = $query->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param list<array<string,mixed>> $items */
    private function hasNolimit(array $items): bool
    {
        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'), 'is_int')));
        if ($productIds === []) {
            return false;
        }

        return ProductStock::query()
            ->whereKey($productIds)
            ->get(['id', 'stock_code', 'name'])
            ->contains(function (ProductStock $product): bool {
                $sku = mb_strtolower(trim((string) $product->stock_code));

                return in_array($sku, ['nlmp', 'nlmbm'], true)
                    || str_contains(mb_strtolower((string) $product->name), 'nolimit');
            });
    }
}
