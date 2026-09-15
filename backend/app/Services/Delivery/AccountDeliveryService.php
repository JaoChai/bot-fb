<?php

namespace App\Services\Delivery;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Jobs\MarkStockSold;
use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
use App\Models\AccountDeliveryItem;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\JsonValue;
use App\Services\CommerceSafety\MoneyMinor;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\LINEService;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * งานส่งบัญชีอัตโนมัติ: จองจาก stock (mhha_acc_db) → การ์ด Telegram → ส่ง LINE → sold
 * ห้าม log ค่า detail (credential) เด็ดขาด
 */
class AccountDeliveryService
{
    /** เส้นคั่นระหว่างบัญชีที่อยู่ bubble เดียวกัน (เคสยำ ≥5 บัญชี) — ให้เห็นขอบเขตชัด */
    private const ACCOUNT_DIVIDER = "\n\n━━━━━━━━━━━━━━\n\n";

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

        $mode = app(SafetyScope::class)->mode($bot);
        $hasAuthoritativeCheckout = VerifiedPaymentEvent::query()
            ->where('bot_id', $bot->id)
            ->where('conversation_id', $conversation->id)
            ->where('slip_verification_id', $slipVerificationId)
            ->whereNotNull('checkout_id')
            ->exists();
        $canonical = $hasAuthoritativeCheckout || in_array($mode, ['enforce', 'hold'], true);
        if ($canonical) {
            $checkout = app(CheckoutAuthority::class)
                ->authorizeReservation($bot, $conversation, $slipVerificationId);
            if ($checkout === null || ! $this->validScopedRequest($checkout, $amount, $items)) {
                if ($checkout?->state === 'paid') {
                    $checkout->forceFill(['state' => 'paid_hold'])->save();
                }

                return null;
            }

            $amount = $checkout->total_minor / 100;
            $items = $checkout->items;
        }

        $plan = $this->reservationPlan($items, $canonical);
        if ($plan === null) {
            $checkout->forceFill(['state' => 'paid_hold'])->save();

            return null;
        }
        [$delivery, $duplicateOf, $reservationToken, $returnExisting] = $this->initializeAndClaim(
            $bot,
            $conversation,
            $slipVerificationId,
            $amount,
            $plan,
            $canonical ? $checkout : null,
        );
        if ($delivery === null) {
            return null;
        }
        if ($returnExisting || $reservationToken === null) {
            return $delivery;
        }

        if ($duplicateOf !== null) {
            Log::info('Delivery: created despite recent same-amount delivery — card carries a warning', [
                'conversation_id' => $conversation->id, 'amount' => $amount,
                'duplicate_of' => $duplicateOf->id,
            ]);
        }

