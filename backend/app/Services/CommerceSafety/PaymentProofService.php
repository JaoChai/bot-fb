<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaymentProofService
{
    public function record(
        Bot $bot,
        Conversation $conversation,
        SlipVerification $slip,
        Message $receipt,
        ?int $actorId,
    ): VerifiedPaymentEvent {
        $bot = $this->reload(Bot::class, $bot->getKey(), 'bot');
        $conversation = $this->reload(Conversation::class, $conversation->getKey(), 'conversation');
        $slip = $this->reload(SlipVerification::class, $slip->getKey(), 'slip');
        $receipt = $this->reload(Message::class, $receipt->getKey(), 'receipt');

        if ((int) $conversation->bot_id !== (int) $bot->id
            || (int) $slip->bot_id !== (int) $bot->id
            || (int) $slip->conversation_id !== (int) $conversation->id
            || (int) $receipt->conversation_id !== (int) $conversation->id) {
            $this->invalid('proof', 'Payment proof rows must belong to the same bot and conversation.');
        }

        if ($receipt->sender !== 'bot') {
            $this->invalid('receipt', 'Payment proof receipts must be persisted bot messages.');
        }

        [$source, $eventKey, $storedActorId] = $this->identity($bot, $slip, $receipt, $actorId);

        try {
            $amountMinor = MoneyMinor::fromDecimal((string) $slip->getRawOriginal('amount'));
        } catch (InvalidArgumentException) {
            $this->invalid('amount', 'The verified payment amount is invalid.');
        }

        $attributes = [
            'bot_id' => (int) $bot->id,
            'conversation_id' => (int) $conversation->id,
            'slip_verification_id' => (int) $slip->id,
            'receipt_message_id' => (int) $receipt->id,
            'order_id' => null,
            'source' => $source,
            'event_key' => $eventKey,
            'currency' => 'THB',
            'amount_minor' => $amountMinor,
            'actor_id' => $storedActorId,
        ];

        $existing = $this->findByEventKey($attributes['bot_id'], $eventKey);
        if ($existing !== null) {
            return $this->matchingEventOrFail($existing, $attributes);
        }

        try {
            return DB::transaction(function () use ($attributes): VerifiedPaymentEvent {
                $event = new VerifiedPaymentEvent;

                foreach ($attributes as $attribute => $value) {
                    $event->{$attribute} = $value;
                }

                $event->save();

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent insert can win either unique constraint. The failed
            // transaction has rolled back here, so PostgreSQL permits this lookup.
            $existing = $this->findByEventKey($attributes['bot_id'], $eventKey);

            if ($existing !== null) {
                return $this->matchingEventOrFail($existing, $attributes);
            }

            $this->invalid('proof', 'The receipt is already bound to another payment proof.');
        }
    }

    public function forReceipt(
        Bot $bot,
        Conversation $conversation,
        Message $receipt,
    ): ?VerifiedPaymentEvent {
        if ($bot->getKey() === null || $conversation->getKey() === null || $receipt->getKey() === null) {
            return null;
        }

        return VerifiedPaymentEvent::query()
            ->where('bot_id', $bot->getKey())
            ->where('conversation_id', $conversation->getKey())
            ->where('receipt_message_id', $receipt->getKey())
            ->first();
    }

    /**
     * @return array{0: string, 1: string, 2: ?int}
     */
    private function identity(
        Bot $bot,
        SlipVerification $slip,
        Message $receipt,
        ?int $actorId,
    ): array {
        if ($slip->status === 'passed') {
            $transRef = trim((string) $slip->trans_ref);

            if ($transRef === '') {
                $this->invalid('slip', 'An automatic payment proof requires a transaction reference.');
            }

            return ['easyslip', "easyslip:{$transRef}", null];
        }

        if ($slip->status !== 'manual_confirmed') {
            $this->invalid('slip', 'Only passed or manually confirmed slips can create payment proof.');
        }

        $actor = $actorId === null ? null : User::query()->find($actorId);

        if ($actor === null || ! $actor->isOwner() || (int) $actor->id !== (int) $bot->user_id) {
            $this->invalid('actor', 'Manual payment proof requires the authorized bot owner.');
        }

        if ((int) $slip->message_id !== (int) $receipt->id) {
            $this->invalid('receipt', 'The manual confirmation receipt must match the slip message.');
        }

        return ['manual', "manual-slip:{$slip->id}", (int) $actor->id];
    }

    private function findByEventKey(int $botId, string $eventKey): ?VerifiedPaymentEvent
    {
        return VerifiedPaymentEvent::query()
            ->where('bot_id', $botId)
            ->where('event_key', $eventKey)
            ->first();
    }

    /**
     * @param  array<string, int|string|null>  $attributes
     */
    private function matchingEventOrFail(
        VerifiedPaymentEvent $event,
        array $attributes,
    ): VerifiedPaymentEvent {
        foreach ($attributes as $attribute => $value) {
            if ((string) $event->getAttribute($attribute) !== (string) $value) {
                $this->invalid('proof', 'The payment event key is already bound to different proof rows.');
            }
        }

        return $event;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function reload(string $model, mixed $key, string $field): mixed
    {
        if ($key === null || ($fresh = $model::query()->find($key)) === null) {
            $this->invalid($field, "The persisted {$field} row is required.");
        }

        return $fresh;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
