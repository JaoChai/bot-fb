<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Delivery\AccountDeliveryService;
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

    public function handle(AccountDeliveryService $service): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        if (! $bot || ! $conversation) {
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
}