        return $this->resumeAmbiguousReservations($delivery, $reservationToken, $duplicateOf);
    }

    private function resumeAmbiguousReservations(
        AccountDelivery $delivery,
        string $reservationToken,
        ?AccountDelivery $duplicateOf,
    ): AccountDelivery {
        // A pre-existing delivery may have committed stock under its legacy identity.
        // Resolve that identity outside the local transaction before allocating any unit.
        $canReserve = $delivery->wasRecentlyCreated || $this->reconcileLegacyReservation($delivery);
        $items = $canReserve
            ? $delivery->items()->where('status', AccountDeliveryItem::ST_RESERVING)->orderBy('id')->get()
            : [];
        foreach ($items as $item) {
            $orderRef = StockPoolService::orderRef($delivery->id, $item->id);
            $ambiguous = false;
            try {
                $row = $this->pool->reserveOne((string) $item->stock_code, $orderRef);
            } catch (\Throwable $exception) {
                $ambiguous = true;
                Log::error('Delivery: ambiguous stock reservation retry failed', [
                    'delivery_id' => $delivery->id,
                    'stock_code' => $item->stock_code,
                    'error' => $exception->getMessage(),
                ]);
                try {
                    $row = $this->pool->reservedByOrderRef($orderRef);
                } catch (\Throwable $recoveryError) {
                    Log::error('Delivery: stock reservation recovery failed', [
                        'delivery_id' => $delivery->id,
                        'stock_code' => $item->stock_code,
                        'error' => $recoveryError->getMessage(),
                    ]);
                    $row = null;
                }
            }
            if ($row !== null && (string) ($row['name'] ?? '') !== (string) $item->stock_code) {
                $row = null;
            }
            $item->update([
                'stock_item_id' => $row['id'] ?? null,
                'status' => $row === null
                    ? ($ambiguous ? AccountDeliveryItem::ST_RESERVING : AccountDeliveryItem::ST_SHORTAGE)
                    : AccountDeliveryItem::ST_RESERVED,
            ]);
        }

        [$finished, $dispatchCard] = DB::transaction(function () use (
            $delivery,
            $reservationToken,
        ): array {
            Conversation::query()->whereKey($delivery->conversation_id)->lockForUpdate()->first();
            $locked = AccountDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if (! hash_equals((string) $locked->reservation_token, $reservationToken)) {
                return [$locked, false];
            }
            if (! $this->anchorsAreComplete($locked)) {
                $locked->forceFill([
                    'reservation_token' => null,
                    'reservation_claimed_at' => null,
                ])->save();

                return [$locked, false];
            }
            if ($locked->items()->where('status', AccountDeliveryItem::ST_RESERVING)->exists()) {
                $locked->forceFill([
                    'status' => AccountDelivery::STATUS_RESERVING,
                    'reservation_token' => null,
                    'reservation_claimed_at' => null,
                ])->save();

                return [$locked, false];
            }

            $deliverable = $locked->items()->where('status', AccountDeliveryItem::ST_RESERVED)->exists();
            $dispatchCard = $locked->card_dispatched_at === null;
            $locked->forceFill([
                'status' => $deliverable ? AccountDelivery::STATUS_RESERVED : AccountDelivery::STATUS_FAILED,
                'reservation_token' => null,
                'reservation_claimed_at' => null,
                'card_dispatched_at' => $dispatchCard ? now() : $locked->card_dispatched_at,
            ])->save();

            return [$locked, $dispatchCard];
        });

        if ($dispatchCard) {
            // ส่งผ่าน job หลัง local commit และ claim/finalization แบบ once-only เท่านั้น
            SendDeliveryCard::dispatchSafely($finished->id, $this->duplicateWarning($duplicateOf));
        }

        return $finished->fresh();
    }

    private function reconcileLegacyReservation(AccountDelivery $delivery): bool
    {
        try {
            // Exact lookup rejects multiple legacy rows without picking a credential.
            $row = $this->pool->reservedByOrderRef(StockPoolService::orderRef($delivery->id));
            if ($row === null || $delivery->items()->where('stock_item_id', $row['id'])->exists()) {
                return true;
            }

            $candidates = $delivery->items()
                ->where('kind', AccountDeliveryItem::KIND_STOCK)
                ->where('status', AccountDeliveryItem::ST_RESERVING)
                ->whereNull('stock_item_id')
                ->where('stock_code', $row['name'])
                ->where('qty', 1)
                ->get();
            if ($candidates->count() !== 1) {
                return false;
            }
            $item = $candidates->first();
            if ($this->pool->reservedByOrderRef(StockPoolService::orderRef($delivery->id, $item->id)) !== null) {
                return false;
            }

            // Keep the legacy remote ref intact so a crash here remains recoverable
            // and reconciliation can still identify the original reservation.
            $item->update([
                'stock_item_id' => $row['id'],
                'status' => AccountDeliveryItem::ST_RESERVED,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Delivery: legacy stock reservation requires reconciliation', [
                'delivery_id' => $delivery->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** @return array{0: ?AccountDelivery, 1: ?AccountDelivery, 2: ?string, 3: bool} */
    private function initializeAndClaim(
        Bot $bot,
        Conversation $conversation,
        int $slipVerificationId,
        ?float $amount,
        array $plan,
        ?CheckoutSession $checkout = null,
    ): array {
        return DB::transaction(function () use (
            $bot,
            $conversation,
            $slipVerificationId,
            $amount,
            $plan,
            $checkout,
        ): array {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $delivery = AccountDelivery::query()
                ->where('slip_verification_id', $slipVerificationId)
                ->lockForUpdate()
                ->first();
            $duplicateOf = null;
            if ($delivery === null) {
                $duplicateOf = $this->recentDuplicateDelivery(
                    $conversation->id,
                    $amount,
                    $slipVerificationId,
                );
                $delivery = AccountDelivery::create([
                    'bot_id' => $bot->id,
                    'conversation_id' => $conversation->id,
                    'slip_verification_id' => $slipVerificationId,
                    'status' => AccountDelivery::STATUS_RESERVING,
                    'amount' => $amount,
                    'reservation_plan' => $plan,
                ]);
            } elseif ((int) $delivery->bot_id !== (int) $bot->id
                || (int) $delivery->conversation_id !== (int) $conversation->id) {
                return [null, null, null, false];
            } elseif ($delivery->status !== AccountDelivery::STATUS_RESERVING) {
                return [null, null, null, false];
            }

            if ($checkout !== null && is_array($delivery->reservation_plan)
                && ! JsonValue::equals($delivery->reservation_plan, $plan)) {
                $checkout->forceFill(['state' => 'paid_hold'])->save();

                return [null, null, null, false];
            }

            if (! is_array($delivery->reservation_plan)) {
                $delivery->forceFill(['reservation_plan' => $plan])->save();
            }
            $this->initializeAnchors($delivery, $delivery->reservation_plan ?? []);

            if ($delivery->reservation_token !== null
                && ($delivery->reservation_claimed_at === null
                    || $delivery->reservation_claimed_at->gt(now()->subMinutes(5)))) {
                return [$delivery->fresh(), $duplicateOf, null, true];
            }

            $token = (string) Str::uuid();
            $delivery->forceFill([
                'reservation_token' => $token,
                'reservation_claimed_at' => now(),
            ])->save();

            // Retain wasRecentlyCreated to distinguish new work from recovery.
            return [$delivery, $duplicateOf, $token, false];
        });
    }

    /** @param array<int, array<string, mixed>> $plan */
    private function initializeAnchors(AccountDelivery $delivery, array $plan): void
    {
        $unclaimed = $delivery->items()->whereNull('anchor_key')->orderBy('id')->get();
        foreach ($plan as $anchor) {
            $exists = $delivery->items()->where('anchor_key', $anchor['anchor_key'])->exists();
            if ($exists) {
                continue;
            }

            $legacy = $unclaimed->first(function (AccountDeliveryItem $item) use ($anchor): bool {
                return $item->product_name === $anchor['product_name']
                    && (string) $item->stock_code === (string) ($anchor['stock_code'] ?? null)
                    && $item->kind === $anchor['kind']
                    && (int) $item->qty === (int) $anchor['qty'];
            });
            if ($legacy !== null) {
                $legacy->update(['anchor_key' => $anchor['anchor_key']]);
                $unclaimed = $unclaimed->reject(fn (AccountDeliveryItem $item): bool => $item->is($legacy));

                continue;
            }

            $delivery->items()->create($anchor);
        }

        $delivery->forceFill(['anchors_initialized_at' => now()])->save();
    }

    private function anchorsAreComplete(AccountDelivery $delivery): bool
    {
        $plan = $delivery->reservation_plan;
        if (! is_array($plan) || $delivery->anchors_initialized_at === null) {
            return false;
        }
        $keys = array_column($plan, 'anchor_key');

        return $delivery->items()->whereIn('anchor_key', $keys)->count() === count($keys);
    }

    /** @return array<int, array<string, mixed>> */
    private function reservationPlan(array $items, bool $canonical = false): ?array
    {
        $plan = [];
        $maxQty = max(1, config_int('delivery.max_qty', 20));
        foreach (array_values($items) as $line => $item) {
            if (! $canonical && PaymentMessageDetector::isZeroPriceItem($item)) {
                continue;
            }
            $rawQty = max(1, (int) ($item['qty'] ?? 1));
            $qty = min($maxQty, $rawQty);
            $requestedQty = $qty < $rawQty ? $rawQty : null;
            if ($canonical) {
                $product = ProductStock::query()->find($item['product_id'] ?? null);
                if (! $product
                    || ! is_int($item['qty'] ?? null) || $item['qty'] <= 0 || $item['qty'] > $maxQty
                    || (string) $product->name !== ($item['name'] ?? null)
                    || trim((string) ($product->stock_code ?: $product->slug)) !== ($item['sku'] ?? null)
                    || $product->delivery_method !== ($item['delivery_method'] ?? null)
                    || ! in_array($item['method'] ?? null, ['card', 'topup', 'none'], true)
                    || ! $product->in_stock || $product->manual_off
                    || ($product->delivery_method === 'stock'
                        && ($product->available_count === null || $product->available_count < $item['qty']))) {
                    return null;
                }
                $qty = $item['qty'];
                $requestedQty = null;
            } else {
                $product = $this->mapper->map((string) ($item['name'] ?? ''));
            }
            if ($product === null || ($canonical && $product->delivery_method === 'none')) {
                $plan[] = [
                    'anchor_key' => "line:{$line}:manual",
                    'product_name' => (string) ($item['name'] ?? ''),
                    'stock_code' => null,
                    'kind' => AccountDeliveryItem::KIND_MANUAL,
                    'qty' => $qty,
                    'requested_qty' => $requestedQty,
                    'status' => AccountDeliveryItem::ST_UNMAPPED,
                ];

                continue;
            }
            if ($product->delivery_method === 'support_link') {
                $plan[] = [
                    'anchor_key' => "line:{$line}:support",
                    'product_name' => $product->name,
                    'stock_code' => null,
                    'kind' => AccountDeliveryItem::KIND_SUPPORT_LINK,
                    'qty' => $qty,
                    'requested_qty' => $requestedQty,
                    'status' => AccountDeliveryItem::ST_RESERVED,
                ];

                continue;
            }
            for ($unit = 0; $unit < $qty; $unit++) {
                $plan[] = [
                    'anchor_key' => "line:{$line}:unit:{$unit}",
                    'product_name' => $product->name,
                    'stock_code' => $canonical ? $item['sku'] : $product->stock_code,
                    'kind' => AccountDeliveryItem::KIND_STOCK,
                    'qty' => 1,
                    'requested_qty' => $unit === 0 ? $requestedQty : null,
                    'status' => AccountDeliveryItem::ST_RESERVING,
                ];
            }
        }

        return $plan;
    }

    private function validScopedRequest(
        CheckoutSession $checkout,
        ?float $amount,
        array $items,
    ): bool {
        if ($amount !== null) {
            try {
                if (MoneyMinor::fromDecimal(number_format($amount, 2, '.', '')) !== $checkout->total_minor) {
                    return false;
                }
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        $maxQty = max(1, config_int('delivery.max_qty', 20));
        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_int($item['qty'] ?? null)
                || $item['qty'] <= 0
                || $item['qty'] > $maxQty) {
                return false;
            }
        }

        return true;
    }

    /**
     * งานส่งยอดเดียวกันบน conversation นี้ที่ยัง active/ส่งแล้ว ในหน้าต่างเวลา — คืน null ถ้าไม่มี
     *
     * ระบบแยกไม่ออกว่า "จ่ายก้อนเดิมเข้า 2 path" หรือ "ลูกค้าซื้อชุดเดิมซ้ำ" จึงไม่บล็อก
     * แต่เอางานที่ซ้ำไปติดคำเตือนบนการ์ดให้เจ้าของตัดสิน (บล็อกเองทำให้ออเดอร์จริงหายเงียบ)
     */
    private function recentDuplicateDelivery(int $conversationId, ?float $amount, int $slipVerificationId): ?AccountDelivery
    {
        $window = now()->subMinutes(config_int('delivery.dedup_window_minutes', 30));

        $query = AccountDelivery::where('conversation_id', $conversationId)
            ->whereIn('status', [
                AccountDelivery::STATUS_RESERVING,
                AccountDelivery::STATUS_RESERVED,
                AccountDelivery::STATUS_DELIVERING,
                AccountDelivery::STATUS_DELIVERED,
            ])
            ->where('created_at', '>=', $window)
            ->where(fn ($q) => $amount === null ? $q->whereNull('amount') : $q->where('amount', $amount));

        // trans_ref คนละค่า = ยืนยันได้ว่าคนละการโอน ไม่ใช่เรื่องน่าสงสัย → ไม่ต้องเตือนให้รำคาญ
        // trans_ref ว่าง = manual confirm ที่ไม่มีสลิป เทียบไม่ได้ → ยังนับเป็นคู่ที่ต้องเตือน
        $transRef = SlipVerification::whereKey($slipVerificationId)->value('trans_ref');
        if ($transRef !== null && $transRef !== '') {
            $otherTransfers = SlipVerification::where('conversation_id', $conversationId)
                ->whereNotNull('trans_ref')
                ->where('trans_ref', '!=', $transRef)
                ->pluck('id');
            $query->whereNotIn('slip_verification_id', $otherTransfers);
        }

        return $query->latest('id')->first();
    }

    /** คำเตือนหัวการ์ดเมื่อมีงานยอดเดียวกันเพิ่งเกิดไป — คืน '' ถ้าไม่มีคู่ซ้ำ */
    private function duplicateWarning(?AccountDelivery $duplicateOf): string
    {
        if ($duplicateOf === null) {
            return '';
        }

        $minutes = (int) $duplicateOf->created_at->diffInMinutes(now());

        return "⚠️ <b>ยอดนี้ซ้ำกับงาน #{$duplicateOf->id}</b> เมื่อ {$minutes} นาทีที่แล้ว\n"
            ."ถ้าเป็นเงินก้อนเดิม กด \"ยกเลิก คืนเข้า stock\"\n\n";
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

    /**
     * ส่งการ์ดสรุป + ปุ่มเข้า Telegram (ใช้ตอนสร้างงาน และตอนเตือนซ้ำ)
     *
     * @return bool false = การ์ดไม่ได้ออก ผู้เรียกต้องจัดการต่อ (ยิงซ้ำ / บันทึกไว้ตาม)
     */
    public function sendCard(AccountDelivery $delivery, string $prefix = ''): bool
    {
        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for card', ['delivery_id' => $delivery->id]);

            return false;
        }

        $keyboard = $delivery->status === AccountDelivery::STATUS_RESERVED
            ? $this->cardKeyboard($delivery)
            : null;

        $result = $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $prefix.$this->cardText($delivery),
            $keyboard,
        );

        if ($result === null) {
            return false;
        }

        // จดใบที่ถือปุ่มไว้ ให้รอบเตือนซ้ำ reply มาที่ใบนี้แทนการสร้างปุ่มชุดใหม่
        // เทียบ !== null ไม่ใช่ truthiness: result ที่ว่าง ([]) ยังแปลว่าส่งสำเร็จ
        if (isset($result['message_id'])) {
            $delivery->update(['card_message_id' => (int) $result['message_id']]);
        }

        return true;
    }

    /**
     * เตือนงานที่ค้างกดยืนยัน — ข้อความสั้นไม่มีปุ่ม ที่ reply กลับไปที่การ์ดใบแรก
     *
     * เจตนา: 1 งานต้องมีปุ่มกดอยู่ชุดเดียวเสมอ เดิมรอบเตือนส่งการ์ดเต็มพร้อมปุ่มชุดใหม่
     * ทุกรอบ พอกดส่งจากใบหนึ่ง ปุ่มบนใบอื่นยังค้าง เจ้าของเผลอกดซ้ำได้
     * (กดซ้ำไม่ทำให้ส่งของซ้ำ — deliver() กันไว้ — แต่ทำให้สับสนว่าตกลงส่งไปหรือยัง)
     *
     * @return bool false = ใบเตือนไม่ออก ผู้เรียกต้องไม่ประทับ last_reminded_at
     */
    public function sendReminder(AccountDelivery $delivery, int $ageMinutes): bool
    {
        // การ์ดใบแรกไม่เคยไปถึง Telegram (เหตุการณ์ 1 ส.ค. 2026) — งานนี้ยังไม่มีปุ่มให้กดเลย
        // ต้องส่งการ์ดเต็มพร้อมปุ่ม ไม่ใช่ข้อความชี้ไปที่การ์ดที่ไม่มีอยู่จริง
        if ($delivery->card_message_id === null) {
            return $this->sendCard($delivery, "⏰ <b>เตือน:</b> งานส่งของค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
        }

        $plugin = $this->telegramPlugin($delivery);
        if (! $plugin) {
            Log::warning('Delivery: no telegram plugin for reminder', ['delivery_id' => $delivery->id]);

            return false;
        }

        $customer = $this->customerLabel($delivery->conversation);
        $amount = $this->amountLabel($delivery);

        $text = "⏰ <b>เตือน:</b> งาน #{$delivery->id} ค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n"
            ."👤 <b>{$customer}</b> · 💵 <code>{$amount}</code> บาท\n"
            .'👆 กดปุ่มบนการ์ดที่ quote ไว้';

        return $this->alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            $text,
            null,
            $delivery->card_message_id,
        ) !== null;
    }

    /** @return array<int, array<int, array{text: string, callback_data: string}>> */
    public function cardKeyboard(AccountDelivery $delivery): array
    {
        return [
            [['text' => '✅ ส่งให้ลูกค้าเลย', 'callback_data' => "dv|{$delivery->id}|x"]],
            [['text' => '↩️ ยกเลิก คืนเข้า stock', 'callback_data' => "dx|{$delivery->id}|x"]],
        ];
    }

    /** ชื่อลูกค้าที่ขึ้นบนการ์ดและใบเตือน — escape แล้ว · ไม่มีชื่อใช้ "แชท #id" แทน */
    private function customerLabel(?Conversation $conv): string
    {
        return TelegramAlertBotService::esc($conv?->customerProfile?->display_name ?? "แชท #{$conv?->id}");
    }

    /** ยอดเงินที่ขึ้นบนการ์ดและใบเตือน — ไม่มียอดใช้ '-' */
    private function amountLabel(AccountDelivery $delivery): string
    {
        return $delivery->amount !== null ? number_format($delivery->amount) : '-';
    }

    private function cardText(AccountDelivery $delivery): string
    {
        $conv = $delivery->conversation;   // ยังใช้ต่อในบรรทัด "แชท #{$conv?->id}" ด้านล่าง — ห้ามลบ
        $customer = $this->customerLabel($conv);
        $amount = $this->amountLabel($delivery);

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
        $capped = $delivery->items->whereNotNull('requested_qty');
        foreach ($capped as $item) {
            $name = TelegramAlertBotService::esc($item->product_name);
            // นับเป็น "ชิ้นที่จองได้จริง" ไม่ใช่จำนวนแถว: stock = 1 แถว/ชิ้น, support_link = 1 แถว qty=N
            // และตัด shortage/unmapped ออก (ของหมดไม่ใช่จองได้); รวม delivered เผื่อเตือนซ้ำหลังส่งแล้ว
            $reserved = $delivery->items
                ->where('product_name', $item->product_name)
                ->whereIn('status', [AccountDeliveryItem::ST_RESERVED, AccountDeliveryItem::ST_DELIVERED])
                ->sum('qty');
            $lines[] = "⚠️ <b>{$name}: ลูกค้าสั่ง {$item->requested_qty} แต่จองได้ {$reserved}</b> (เกินเพดานระบบ) — ส่วนที่เหลือต้องส่งเอง";
        }
        if ($items !== []) {
            $lines[] = '<blockquote>'.implode("\n", $items).'</blockquote>';
        }
        if ($delivery->status === AccountDelivery::STATUS_FAILED) {
            $lines[] = '❌ ไม่มีรายการที่ส่งอัตโนมัติได้ — รบกวนส่งเองในแชทนะครับ';
        }

        return implode("\n", $lines);
    }

    /** ทางเข้าสำหรับเทสต์เท่านั้น — cardText เป็น private โดยตั้งใจ (ห้ามผู้เรียกอื่นประกอบการ์ดเอง) */
    public function cardTextForTesting(AccountDelivery $delivery): string
    {
        return $this->cardText($delivery);
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

            $messages = $this->buildCustomerMessages($delivery, $stockItems, $supportItems, $reservedRows);
            $this->pushTextsToLine($delivery, $messages['accounts'], $messages['support']);
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
     * @return array{accounts: array<int, string>, support: ?string}
     *
     * แต่ละบัญชี = 1 ข้อความ; support แยกออกมาต่างหาก (null ถ้าไม่มี):
     * มีเพจ → ข้อความเพจ, บัญชีล้วน → ข้อความ Support เรื่องบัญชี/ตั้งค่า
     */
    private function buildCustomerMessages(AccountDelivery $delivery, $stockItems, $supportItems, array $reservedRows): array
    {
        $accounts = [];
        $n = $stockItems->count();
        foreach ($stockItems->values() as $i => $item) {
            $row = $reservedRows[$item->stock_item_id];
            $no = $i + 1;
            $text = "✅ {$item->product_name} ({$no}/{$n})\n\n{$row['detail']}";
            // แจ้ง id ตามข้อมูลจริงของแถวนั้น: BM มี bmId+adsId, ส่วนตัวมีแค่ adsId, G3D ไม่มี
            $idLines = [];
            foreach (['BM ID' => 'bmId', 'Ads ID' => 'adsId'] as $label => $column) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    $idLines[] = "{$label}: {$value}";
                }
            }
            if ($idLines !== []) {
                $text .= "\n\n".implode("\n", $idLines);
            }
            $accounts[] = $text;
        }

        $support = null;
        if ($supportItems->isNotEmpty()) {
            $support = $this->supportLinkText($delivery);
        } elseif ($stockItems->isNotEmpty()) {
            $support = config_string('delivery.account_support_template');
        }

        return ['accounts' => $accounts, 'support' => $support];
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
     * LINE ให้ 5 ข้อความ/push, ข้อความละ ~5000 ตัวอักษร
     * ถ้าจัดแล้วเกิน 5 bubble หรือ bubble ไหนยาวเกิน 5000 ให้ throw ก่อนส่ง (fail-safe: ยังไม่ส่งเลย)
     */
    private function pushTextsToLine(AccountDelivery $delivery, array $accounts, ?string $support): void
    {
        $conversation = $delivery->conversation;
        $externalId = $conversation?->external_customer_id;
        if ($conversation?->channel_type !== 'line' || ! $externalId) {
            throw new \RuntimeException('delivery target is not a LINE conversation');
        }
        if ($accounts === [] && $support === null) {
            throw new \RuntimeException('nothing to deliver');
        }

        $messages = $this->packTexts($accounts, $support);
        if (count($messages) > 5 || $this->anyBubbleTooLong($messages)) {
            throw new \RuntimeException('delivery message too large for a single LINE push');
        }

        $this->line->replyWithFallback(
            $delivery->bot, null, $externalId,
            array_map(fn (string $t) => ['type' => 'text', 'text' => $t], $messages),
            $this->line->generateRetryKey(),
        );
    }

    /**
     * จัด bubble สำหรับ push เดียว (≤5 ตาม LINE limit) โดยกัน 1 bubble ให้ support เสมอ:
     * บัญชี ≤ งบ → ตัวละ bubble; เกินงบ → กระจายลงครบงบให้สมดุล; support ต่อท้ายเป็น bubble ของตัวเอง
     *
     * @param  array<int, string>  $accounts
     * @return array<int, string>
     */
    private function packTexts(array $accounts, ?string $support): array
    {
        $budget = $support !== null ? 4 : 5;
        $bubbles = $this->groupAccounts($accounts, $budget);
        if ($support !== null) {
            $bubbles[] = $support;
        }

        return $bubbles;
    }

    /**
     * แจกข้อความบัญชีลง bubble ให้แยกมากที่สุดแต่ไม่เกิน $max ก้อน:
     * ≤$max → ตัวละ bubble; เกิน → กระจายลงครบ $max ก้อนให้สมดุล (ก้อนแรกๆ ได้ +1 ถ้าหารไม่ลงตัว)
     * คั่นแต่ละบัญชีในก้อนเดียวกันด้วยเส้นคั่น (ACCOUNT_DIVIDER) ให้เห็นขอบเขตชัด
     *
     * @param  array<int, string>  $accounts
     * @return array<int, string>
     */
    private function groupAccounts(array $accounts, int $max): array
    {
        $accounts = array_values($accounts);
        $count = count($accounts);
        if ($count <= $max) {
            return $accounts;
        }

        $base = intdiv($count, $max);
        $rem = $count % $max;
        $bubbles = [];
        $offset = 0;
        for ($g = 0; $g < $max; $g++) {
            $size = $base + ($g < $rem ? 1 : 0);
            $bubbles[] = implode(self::ACCOUNT_DIVIDER, array_slice($accounts, $offset, $size));
            $offset += $size;
        }

        return $bubbles;
    }

    /** มี bubble ไหนยาวเกิน limit ของ LINE ไหม (ใช้ตัดสิน throw ก่อนส่ง) */
    private function anyBubbleTooLong(array $messages, int $limit = 5000): bool
    {
        foreach ($messages as $message) {
            if (mb_strlen($message) > $limit) {
                return true;
            }
        }

        return false;
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
