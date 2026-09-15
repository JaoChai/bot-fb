<?php

namespace App\Services\Payment;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\NoPendingPaymentException;
use App\Exceptions\RecentManualConfirmException;
use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\CommerceSafety\ConversationAuthorityLock;
use App\Services\CommerceSafety\MoneyMinor;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
        private readonly SafetyScope $safetyScope,
    ) {}

    /**
     * @return array{message: Message, order_created: bool}
     *
     * @throws NoPendingPaymentException When no amount can be resolved.
     * @throws RecentManualConfirmException When a manual confirm for this conversation happened within the idempotency window.
     */
    public function confirm(
        Bot $bot,
        Conversation $conversation,
        int|string|float|null $amountOverride,
        int $confirmedBy,
        ?array $itemsOverride = null,
        ?string $checkoutId = null,
        ?int $checkoutRevision = null,
    ): array {
        // เช็คยืนยันซ้ำก่อน resolve ยอด: หลังยืนยันสำเร็จ ข้อความ "เงินเข้าแล้ว" จะบัง
        // summary เก่าใน history (ตั้งใจ — จ่ายแล้ว) ทำให้ resolve ยอดไม่ได้ ถ้าไม่เช็คตรงนี้
        // การกดซ้ำจะกลายเป็น NoPendingPayment (422) แทน RecentManualConfirm (409)
        // เช็คจริงแบบ atomic ยังอยู่ใน transaction ด้านล่างเหมือนเดิม
        $scoped = in_array($this->safetyScope->mode($bot), ['enforce', 'hold'], true);
        if ($scoped) {
            $actor = User::query()->find($confirmedBy);
            if (! $actor || ! $actor->isOwner() || (int) $actor->id !== (int) $bot->user_id) {
                throw ValidationException::withMessages([
                    'actor' => 'Manual payment confirmation requires the authorized bot owner.',
                ]);
            }
        }
        $scopedAmountMinor = $scoped && $amountOverride !== null
            ? $this->strictAmountMinor($amountOverride)
            : null;
        $this->guardAgainstDoubleConfirm($conversation);

        $checkoutQuery = CheckoutSession::query()
            ->where('bot_id', $bot->id)
            ->where('conversation_id', $conversation->id);
        $checkout = null;
        $explicitCheckout = $scoped && ($checkoutId !== null || $checkoutRevision !== null);
        if ($explicitCheckout) {
            if ($checkoutId === null || $checkoutRevision === null) {
                throw ValidationException::withMessages([
                    'checkout' => 'Exact checkout ID and revision are required together.',
                ]);
            }
            $checkout = (clone $checkoutQuery)
                ->whereKey($checkoutId)
                ->where('revision', $checkoutRevision)
                ->first();
            if ($checkout === null) {
                throw ValidationException::withMessages([
                    'checkout' => 'The selected checkout revision is missing or stale.',
                ]);
            }
        } elseif ($scoped) {
            $candidates = (clone $checkoutQuery)
                ->whereIn('state', ['draft', 'awaiting_confirm', 'awaiting_support', 'awaiting_terms', 'payable'])
                ->limit(2)
                ->get();
            $checkout = $candidates->count() === 1 ? $candidates->first() : null;
        }
        $history = $scoped ? [] : $this->recentTextHistory($conversation);
        $receiverAccount = $bot->settings?->slip_receiver_account ?: null;

        // เจ้าของกดเลือกรายการจากการ์ดแล้ว → ใช้ตามนั้น ไม่ต้องเดาจากข้อความอีก
        if ($scoped) {
            // Overrides can attest to received money, but the cart itself comes only
            // from the persisted checkout/revision.
            $expected = $checkout === null ? null : [
                'total' => $this->minorDecimal($checkout->total_minor),
                'summary' => collect($checkout->items)
                    ->map(fn (array $item): string => $item['name'].' x'.$item['qty'])
                    ->implode(', '),
                'items' => $checkout->items,
            ];
        } elseif ($itemsOverride !== null && $itemsOverride !== []) {
            $expected = [
                'total' => $amountOverride,
                'summary' => PaymentMessageDetector::formatItemSummary($itemsOverride),
                'items' => $itemsOverride,
            ];
        } else {
            // ยอดที่กดยืนยันต้องชี้ใบสรุปที่ยอดตรงเท่านั้น — ห้ามคว้าใบล่าสุดมาแปะยอดอื่น
            // (เคสจริง #1253: กด "ยอดในสลิป 1,100" แต่ใบล่าสุดคือ Page 199 → เคยได้ออเดอร์
            // Page ราคา 1,100 ผิดตัว) ไม่เจอใบที่ตรง → fallback ข้อความยืนยันขั้น 2 ด้านล่าง
            // ซึ่ง match ด้วยยอดอยู่แล้ว → ยังไม่เจออีก = summary '-' ไม่มี items ปลอดภัยกว่าเดาผิด
            $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
            $legacyAmountOverride = $amountOverride === null ? null : (float) $amountOverride;
            $expected = $this->slipVerification->findExpectedPayment(
                $history, $receiverAccount, $bot, $legacyAmountOverride, $tolerance,
            );
        }

        $amount = $scoped
            ? ($scopedAmountMinor === null
                ? ($expected['total'] ?? null)
                : $this->minorDecimal($scopedAmountMinor))
            : ($amountOverride ?? ($expected['total'] ?? null));
        if ($amount === null) {
            throw new NoPendingPaymentException;
        }

        // Fallback ชั้น 3 (ลูกค้าโอนข้ามขั้นตอน): ไม่มีข้อความสรุปยอด+เลขบัญชีใน window
        // → อ่านออเดอร์จากข้อความยืนยันขั้น 2 โดยยอดต้องตรงกับยอดที่กดยืนยัน
        if (! $scoped && $expected === null) {
            $expected = $this->slipVerification->findExpectedFromConfirmMessage($history, $bot, (float) $amount);
        }

        $summary = $expected['summary'] ?? '-';
        $template = $bot->settings?->slip_success_message ?: LineWebhookResponseService::SLIP_SUCCESS_TEMPLATE;
        $text = str_replace(
            ['{amount}', '{order_summary}'],
            [number_format((float) $amount), $summary],
            $template,
        );

        if ($scoped) {
            // No delivery promise exists until the persisted disposition is known.
            $text = 'เงินเข้าแล้ว '.$amount.' บาทครับ อยู่ระหว่างให้ทีมงานตรวจสอบรายการ';
            $result = DB::transaction(function () use (
                $bot,
                $conversation,
                $amount,
                $receiverAccount,
                $text,
                $confirmedBy,
                $checkout,
                $explicitCheckout,
            ): array {
                Bot::whereKey($bot->id)->lockForUpdate()->firstOrFail();
                ConversationAuthorityLock::acquire((int) $bot->id, (int) $conversation->id);
                $this->guardAgainstDoubleConfirm($conversation);
                $lockedCheckout = $checkout === null || ! $explicitCheckout
                    ? null
                    : CheckoutSession::query()->lockForUpdate()->find($checkout->getKey());
                if ($explicitCheckout && (! $lockedCheckout
                    || (int) $lockedCheckout->bot_id !== (int) $bot->id
                    || (int) $lockedCheckout->conversation_id !== (int) $conversation->id
                    || (int) $lockedCheckout->revision !== (int) $checkout->revision)) {
                    throw ValidationException::withMessages([
                        'checkout' => 'The selected checkout revision is missing or stale.',
                    ]);
                }

                $slip = $this->reserveSlipVerification($bot, $conversation, $amount, $receiverAccount);
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
                // Scoped money proof is one local atomic unit. Linkage and settlement
                // failures must roll back the slip, receipt, event, and Order together.
                $slip->update(['message_id' => $botMessage->id]);
                $outcome = $this->slipVerification->settleVerifiedReceipt(
                    $bot,
                    $conversation,
                    $slip,
                    $botMessage,
                    $confirmedBy,
                    $lockedCheckout,
                );

                return [
                    'message' => $botMessage,
                    'order_created' => $outcome->action === 'settled'
                        && $outcome->checkout?->settled_event_id !== null,
                ];
            });
            DB::afterCommit(function () use ($bot, $conversation, $result): void {
                try {
                    $event = app(PaymentProofService::class)
                        ->forReceipt($bot, $conversation, $result['message']);
                    if ($event === null) {
                        return;
                    }
                    $flex = $this->paymentFlex->fromVerifiedPayment($event);
                    $result['message']->update(['content' => $flex['altText']]);
                    if ($conversation->channel_type === 'line' && $conversation->external_customer_id) {
                        $this->line->replyWithFallback($bot, null, $conversation->external_customer_id, [$flex], $this->line->generateRetryKey());
                    }
                } catch (\Throwable $e) {
                    Log::warning('Verified manual receipt presentation failed', ['message_id' => $result['message']->id, 'error' => $e->getMessage()]);
                }
            });

            return $result;
        }

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

        // เจ้าของกดเลือกรายการเอง (itemsOverride) = เชื่อได้เสมอ; ถ้าเดาจากข้อความแล้วผลรวม
        // ขัดกับยอด ต้องไม่จองเอง — ให้เจ้าของกดเลือกจากการ์ดแทน (กติกาเดียวกับเส้นทาง EasySlip)
        $trusted = ($itemsOverride !== null && $itemsOverride !== [])
            || ! ($expected['items_unreliable'] ?? false);

        if ($trusted) {
            ReserveAccountStock::dispatchSafely(
                $bot->id,
                $conversation->id,
                $slip->id,
                $amount,
                $expected['items'] ?? [],
            );
        } else {
            Log::warning('Manual confirm: skipped auto-reserve — order items failed checksum', [
                'conversation_id' => $conversation->id,
                'amount' => $amount,
            ]);

            // LOG_LEVEL บน prod กลืน warning ทิ้ง → ต้องแจ้งผ่านการ์ด Telegram ไม่งั้นเจ้าของเพิ่งกด
            // ยืนยันรับเงินแล้วรอการ์ดส่งของ พอไม่มีอะไรมาเลย = ออเดอร์ค้างเงียบ (กติกาเดียวกับอีก 2 เส้นทาง)
            $this->slipVerification->notifyAdmin($bot, $conversation, new SlipVerificationResult(
                isSlip: true,
                passed: true,
                amount: $amount,
                orderSummary: $summary,
                itemsUnreliable: true,
            ));
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
     * @return array<int, array{sender: string, content: string, metadata: ?array}>
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
            ->map(fn (Message $msg) => [
                'sender' => $msg->sender,
                'content' => $msg->content,
                'metadata' => $msg->metadata,
            ])
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
        int|string|float $amount,
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

    private function strictAmountMinor(int|string|float $amount): int
    {
        if (! is_int($amount) && ! is_string($amount)) {
            throw ValidationException::withMessages([
                'amount' => 'Scoped confirmation requires a plain decimal or integer amount.',
            ]);
        }
        try {
            $minor = MoneyMinor::fromDecimal((string) $amount);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => 'Scoped confirmation requires at most two decimal places.',
            ]);
        }
        if ($minor <= 0 || $minor > 100000000) {
            throw ValidationException::withMessages([
                'amount' => 'Scoped confirmation amount is outside the supported range.',
            ]);
        }

        return $minor;
    }

    private function minorDecimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
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
