<?php

namespace App\Services\Payment;

use App\Jobs\RetrySlipVerification;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use Illuminate\Support\Facades\Log;

/**
 * จัดการ retry ตรวจสลิปหลัง EasySlip คืน SLIP_PENDING — re-verify รูปเดิมกับ EasySlip
 * ตามตาราง delay. ผ่าน → emit success + จองของ, pending → re-dispatch/แจ้งแอดมิน,
 * fail อื่น → ตอบลูกค้า + แจ้งแอดมิน. เขียนแยกจาก ManualPaymentConfirmService โดยตั้งใจ.
 */
class SlipRetryService
{
    public function __construct(
        private readonly SlipVerificationService $slipVerification,
    ) {}

    /**
     * @param  int  $attempt  รอบปัจจุบัน (เริ่มที่ 1). count(delays) = รอบสุดท้าย
     */
    public function retry(Bot $bot, Conversation $conversation, Message $message, string $imageUrl, int $attempt): void
    {
        // ลูกค้าส่งสลิปซ้ำผ่านเอง / แอดมินยืนยันมือไปแล้ว → หยุด กันออเดอร์ซ้ำ
        if ($this->alreadyResolved($conversation, $message)) {
            return;
        }

        $history = $this->recentTextHistory($conversation);
        $result = $this->slipVerification->verify($bot, $conversation, $message, $imageUrl, $history);

        if ($result->passed) {
            // Task 2 เติม emitSuccess() — ชั่วคราวยังไม่ทำอะไร
            return;
        }

        if ($result->failReason === 'pending' || in_array($result->failReason, ['api_error', 'config_error'], true)) {
            $this->handlePendingOrTransient($bot, $conversation, $message, $imageUrl, $attempt, $result);

            return;
        }

        // Task 3 เติม fail อื่น (fake/amount_mismatch/...) — ชั่วคราวแจ้งแอดมิน
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
    }

    /**
     * มีสลิป passed/manual_confirmed ที่เกิดหลังข้อความสลิปนี้แล้วหรือยัง
     */
    private function alreadyResolved(Conversation $conversation, Message $message): bool
    {
        return SlipVerification::where('conversation_id', $conversation->id)
            ->whereIn('status', ['passed', 'manual_confirmed'])
            ->where('created_at', '>=', $message->created_at)
            ->exists();
    }

    private function handlePendingOrTransient(
        Bot $bot, Conversation $conversation, Message $message, string $imageUrl, int $attempt, SlipVerificationResult $result
    ): void {
        $delays = (array) config('delivery.pending_retry.delays', [90, 180, 300]);
        $maxAttempts = count($delays);

        if ($attempt < $maxAttempts) {
            $nextDelay = (int) $delays[$attempt]; // delays[attempt] = ระยะก่อนรอบ attempt+1

            try {
                RetrySlipVerification::dispatch($bot->id, $conversation->id, $message->id, $imageUrl, $attempt + 1)
                    ->delay(now()->addSeconds($nextDelay));

                return;
            } catch (\Throwable $e) {
                Log::warning('Slip retry: re-dispatch failed', [
                    'conversation_id' => $conversation->id, 'error' => $e->getMessage(),
                ]);
                // fall through to notifyAdmin backstop below
            }
        }

        // ครบทุกรอบยัง pending/ตรวจไม่ได้ → แจ้งแอดมินให้ตรวจมือ (backstop)
        Log::info('Slip pending retry exhausted, alerting admin', [
            'conversation_id' => $conversation->id, 'attempts' => $attempt, 'reason' => $result->failReason,
        ]);
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
    }

    /**
     * ประวัติ text ล่าสุด (mirror ManualPaymentConfirmService::recentTextHistory)
     *
     * @return array<int, array{sender: string, content: string}>
     */
    private function recentTextHistory(Conversation $conversation, int $limit = 15): array
    {
        $query = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->where('type', 'text');

        if ($conversation->context_cleared_at) {
            $query->where('created_at', '>', $conversation->context_cleared_at);
        }

        return $query->latest()->take($limit)->get()->reverse()
            ->map(fn (Message $msg) => ['sender' => $msg->sender, 'content' => $msg->content])
            ->values()->toArray();
    }
}
