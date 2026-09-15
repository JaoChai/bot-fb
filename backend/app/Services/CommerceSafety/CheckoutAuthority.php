<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use App\Models\VerifiedPaymentEvent;
use Illuminate\Support\Facades\DB;

class CheckoutAuthority
{
    private const OPEN_STATES = [
        'draft',
        'awaiting_confirm',
        'awaiting_support',
        'awaiting_terms',
        'payable',
    ];

    private const SUPPORT_ACCEPT = [
        'ตกลง',
        'ยอมรับ',
        'รับทราบ',
        'รับได้',
        'โอเค',
        'accept',
        'agree',
    ];

    private const SLIP_UNDER_REVIEW_STATUSES = [
        'pending',
        'api_error',
        'config_error',
        'needs_choice',
        'unreadable',
        'image_download_failed',
        'amount_mismatch',
        'wrong_account',
        'no_pending_order',
    ];

    public function __construct(
        private readonly CheckoutConsentPolicy $policy,
        private readonly SafetyScope $scope,
        private readonly CanonicalCartValidator $validator,
        private readonly CheckoutRenderer $renderer,
    ) {}

    public function propose(Bot $bot, Conversation $conversation, CartValidation $cart): CheckoutOutcome
    {
        $mode = $this->scope->mode($bot);
        if (! $cart->valid
            || ! $bot->exists
            || ! $conversation->exists
            || (int) $conversation->bot_id !== (int) $bot->getKey()) {
            return new CheckoutOutcome('clarify', null);
        }
        if ($mode === 'hold') {
            return new CheckoutOutcome(
                'manual_hold',
                null,
                'ขออภัยครับ ระบบพักการส่งข้อมูลชำระเงินชั่วคราว ทีมงานจะช่วยตรวจสอบรายการให้ครับ',
            );
        }
        if ($mode !== 'enforce') {
            return new CheckoutOutcome('clarify', null);
        }

        return DB::transaction(function () use ($bot, $conversation, $cart): CheckoutOutcome {
            $lockedConversation = Conversation::query()
                ->where('bot_id', $bot->getKey())
                ->lockForUpdate()
                ->find($conversation->getKey());
            if (! $lockedConversation) {
                return new CheckoutOutcome('clarify', null);
            }

            $checkout = $this->currentCheckout($bot, $lockedConversation);
            if ($checkout && $this->hasPaymentUnderReview($checkout)) {
                return new CheckoutOutcome('manual_hold', $checkout);
            }
            if ($checkout && ! $this->acceptedMessagesRemainAuthoritative($checkout)) {
                $checkout->forceFill([
                    'revision' => $checkout->revision + 1,
                    'accepted' => [],
                    'state' => $this->nextState($checkout->requirements, []),
                    'challenge_message_id' => null,
                    'challenge_action' => null,
                    'presented_at' => null,
                    'presented_event_timestamp' => null,
                    'presented_message_watermark_id' => null,
                ])->save();
            }

            if (! $checkout) {
                $canonical = $this->revalidate($bot, $lockedConversation, $cart->lines, $cart->totalMinor);
                if ($canonical === null) {
                    return $this->unsafeProposalOutcome(null);
                }

                $requirements = $this->policy->requirements($bot, $lockedConversation, $canonical->lines);
                $checkout = new CheckoutSession;
                $checkout->forceFill([
                    'bot_id' => $bot->getKey(),
                    'conversation_id' => $lockedConversation->getKey(),
                    'revision' => 1,
                    'state' => $this->nextState($requirements, []),
                    'items' => $canonical->lines,
                    'total_minor' => $canonical->totalMinor,
                    'currency' => 'THB',
                    'fingerprint' => $canonical->fingerprint,
                    'requirements' => $requirements,
                    'accepted' => [],
                    'challenge_message_id' => null,
                    'challenge_action' => null,
                    'presented_at' => null,
                    'presented_event_timestamp' => null,
                    'presented_message_watermark_id' => null,
                    'settled_event_id' => null,
                ])->save();

                return $this->outcome($checkout);
            }

            $mergedItems = $this->mergeItems($checkout->items, $cart->lines);
            $claimedTotal = $this->checkedTotal($mergedItems);
            if ($claimedTotal === null) {
                return $this->unsafeProposalOutcome($checkout);
            }
            $canonical = $this->revalidate($bot, $lockedConversation, $mergedItems, $claimedTotal);
            if ($canonical === null) {
                return $this->unsafeProposalOutcome($checkout);
            }

            $mergedItems = $canonical->lines;
            $totalMinor = $canonical->totalMinor;
            $fingerprint = $canonical->fingerprint;
            if (hash_equals($checkout->fingerprint, $fingerprint)) {
                return $this->outcome($checkout);
            }

            $requirements = $this->policy->requirements($bot, $lockedConversation, $mergedItems);
            $accepted = [];
            if ($this->topupSignature($checkout->items) === $this->topupSignature($mergedItems)
                && isset($checkout->accepted['topup_ack'])) {
                $accepted['topup_ack'] = $checkout->accepted['topup_ack'];
            }

            $checkout->forceFill([
                'revision' => $checkout->revision + 1,
                'state' => $this->nextState($requirements, $accepted),
                'items' => $mergedItems,
                'total_minor' => $totalMinor,
                'fingerprint' => $fingerprint,
                'requirements' => $requirements,
                'accepted' => $accepted,
                'challenge_message_id' => null,
                'challenge_action' => null,
                'presented_at' => null,
                'presented_event_timestamp' => null,
                'presented_message_watermark_id' => null,
            ])->save();
            $this->carryAcceptedRows($checkout, $accepted);

            return $this->outcome($checkout);
        });
    }

