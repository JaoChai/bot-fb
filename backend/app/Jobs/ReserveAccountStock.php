<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * จองบัญชีจาก stock หลังยืนยันเงิน (EasySlip ผ่าน / เจ้าของกดยืนยัน)
 *
 * tries=1 โดยตั้งใจ: ถ้า mhha DB มีปัญหา ชิ้นที่จองไม่ได้จะถูกบันทึกเป็น shortage
 * และการ์ด Telegram บอกให้ส่งเอง (fail-safe) — ไม่ retry เพื่อไม่ให้จองซ้ำครึ่งๆ กลางๆ
 */
class ReserveAccountStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $botId,
        public readonly int $conversationId,
        public readonly int $slipVerificationId,
        public readonly ?float $amount,
        public readonly array $items,
    ) {}

    public function handle(AccountDeliveryService $service, CheckoutAuthority $authority): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        if (! $bot || ! $conversation) {
            return;
        }

        $mode = app(SafetyScope::class)->mode($bot);
        if (in_array($mode, ['enforce', 'hold'], true)) {
            if ($mode !== 'enforce') {
                return;
            }
            $checkout = $authority->authorizeReservation($bot, $conversation, $this->slipVerificationId);
            if ($checkout === null) {
                return;
            }
            $service->createFromPayment(
                $bot,
                $conversation,
                $this->slipVerificationId,
                $checkout->total_minor / 100,
                $checkout->items,
            );

            return;
        }

        $service->createFromPayment($bot, $conversation, $this->slipVerificationId, $this->amount, $this->items);
    }

    /**
     * Dispatch แบบไม่ให้พังลาม payment flow — การจองเป็น best-effort:
     * พลาดแล้ว delivery:reconcile จะเจอ + เจ้าของส่งเองได้
     */
    public static function dispatchSafely(int $botId, int $conversationId, int $slipVerificationId, ?float $amount, array $items): void
    {
        try {
            // หน่วงให้ข้อความ "ออเดอร์ใหม่!" จาก plugin ไปก่อน การ์ดปุ่มจะได้อยู่ล่างสุดของแชท
            self::dispatch($botId, $conversationId, $slipVerificationId, $amount, $items)
                ->delay(config('delivery.card_delay_seconds', 15));
        } catch (\Throwable $e) {
            Log::warning('Account delivery: reserve job dispatch failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ประตูเดียวของทั้ง 3 เส้นทางที่ยืนยันเงินแล้วจะจองสต๊อก (EasySlip auto / auto-retry /
     * เจ้าของกดยืนยัน) — กติกา "รายการเชื่อไม่ได้ = ห้ามส่งของเอง" เขียนที่นี่ที่เดียว
     * ไม่งั้นเพิ่มเส้นทางที่สี่วันหลังแล้วลืมเช็ค = กลับไปส่งของผิดจำนวนเงียบอีก
     */
    public static function dispatchIfItemsTrusted(int $botId, int $conversationId, SlipVerificationResult $result): void
    {
        if ($result->slipVerificationId === null) {
            return;
        }

        $bot = Bot::query()->find($botId);
        $conversation = Conversation::query()->find($conversationId);
        if ($bot && $conversation
            && in_array(app(SafetyScope::class)->mode($bot), ['enforce', 'hold'], true)) {
            $slip = SlipVerification::query()->find($result->slipVerificationId);
            $receipt = $slip === null ? null : self::verifiedReceipt($conversation, $slip);
            if ($slip && $receipt) {
                app(SlipVerificationService::class)->settleVerifiedReceipt(
                    $bot,
                    $conversation,
                    $slip,
                    $receipt,
                    null,
                );
            }

            // A3 will durably enqueue effects after commit. B3 records authority only.
            return;
        }

        if ($result->itemsUnreliable) {
            Log::warning('Delivery: skipped auto-reserve — order items failed checksum', [
                'conversation_id' => $conversationId,
                'slip_verification_id' => $result->slipVerificationId,
                'amount' => $result->amount,
            ]);

            return;
        }

        self::dispatchSafely($botId, $conversationId, $result->slipVerificationId, $result->amount, $result->orderItems ?? []);
    }

    private static function verifiedReceipt(Conversation $conversation, SlipVerification $slip): ?Message
    {
        if ($slip->status === 'manual_confirmed' && $slip->message_id !== null) {
            return Message::query()
                ->whereKey($slip->message_id)
                ->where('conversation_id', $conversation->id)
                ->where('sender', 'bot')
                ->first();
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->where('metadata->slip_verification', true)
            ->where('metadata->slip_status', 'passed')
            ->when(trim((string) $slip->trans_ref) !== '', fn ($query) => $query
                ->where('metadata->slip_trans_ref', trim((string) $slip->trans_ref)))
            ->latest('id')
            ->first();
    }
}
