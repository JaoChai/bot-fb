<?php

namespace App\Services;

use App\Models\Conversation;
use App\Services\Payment\FlexMessageBuilder;
use App\Services\Payment\PaymentMessageDetector;
use Illuminate\Support\Facades\Log;

class PaymentFlexService
{
    public function __construct(
        private PaymentMessageDetector $detector,
        private FlexMessageBuilder $builder,
    ) {}

    private const MAX_FLEX_SIZE = 30000;

    /**
     * Try to convert payment text to LINE Flex Message.
     * Returns Flex array if payment detected, or original text as fallback.
     *
     * @return string|array Original text or Flex message array
     */
    public function tryConvertToFlex(string $text, ?Conversation $conversation = null): string|array
    {
        // Strip markdown bold before regex parsing (LINE doesn't render markdown)
        $text = str_replace('**', '', $text);

        try {
            $isVip = $this->isVipConversation($conversation);

            // Step 4: Payment message (existing)
            if ($this->isPaymentMessage($text)) {
                $data = $this->parsePaymentData($text);
                if ($data !== null) {
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
                    return $this->safeBuildFlex($text, $this->buildVerifyFlexMessage($data, $isVip));
                }
            }

            // Step 2: Confirm message (least specific — check last)
            if ($this->isConfirmMessage($text)) {
                $data = $this->parseConfirmData($text);
                if ($data !== null) {
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

    /**
     * Check if conversation belongs to a VIP customer.
     * Looks for "VIP" keyword in memory_notes.
     */
    public function isVipConversation(?Conversation $conversation): bool
    {
        if ($conversation === null) {
            return false;
        }

        $memoryNotes = $conversation->memory_notes;
        if (empty($memoryNotes)) {
            return false;
        }

        // memory_notes is cast as array - each entry can be:
        // 1. A string: "ลูกค้า VIP ..."
        // 2. An object with 'content' key: {"type":"memory","content":"ลูกค้า VIP ..."}
        foreach ($memoryNotes as $note) {
            $text = is_string($note) ? $note : ($note['content'] ?? null);
            if (is_string($text) && preg_match('/\bVIP\b/iu', $text)) {
                return true;
            }
        }

        return false;
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
