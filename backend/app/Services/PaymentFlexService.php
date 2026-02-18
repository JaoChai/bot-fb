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

    /**
     * Try to convert payment text to LINE Flex Message.
     * Returns Flex array if payment detected, or original text as fallback.
     *
     * @return string|array Original text or Flex message array
     */
    public function tryConvertToFlex(string $text): string|array
    {
        try {
            if (! $this->isPaymentMessage($text)) {
                return $text;
            }

            $data = $this->parsePaymentData($text);
            if ($data === null) {
                return $text;
            }

            $flex = $this->buildFlexMessage($data);

            // Safety: ensure Flex JSON doesn't exceed LINE's size limit
            $encoded = json_encode($flex);
            if ($encoded === false) {
                return $text;
            }
            $jsonSize = strlen($encoded);
            if ($jsonSize > self::MAX_FLEX_SIZE) {
                Log::warning('Payment Flex message exceeds size limit', [
                    'size' => $jsonSize,
                    'limit' => self::MAX_FLEX_SIZE,
                ]);

                return $text;
            }

            return $flex;
        } catch (\Throwable $e) {
            Log::warning('PaymentFlexService: fallback to text', [
                'error' => $e->getMessage(),
            ]);

            return $text;
        }
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
}
