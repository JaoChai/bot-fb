<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VipDetectionService
{
    /**
     * Evaluate a customer and upsert a VIP memory note on all their conversations.
     * Returns true if the customer qualifies as VIP.
     */
    public function evaluateCustomer(CustomerProfile $customer): bool
    {
        $threshold = (int) config('rag.vip.threshold', 3);
        $windowMonths = (int) config('rag.vip.window_months', 12);
        $since = now()->subMonths($windowMonths);

        $stats = Order::where('customer_profile_id', $customer->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
            ->first();

        if ((int) $stats->c < $threshold) {
            return false;
        }

        $topItems = $this->getTopItems($customer->id, $since);
        $latest = $stats->last instanceof Carbon ? $stats->last : Carbon::parse($stats->last);
        $content = $this->buildVipNoteContent(
            (int) $stats->c,
            (float) $stats->total,
            $latest,
            $topItems
        );

        $customer->conversations()->chunkById(100, function ($chunk) use ($content) {
            foreach ($chunk as $conversation) {
                $this->upsertVipNote($conversation, $content, 'vip_auto');
            }
        });

        return true;
    }

    /**
     * Manually promote a customer to VIP (admin action).
     * Uses source='vip_manual' so auto-detection won't overwrite.
     */
    public function manualPromote(CustomerProfile $customer, string $content): void
    {
        $content = Str::limit($content, 2000, '');

        $customer->conversations()->chunkById(100, function ($chunk) use ($content) {
            foreach ($chunk as $conversation) {
                $this->upsertVipNote($conversation, $content, 'vip_manual');
            }
        });
    }

    /**
     * Remove any automated VIP notes from all conversations of the customer.
     */
    public function revokeAutoVip(CustomerProfile $customer): int
    {
        $removed = 0;
        $customer->conversations()->chunkById(100, function ($chunk) use (&$removed) {
            foreach ($chunk as $conversation) {
                DB::transaction(function () use ($conversation, &$removed) {
                    $fresh = Conversation::lockForUpdate()->find($conversation->id);
                    if (! $fresh) {
                        return;
                    }

                    $notes = $this->normalizeNotes($fresh->memory_notes ?? []);
                    $before = count($notes);
                    $notes = collect($notes)
                        ->reject(fn ($n) => ($n['source'] ?? null) === 'vip_auto')
                        ->values()
                        ->all();
                    if (count($notes) !== $before) {
                        $fresh->update(['memory_notes' => $notes]);
                        $removed++;
                    }
                });
            }
        });

        return $removed;
    }

    protected function getTopItems(int $customerProfileId, Carbon $since): Collection
    {
        $limit = (int) config('rag.vip.top_n_items', 5);

        return OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.customer_profile_id', $customerProfileId)
            ->where('orders.status', 'completed')
            ->where('orders.created_at', '>=', $since)
            ->selectRaw('order_items.product_name, order_items.variant, SUM(order_items.quantity) as qty')
            ->groupBy('order_items.product_name', 'order_items.variant')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();
    }

    protected function buildVipNoteContent(int $count, float $total, Carbon $latest, Collection $topItems): string
    {
        $lines = [];
        $lines[] = sprintf(
            'ลูกค้า VIP — ซื้อยืนยันแล้ว %d ครั้ง รวม %s บาท',
            $count,
            number_format($total, 0)
        );

        foreach ($topItems as $item) {
            $name = Str::limit($item->product_name, 80, '');
            $variant = $item->variant ? Str::limit($item->variant, 40, '') : null;
            $variantText = $variant ? " ({$variant})" : '';
            $lines[] = "• {$name}{$variantText} x{$item->qty}";
        }

        $lines[] = 'ล่าสุด: '.$latest->format('Y-m-d');

        return implode("\n", $lines);
    }

    protected function upsertVipNote(Conversation $conversation, string $content, string $source): void
    {
        DB::transaction(function () use ($conversation, $content, $source) {
            $fresh = Conversation::lockForUpdate()->find($conversation->id);
            if (! $fresh) {
                return;
            }

            $notes = $this->normalizeNotes($fresh->memory_notes ?? []);

            $existingIdx = null;
            foreach ($notes as $i => $note) {
                if (($note['source'] ?? null) === $source) {
                    $existingIdx = $i;
                    break;
                }
            }

            $now = now()->toISOString();

            if ($existingIdx === null) {
                $notes[] = [
                    'id' => (string) Str::uuid(),
                    'content' => $content,
                    'type' => 'memory',
                    'source' => $source,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } else {
                $notes[$existingIdx]['content'] = $content;
                $notes[$existingIdx]['updated_at'] = $now;
                $notes[$existingIdx]['type'] = 'memory';
                $notes[$existingIdx]['source'] = $source;
            }

            $fresh->update(['memory_notes' => array_values($notes)]);
        });
    }

    /**
     * Guard against legacy object format like {"vip": true} which some
     * conversations still have (see NoteService::getNotes for reference).
     */
    protected function normalizeNotes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        if (! empty($raw) && ! array_is_list($raw)) {
            return [];
        }

        return $raw;
    }
}
