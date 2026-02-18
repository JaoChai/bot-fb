<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PaymentFlexService
{
    private const BANK_ACCOUNT = '223-3-24880-3';
    private const BANK_NAME = 'ธนาคารกสิกรไทย (KBANK)';
    private const ACCOUNT_NAME = 'หจก. มั่งมีทรัพย์ขายของออนไลน์';
    private const CLIPBOARD_TEXT = '2233248803';
    private const MAX_FLEX_SIZE = 30000;
    private const TERMS_URL = 'https://mhhacoursecontent.my.canva.site/ads-vance';

    /**
     * Try to convert payment text to LINE Flex Message.
     * Returns Flex array if payment detected, or original text as fallback.
     *
     * @return string|array Original text or Flex message array
     */
    public function tryConvertToFlex(string $text): string|array
    {
        try {
            // Step 4: Payment message (existing)
            if ($this->isPaymentMessage($text)) {
                $data = $this->parsePaymentData($text);
                if ($data !== null) {
                    return $this->safeBuildFlex($text, $this->buildFlexMessage($data));
                }
            }

            // Step 3: Terms message (always uses fixed TERMS_URL)
            if ($this->isTermsMessage($text)) {
                return $this->safeBuildFlex($text, $this->buildTermsFlexMessage());
            }

            // Step 5: Verify success message
            if ($this->isVerifySuccessMessage($text)) {
                $data = $this->parseVerifyData($text);
                if ($data !== null) {
                    return $this->safeBuildFlex($text, $this->buildVerifyFlexMessage($data));
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

        return (bool) preg_match('/รวมยอดโอน|สรุปยอด|ยอดโอน/u', $text);
    }

    /**
     * Parse payment data from text.
     * Returns null if total cannot be parsed (required field).
     */
    public function parsePaymentData(string $text): ?array
    {
        // Parse total (required)
        if (! preg_match('/(?:รวมยอดโอน|สรุปยอด(?:โอน)?|ยอดโอน)\s*:?\s*([\d,]+)\s*บาท/u', $text, $totalMatch)) {
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
    public function buildFlexMessage(array $data): array
    {
        $total = $data['total'];
        $items = $data['items'];

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
                    'color' => '#1DB446',
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
                    'color' => '#1DB446',
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

        // TrueMoney warning box
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'backgroundColor' => '#FFF9E6',
            'cornerRadius' => 'md',
            'paddingAll' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '⚠️ ไม่รับโอนเงินจากทรูมันนี่',
                    'size' => 'xs',
                    'color' => '#996600',
                    'wrap' => true,
                ],
            ],
        ];

        return [
            'type' => 'flex',
            'altText' => "สรุปรายการสั่งซื้อ - ยอดโอน {$total} บาท",
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#1DB446',
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'สรุปรายการสั่งซื้อ',
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
                            'color' => '#1DB446',
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
    // Step 3: Terms Message
    // ────────────────────────────────────────────────────────

    /**
     * Detect if text is a terms/agreement message (Step 3).
     * Must contain "ยอมรับ" + ("ข้อตกลง" or URL), but NOT bank account or "เงินเข้าแล้ว".
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

        // Must have "ข้อตกลง"
        return mb_strpos($text, 'ข้อตกลง') !== false;
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
     * Must contain "[ยืนยันชำระเงิน]" tag AND "เงินเข้าแล้ว" + amount.
     */
    public function isVerifySuccessMessage(string $text): bool
    {
        if (mb_strpos($text, '[ยืนยันชำระเงิน]') === false) {
            return false;
        }

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
    public function buildVerifyFlexMessage(array $data): array
    {
        $amount = $data['amount'];
        $items = $data['items'];
        $delivery = $data['delivery'];

        $bodyContents = [];

        // Amount display
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'เงินเข้าแล้ว',
            'size' => 'md',
            'color' => '#555555',
        ];
        $bodyContents[] = [
            'type' => 'text',
            'text' => "{$amount} บาท",
            'size' => 'xxl',
            'color' => '#1DB446',
            'weight' => 'bold',
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
                'text' => 'ออเดอร์:',
                'size' => 'sm',
                'color' => '#555555',
                'margin' => 'lg',
            ];

            foreach ($items as $item) {
                $bodyContents[] = [
                    'type' => 'text',
                    'text' => "• {$item}",
                    'size' => 'sm',
                    'color' => '#333333',
                    'margin' => 'sm',
                    'wrap' => true,
                ];
            }
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
                        'text' => "📦 ส่งใน {$delivery}",
                        'size' => 'sm',
                        'color' => '#1B5E20',
                        'wrap' => true,
                    ],
                ],
            ];
        }

        return [
            'type' => 'flex',
            'altText' => "ชำระเงินสำเร็จ - {$amount} บาท [ยืนยันชำระเงิน]",
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#1DB446',
                    'paddingAll' => 'lg',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '✅ ชำระเงินสำเร็จ',
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
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => 'ขอบคุณที่อุดหนุนครับ 🙏',
                            'size' => 'sm',
                            'color' => '#888888',
                            'align' => 'center',
                        ],
                    ],
                ],
            ],
        ];
    }
}
