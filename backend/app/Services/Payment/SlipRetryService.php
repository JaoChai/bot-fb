<?php

namespace App\Services\Payment;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Jobs\ReserveAccountStock;
use App\Jobs\RetrySlipVerification;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\PaymentFlexService;
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
        private readonly PaymentFlexService $paymentFlex,
        private readonly LINEService $line,
        private readonly FlowPluginService $flowPlugin,
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
            $this->emitSuccess($bot, $conversation, $result);

            return;
        }

        if ($result->failReason === 'pending' || in_array($result->failReason, ['api_error', 'config_error'], true)) {
            $this->handlePendingOrTransient($bot, $conversation, $message, $imageUrl, $attempt, $result);

            return;
        }

        // fail อื่น (fake/amount_mismatch/wrong_account/duplicate/no_pending_order):
        // ตอบลูกค้าด้วย fail template + แจ้งแอดมิน (mirror webhook fail path)
        $failText = $bot->settings?->slip_fail_message
            ?: LineWebhookResponseService::SLIP_FAIL_TEMPLATE;
        $conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => $failText,
            'metadata' => ['slip_verification' => true, 'slip_status' => $result->status(), 'slip_retry' => true],
        ]);
        $this->pushToLine($bot, $conversation, $failText);
        $this->slipVerification->notifyAdmin($bot, $conversation, $result);
    }

    /**
     * ปล่อย success side-effects แบบ push (นอก webhook ไม่มี reply token) —
     * mirror ManualPaymentConfirmService post-commit path แต่ใช้ผล EasySlip 'passed'
     * ที่ verify() บันทึกไว้แล้ว (มี slipVerificationId + trans_ref)
     */
    private function emitSuccess(Bot $bot, Conversation $conversation, SlipVerificationResult $result): void
    {
        $template = $bot->settings?->slip_success_message
            ?: LineWebhookResponseService::SLIP_SUCCESS_TEMPLATE;
        $text = str_replace(
            ['{amount}', '{order_summary}'],
            [number_format($result->amount ?? 0), $result->orderSummary ?? '-'],
            $template,
        );

        $botMessage = $conversation->messages()->create([
            'sender' => 'bot',
            'content' => $text,
            'type' => 'text',
            'metadata' => [
                'slip_verification' => true,
                'slip_status' => 'passed',
                'slip_trans_ref' => $result->transRef,
                'slip_retry' => true,
            ],
        ]);

        $this->pushToLine($bot, $conversation, $text);
        $this->runPlugins($bot, $conversation, $botMessage);

        if ($result->slipVerificationId !== null) {
            ReserveAccountStock::dispatchSafely(
                $bot->id,
                $conversation->id,
                $result->slipVerificationId,
                $result->amount,
                $result->orderItems ?? [],
            );
        }

        $this->broadcast($conversation, $botMessage);
    }

    private function pushToLine(Bot $bot, Conversation $conversation, string $text): void
    {
        $externalId = $conversation->external_customer_id;
        if ($conversation->channel_type !== 'line' || ! $externalId) {
            return;
        }

        try {
            $transformed = $this->paymentFlex->tryConvertToFlex($text, $conversation);
            $this->line->replyWithFallback($bot, null, $externalId, [$transformed], $this->line->generateRetryKey());
        } catch (\Throwable $e) {
            Log::error('Slip retry: LINE push failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function runPlugins(Bot $bot, Conversation $conversation, Message $botMessage): void
    {
        try {
            $this->flowPlugin->executePlugins($bot, $conversation, $botMessage);
        } catch (\Throwable $e) {
            Log::warning('Slip retry: plugin execution failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function broadcast(Conversation $conversation, Message $botMessage): void
    {
        $conversation->update(['last_message_at' => now(), 'last_message_id' => $botMessage->id]);
        $conversation->increment('message_count');
        $conversation->refresh();

        try {
            broadcast(new MessageSent($botMessage, [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ]))->toOthers();
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        } catch (\Throwable $e) {
            Log::error('Slip retry: broadcast failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
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
