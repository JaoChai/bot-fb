<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Guardrail\GuardrailOutputSanitizer;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use Illuminate\Support\Facades\Log;

/** Caller-side logging and cleanup. Invoke after the financial guard, before persistence/splitting. */
final class CustomerReplyGuard
{
    public function __construct(
        private readonly CustomerReplyPolicy $policy,
        private readonly GuardrailOutputSanitizer $sanitizer,
    ) {}

    public function generated(Bot $bot, array $result, ?Conversation $conversation = null, bool $legacySanitizer = false): array
    {
        $content = (string) ($result['content'] ?? '');
        // A2 denial always wins. Neither C1 nor model metadata can grant financial authority.
        if ($content === FinancialOutputGuard::DENIAL) {
            return $result;
        }
        $mode = $this->policy->mode($bot);
        if (! $legacySanitizer && $mode === 'off') {
            return $result;
        }
        $sanitized = $this->sanitizer->check($content, $this->policy->allowTruthfulAiIdentity($bot));
        if ($sanitized['flagged']) {
            Log::warning('Guardrail output sanitizer triggered', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'reason' => $sanitized['reason'],
            ]);
            if ($legacySanitizer || $this->policy->enforced($bot)) {
                return $this->reject($result, OffTopicCircuitBreaker::CANNED_MESSAGE);
            }
        }
        $decision = $this->policy->apply($bot, $content);
        foreach ($decision['reasons'] as $reason) {
            Log::warning('Customer reply policy triggered', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'reason' => $reason,
            ]);
        }

        return $decision['corrected'] ? $this->reject($result, $decision['content']) : $result;
    }

    public function text(Bot $bot, string $content, ?Conversation $conversation = null): string
    {
        return $this->generated($bot, ['content' => $content], $conversation)['content'];
    }

    public function message(Bot $bot, Conversation $conversation, Message $message): void
    {
        $result = $this->generated($bot, ['content' => $message->content], $conversation);
        if ($result['content'] === $message->content) {
            return;
        }
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        unset($metadata['order_payload'], $metadata['commerce_safety_cart_validation'], $metadata['checkout_presentation']);
        $message->forceFill(['content' => $result['content'], 'metadata' => $metadata ?: null]);
        if ($message->exists && (int) $message->conversation_id === (int) $conversation->id
            && (int) $conversation->bot_id === (int) $bot->id) {
            $message->save();
        }
    }

    private function reject(array $result, string $content): array
    {
        $result['content'] = $content;
        $result['order_payload'] = null;
        // Every rejection invalidates the proposal, including legacy sanitizer paths.
        unset($result['cart_validation'], $result['commerce_safety_cart_validation'], $result['checkout_presentation']);

        return $result;
    }
}
