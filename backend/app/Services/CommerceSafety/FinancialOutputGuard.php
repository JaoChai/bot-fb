<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\PaymentFlexService;

final class FinancialOutputGuard
{
    public const DENIAL = 'รบกวนรอผลตรวจสอบการชำระเงินจากระบบหรือทีมงานครับ';

    public function enforced(?Bot $bot): bool
    {
        return $bot !== null && in_array(app(SafetyScope::class)->mode($bot), ['enforce', 'hold'], true);
    }

    public function text(Bot $bot, string $text): string
    {
        return $this->enforced($bot) && app(FinancialOutputDetector::class)->detects($bot, $text)
            ? self::DENIAL : $text;
    }

    /** Sanitize the complete model result before proposal parsing or persistence. */
    public function generated(Bot $bot, array $result): array
    {
        if (! $this->enforced($bot)) {
            return $result;
        }
        $hasUntrustedPayload = ! empty($result['order_payload'])
            && ! (($result['commerce_safety_cart_validation'] ?? null) instanceof CartValidation);
        if ($hasUntrustedPayload || $this->text($bot, (string) ($result['content'] ?? '')) !== ($result['content'] ?? '')) {
            $result['content'] = self::DENIAL;
            $result['order_payload'] = null;
            unset($result['commerce_safety_cart_validation'], $result['checkout_presentation']);
            $result['financial_output_denied'] = true;
        }

        $result['order_payload'] = null;

        return $result;
    }

    /** Resolve server checkout authority from its row, never a metadata boolean. */
    public function checkoutText(Bot $bot, Conversation $conversation, Message $message): ?string
    {
        if (! $message->exists || app(SafetyScope::class)->mode($bot) !== 'enforce') {
            return null;
        }
        $checkout = CheckoutSession::query()->where('bot_id', $bot->id)
            ->where('conversation_id', $conversation->id)
            ->where('challenge_message_id', $message->id)->first();
        $action = match ($checkout?->state) {
            'draft' => 'ack', 'awaiting_confirm' => 'confirm',
            'awaiting_support' => 'support_delay', 'awaiting_terms' => 'terms',
            'payable' => 'payment', default => null,
        };
        if ($action === null || $checkout->challenge_action !== $action) {
            return null;
        }
        $fresh = Message::query()->find($message->id);
        $text = app(CheckoutRenderer::class)->render($checkout, $action);

        return $fresh?->sender === 'bot' && (int) $fresh->conversation_id === (int) $conversation->id
            && $fresh->content === $text ? $text : null;
    }

    /** Defense at consumers also covers alternate callers that bypass generation. */
    public function message(Bot $bot, Conversation $conversation, Message $message): void
    {
        if (! $this->enforced($bot)) {
            return;
        }
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $event = app(PaymentProofService::class)->forReceipt($bot, $conversation, $message);
        if ($event !== null) {
            try {
                $text = app(PaymentFlexService::class)->fromVerifiedPayment($event)['altText'];
            } catch (\Throwable) {
                $text = self::DENIAL;
            }
        } elseif (($canonical = $this->checkoutText($bot, $conversation, $message)) !== null) {
            // The persisted challenge authorizes only its server-rendered wording.
            $message->content = $canonical;
            $message->metadata = $message->fresh()->metadata;

            return;
        } else {
            $text = $this->text($bot, (string) $message->content);
            if (! empty($metadata['order_payload']) || ! empty($metadata['checkout_presentation']) || str_contains($text, '[[ORDER]]')) {
                $text = self::DENIAL;
            }
        }
        unset($metadata['order_payload'], $metadata['checkout_presentation']);
        if ($text !== $message->content || $metadata !== ($message->metadata ?? [])) {
            $message->forceFill(['content' => $text, 'metadata' => $metadata ?: null]);
            // Never write a supplied cross-conversation receipt through this gate.
            if ($message->exists && (int) $message->conversation_id === (int) $conversation->id
                && (int) $conversation->bot_id === (int) $bot->id) {
                $message->save();
            }
        }
    }
}
