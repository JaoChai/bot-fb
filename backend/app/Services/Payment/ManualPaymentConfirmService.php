<?php

namespace App\Services\Payment;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\SlipVerification;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin manual payment confirmation.
 *
 * Confirms a payment through the SAME output path the bot's slip-success reply uses
 * (Flex conversion + LINE push + flow plugins → OrderService), so a manually confirmed
 * payment creates an order exactly like the automatic happy path.
 */
class ManualPaymentConfirmService
{
    public function __construct(
        private readonly LINEService $line,
        private readonly PaymentFlexService $paymentFlex,
        private readonly FlowPluginService $flowPlugin,
        private readonly SlipVerificationService $slipVerification,
    ) {}

    /**
     * @return array{message: Message, order_created: bool}
     *
     * @throws NoPendingPaymentException When no amount can be resolved.
     * @throws RecentManualConfirmException When a manual confirm for this conversation happened within the idempotency window.
     */
    public function confirm(Bot $bot, Conversation $conversation, ?float $amountOverride, int $confirmedBy): array
    {
        $history = $this->recentTextHistory($conversation);
        $receiverAccount = $bot->settings?->slip_receiver_account ?: null;
        $expected = $this->slipVerification->findExpectedPayment($history, $receiverAccount);

        $amount = $amountOverride ?? ($expected['total'] ?? null);
        if ($amount === null) {
            throw new NoPendingPaymentException;
        }

        $summary = $expected['summary'] ?? '-';
        $template = $bot->settings?->slip_success_message ?: LineWebhookResponseService::SLIP_SUCCESS_TEMPLATE;
        $text = str_replace(
            ['{amount}', '{order_summary}'],
            [number_format($amount), $summary],
            $template,
        );

        // Atomic idempotency reservation: take a row lock on the conversation, re-run the
        // double-confirm guard, and insert the manual_confirmed slip row inside ONE
        // transaction. The lock serializes concurrent confirms so the guard's exists()
        // check and the reservation insert can't interleave (fixes the TOCTOU where two
        // requests both passed the guard before either had inserted its slip row). The
        // reservation is committed before any HTTP side effect below, so a concurrent
        // second request sees it and 409s. No external calls happen inside the
        // transaction — we never hold a DB lock across HTTP.
        $slip = DB::transaction(function () use ($bot, $conversation, $amount, $receiverAccount) {
            Conversation::whereKey($conversation->id)->lockForUpdate()->first();
            $this->guardAgainstDoubleConfirm($conversation);

            return $this->reserveSlipVerification($bot, $conversation, $amount, $receiverAccount);
        });

        // Side effects run AFTER commit (message linkage, LINE push, plugins, broadcast).
        $botMessage = $conversation->messages()->create([
            'sender' => 'bot',
            'content' => $text,
            'type' => 'text',
            'metadata' => [
                'slip_verification' => true,
                'slip_status' => 'manual_confirmed',
                'confirmed_by' => $confirmedBy,
            ],
        ]);

        $this->linkSlipToMessage($slip, $botMessage);

        $this->pushToLine($bot, $conversation, $text);

        $orderCreated = $this->runPlugins($bot, $conversation, $botMessage);

        try {
            ReserveAccountStock::dispatch(
                $bot->id,
                $conversation->id,
                $slip->id,
                $amount,
                $expected['items'] ?? [],
            );
        } catch (\Throwable $e) {
            Log::warning('Account delivery: reserve job dispatch failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->broadcast($conversation, $botMessage);

        return ['message' => $botMessage, 'order_created' => $orderCreated];
    }

    /**
     * Reject a repeat confirmation for the same conversation inside the idempotency
     * window (two tabs / retried request) so we never create duplicate orders and
     * customer pushes. Covers both a prior manual confirm and an EasySlip auto-pass
     * ('passed') on the same conversation — an admin clicking confirm right after the
     * slip auto-passed would otherwise create a second order.
     *
     * @throws RecentManualConfirmException
     */
    private function guardAgainstDoubleConfirm(Conversation $conversation, int $windowSeconds = 120): void
    {
        $recent = SlipVerification::where('conversation_id', $conversation->id)
            ->whereIn('status', ['passed', 'manual_confirmed'])
            ->where('created_at', '>=', now()->subSeconds($windowSeconds))
            ->exists();

        if ($recent) {
            throw new RecentManualConfirmException;
        }
    }

    /**
     * Recent text messages (user + bot) after the last context clear — mirrors the
     * slip pipeline window so findExpectedPayment sees the same order summary.
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

        return $query->latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn (Message $msg) => ['sender' => $msg->sender, 'content' => $msg->content])
            ->values()
            ->toArray();
    }

    /**
     * Send via LINE using the same Flex conversion the bot reply uses. Push (no reply
     * token) since this is an out-of-band admin action.
     */
    private function pushToLine(Bot $bot, Conversation $conversation, string $text): void
    {
        $externalId = $conversation->external_customer_id;
        if ($conversation->channel_type !== 'line' || ! $externalId) {
            return;
        }

        try {
            $transformed = $this->paymentFlex->tryConvertToFlex($text, $conversation);
            $retryKey = $this->line->generateRetryKey();
            $this->line->replyWithFallback($bot, null, $externalId, [$transformed], $retryKey);
        } catch (\Throwable $e) {
            Log::error('Manual payment confirm: LINE push failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run flow plugins (Telegram alert + OrderService). Best effort — plugin failures
     * must not fail the confirmation. Returns whether an order was created.
     */
    private function runPlugins(Bot $bot, Conversation $conversation, Message $botMessage): bool
    {
        $ordersBefore = Order::where('conversation_id', $conversation->id)->count();

        try {
            $this->flowPlugin->executePlugins($bot, $conversation, $botMessage);
        } catch (\Throwable $e) {
            Log::warning('Manual payment confirm: plugin execution failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return Order::where('conversation_id', $conversation->id)->count() > $ordersBefore;
    }

    /**
     * Insert the idempotency reservation row. Called INSIDE the confirm transaction under
     * the conversation row lock, so a failure here must roll back the whole confirm — do
     * NOT swallow: the row is the concurrency guarantee, not a best-effort record. The
     * message_id is linked afterwards (post-commit) via {@see linkSlipToMessage}.
     */
    private function reserveSlipVerification(
        Bot $bot,
        Conversation $conversation,
        float $amount,
        ?string $receiverAccount,
    ): SlipVerification {
        return SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'message_id' => null,
            'trans_ref' => null,
            'amount' => $amount,
            'receiver_account' => $receiverAccount,
            'status' => 'manual_confirmed',
            'raw_response' => null,
        ]);
    }

    /**
     * Link the committed reservation to its bot message. Best effort — the reservation is
     * already durable, so a linkage failure must not fail the confirmation.
     */
    private function linkSlipToMessage(SlipVerification $slip, Message $botMessage): void
    {
        try {
            $slip->update(['message_id' => $botMessage->id]);
        } catch (\Throwable $e) {
            Log::error('Manual payment confirm: failed to link slip verification to message', [
                'slip_verification_id' => $slip->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function broadcast(Conversation $conversation, Message $botMessage): void
    {
        $conversation->update(['last_message_at' => now(), 'last_message_id' => $botMessage->id]);
        $conversation->increment('message_count');
        $conversation->refresh();

        $conversationData = [
            'id' => $conversation->id,
            'message_count' => $conversation->message_count,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'unread_count' => $conversation->unread_count,
        ];

        try {
            broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        } catch (\Throwable $e) {
            Log::error('Manual payment confirm: broadcast failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
