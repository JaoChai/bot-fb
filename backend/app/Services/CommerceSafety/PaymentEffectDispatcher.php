<?php

namespace App\Services\CommerceSafety;

use App\Jobs\ReserveAccountStock;
use App\Jobs\RunPaymentEffect;
use App\Models\CheckoutSession;
use App\Models\FlowPlugin;
use App\Models\PaymentEffect;
use App\Models\VerifiedPaymentEvent;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\OrderService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentEffectDispatcher
{
    public const MAX_ATTEMPTS = 5;

    public const LEASE_SECONDS = 300;

    private const BACKOFF = [30, 120, 300, 900, 1800];

    public function enqueue(VerifiedPaymentEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $candidate = VerifiedPaymentEvent::find($event->id);
            if (! $candidate) {
                return;
            }
            ConversationAuthorityLock::acquire((int) $candidate->bot_id, (int) $candidate->conversation_id);
            $candidate = $this->authority($candidate->id, true);
            if (! $candidate || ! in_array(app(SafetyScope::class)->mode($candidate->bot), ['enforce', 'hold'], true)) {
                return;
            }
            // Receipt authority is the persisted proof itself. Fulfillment still
            // requires an enforce checkout settled into its exact Order.
            $settled = app(SafetyScope::class)->mode($candidate->bot) === 'enforce'
                && $this->authority($candidate->id) !== null;
            $plugin = $settled ? $this->configuredPlugin($candidate) : null;
            $kinds = $settled ? ['line_receipt', 'telegram_payment', 'reserve_stock'] : ['line_receipt'];
            foreach ($kinds as $kind) {
                $id = (string) Str::uuid();
                $invalidPlugin = $kind === 'telegram_payment' && $plugin === null;
                $inserted = DB::table('payment_effects')->insertOrIgnore([
                    'id' => $id, 'event_id' => $candidate->id, 'kind' => $kind,
                    'state' => $invalidPlugin ? 'failed' : 'pending',
                    'plugin_id' => $kind === 'telegram_payment' ? $plugin?->id : null,
                    'retry_key' => $kind === 'line_receipt' ? (string) Str::uuid() : null,
                    'attempt_count' => 0,
                    'last_error_code' => $invalidPlugin ? 'plugin_configuration_invalid' : null,
                    'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($inserted) {
                    $this->submit($id);
                }
            }
        });
    }

    /** Re-read all authority; no caller payload, prose or cached relationships are trusted. */
    public function authority(string $eventId, bool $allowHeld = false): ?VerifiedPaymentEvent
    {
        return DB::transaction(function () use ($eventId, $allowHeld): ?VerifiedPaymentEvent {
            $candidate = VerifiedPaymentEvent::find($eventId);
            if (! $candidate) {
                return null;
            }
            ConversationAuthorityLock::acquire((int) $candidate->bot_id, (int) $candidate->conversation_id);
            CheckoutSession::whereKey($candidate->checkout_id)->lockForUpdate()->first();

            return $this->lockedAuthority($eventId, $allowHeld);
        });
    }

    private function lockedAuthority(string $eventId, bool $allowHeld): ?VerifiedPaymentEvent
    {
        $event = VerifiedPaymentEvent::with(['bot', 'conversation', 'checkout', 'slipVerification', 'receiptMessage', 'actor'])->lockForUpdate()->find($eventId);
        $checkout = $event?->checkout;
        $slip = $event?->slipVerification;
        $receipt = $event?->receiptMessage;
        if (! $event || ! $event->bot || ! $event->conversation || ! $slip || ! $receipt
            || (int) $event->conversation->bot_id !== (int) $event->bot_id
            || (int) $slip->bot_id !== (int) $event->bot_id
            || (int) $slip->conversation_id !== (int) $event->conversation_id
            || (int) $receipt->conversation_id !== (int) $event->conversation_id
            || $receipt->sender !== 'bot' || $event->currency !== 'THB') {
            return null;
        }
        try {
            if (MoneyMinor::fromDecimal((string) $slip->getRawOriginal('amount')) !== $event->amount_minor) {
                return null;
            }
        } catch (\InvalidArgumentException) {
            return null;
        }
        $valid = match ($event->source) {
            'easyslip' => $slip->status === 'passed' && trim((string) $slip->trans_ref) !== ''
                && $event->event_key === 'easyslip:'.trim((string) $slip->trans_ref),
            'manual' => $slip->status === 'manual_confirmed'
                && $event->event_key === 'manual-slip:'.$slip->id
                && (int) $slip->message_id === (int) $receipt->id
                && (($event->actor?->isOwner() && (int) $event->actor_id === (int) $event->bot->user_id)
                    || ($allowHeld && $this->recoveredManualHold($event))),
            default => false,
        };
        if (! $valid || ($event->checkout_id !== null && (! $checkout
            || (int) $checkout->bot_id !== (int) $event->bot_id
            || (int) $checkout->conversation_id !== (int) $event->conversation_id))) {
            return null;
        }
        if (! $allowHeld && (! $checkout || $event->disposition !== 'settled'
            || $checkout->state !== 'paid' || $checkout->settled_event_id !== $event->id
            || $checkout->currency !== 'THB' || $event->amount_minor !== $checkout->total_minor
            || app(OrderService::class)->lockedOrderForCheckout($checkout, $event) === null)) {
            return null;
        }

        return $event;
    }

    private function recoveredManualHold(VerifiedPaymentEvent $event): bool
    {
        return $event->source === 'manual' && $event->actor_id === null
            && $event->checkout_id === null && $event->order_id === null
            && $event->disposition === 'manual_hold' && $event->hold_reason === 'manual_actor_unavailable';
    }

    private function receiptFlex(VerifiedPaymentEvent $event): array
    {
        if (! $this->recoveredManualHold($event)) {
            return app(PaymentFlexService::class)->fromVerifiedPayment($event);
        }

        // Recovered terminal manual proof acknowledges money only. Missing actor
        // provenance cannot authorize an Order or enter the settled Flex path.
        $amount = number_format(intdiv($event->amount_minor, 100))
            .($event->amount_minor % 100 ? '.'.str_pad((string) ($event->amount_minor % 100), 2, '0', STR_PAD_LEFT) : '');
        $text = 'เงินเข้าแล้ว '.$amount." บาทครับ\nรับเงินไว้แล้ว อยู่ระหว่างให้ทีมงานตรวจสอบรายการครับ";

        return [
            'type' => 'flex', 'altText' => $text,
            'contents' => ['type' => 'bubble', 'body' => [
                'type' => 'box', 'layout' => 'vertical', 'contents' => [
                    ['type' => 'text', 'text' => 'รับเงินแล้ว รอทีมงานตรวจสอบ', 'weight' => 'bold', 'wrap' => true],
                    ['type' => 'text', 'text' => $text, 'wrap' => true, 'margin' => 'md'],
                ],
            ]],
        ];
    }

    public function configuredPlugin(VerifiedPaymentEvent $event): ?FlowPlugin
    {
        $ids = config("commerce_safety.bots.{$event->bot_id}.payment_plugin_ids");
        if (! is_array($ids) || count($ids) !== 1 || ! is_int(array_values($ids)[0]) || array_values($ids)[0] <= 0) {
            return null;
        }
        $plugin = FlowPlugin::whereKey(array_values($ids)[0])->where('enabled', true)->where('type', 'telegram')
            ->whereHas('flow', fn ($query) => $query->where('bot_id', $event->bot_id))->first();
        $config = $plugin?->config ?? [];
        if (! is_string($config['access_token'] ?? null) || trim($config['access_token']) === ''
            || ! is_scalar($config['chat_id'] ?? null) || trim((string) $config['chat_id']) === ''
            || ! is_string($config['message_template'] ?? null) || trim($config['message_template']) === '') {
            return null;
        }

        return $plugin;
    }

    public function submit(string $id): void
    {
        // Catch queue failures inside the callback: the local commit must still succeed.
        DB::afterCommit(function () use ($id): void {
            try {
                RunPaymentEffect::dispatch($id)->afterCommit();
            } catch (\Throwable) {
                Log::warning('Payment effect queue submission failed', ['effect_id' => $id]);
            }
        });
    }

    public function claim(string $id): ?PaymentEffect
    {
        return DB::transaction(function () use ($id): ?PaymentEffect {
            $effect = PaymentEffect::lockForUpdate()->find($id);
            if (! $effect || in_array($effect->state, ['succeeded', 'uncertain'], true)) {
                return null;
            }
            if ($effect->state === 'running') {
                if ($effect->claimed_at && $effect->claimed_at->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                    return null;
                }
                if ($effect->kind === 'telegram_payment' && $effect->transport_started_at !== null) {
                    $effect->forceFill(['state' => 'uncertain', 'last_error_code' => 'transport_process_lost',
                        'claim_token' => null, 'claimed_at' => null, 'next_attempt_at' => null])->save();

                    return null;
                }
            }
            if ($effect->attempt_count >= self::MAX_ATTEMPTS) {
                $effect->forceFill(['state' => 'failed', 'claim_token' => null, 'claimed_at' => null,
                    'next_attempt_at' => null, 'last_error_code' => 'attempts_exhausted'])->save();

                return null;
            }
            if ($effect->state !== 'running' && $effect->next_attempt_at?->isFuture()) {
                return null;
            }
            // LINE's remote retry identity has a finite lifetime. Never replay an old ambiguous push.
            if ($effect->kind === 'line_receipt' && $effect->transport_started_at?->lte(now()->subHours(23))) {
                $effect->forceFill(['state' => 'uncertain', 'last_error_code' => 'line_retry_window_expired',
                    'claim_token' => null, 'claimed_at' => null, 'next_attempt_at' => null])->save();

                return null;
            }
            $effect->forceFill(['state' => 'running', 'claim_token' => (string) Str::uuid(),
                'claimed_at' => now(), 'attempt_count' => $effect->attempt_count + 1,
                'next_attempt_at' => null,
                'transport_started_at' => $effect->kind === 'line_receipt' ? $effect->transport_started_at : null])->save();

            return $effect;
        });
    }

    public function beginTransport(PaymentEffect $claim): bool
    {
        return PaymentEffect::whereKey($claim->id)->where('state', 'running')
            ->where('claim_token', $claim->claim_token)
            ->where('claimed_at', '>', now()->subSeconds(self::LEASE_SECONDS))
            ->update(['transport_started_at' => $claim->transport_started_at ?? now()]) === 1;
    }

    public function finish(PaymentEffect $claim, string $state, ?string $code = null, ?string $remoteId = null): bool
    {
        return DB::transaction(fn (): bool => PaymentEffect::whereKey($claim->id)->where('state', 'running')
            ->where('claim_token', $claim->claim_token)->update([
                'state' => $state, 'last_error_code' => $code, 'remote_id' => $remoteId,
                'claim_token' => null, 'claimed_at' => null,
                'next_attempt_at' => $state === 'failed' && $claim->attempt_count < self::MAX_ATTEMPTS
                    ? now()->addSeconds(self::BACKOFF[$claim->attempt_count - 1]) : null,
            ]) === 1);
    }

    public function run(string $id): void
    {
        // A synchronous caller inside an outer transaction must never execute transport.
        if (DB::transactionLevel() > 0) {
            // Leave it pending for reconciliation if invoked by an unsafe synchronous caller.
            return;
        }
        $claim = $this->claim($id);
        if (! $claim) {
            return;
        }
        try {
            $event = $this->authority($claim->event_id, $claim->kind === 'line_receipt');
            if (! $event) {
                throw new PaymentEffectFailure('authority_invalid');
            }
            $remoteId = null;
            if ($claim->kind === 'line_receipt') {
                if (! in_array(app(SafetyScope::class)->mode($event->bot), ['enforce', 'hold'], true)) {
                    throw new PaymentEffectFailure('scope_disabled');
                }
                if ($event->conversation->channel_type !== 'line' || ! $event->conversation->external_customer_id) {
                    throw new PaymentEffectFailure('line_destination_invalid');
                }
                $flex = $this->receiptFlex($event);
                $event->receiptMessage->update(['content' => $flex['altText']]);
                if (! $this->beginTransport($claim)) {
                    return;
                }
                $remoteId = app(LINEService::class)->pushPaymentReceipt($event->bot,
                    $event->conversation->external_customer_id, $flex, $claim->retry_key);
            } elseif ($claim->kind === 'telegram_payment') {
                app(FlowPluginService::class)->sendVerifiedPayment($event, $claim->plugin_id ?? 0,
                    function () use ($claim): void {
                        if (! $this->beginTransport($claim)) {
                            throw new PaymentEffectFailure('claim_lost');
                        }
                    });
            } else {
                if (! $this->beginTransport($claim)) {
                    return;
                }
                $remoteId = ReserveAccountStock::runEffect($event);
            }
            $this->finish($claim, 'succeeded', null, $remoteId);
        } catch (PaymentEffectFailure $e) {
            $this->finish($claim, $e->ambiguous ? 'uncertain' : 'failed', $e->errorCode);
        } catch (\Throwable) {
            $started = PaymentEffect::whereKey($id)->value('transport_started_at') !== null;
            $this->finish($claim, $claim->kind === 'telegram_payment' && $started ? 'uncertain' : 'failed', 'execution_failed');
        }
    }

    /** @return array{queued: int, uncertain: int, exhausted: int} */
    public function reconcile(): array
    {
        $queued = 0;
        PaymentEffect::whereIn('state', ['pending', 'failed', 'running'])->orderBy('id')->chunkById(100,
            function ($effects) use (&$queued): void {
                foreach ($effects as $effect) {
                    if ($effect->state === 'running') {
                        if ($effect->claimed_at?->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                            continue;
                        }
                        DB::transaction(function () use ($effect): void {
                            $locked = PaymentEffect::lockForUpdate()->find($effect->id);
                            if (! $locked || $locked->state !== 'running'
                                || $locked->claimed_at?->gt(now()->subSeconds(self::LEASE_SECONDS))) {
                                return;
                            }
                            $uncertain = $locked->kind === 'telegram_payment' && $locked->transport_started_at !== null;
                            $locked->forceFill(['state' => $uncertain ? 'uncertain' : 'failed',
                                'claim_token' => null, 'claimed_at' => null,
                                'last_error_code' => $uncertain ? 'transport_process_lost' : 'stale_claim_recovered',
                                'next_attempt_at' => $uncertain || $locked->attempt_count >= self::MAX_ATTEMPTS ? null : now()])->save();
                        });
                        $effect->refresh();
                        if (! in_array($effect->state, ['pending', 'failed'], true)) {
                            continue;
                        }
                    }
                    if ($effect->attempt_count < self::MAX_ATTEMPTS && ! $effect->next_attempt_at?->isFuture()) {
                        $this->submit($effect->id);
                        $queued++;
                    }
                }
            });

        return ['queued' => $queued, 'uncertain' => PaymentEffect::where('state', 'uncertain')->count(),
            'exhausted' => PaymentEffect::where('state', 'failed')->where('attempt_count', '>=', self::MAX_ATTEMPTS)->count()];
    }
}
