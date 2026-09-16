<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\ConversationAuthorityLock;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\CommerceSafety\MoneyMinor;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\Payment\FlexMessageBuilder;
use App\Services\Payment\PaymentMessageDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentFlexService
{
    public function __construct(
        private PaymentMessageDetector $detector,
        private FlexMessageBuilder $builder,
        private VipPricingService $vipPricingService,
    ) {}

    private const MAX_FLEX_SIZE = 30000;

    /**
     * Try to convert payment text to LINE Flex Message.
     * Returns Flex array if payment detected, or original text as fallback.
     *
     * @return string|array Original text or Flex message array
     */
    public function tryConvertToFlex(string $text, ?Conversation $conversation = null, ?Message $receipt = null): string|array
    {
        $guard = app(FinancialOutputGuard::class);
        if ($conversation !== null && $guard->enforced($conversation->bot)) {
            if ($receipt === null) {
                return $guard->text($conversation->bot, $text);
            }
            $guard->message($conversation->bot, $conversation, $receipt);
            $event = app(PaymentProofService::class)->forReceipt($conversation->bot, $conversation, $receipt);
            if ($event !== null) {
                try {
                    return $this->fromVerifiedPayment($event);
                } catch (\Throwable) {
                    return FinancialOutputGuard::DENIAL;
                }
            }

            $checkoutText = $guard->checkoutText($conversation->bot, $conversation, $receipt);
            if ($checkoutText !== null) {
                return ['type' => 'text', 'text' => trim(preg_replace('/\[\[ORDER\]\].*?\[\[\/ORDER\]\]/s', '', $checkoutText))];
            }

            return $guard->text($conversation->bot, (string) $receipt->content);
        }

        // Strip markdown bold before regex parsing (LINE doesn't render markdown)
        $text = str_replace('**', '', $text);

        try {
            $isVip = $this->isVipConversation($conversation);

            // Step 4: Payment message (existing)
            if ($this->isPaymentMessage($text)) {
                $data = $this->parsePaymentData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit($data, $isVip);

                    return $this->safeBuildFlex($text, $this->buildFlexMessage($data, $isVip));
                }
            }

            // Step 2.5: Support delay warning
            if ($this->isSupportDelayMessage($text)) {
                return $this->safeBuildFlex($text, $this->buildSupportDelayFlexMessage($isVip));
            }

            // Step 3: Terms message (always uses fixed TERMS_URL)
            if ($this->isTermsMessage($text)) {
                return $this->safeBuildFlex($text, $this->buildTermsFlexMessage());
            }

            // Step 5: Verify success message
            if ($this->isVerifySuccessMessage($text)) {
                $data = $this->parseVerifyData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit(
                        $data,
                        $isVip,
                        requireCanonicalItemPrices: false
                    );

                    return $this->safeBuildFlex($text, $this->buildVerifyFlexMessage($data, $isVip));
                }
            }

            // Step 2: Confirm message (least specific — check last)
            if ($this->isConfirmMessage($text)) {
                $data = $this->parseConfirmData($text);
                if ($data !== null) {
                    $data = $this->vipPricingService->addFlexBenefit($data, $isVip);

                    return $this->safeBuildFlex($text, $this->buildConfirmFlexMessage($data, $isVip));
                }
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('PaymentFlexService: fallback to text', [
                'error' => $e->getMessage(),
            ]);

            return $text;
        }
    }

    /** The only trusted payment presentation entry point. No parsed prose or effects. */
    public function fromVerifiedPayment(VerifiedPaymentEvent $event): array
    {
        $candidate = VerifiedPaymentEvent::query()->find($event->getKey());
        if ($candidate === null) {
            throw new \InvalidArgumentException('A persisted payment event is required.');
        }

        return DB::transaction(function () use ($candidate): array {
            Bot::query()->lockForUpdate()->findOrFail($candidate->bot_id);
            ConversationAuthorityLock::acquire((int) $candidate->bot_id, (int) $candidate->conversation_id);
            $event = VerifiedPaymentEvent::query()->with([
                'bot', 'conversation', 'slipVerification', 'receiptMessage', 'checkout', 'order', 'actor',
            ])->find($candidate->getKey());
            $slip = $event?->slipVerification;
            $receipt = $event?->receiptMessage;
            if (! $event || ! $slip || ! $receipt || ! $event->bot || ! $event->conversation
                || (int) $event->conversation->bot_id !== (int) $event->bot_id
                || (int) $slip->bot_id !== (int) $event->bot_id
                || (int) $slip->conversation_id !== (int) $event->conversation_id
                || (int) $receipt->conversation_id !== (int) $event->conversation_id
                || $receipt->sender !== 'bot' || $event->currency !== 'THB'
                || MoneyMinor::fromDecimal((string) $slip->getRawOriginal('amount')) !== $event->amount_minor) {
                throw new \InvalidArgumentException('Payment proof is missing or inconsistent.');
            }
            $valid = match ($event->source) {
                'easyslip' => $slip->status === 'passed' && trim((string) $slip->trans_ref) !== ''
                    && $event->event_key === 'easyslip:'.trim((string) $slip->trans_ref),
                'manual' => $slip->status === 'manual_confirmed'
                    && $event->event_key === 'manual-slip:'.$slip->id
                    && (int) $slip->message_id === (int) $receipt->id
                    && $event->actor?->isOwner()
                    && (int) $event->actor_id === (int) $event->bot->user_id,
                default => false,
            };
            if (! $valid) {
                throw new \InvalidArgumentException('Payment provenance is no longer valid.');
            }
            $checkout = $event->checkout;
            if ($event->checkout_id !== null && (! $checkout
                || (int) $checkout->bot_id !== (int) $event->bot_id
                || (int) $checkout->conversation_id !== (int) $event->conversation_id)) {
                throw new \InvalidArgumentException('Payment checkout scope is inconsistent.');
            }
            $settled = app(SafetyScope::class)->mode($event->bot) !== 'hold'
                && $event->disposition === 'settled' && $checkout?->state === 'paid'
                && $checkout->settled_event_id === $event->id && $checkout->currency === 'THB'
                && $checkout->total_minor === $event->amount_minor
                && app(OrderService::class)->lockedOrderForCheckout($checkout, $event) !== null;
            $amount = number_format(intdiv($event->amount_minor, 100))
                .($event->amount_minor % 100 ? '.'.str_pad((string) ($event->amount_minor % 100), 2, '0', STR_PAD_LEFT) : '');
            $text = 'เงินเข้าแล้ว '.$amount.' บาทครับ';
            $items = array_map(fn (array $item): string => $item['name'].' x'.$item['qty'], $checkout?->items ?? []);
            if ($items !== []) {
                $text .= "\nรายการ: ".implode(', ', $items);
            }
            $dispositionText = $settled ? "\nส่งใน 5-10 นาที ขอบคุณครับ" : "\nรับเงินไว้แล้ว อยู่ระหว่างให้ทีมงานตรวจสอบรายการครับ";

            $text .= $dispositionText;

            return [
                'type' => 'flex', 'altText' => 'เงินเข้าแล้ว '.$amount.' บาทครับ'.$dispositionText,
                'contents' => ['type' => 'bubble', 'body' => [
                    'type' => 'box', 'layout' => 'vertical', 'contents' => [
                        ['type' => 'text', 'text' => $settled ? 'ยืนยันรับเงินแล้ว' : 'รับเงินแล้ว รอทีมงานตรวจสอบ', 'weight' => 'bold', 'wrap' => true],
                        ['type' => 'text', 'text' => $text, 'wrap' => true, 'margin' => 'md'],
                    ],
                ]],
            ];
        });
    }

    /**
     * Check the canonical auto/manual VIP note, with legacy-note compatibility.
     */
    public function isVipConversation(?Conversation $conversation): bool
    {
        return $this->vipPricingService->isVipConversation($conversation);
    }

    /**
     * Safely encode Flex to JSON and check size limit.
     * Returns Flex array if OK, or original text as fallback.
     */
    private function safeBuildFlex(string $fallbackText, array $flex): string|array
    {
        $encoded = json_encode($flex);
        if ($encoded === false) {
            return $fallbackText;
        }

        $jsonSize = strlen($encoded);
        if ($jsonSize > self::MAX_FLEX_SIZE) {
            Log::warning('Flex message exceeds size limit', [
                'size' => $jsonSize,
                'limit' => self::MAX_FLEX_SIZE,
            ]);

            return $fallbackText;
        }

        return $flex;
    }

    /**
     * Detect if text is a payment message.
     * Must contain both the bank account number AND a total keyword.
     */
    public function isPaymentMessage(string $text): bool
    {
        return $this->detector->isPaymentMessage($text);
    }

    /**
     * Parse payment data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parsePaymentData(string $text): ?array
    {
        return $this->detector->parsePaymentData($text);
    }

    /**
     * Build LINE Flex Message array from parsed payment data.
     */
    public function buildFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildFlexMessage($data, $isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 2.5: Support Delay Warning
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a support delay warning message.
     * Primary: "[แจ้งเตือน Support]" tag from prompt template.
     * Fallback: content-based detection when LLM omits the tag.
     */
    public function isSupportDelayMessage(string $text): bool
    {
        return $this->detector->isSupportDelayMessage($text);
    }

    /**
     * Build LINE Flex Message for support delay warning.
     * VIP gets gold styling with priority note; normal gets orange with secondary button.
     */
    public function buildSupportDelayFlexMessage(bool $isVip = false): array
    {
        return $this->builder->buildSupportDelayFlexMessage($isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 2: Confirm Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a confirm message (Step 2).
     * Must contain "รวม...บาท" + "ยืนยัน", but NOT bank account, verify tag, or terms keywords.
     */
    public function isConfirmMessage(string $text): bool
    {
        return $this->detector->isConfirmMessage($text);
    }

    /**
     * Parse confirm data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        return $this->detector->parseConfirmData($text);
    }

    /**
     * Build LINE Flex Message for order confirmation (Step 2).
     */
    public function buildConfirmFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildConfirmFlexMessage($data, $isVip);
    }

    // ────────────────────────────────────────────────────────
    // Step 3: Terms Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a terms/agreement message (Step 3).
     * Must contain "ยอมรับ" + ("ข้อตกลง" or "เงื่อนไข" or TERMS_URL).
     * Excludes payment, verify messages.
     */
    public function isTermsMessage(string $text): bool
    {
        return $this->detector->isTermsMessage($text);
    }

    /**
     * Build LINE Flex Message for terms/agreement (Step 3).
     * Always uses the fixed TERMS_URL constant.
     */
    public function buildTermsFlexMessage(): array
    {
        return $this->builder->buildTermsFlexMessage();
    }

    // ────────────────────────────────────────────────────────
    // Step 5: Verify Success Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a verify-success message (Step 5).
     * "เงินเข้าแล้ว X บาท" pattern is sufficient — tag is optional
     * because buildVerifyFlexMessage() hardcodes the tag in altText.
     */
    public function isVerifySuccessMessage(string $text): bool
    {
        return $this->detector->isVerifySuccessMessage($text);
    }

    /**
     * Parse verify-success data from text.
     * Returns null if amount cannot be parsed.
     */
    public function parseVerifyData(string $text): ?array
    {
        return $this->detector->parseVerifyData($text);
    }

    /**
     * Build LINE Flex Message for verify-success (Step 5).
     */
    public function buildVerifyFlexMessage(array $data, bool $isVip = false): array
    {
        return $this->builder->buildVerifyFlexMessage($data, $isVip);
    }
}
