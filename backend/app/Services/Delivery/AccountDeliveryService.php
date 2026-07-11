<?php

namespace App\Services\Delivery;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Jobs\MarkStockSold;
use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Services\LINEService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
        if (! config('delivery.enabled') || ! $bot->auto_delivery_enabled) {
            return null;
        }

        // สร้างงานใน transaction ที่ lock conversation: กัน 2 dispatch path (EasySlip vs manual)
        // ที่ใช้ slip คนละใบ สร้างงานส่งยอดเดียวกันพร้อมกัน → ขายซ้ำ (unique(slip) กันข้าม path ไม่ได้)
        try {
            $delivery = DB::transaction(function () use ($bot, $conversation, $slipVerificationId, $amount) {
                Conversation::whereKey($conversation->id)->lockForUpdate()->first();

                if ($this->hasRecentActiveDelivery($conversation->id, $amount)) {
                    return null;
                }

                return AccountDelivery::create([
                    'bot_id' => $bot->id,
                    'conversation_id' => $conversation->id,
                    'slip_verification_id' => $slipVerificationId,
                    'status' => AccountDelivery::STATUS_RESERVING,
                    'amount' => $amount,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null; // webhook ซ้ำ/job รันซ้ำ (slip เดียวกัน)
        }

        if ($delivery === null) {
            // อีก path เพิ่งสร้างงานส่งยอดเดียวกันไปแล้ว — log ไว้ให้สืบย้อนได้ (บล็อกเงียบทำให้ debug ยาก)
            Log::warning('Delivery: skipped duplicate reserve (recent active delivery)', [
                'conversation_id' => $conversation->id, 'amount' => $amount,
            ]);

            return null;
        }

        $deliverable = false;
        // floor ที่ 1 กัน footgun: ตั้ง max_qty=0 ผิดจะทำให้ loop ไม่รัน item หายเงียบ
        $maxQty = max(1, config_int('delivery.max_qty', 20));
        foreach ($items as $item) {
            $rawQty = max(1, (int) ($item['qty'] ?? 1));
            $qty = min($maxQty, $rawQty);
            if ($qty < $rawQty) {
                Log::warning('Delivery: qty capped', [
                    'delivery_id' => $delivery->id, 'product' => $item['name'],
                    'requested' => $rawQty, 'capped' => $qty,
                ]);
            }
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
                // สร้าง item anchor ก่อนจอง: ถ้า process ตายหลัง reserveOne สำเร็จแต่ก่อน update
                // จะเหลือ item ค้าง reserving ให้ตามได้ ไม่ใช่ stock หายเงียบไม่มีที่อ้างอิง
                $item = $delivery->items()->create([
                    'product_name' => $product->name,
                    'stock_code' => $product->stock_code,
                    'kind' => AccountDeliveryItem::KIND_STOCK,
                    'qty' => 1,
                    'status' => AccountDeliveryItem::ST_RESERVING,
                ]);
                try {
                    $row = $this->pool->reserveOne($product->stock_code, StockPoolService::orderRef($delivery->id));
                } catch (\Throwable $e) {
                    Log::error('Delivery: stock reserve failed', [
                        'delivery_id' => $delivery->id, 'stock_code' => $product->stock_code,
                        'error' => $e->getMessage(),
                    ]);
                    $row = null;
                }
                $item->update([
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

    /**
     * มีงานส่งยอดเดียวกันบน conversation นี้ที่ยัง active/ส่งแล้ว ในหน้าต่างกันซ้ำไหม
     * (กันขายซ้ำเมื่อ EasySlip auto-pass กับ manual confirm ยิงคนละ slip แต่เป็นการจ่ายก้อนเดียวกัน)
     */
    private function hasRecentActiveDelivery(int $conversationId, ?float $amount): bool
    {
        $window = now()->subMinutes(config_int('delivery.dedup_window_minutes', 30));

        return AccountDelivery::where('conversation_id', $conversationId)
            ->whereIn('status', [
                AccountDelivery::STATUS_RESERVING,
                AccountDelivery::STATUS_RESERVED,
                AccountDelivery::STATUS_DELIVERING,
                AccountDelivery::STATUS_DELIVERED,
            ])
            ->where('created_at', '>=', $window)
            ->where(fn ($q) => $amount === null ? $q->whereNull('amount') : $q->where('amount', $amount))
            ->exists();
    }

    /**
     * ข้อความเตือน "ยังต้องส่งเอง" สำหรับ item ที่ shortage/unmapped — คืน '' ถ้าไม่มี
     * ใช้ต่อท้ายข้อความสำเร็จตอนกดส่ง เพื่อไม่ให้คำเตือนหายตอน editMessageText แทนที่ทั้งการ์ด
     */
    public function pendingManualNote(AccountDelivery $delivery): string
    {
        $names = $delivery->items()
            ->whereIn('status', [AccountDeliveryItem::ST_SHORTAGE, AccountDeliveryItem::ST_UNMAPPED])
            ->pluck('product_name')->unique()->values()->all();

        return $names === [] ? '' : "\n⚠️ ยังต้องส่งเอง: ".TelegramAlertBotService::esc(implode(', ', $names));
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
        $customer = TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
        $amount = $delivery->amount !== null ? number_format($delivery->amount) : '-';

        $items = [];
        foreach ($delivery->items as $item) {
            $name = TelegramAlertBotService::esc($item->product_name);
            $items[] = match ($item->status) {
                AccountDeliveryItem::ST_RESERVED => $item->kind === AccountDeliveryItem::KIND_SUPPORT_LINK
                    ? "📦 {$name} ×{$item->qty} — ส่งลิงก์ Support ให้ลูกค้า"
                    : "📦 {$name} — จองแล้ว (#{$item->stock_item_id})",
                AccountDeliveryItem::ST_SHORTAGE => "⚠️ {$name} — ของหมด ต้องส่งเอง",
                AccountDeliveryItem::ST_UNMAPPED => "⚠️ {$name} — ไม่รู้จักสินค้า ต้องส่งเอง",
                default => "• {$name} — ".TelegramAlertBotService::esc($item->status),
            };
        }

        $lines = [
            "🚚 <b>พร้อมส่งสินค้า</b> · งาน #{$delivery->id}",
            "👤 <b>{$customer}</b> · แชท #{$conv?->id}",
            "💵 ยอด <code>{$amount}</code> บาท",
        ];
        if ($items !== []) {
            $lines[] = '<blockquote>'.implode("\n", $items).'</blockquote>';
        }
        if ($delivery->status === AccountDelivery::STATUS_FAILED) {
            $lines[] = '❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ';
        }

        return implode("\n", $lines);
    }

    public function telegramPlugin(AccountDelivery $delivery): ?FlowPlugin
    {
        $bot = $delivery->bot;
        $flow = $delivery->conversation?->currentFlow ?? $bot?->defaultFlow;

        return $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();
    }

    /**
     * ส่งของให้ลูกค้า (เรียกตอนเจ้าของกดปุ่ม ✅ ใน Telegram)
     * ลำดับ: lock สถานะ → push LINE → ย้ายเข้า items_sold → บันทึกประวัติ
     * push พังของยังอยู่ reserved กดใหม่ได้; markSold พังหลัง push = log error ให้ reconcile เจอ
     *
     * @throws DeliveryAlreadyHandledException สถานะไม่ใช่ reserved (กดซ้ำ/ยกเลิกแล้ว)
     */
    public function deliver(AccountDelivery $delivery, string $confirmedByName): void
    {
        // จองสิทธิ์ส่ง: reserved → delivering ใน transaction เดียว กันกดพร้อมกัน
        $delivery = DB::transaction(function () use ($delivery) {
            $locked = AccountDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AccountDelivery::STATUS_RESERVED) {
                throw new DeliveryAlreadyHandledException($locked->status);
            }
            $locked->update(['status' => AccountDelivery::STATUS_DELIVERING]);

            return $locked;
        });

        try {
            $stockItems = $delivery->items()
                ->where('kind', AccountDeliveryItem::KIND_STOCK)
                ->where('status', AccountDeliveryItem::ST_RESERVED)
                ->get();
            $supportItems = $delivery->items()
                ->where('kind', AccountDeliveryItem::KIND_SUPPORT_LINK)
                ->where('status', AccountDeliveryItem::ST_RESERVED)
                ->get();

            $reservedRows = $this->pool->getReserved($stockItems->pluck('stock_item_id')->all());
            foreach ($stockItems as $item) {
                if (! isset($reservedRows[$item->stock_item_id])) {
                    throw new \RuntimeException("reserved row missing: #{$item->stock_item_id}");
                }
            }

            $texts = $this->buildCustomerMessages($delivery, $stockItems, $supportItems, $reservedRows);
            $this->pushTextsToLine($delivery, $texts);
        } catch (\Throwable $e) {
            $delivery->update(['status' => AccountDelivery::STATUS_RESERVED]);
            throw $e;
        }

        // ลูกค้าได้ของแล้ว — จากนี้ห้าม throw กลับไปเป็น "ยังไม่ส่ง"
        $stockItemIds = $stockItems->pluck('stock_item_id')->all();
        try {
            $this->pool->markSold($stockItemIds, $confirmedByName, 'bot-fb');
        } catch (\Throwable $e) {
            // dispatch job ตามเก็บ (idempotent) แทนปล่อยของค้าง items_reserved ให้เจ้าของเดาเอง
            Log::error('Delivery: markSold failed AFTER customer push — retry job dispatched', [
                'delivery_id' => $delivery->id, 'error' => $e->getMessage(),
            ]);
            try {
                MarkStockSold::dispatch($stockItemIds, $confirmedByName);
            } catch (\Throwable $dispatchError) {
                // ลูกค้าได้ของแล้ว — dispatch พังก็ต้องจบ DELIVERED ให้ได้ (ของค้าง items_reserved ให้ reconcile จับ)
                Log::error('Delivery: MarkStockSold dispatch failed — ของค้างรอ reconcile', [
                    'delivery_id' => $delivery->id, 'error' => $dispatchError->getMessage(),
                ]);
            }
        }

        $delivery->update([
            'status' => AccountDelivery::STATUS_DELIVERED,
            'confirmed_by' => $confirmedByName,
            'delivered_at' => now(),
        ]);
        $delivery->items()
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->update(['status' => AccountDeliveryItem::ST_DELIVERED]);

        $this->recordConversationMessage($delivery);
    }

    /**
     * ยกเลิกงาน คืนของเข้า items_available (manual escape hatch — ระบบไม่คืนอัตโนมัติ)
     * mark canceled ก่อนคืนของ: ถ้าคืนพังกลางทาง แถวค้างใน items_reserved ให้ reconcile เจอ
     *
     * @throws DeliveryAlreadyHandledException สถานะไม่ใช่ reserved
     */
    public function cancel(AccountDelivery $delivery, string $byName): void
    {
        $delivery = DB::transaction(function () use ($delivery, $byName) {
            $locked = AccountDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AccountDelivery::STATUS_RESERVED) {
                throw new DeliveryAlreadyHandledException($locked->status);
            }
            $locked->update(['status' => AccountDelivery::STATUS_CANCELED, 'confirmed_by' => $byName]);

            return $locked;
        });

        $ids = $delivery->items()
            ->where('kind', AccountDeliveryItem::KIND_STOCK)
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->pluck('stock_item_id')
            ->all();
        $this->pool->returnToAvailable($ids);
        $delivery->items()
            ->where('status', AccountDeliveryItem::ST_RESERVED)
            ->update(['status' => AccountDeliveryItem::ST_RETURNED]);
    }

    /**
     * @return array<int, string> ข้อความที่จะส่งให้ลูกค้า (เรียงตามลำดับ)
     *
     * ปิดท้ายด้วยข้อความ Support เสมอ: มีเพจ (อย่างเดียวหรือปนบัญชี) → ข้อความเพจ,
     * บัญชีล้วน → ข้อความ Support เรื่องบัญชี/ตั้งค่า
     */
    private function buildCustomerMessages(AccountDelivery $delivery, $stockItems, $supportItems, array $reservedRows): array
    {
        $texts = [];
        $n = $stockItems->count();
        foreach ($stockItems->values() as $i => $item) {
            $row = $reservedRows[$item->stock_item_id];
            $no = $i + 1;
            $text = "✅ {$item->product_name} ({$no}/{$n})\n{$row['detail']}";
            // แจ้ง id ตามข้อมูลจริงของแถวนั้น: BM มี bmId+adsId, ส่วนตัวมีแค่ adsId, G3D ไม่มี
            foreach (['BM ID' => 'bmId', 'Ads ID' => 'adsId'] as $label => $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $text .= "\n{$label}: {$value}";
                }
            }
            $texts[] = $text;
        }
        if ($supportItems->isNotEmpty()) {
            $texts[] = $this->supportLinkText($delivery);
        } elseif ($stockItems->isNotEmpty()) {
            $texts[] = config_string('delivery.account_support_template');
        }

        return $texts;
    }

    /** ข้อความเพจ: แทน {customer} ด้วยชื่อลูกค้า — ไม่มีชื่อก็ตัด placeholder ทิ้งให้ประโยคยังอ่านลื่น */
    private function supportLinkText(AccountDelivery $delivery): string
    {
        $template = config_string('delivery.support_link_template');
        $name = trim((string) $delivery->conversation?->customerProfile?->display_name);

        return $name === ''
            ? (string) preg_replace('/\h*\{customer\}/u', '', $template)
            : str_replace('{customer}', $name, $template);
    }

    /**
     * ส่งเป็น push เดียวแบบ all-or-nothing (text ล้วน ห้ามผ่าน LLM/Flex) — ห้ามแบ่งหลาย push:
     * ถ้า push แรกสำเร็จแล้ว push ถัดไปพัง ระบบจะคิดว่ายังไม่ส่งและอาจคืน stock
     * ทั้งที่ลูกค้าได้ credential ไปแล้ว → บัญชีเดิมถูกขายซ้ำได้
     * LINE ให้ 5 ข้อความ/push, ข้อความละ ~5000 ตัวอักษร → pack ได้เหลือเฟือ
     * ถ้า pack ไม่พอ (เกิน 5 ข้อความ) ให้ throw ก่อนส่งอะไรออกไป (fail-safe: ยังไม่ส่งเลย)
     */
    private function pushTextsToLine(AccountDelivery $delivery, array $texts): void
    {
        $conversation = $delivery->conversation;
        $externalId = $conversation?->external_customer_id;
        if ($conversation?->channel_type !== 'line' || ! $externalId) {
            throw new \RuntimeException('delivery target is not a LINE conversation');
        }
        if ($texts === []) {
            throw new \RuntimeException('nothing to deliver');
        }

        $messages = $this->packTexts($texts);
        if (count($messages) > 5) {
            throw new \RuntimeException('delivery message too large for a single LINE push');
        }

        $this->line->replyWithFallback(
            $delivery->bot, null, $externalId,
            array_map(fn (string $t) => ['type' => 'text', 'text' => $t], $messages),
            $this->line->generateRetryKey(),
        );
    }

    /** รวม texts หลายชิ้นเข้าเป็นก้อนละไม่เกิน 4900 ตัวอักษร (กันชน limit 5000) คั่นด้วยบรรทัดว่าง */
    private function packTexts(array $texts, int $maxLen = 4900): array
    {
        $packed = [];
        $current = '';
        foreach ($texts as $text) {
            if ($current === '') {
                $current = $text;
            } elseif (mb_strlen($current) + mb_strlen($text) + 2 <= $maxLen) {
                $current .= "\n\n".$text;
            } else {
                $packed[] = $current;
                $current = $text;
            }
        }
        if ($current !== '') {
            $packed[] = $current;
        }

        return $packed;
    }

    /**
     * บันทึกสิ่งที่ส่งเข้าประวัติแชท (บอท/หน้าเว็บเห็นว่าส่งอะไรไปแล้ว) — best effort
     * เก็บแค่ placeholder (ชื่อสินค้า + #stock_item_id) ห้ามเก็บ credential ดิบเด็ดขาด:
     * content ถูกดึงกลับเข้า LLM context (ส่งขึ้น OpenRouter) + surface บนหน้าเว็บแชท
     */
    private function recordConversationMessage(AccountDelivery $delivery): void
    {
        $lines = [];
        foreach ($delivery->items()->where('status', AccountDeliveryItem::ST_DELIVERED)->get() as $item) {
            $lines[] = $item->kind === AccountDeliveryItem::KIND_SUPPORT_LINK
                ? "✅ ส่งลิงก์ Support {$item->product_name} แล้ว"
                : "✅ ส่งบัญชี {$item->product_name} แล้ว (#{$item->stock_item_id})";
        }
        if ($lines === []) {
            return;
        }

        try {
            $delivery->conversation?->messages()->create([
                'sender' => 'bot',
                'type' => 'text',
                'content' => implode("\n", $lines),
                'metadata' => [
                    'account_delivery' => true,
                    'delivery_id' => $delivery->id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Delivery: failed to record conversation message', [
                'delivery_id' => $delivery->id, 'exception' => $e::class,
            ]);
        }
    }
}