    public function pending(CheckoutSession $checkout, int $revision, Message $challenge, string $action): void
    {
        DB::transaction(function () use ($checkout, $revision, $challenge, $action): void {
            $locked = CheckoutSession::query()->lockForUpdate()->find($checkout->getKey());
            if (! $locked
                || $locked->revision !== $revision
                || $this->actionForState($locked->state) !== $action
                || ! $this->validChallenge($locked, $challenge)) {
                return;
            }

            $locked->forceFill([
                'challenge_message_id' => $challenge->getKey(),
                'challenge_action' => $action,
                'presented_at' => null,
                'presented_event_timestamp' => null,
                'presented_message_watermark_id' => null,
            ])->save();
        });
    }

    public function presented(CheckoutSession $checkout, int $revision, Message $challenge): void
    {
        DB::transaction(function () use ($checkout, $revision, $challenge): void {
            $locked = CheckoutSession::query()->lockForUpdate()->find($checkout->getKey());
            if (! $locked
                || $locked->revision !== $revision
                || ($locked->challenge_message_id !== null
                    && (int) $locked->challenge_message_id !== (int) $challenge->getKey())
                || $locked->challenge_action !== $this->actionForState($locked->state)
                || ! $this->validChallenge($locked, $challenge)) {
                return;
            }

            $locked->forceFill([
                'challenge_message_id' => $challenge->getKey(),
                'presented_at' => now(),
                'presented_event_timestamp' => now()->getTimestampMs(),
                'presented_message_watermark_id' => Message::query()
                    ->where('conversation_id', $locked->conversation_id)
                    ->max('id'),
            ])->save();
        });
    }

