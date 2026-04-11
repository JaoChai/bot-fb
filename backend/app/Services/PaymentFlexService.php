<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class PaymentFlexService
{
    private const BANK_ACCOUNT = '223-3-24880-3';

    private const BANK_NAME = 'ธนาคารกสิกรไทย (KBANK)';

    private const ACCOUNT_NAME = 'หจก. มั่งมีทรัพย์ขายของออนไลน์';

    private const CLIPBOARD_TEXT = '2233248803';

    private const MAX_FLEX_SIZE = 30000;

    private const TERMS_URL = 'https://mhhacoursecontent.my.canva.site/ads-vance';

    private const SUPPORT_LINE_ID = '@743ddeqy';

    private const NORMAL_PRIMARY_COLOR = '#1DB446';

    private const VIP_PRIMARY_COLOR = '#D4A017';

    private const CONFIRM_PRIMARY_COLOR = '#FF6B00';

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
        if (mb_strpos($text, self::BANK_ACCOUNT) === false) {
            return false;
        }

        return (bool) preg_match('/รวมยอดโอน|สรุปยอด|ยอดโอน|ยอดรวม|รวมเป็นเงิน|สรุปรายการ/u', $text);
    }

    /**
     * Parse payment data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parsePaymentData(string $text): ?array
    {
        // Parse total (required)
        if (! preg_match('/(?:รวมยอดโอน|สรุปยอด(?:โอน)?|ยอดโอน|ยอดรวม|รวมเป็นเงิน)\s*:?\s*([\d,]+)\s*บาท/u', $text, $totalMatch)) {
            return null;
        }

        $total = $totalMatch[1];

        // Parse items (optional)
        $items = [];
        preg_match_all(
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|[-•]\s*)(.+?)\s*(?:\(([\d,]+)\s*[x×]\s*(\d+)\)\s*=\s*)?([\d,]+)\s*บาท/u',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );

        foreach ($itemMatches as $match) {
            $item = [
                'name' => trim($match[1]),
                'total' => $match[4],
            ];

            if (! empty($match[2]) && ! empty($match[3])) {
                $item['price'] = $match[2];
                $item['qty'] = (int) $match[3];
            }

            $items[] = $item;
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Build LINE Flex Message array from parsed payment data.
     */
    public function buildFlexMessage(array $data, bool $isVip = false): array
    {
        $total = $data['total'];
        $items = $data['items'];
        $primaryColor = $isVip ? self::VIP_PRIMARY_COLOR : self::NORMAL_PRIMARY_COLOR;
        $headerText = $isVip ? '👑 VIP สรุปรายการสั่งซื้อ' : 'สรุปรายการสั่งซื้อ';

        $bodyContents = [];

        // Items section
        if (! empty($items)) {
            foreach ($items as $item) {
                $bodyContents[] = $this->buildItemRow($item);
            }

            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg',
            ];
        }

        // Total row
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'รวมยอดโอน',
                    'size' => 'md',
                    'color' => '#555555',
                    'flex' => 0,
                ],
                [
                    'type' => 'text',
                    'text' => "{$total} บาท ✅",
                    'size' => 'lg',
                    'color' => $primaryColor,
                    'weight' => 'bold',
                    'align' => 'end',
                ],
            ],
        ];

        // Separator before bank info
        $bodyContents[] = [
            'type' => 'separator',
            'margin' => 'lg',
        ];

        // Bank info section
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'โอนเข้าบัญชี',
                    'size' => 'xs',
                    'color' => '#AAAAAA',
                ],
                [
                    'type' => 'text',
                    'text' => self::BANK_NAME,
                    'size' => 'sm',
                    'color' => '#555555',
                ],
                [
                    'type' => 'text',
                    'text' => self::BANK_ACCOUNT,
                    'size' => 'xxl',
                    'color' => $primaryColor,
                    'weight' => 'bold',
                ],
                [
                    'type' => 'text',
                    'text' => self::ACCOUNT_NAME,
                    'size' => 'xs',
                    'color' => '#888888',
                ],
            ],
        ];

        // Warning & slip instruction box
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'backgroundColor' => '#FFF9E6',
            'cornerRadius' => 'md',
            'paddingAll' => 'md',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '⚠️ ไม่รับโอนเงินจากทรูมันนี่',
                    'size' => 'xs',
                    'color' => '#996600',
                    'wrap' => true,
                ],
                [
                    'type' => 'separator',
                    'margin' => 'sm',
                ],
                [
                    'type' => 'text',
                    'text' => '📸 เมื่อโอนเงินเสร็จแล้ว กรุณาส่งเป็นรูปสลิปจากแอปธนาคารเท่านั้น',
                    'size' => 'xs',
                    'color' => '#555555',
                    'wrap' => true,
                ],
                [
                    'type' => 'text',
                    'text' => '❌ ไม่รับรูปถ่ายจากกล้องมือถือ',
                    'size' => 'xs',
                    'color' => '#CC0000',
                    'weight' => 'bold',
                    'wrap' => true,
                ],
            ],
        ];

        return [
            'type' => 'flex',
            'altText' => "{$headerText} - ยอดโอน {$total} บาท",
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => $primaryColor,
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $headerText,
                            'color' => '#FFFFFF',
                            'size' => 'lg',
                            'weight' => 'bold',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'clipboard',
                                'label' => '📋 คัดลอกเลขบัญชี',
                                'clipboardText' => self::CLIPBOARD_TEXT,
                            ],
                            'style' => 'primary',
                            'color' => $primaryColor,
                        ],
                        [
                            'type' => 'text',
                            'text' => 'โอนแล้วรบกวนส่งสลิปมาเลยครับ 🙏',
                            'size' => 'xs',
                            'color' => '#AAAAAA',
                            'align' => 'center',
                            'margin' => 'md',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a single item row for the Flex body.
     */
    protected function buildItemRow(array $item): array
    {
        $nameText = $item['name'];
        if (isset($item['qty']) && $item['qty'] > 1) {
            $nameText .= " ×{$item['qty']}";
        }

        return [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $nameText,
                    'size' => 'sm',
                    'color' => '#555555',
                    'flex' => 4,
                    'wrap' => true,
                ],
                [
                    'type' => 'text',
                    'text' => "{$item['total']} บาท",
                    'size' => 'sm',
                    'color' => '#333333',
                    'align' => 'end',
                    'flex' => 2,
                ],
            ],
        ];
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
        // Primary: tag from prompt template
        if (mb_strpos($text, '[แจ้งเตือน Support]') !== false) {
            return true;
        }

        // Fallback: content-based — must mention support delay + ask for "ตกลง"
        $hasSupportDelay = (bool) preg_match('/(?:ซัพพอร์ต|Support)[\s\S]*?(?:นานกว่าปกติ|ล่าช้า|รอคิว)/iu', $text);
        $hasAcceptKeyword = mb_strpos($text, 'ตกลง') !== false;

        return $hasSupportDelay && $hasAcceptKeyword;
    }

    /**
     * Build LINE Flex Message for support delay warning.
     * VIP gets gold styling with priority note; normal gets orange with secondary button.
     */
    public function buildSupportDelayFlexMessage(bool $isVip = false): array
    {
        $primaryColor = $isVip ? self::VIP_PRIMARY_COLOR : self::CONFIRM_PRIMARY_COLOR;
        $headerText = $isVip ? '👑 VIP แจ้งระยะเวลา Support' : '⏰ แจ้งระยะเวลา Support';

        $bodyContents = [];

        // VIP greeting
        if ($isVip) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => 'ขอบคุณที่อุดหนุนเสมอครับพี่ 🙏',
                'size' => 'sm',
                'color' => self::VIP_PRIMARY_COLOR,
                'weight' => 'bold',
            ];
        }

        // Warning message
        $warningText = [
            'type' => 'text',
            'text' => 'ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ หากบัญชีมีปัญหาต้องรอคิวนิดนึง',
            'size' => 'sm',
            'color' => '#555555',
            'wrap' => true,
        ];
        if ($isVip) {
            $warningText['margin'] = 'lg';
        }
        $bodyContents[] = $warningText;

        $bodyContents[] = [
            'type' => 'separator',
            'margin' => 'lg',
        ];

        // Condition text
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม',
            'size' => 'sm',
            'color' => '#555555',
            'wrap' => true,
            'margin' => 'lg',
        ];

        // Info box
        $infoText = $isVip
            ? 'ลูกค้า VIP จะได้รับการดูแลเป็นลำดับต้นๆ ครับ'
            : 'กดปุ่มด้านล่างเพื่อตอบรับเงื่อนไข';
        $infoColor = $isVip ? self::VIP_PRIMARY_COLOR : self::CONFIRM_PRIMARY_COLOR;
        $infoBg = $isVip ? '#FFF8E1' : '#FFF3E0';

        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'backgroundColor' => $infoBg,
            'cornerRadius' => 'md',
            'paddingAll' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => "ℹ️ {$infoText}",
                    'size' => 'sm',
                    'color' => $infoColor,
                    'wrap' => true,
                ],
            ],
        ];

        return [
            'type' => 'flex',
            'altText' => $headerText,
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => $primaryColor,
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $headerText,
                            'color' => '#FFFFFF',
                            'size' => 'lg',
                            'weight' => 'bold',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'message',
                                'label' => '✅ ตกลง',
                                'text' => 'ตกลง',
                            ],
                            'style' => 'primary',
                            'color' => $primaryColor,
                        ],
                    ],
                ],
            ],
        ];
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
        // Exclude Step 4 (payment - has bank account)
        if (mb_strpos($text, self::BANK_ACCOUNT) !== false) {
            return false;
        }
        // Exclude Step 5 (verify - has เงินเข้าแล้ว)
        if (mb_strpos($text, 'เงินเข้าแล้ว') !== false) {
            return false;
        }
        // Exclude Step 5 (verify - has tag)
        if (mb_strpos($text, '[ยืนยันชำระเงิน]') !== false) {
            return false;
        }
        // Exclude Step 3 (terms)
        if (mb_strpos($text, 'ข้อตกลง') !== false) {
            return false;
        }
        // Exclude Step 3 (terms fallback keyword)
        if (mb_strpos($text, 'เงื่อนไข') !== false) {
            return false;
        }

        // Must have total pattern: รวม...บาท (รองรับ รวม, รวมทั้งหมด, รวมยอด, รวมเป็นเงิน)
        if (! preg_match('/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*[\d,]+\s*บาท/u', $text)) {
            return false;
        }

        // Must have "ยืนยัน"
        return mb_strpos($text, 'ยืนยัน') !== false;
    }

    /**
     * Parse confirm data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parseConfirmData(string $text): ?array
    {
        // Parse total (required) — รองรับ รวม, รวมทั้งหมด, รวมยอด, รวมเป็นเงิน
        if (! preg_match('/รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?\s*:?\s*([\d,]+)\s*บาท/u', $text, $totalMatch)) {
            return null;
        }

        $total = $totalMatch[1];

        // Parse items (optional) — reuse same regex as parsePaymentData
        $items = [];
        preg_match_all(
            '/(?:^|\n)\s*(?:\d+[\.\)]\s*|[-•]\s*)(.+?)\s*(?:\(([\d,]+)\s*[x×]\s*(\d+)\)\s*=\s*)?([\d,]+)\s*บาท/u',
            $text,
            $itemMatches,
            PREG_SET_ORDER
        );

        foreach ($itemMatches as $match) {
            $item = [
                'name' => trim($match[1]),
                'total' => $match[4],
            ];

            if (! empty($match[2]) && ! empty($match[3])) {
                $item['price'] = $match[2];
                $item['qty'] = (int) $match[3];
            }

            $items[] = $item;
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Build LINE Flex Message for order confirmation (Step 2).
     */
    public function buildConfirmFlexMessage(array $data, bool $isVip = false): array
    {
        $total = $data['total'];
        $items = $data['items'];
        $primaryColor = $isVip ? self::VIP_PRIMARY_COLOR : self::CONFIRM_PRIMARY_COLOR;
        $headerText = $isVip ? '👑 VIP ยืนยันรายการสั่งซื้อ' : '📋 ยืนยันรายการสั่งซื้อ';

        $bodyContents = [];

        // Subheader text
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'กรุณาตรวจสอบความถูกต้อง',
            'size' => 'sm',
            'color' => '#888888',
        ];

        // Items section
        if (! empty($items)) {
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg',
            ];

            foreach ($items as $item) {
                $bodyContents[] = $this->buildItemRow($item);
            }

            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg',
            ];
        }

        // Total row
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'รวม',
                    'size' => 'md',
                    'color' => '#555555',
                    'flex' => 0,
                ],
                [
                    'type' => 'text',
                    'text' => "{$total} บาท",
                    'size' => 'lg',
                    'color' => $primaryColor,
                    'weight' => 'bold',
                    'align' => 'end',
                ],
            ],
        ];

        return [
            'type' => 'flex',
            'altText' => "{$headerText} - รวม {$total} บาท",
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => $primaryColor,
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $headerText,
                            'color' => '#FFFFFF',
                            'size' => 'lg',
                            'weight' => 'bold',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '👇 กดปุ่มด้านล่างเพื่อยืนยันรายการ',
                            'size' => 'sm',
                            'color' => '#555555',
                            'align' => 'center',
                        ],
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'message',
                                'label' => '✅ ยืนยันรายการ',
                                'text' => 'ยืนยัน',
                            ],
                            'style' => 'primary',
                            'color' => $primaryColor,
                        ],
                        [
                            'type' => 'text',
                            'text' => "หรือพิมพ์ 'ยืนยัน' ได้เลยครับ 🙏",
                            'size' => 'xs',
                            'color' => '#AAAAAA',
                            'align' => 'center',
                            'margin' => 'md',
                        ],
                    ],
                ],
            ],
        ];
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
        // Exclude Step 4 (payment) and Step 5 (verify)
        if (mb_strpos($text, self::BANK_ACCOUNT) !== false) {
            return false;
        }
        if (mb_strpos($text, 'เงินเข้าแล้ว') !== false) {
            return false;
        }
        if (mb_strpos($text, '[ยืนยันชำระเงิน]') !== false) {
            return false;
        }

        // Must have "ยอมรับ" keyword
        if (mb_strpos($text, 'ยอมรับ') === false) {
            return false;
        }

        // Primary: "ข้อตกลง"
        if (mb_strpos($text, 'ข้อตกลง') !== false) {
            return true;
        }

        // Fallback: TERMS_URL present (very reliable signal)
        return mb_strpos($text, 'canva.site/ads-vance') !== false;
    }

    /**
     * Build LINE Flex Message for terms/agreement (Step 3).
     * Always uses the fixed TERMS_URL constant.
     */
    public function buildTermsFlexMessage(): array
    {
        $url = self::TERMS_URL;

        return [
            'type' => 'flex',
            'altText' => '📋 ข้อตกลงการใช้บริการ - กรุณาอ่านและพิมพ์ \'ยอมรับ\'',
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#0367D3',
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '📋 ข้อตกลงการใช้บริการ',
                            'color' => '#FFFFFF',
                            'size' => 'lg',
                            'weight' => 'bold',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'ก่อนชำระเงิน',
                            'size' => 'md',
                            'color' => '#555555',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'รบกวนอ่านข้อตกลงก่อนนะครับ',
                            'size' => 'md',
                            'color' => '#555555',
                            'margin' => 'sm',
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'lg',
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'lg',
                            'backgroundColor' => '#E8F4FD',
                            'cornerRadius' => 'md',
                            'paddingAll' => 'md',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => "ℹ️ อ่านจบแล้ว พิมพ์ 'ยอมรับ' ได้เลยครับ",
                                    'size' => 'sm',
                                    'color' => '#0367D3',
                                    'wrap' => true,
                                ],
                            ],
                        ],
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'uri',
                                'label' => '📖 อ่านข้อตกลง',
                                'uri' => $url,
                            ],
                            'style' => 'primary',
                            'color' => '#0367D3',
                        ],
                        [
                            'type' => 'text',
                            'text' => "พิมพ์ 'ยอมรับ' หลังอ่านจบครับ",
                            'size' => 'xs',
                            'color' => '#AAAAAA',
                            'align' => 'center',
                            'margin' => 'md',
                        ],
                    ],
                ],
            ],
        ];
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
        return (bool) preg_match('/เงินเข้าแล้ว\s*[\d,.]+\s*บาท/u', $text);
    }

    /**
     * Parse verify-success data from text.
     * Returns null if amount cannot be parsed.
     */
    public function parseVerifyData(string $text): ?array
    {
        // Parse amount (required)
        if (! preg_match('/เงินเข้าแล้ว\s*([\d,.]+)\s*บาท/u', $text, $amountMatch)) {
            return null;
        }

        $amount = $amountMatch[1];

        // Parse items (optional): "• item" or "- item" at start of line
        $items = [];
        if (preg_match_all('/^[•\-]\s*(.+)/mu', $text, $itemMatches)) {
            $items = array_map('trim', $itemMatches[1]);
        }

        // Parse delivery info (optional)
        $delivery = null;
        if (preg_match('/ส่งใน\s*(.+?)(?:\n|$)/u', $text, $deliveryMatch)) {
            $delivery = trim($deliveryMatch[1]);
        }

        return [
            'amount' => $amount,
            'items' => $items,
            'delivery' => $delivery,
        ];
    }

    /**
     * Build LINE Flex Message for verify-success (Step 5).
     */
    public function buildVerifyFlexMessage(array $data, bool $isVip = false): array
    {
        $amount = $data['amount'];
        $items = $data['items'];
        $delivery = $data['delivery'];
        $primaryColor = $isVip ? self::VIP_PRIMARY_COLOR : self::NORMAL_PRIMARY_COLOR;
        $headerText = $isVip ? '👑 VIP ยืนยันชำระเงินสำเร็จ' : '✅ ยืนยันชำระเงินสำเร็จ';
        $footerText = $isVip ? 'ขอบคุณลูกค้า VIP ที่อุดหนุนครับ 🙏' : 'ขอบคุณที่อุดหนุนครับ 🙏';

        $bodyContents = [];

        // Centered checkmark icon
        $bodyContents[] = [
            'type' => 'text',
            'text' => '✅',
            'size' => 'xxl',
            'align' => 'center',
            'margin' => 'md',
        ];

        // Centered amount display
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'เงินเข้าเรียบร้อย',
            'size' => 'md',
            'color' => '#555555',
            'align' => 'center',
            'margin' => 'md',
        ];
        $bodyContents[] = [
            'type' => 'text',
            'text' => "{$amount} บาท",
            'size' => 'xxl',
            'color' => $primaryColor,
            'weight' => 'bold',
            'align' => 'center',
            'margin' => 'sm',
        ];

        // Items section
        if (! empty($items)) {
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg',
            ];
            $bodyContents[] = [
                'type' => 'text',
                'text' => '📋 รายการสินค้า',
                'size' => 'sm',
                'color' => '#555555',
                'margin' => 'lg',
            ];

            // Items in a styled box
            $itemContents = [];
            foreach ($items as $item) {
                $itemContents[] = [
                    'type' => 'text',
                    'text' => "• {$item}",
                    'size' => 'sm',
                    'color' => '#333333',
                    'margin' => 'sm',
                    'wrap' => true,
                ];
            }

            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'sm',
                'backgroundColor' => '#F7F7F7',
                'cornerRadius' => 'md',
                'paddingAll' => 'md',
                'contents' => $itemContents,
            ];
        }

        // Delivery info
        if ($delivery !== null) {
            $bodyContents[] = [
                'type' => 'separator',
                'margin' => 'lg',
            ];
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'backgroundColor' => '#E8F5E9',
                'cornerRadius' => 'md',
                'paddingAll' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => "📦 จัดส่งภายใน {$delivery}",
                        'size' => 'sm',
                        'color' => '#1B5E20',
                        'wrap' => true,
                    ],
                ],
            ];
        }

        // Support contact box
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'backgroundColor' => '#E8F4FD',
            'cornerRadius' => 'md',
            'paddingAll' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '💬 ต้องการความช่วยเหลือ?',
                    'size' => 'xs',
                    'color' => '#0367D3',
                ],
                [
                    'type' => 'text',
                    'text' => 'LINE: '.self::SUPPORT_LINE_ID,
                    'size' => 'xs',
                    'color' => '#555555',
                    'margin' => 'sm',
                ],
            ],
        ];

        return [
            'type' => 'flex',
            'altText' => "ชำระเงินสำเร็จ - {$amount} บาท [ยืนยันชำระเงิน]",
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => $primaryColor,
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $headerText,
                            'color' => '#FFFFFF',
                            'size' => 'lg',
                            'weight' => 'bold',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'ระบบตรวจสอบเรียบร้อยแล้ว',
                            'color' => '#FFFFFF',
                            'size' => 'sm',
                            'margin' => 'sm',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => $bodyContents,
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $footerText,
                            'size' => 'sm',
                            'color' => '#888888',
                            'align' => 'center',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'กัปตันแอด ยินดีให้บริการนะ',
                            'size' => 'xs',
                            'color' => '#AAAAAA',
                            'align' => 'center',
                            'margin' => 'sm',
                        ],
                    ],
                ],
            ],
        ];
    }
}
