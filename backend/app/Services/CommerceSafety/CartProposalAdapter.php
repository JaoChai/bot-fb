<?php

namespace App\Services\CommerceSafety;

use App\Services\Payment\PaymentMessageDetector;
use InvalidArgumentException;
use JsonException;

class CartProposalAdapter
{
    private const AMOUNT = '(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?';

    public function __construct(
        private readonly PaymentMessageDetector $detector,
    ) {}

    /** @return array{lines:list<array{name:string,method:string,qty:int,price_minor:int}>,total_minor:int}|null */
    public function fromText(string $text): ?array
    {
        $text = str_replace('|||', "\n", $text);
        $located = $this->detector->parsePaymentData($text)
            ?? $this->detector->parseConfirmData($text);
        if (! is_array($located) || ! isset($located['items']) || ! is_array($located['items'])) {
            return null;
        }

        $amount = self::AMOUNT;
        preg_match_all(
            '/(?:^|\R)\s*(?:\d+[\.)]\s*|[-•]\s*)'
                .'(?<name>[^\r\n]+?)\s*\(\s*'
                .'(?<price>'.$amount.')\s*(?:บาท|฿)?\s*[x×]\s*'
                .'(?<qty>[0-9]+)\s*(?:ตัว|เพจ|ใบ|ชิ้น|อัน)?\s*\)\s*=\s*'
                .'(?<line_total>'.$amount.')\s*(?:บาท|฿)\s*(?=\R|$)/u',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === [] || count($matches) !== count($located['items'])) {
            return null;
        }

        preg_match_all(
            '/(?:รวมยอดโอน|รวมทั้งสิ้น|สรุปยอด(?:โอน)?|ยอดโอน|ยอดรวม|รวมเป็นเงิน|ยอดสุทธิ|ยอดที่ต้องโอน|ยอดชำระ|ยอดที่ต้องชำระ|รวม(?:ทั้งหมด|ยอด|เป็นเงิน)?|ราคา)'
                .'\s*:?\s*฿?\s*('.$amount.')\s*(?:บาท|฿)/u',
            $text,
            $totalMatches,
        );
        if (count($totalMatches[1] ?? []) !== 1) {
            return null;
        }

        $lines = [];
        $sum = 0;
        foreach ($matches as $match) {
            $parsedName = $this->proposalName($match['name']);
            $qty = filter_var($match['qty'], FILTER_VALIDATE_INT);
            $priceMinor = $this->moneyMinor($match['price']);
            $lineTotalMinor = $this->moneyMinor($match['line_total']);
            if ($parsedName === null || ! is_int($qty) || $qty <= 0 || $priceMinor === null || $lineTotalMinor === null) {
                return null;
            }
            if ($priceMinor > intdiv(PHP_INT_MAX, $qty)
                || $priceMinor * $qty !== $lineTotalMinor
                || $sum > PHP_INT_MAX - $lineTotalMinor) {
                return null;
            }

            $sum += $lineTotalMinor;
            $lines[] = [
                'name' => $parsedName['name'],
                'method' => $parsedName['method'],
                'qty' => $qty,
                'price_minor' => $priceMinor,
            ];
        }

        $totalMinor = $this->moneyMinor($totalMatches[1][0]);
        if ($totalMinor === null || $sum !== $totalMinor) {
            return null;
        }

        return ['lines' => $lines, 'total_minor' => $totalMinor];
    }

    /** @return array{lines:list<array{name:string,method:string,qty:int,price_minor:int}>,total_minor:int}|null */
    public function fromOrderJson(string $json): ?array
    {
        try {
            $decoded = json_decode(trim($json), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)
            || ! $this->hasExactKeys($decoded, ['items', 'total'])
            || ! is_array($decoded['items'])
            || ! array_is_list($decoded['items'])
            || $decoded['items'] === []) {
            return null;
        }

        $lines = [];
        $sum = 0;
        foreach ($decoded['items'] as $item) {
            if (! is_array($item)
                || ! $this->hasExactKeys($item, ['name', 'qty', 'price'])
                || ! is_string($item['name'])
                || trim($item['name']) === ''
                || ! is_int($item['qty'])
                || $item['qty'] <= 0) {
                return null;
            }

            $parsedName = $this->proposalName($item['name']);
            $priceMinor = $this->moneyMinor($item['price']);
            if ($parsedName === null || $priceMinor === null || $priceMinor > intdiv(PHP_INT_MAX, $item['qty'])) {
                return null;
            }
            $lineTotal = $priceMinor * $item['qty'];
            if ($sum > PHP_INT_MAX - $lineTotal) {
                return null;
            }

            $sum += $lineTotal;
            $lines[] = [
                'name' => $parsedName['name'],
                'method' => $parsedName['method'],
                'qty' => $item['qty'],
                'price_minor' => $priceMinor,
            ];
        }

        $totalMinor = $this->moneyMinor($decoded['total']);
        if ($totalMinor === null || $sum !== $totalMinor) {
            return null;
        }

        return ['lines' => $lines, 'total_minor' => $totalMinor];
    }

    /** @return array{name:string,method:'card'|'topup'|'none'}|null */
    private function proposalName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $hasCard = str_contains($name, 'ผูกบัตร');
        $hasTopup = str_contains($name, 'เติมเงิน');
        if ($hasCard && $hasTopup) {
            return null;
        }

        if (preg_match('/\s*\((ผูกบัตร|เติมเงิน)\)\s*$/u', $name, $match) === 1) {
            $baseName = trim((string) preg_replace('/\s*\((ผูกบัตร|เติมเงิน)\)\s*$/u', '', $name));
            if ($baseName === '' || str_contains($baseName, 'ผูกบัตร') || str_contains($baseName, 'เติมเงิน')) {
                return null;
            }
            if (preg_match('/^(?:page|g3d)$/iu', $baseName) === 1) {
                return null;
            }

            return [
                'name' => $baseName,
                'method' => $match[1] === 'ผูกบัตร' ? 'card' : 'topup',
            ];
        }

        if ($hasCard || $hasTopup || preg_match('/nolimit/iu', $name) === 1) {
            return null;
        }

        return ['name' => $name, 'method' => 'none'];
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private function moneyMinor(mixed $amount): ?int
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }
        if (! is_string($amount)) {
            return null;
        }

        $amount = str_replace(',', '', $amount);
        try {
            $minor = MoneyMinor::fromDecimal($amount);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $minor > 0 ? $minor : null;
    }
}