    public function accept(
        Bot $bot,
        Conversation $conversation,
        Message $customerMessage,
    ): CheckoutOutcome {
        if ($this->scope->mode($bot) !== 'enforce'
            || ! $bot->exists
            || ! $conversation->exists
            || (int) $conversation->bot_id !== (int) $bot->getKey()) {
            return new CheckoutOutcome('clarify', null);
        }

        return DB::transaction(function () use ($bot, $conversation, $customerMessage): CheckoutOutcome {
            $lockedConversation = Conversation::query()
                ->where('bot_id', $bot->getKey())
                ->lockForUpdate()
                ->find($conversation->getKey());
            if (! $lockedConversation) {
                return new CheckoutOutcome('clarify', null);
            }

            $checkout = $this->currentCheckout($bot, $lockedConversation);
            if (! $checkout) {
                return new CheckoutOutcome('clarify', null);
            }

            $message = Message::query()
                ->whereKey($customerMessage->getKey())
                ->where('conversation_id', $lockedConversation->getKey())
                ->where('sender', 'user')
                ->lockForUpdate()
                ->first();
            if (! $message || ! is_string($message->content)) {
                return $this->outcome($checkout);
            }

            $normalized = $this->normalizeResponse($message->content);
            if (in_array($normalized, ['ยกเลิก', 'cancel'], true)) {
                $checkout->forceFill([
                    'state' => 'cancelled',
                    'challenge_message_id' => null,
                    'challenge_action' => null,
                    'presented_at' => null,
                    'presented_event_timestamp' => null,
                    'presented_message_watermark_id' => null,
                ])->save();

                return new CheckoutOutcome('ack', $checkout, 'ยกเลิกรายการนี้แล้วครับ');
            }

            $current = $this->revalidate(
                $bot,
                $lockedConversation,
                $checkout->items,
                $checkout->total_minor,
            );
            if ($current === null) {
                return $this->unsafeProposalOutcome($checkout);
            }
            $requirements = $this->policy->requirements($bot, $lockedConversation, $current->lines);
            if (! hash_equals($checkout->fingerprint, $current->fingerprint)
                || $this->requirementFlags($checkout->requirements) !== $this->requirementFlags($requirements)) {
                $accepted = [];
                if ($this->topupSignature($checkout->items) === $this->topupSignature($current->lines)
                    && isset($checkout->accepted['topup_ack'])) {
                    $accepted['topup_ack'] = $checkout->accepted['topup_ack'];
                }
                $checkout->forceFill([
                    'revision' => $checkout->revision + 1,
                    'state' => $this->nextState($requirements, $accepted),
                    'items' => $current->lines,
                    'total_minor' => $current->totalMinor,
                    'fingerprint' => $current->fingerprint,
                    'requirements' => $requirements,
                    'accepted' => $accepted,
                    'challenge_message_id' => null,
                    'challenge_action' => null,
                    'presented_at' => null,
                    'presented_event_timestamp' => null,
                    'presented_message_watermark_id' => null,
                ])->save();
                $this->carryAcceptedRows($checkout, $accepted);
                $action = $this->actionForState($checkout->state);

                return new CheckoutOutcome(
                    $action,
                    $checkout,
                    $this->renderer->render($checkout, $action),
                );
            }

            $action = $this->actionForState($checkout->state);
            $stage = $this->stageForState($checkout->state);
            if (! $this->acceptedMessagesRemainAuthoritative($checkout)) {
                $checkout->forceFill([
                    'revision' => $checkout->revision + 1,
                    'accepted' => [],
                    'state' => $this->nextState($checkout->requirements, []),
                    'challenge_message_id' => null,
                    'challenge_action' => null,
                    'presented_at' => null,
                    'presented_event_timestamp' => null,
                    'presented_message_watermark_id' => null,
                ])->save();

                return $this->outcome($checkout);
            }
            if ($stage === null
                || $checkout->challenge_message_id === null
                || $checkout->challenge_action !== $action
                || $checkout->presented_at === null
                || $checkout->presented_event_timestamp === null
                || $checkout->presented_message_watermark_id === null
                || in_array((int) $message->getKey(), array_map('intval', array_values($checkout->accepted)), true)
                || ! $this->accepts($stage, $normalized)) {
                return new CheckoutOutcome($action, $checkout);
            }

            $challenge = Message::query()
                ->whereKey($checkout->challenge_message_id)
                ->where('conversation_id', $lockedConversation->getKey())
                ->where('sender', 'bot')
                ->first();
            if (! $challenge
                || $message->event_timestamp === null
                || (int) $message->event_timestamp <= $checkout->presented_event_timestamp
                || (int) $message->getKey() <= (int) $checkout->presented_message_watermark_id
                || $message->created_at->lt($checkout->presented_at)) {
                return new CheckoutOutcome($action, $checkout);
            }

            $accepted = $checkout->accepted;
            $accepted[$stage] = (int) $message->getKey();
            DB::table('checkout_consent_acceptances')->insert([
                'checkout_id' => $checkout->getKey(),
                'revision' => $checkout->revision,
                'stage' => $stage,
                'message_id' => $message->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $checkout->forceFill([
                'accepted' => $accepted,
                'state' => $this->nextState($checkout->requirements, $accepted),
                'challenge_message_id' => null,
                'challenge_action' => null,
                'presented_at' => null,
                'presented_event_timestamp' => null,
                'presented_message_watermark_id' => null,
            ])->save();

            return $this->outcome($checkout);
        });
    }

    private function currentCheckout(Bot $bot, Conversation $conversation): ?CheckoutSession
    {
        return CheckoutSession::query()
            ->where('bot_id', $bot->getKey())
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('state', self::OPEN_STATES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function hasPaymentUnderReview(CheckoutSession $checkout): bool
    {
        return $checkout->settled_event_id !== null
            || VerifiedPaymentEvent::query()->where('checkout_id', $checkout->getKey())->exists()
            || SlipVerification::query()
                ->where('bot_id', $checkout->bot_id)
                ->where('conversation_id', $checkout->conversation_id)
                ->whereIn('status', self::SLIP_UNDER_REVIEW_STATUSES)
                ->where('created_at', '>=', $checkout->created_at)
                ->exists();
    }

    private function acceptedMessagesRemainAuthoritative(CheckoutSession $checkout): bool
    {
        foreach ($checkout->accepted as $stage => $messageId) {
            $exists = DB::table('checkout_consent_acceptances')
                ->join('messages', 'messages.id', '=', 'checkout_consent_acceptances.message_id')
                ->where('checkout_consent_acceptances.checkout_id', $checkout->getKey())
                ->where('checkout_consent_acceptances.revision', $checkout->revision)
                ->where('checkout_consent_acceptances.stage', $stage)
                ->where('checkout_consent_acceptances.message_id', $messageId)
                ->where('messages.conversation_id', $checkout->conversation_id)
                ->where('messages.sender', 'user')
                ->exists();
            if (! $exists) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,int> $accepted */
    private function carryAcceptedRows(CheckoutSession $checkout, array $accepted): void
    {
        foreach ($accepted as $stage => $messageId) {
            if (Message::query()->whereKey($messageId)
                ->where('conversation_id', $checkout->conversation_id)
                ->where('sender', 'user')->exists()) {
                DB::table('checkout_consent_acceptances')->insert([
                    'checkout_id' => $checkout->getKey(),
                    'revision' => $checkout->revision,
                    'stage' => $stage,
                    'message_id' => $messageId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @param list<array<string,mixed>> $existing @param list<array<string,mixed>> $proposed */
    private function mergeItems(array $existing, array $proposed): array
    {
        $replacedSkus = array_fill_keys(array_column($proposed, 'sku'), true);
        $merged = array_values(array_filter(
            $existing,
            fn (array $item): bool => ! isset($replacedSkus[$item['sku']]),
        ));
        array_push($merged, ...$proposed);
        usort($merged, fn (array $a, array $b): int => [$a['product_id'], $a['method']] <=> [$b['product_id'], $b['method']]);

        return $merged;
    }

    /** @param list<array<string,mixed>> $items */
    private function checkedTotal(array $items): ?int
    {
        $total = 0;
        foreach ($items as $item) {
            $lineTotal = $item['line_total_minor'] ?? null;
            if (! is_int($lineTotal) || $lineTotal <= 0 || $total > PHP_INT_MAX - $lineTotal) {
                return null;
            }
            $total += $lineTotal;
        }

        return $total;
    }

    /** @param list<array<string,mixed>> $items */
    private function revalidate(
        Bot $bot,
        Conversation $conversation,
        array $items,
        int $claimedTotal,
    ): ?CartValidation {
        $validation = $this->validator->validate(
            $bot,
            $conversation,
            array_map(fn (array $item): array => [
                'name' => $item['name'] ?? null,
                'method' => $item['method'] ?? null,
                'qty' => $item['qty'] ?? null,
                'price_minor' => $item['price_minor'] ?? null,
            ], $items),
            $claimedTotal,
        );
        if ($validation->valid) {
            return $validation;
        }

        $correctable = ['PRICE_MISMATCH', 'TOTAL_MISMATCH'];
        if ($validation->lines !== []
            && array_diff($validation->errors, $correctable) === []) {
            return new CartValidation(
                valid: true,
                errors: [],
                lines: $validation->lines,
                totalMinor: $validation->totalMinor,
                vip: $validation->vip,
                fingerprint: $validation->fingerprint,
            );
        }

        return null;
    }

    private function unsafeProposalOutcome(?CheckoutSession $checkout): CheckoutOutcome
    {
        return new CheckoutOutcome(
            'manual_hold',
            $checkout,
            'รายการหรือสิทธิการซื้อล่าสุดตรวจสอบไม่ผ่านครับ ทีมงานจะช่วยตรวจสอบก่อนส่งข้อมูลชำระเงิน',
        );
    }

    private function requirementFlags(array $requirements): array
    {
        return [
            'topup_ack' => (bool) ($requirements['topup_ack'] ?? false),
            'support_delay' => (bool) ($requirements['support_delay'] ?? false),
            'terms' => (bool) ($requirements['terms'] ?? false),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function topupSignature(array $items): array
    {
        return array_values(array_map(
            fn (array $item): array => ['sku' => $item['sku'], 'qty' => $item['qty']],
            array_filter($items, fn (array $item): bool => ($item['method'] ?? null) === 'topup'),
        ));
    }

    private function nextState(array $requirements, array $accepted): string
    {
        if (($requirements['topup_ack'] ?? false) && ! isset($accepted['topup_ack'])) {
            return 'draft';
        }
        if (! isset($accepted['confirm'])) {
            return 'awaiting_confirm';
        }
        if (($requirements['support_delay'] ?? false) && ! isset($accepted['support_delay'])) {
            return 'awaiting_support';
        }
        if (($requirements['terms'] ?? false) && ! isset($accepted['terms'])) {
            return 'awaiting_terms';
        }

        return 'payable';
    }

    private function outcome(CheckoutSession $checkout): CheckoutOutcome
    {
        return new CheckoutOutcome($this->actionForState($checkout->state), $checkout);
    }

    private function actionForState(string $state): string
    {
        return match ($state) {
            'draft' => 'ack',
            'awaiting_confirm' => 'confirm',
            'awaiting_support' => 'support_delay',
            'awaiting_terms' => 'terms',
            'payable' => 'payment',
            'paid_hold' => 'manual_hold',
            default => 'ack',
        };
    }

    private function stageForState(string $state): ?string
    {
        return match ($state) {
            'draft' => 'topup_ack',
            'awaiting_confirm' => 'confirm',
            'awaiting_support' => 'support_delay',
            'awaiting_terms' => 'terms',
            default => null,
        };
    }

    private function accepts(string $stage, string $response): bool
    {
        return match ($stage) {
            'topup_ack', 'support_delay' => in_array($response, self::SUPPORT_ACCEPT, true),
            'confirm' => in_array($response, ['ยืนยัน', 'confirm'], true),
            'terms' => in_array($response, ['ยอมรับ', 'accept', 'agree'], true),
            default => false,
        };
    }

    private function normalizeResponse(string $response): string
    {
        $response = mb_strtolower(trim($response));
        $edge = '[\s\x{0022}\x{0027}\x{2018}\x{2019}\x{201C}\x{201D}\.,!\?…。，、:;\(\)\[\]\{\}<>ฯ]';
        $response = preg_replace('/^'.$edge.'+|'.$edge.'+$/u', '', $response) ?? $response;
        $response = preg_replace('/\s*(?:นะครับ|นะคะ|ครับ|ค่ะ|คะ|จ้า|จ้ะ)\s*$/u', '', $response) ?? $response;

        return trim((string) preg_replace('/^'.$edge.'+|'.$edge.'+$/u', '', $response));
    }

    private function validChallenge(CheckoutSession $checkout, Message $challenge): bool
    {
        $message = Message::query()
            ->whereKey($challenge->getKey())
            ->where('conversation_id', $checkout->conversation_id)
            ->where('sender', 'bot')
            ->first();

        return $message !== null;
    }
}
