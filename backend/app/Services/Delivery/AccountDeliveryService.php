<?php

namespace App\Services\Delivery;

use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Services\LINEService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * งานส่งบัญชีอัตโนมัติ: จองจาก stock (mhha_acc_db) → การ์ด Telegram → ส่ง LINE → sold
 * ห้าม log ค่า detail (credential) เด็ดขาด
 */
class AccountDeliveryService
{
    public function __construct(
        private readonly StockPoolService $pool,
        private readonly ProductMapper $mapper,
        private readonly TelegramAlertBotService $alertBot,
        private readonly LINEService $line,
    ) {}

    /**
     * สร้างงานส่งของ + จองทันที (เรียกจาก ReserveAccountStock job หลังยืนยันเงิน)
     * idempotent ด้วย unique(slip_verification_id) — เรียกซ้ำคืน null เฉยๆ
     *
     * @param  array<int, array{name: string, total: string, price?: string, qty?: int}>  $items
     */
    public function createFromPayment(
        Bot $bot,
        Conversation $conversation,
        int $slipVerificationId,
        ?float $amount,
        array $items,
    ): ?AccountDelivery {
        if (! config('delivery.enabled') || ! in_array($bot->id, config('delivery.bot_ids'), true)) {
            return null;
        }

        try {
            $delivery = AccountDelivery::create([
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'slip_verification_id' => $slipVerificationId,
                'status' => AccountDelivery::STATUS_RESERVING,
                'amount' => $amount,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // webhook ซ้ำ/job รันซ้ำ — งานนี้มีคนทำแล้ว
        }

        $deliverable = false;
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $product = $this->mapper->map($item['name']);

            if ($product === null) {
                $delivery->items()->create([
                    'product_name' => $item['name'], 'kind' => AccountDeliveryItem::KIND_MANUAL,
                    'qty' => $qty, 'status' => AccountDeliveryItem::ST_UNMAPPED,
                ]);

                continue;
            }

            if ($product->delivery_method === 'support_link') {
                $delivery->items()->create([
                    'product_name' => $product->name, 'kind' => AccountDeliveryItem::KIND_SUPPORT_LINK,
                    'qty' => $qty, 'status' => AccountDeliveryItem::ST_RESERVED,
                ]);
                $deliverable = true;

                continue;
            }

            for ($u = 0; $u < $qty; $u++) {
                try {
                    $row = $this->pool->reserveOne($product->stock_code, (string) $delivery->id);
                } catch (\Throwable $e) {
                    Log::error('Delivery: stock reserve failed', [
                        'delivery_id' => $delivery->id, 'stock_code' => $product->stock_code,
                        'error' => $e->getMessage(),
                    ]);
                    $row = null;
                }
                $delivery->items()->create([
                    'product_name' => $product->name,
                    'stock_code' => $product->stock_code,
                    'kind' => AccountDeliveryItem::KIND_STOCK,
                    'qty' => 1,
                    'stock_item_id' => $row['id'] ?? null,
                    'status' => $row === null
                        ? AccountDeliveryItem::ST_SHORTAGE
                        : AccountDeliveryItem::ST_RESERVED,
                ]);
                if ($row !== null) {
                    $deliverable = true;
                }
            }
        }

        $delivery->update([
            'status' => $deliverable ? AccountDelivery::STATUS_RESERVED : AccountDelivery::STATUS_FAILED,
        ]);

        $this->sendCard($delivery->fresh('items'));

        return $delivery;
    }

    /** ส่งการ์ดสรุป + ปุ่มเข้า Telegram (ใช้ตอนสร้างงาน และตอนเตือนซ้ำ) */
    public function sendCard(AccountDelivery $delivery, string $prefix = ''): void
    {
        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for card', ['delivery_id' => $delivery->id]);

            return;
        }

        $keyboard = $delivery->status === AccountDelivery::STATUS_RESERVED
            ? $this->cardKeyboard($delivery)
            : null;

        $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $prefix.$this->cardText($delivery),
            $keyboard,
        );
    }

    /** @return array<int, array<int, array{text: string, callback_data: string}>> */
    public function cardKeyboard(AccountDelivery $delivery): array
    {
        return [
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ];
    }

    private function cardText(AccountDelivery $delivery): string
    {
        $conv = $delivery->conversation;
        $customer = $conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}";
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';

        $lines = ["🚚 พร้อมส่งสินค้า — {$customer} (แชท #{$conv?->id}, ยอด {$amount} บาท, งาน #{$delivery->id})"];
        foreach ($delivery->items as $item) {
            $lines[] = match ($item->status) {
                AccountDeliveryItem::ST_RESERVED => $item->kind === AccountDeliveryItem::KIND_SUPPORT_LINK
                    ? "📦 {$item->product_name} ×{$item->qty} — จะส่งลิงก์ Support ให้ลูกค้า"
                    : "📦 {$item->product_name} — จองแล้ว (#{$item->stock_item_id})",
                AccountDeliveryItem::ST_SHORTAGE => "⚠️ {$item->product_name} — ของหมด ต้องส่งเอง",
                AccountDeliveryItem::ST_UNMAPPED => "⚠️ {$item->product_name} — ไม่รู้จักสินค้า ต้องส่งเอง",
                default => "• {$item->product_name} — {$item->status}",
            };
        }
        if ($delivery->status === AccountDelivery::STATUS_FAILED) {
            $lines[] = '❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ';
        }

        return implode("\n", $lines);
    }

    private function telegramPlugin(AccountDelivery $delivery): ?FlowPlugin
    {
        $bot = $delivery->bot;
        $flow = $delivery->conversation?->currentFlow ?? $bot?->defaultFlow;

        return $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();
    }
}
