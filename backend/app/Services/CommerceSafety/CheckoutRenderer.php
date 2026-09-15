<?php

namespace App\Services\CommerceSafety;

use App\Models\CheckoutSession;
use InvalidArgumentException;

class CheckoutRenderer
{
    private const TERMS_URL = 'https://mhhacoursecontent.my.canva.site/ads-vance';

    private const BANK_NAME = 'ธนาคารกสิกรไทย (KBANK)';

    private const BANK_ACCOUNT = '223-3-24880-3';

    private const ACCOUNT_NAME = 'หจก. มั่งมีทรัพย์ขายของออนไลน์';

    public function render(CheckoutSession $checkout, string $action): string
    {
        if ($action === 'payment' && $checkout->state !== 'payable') {
            throw new InvalidArgumentException('Payment instructions require a payable checkout.');
        }

        return match ($action) {
            'ack' => $this->topupAcknowledgement($checkout),
            'confirm' => $this->confirmation($checkout),
            'support_delay' => $this->supportDelay(),
            'terms' => $this->terms(),
            'payment' => $this->payment($checkout),
            'manual_hold' => 'รายการนี้ต้องให้ทีมงานตรวจสอบก่อนดำเนินการต่อครับ',
            default => throw new InvalidArgumentException("Unsupported checkout action [{$action}]."),
        };
    }

    private function topupAcknowledgement(CheckoutSession $checkout): string
    {
        return "รายการ revision {$checkout->revision} มีสินค้าแบบเติมเงินครับ "
            ."กรุณาตรวจสอบวิธีใช้งานและตอบ 'รับทราบ' หากต้องการดำเนินการต่อ";
    }

    private function confirmation(CheckoutSession $checkout): string
    {
        return "สรุปรายการ revision {$checkout->revision} ที่พี่สั่งซื้อครับ:\n"
            .$this->lineText($checkout)
            ."\nรวม: ".$this->formatMinor($checkout->total_minor)
            ." บาท\nกรุณาตรวจสอบและพิมพ์ 'ยืนยัน' เพื่อดำเนินการต่อครับ";
    }

    private function supportDelay(): string
    {
        return 'ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ '
            ."หากบัญชีมีปัญหาต้องรอคิวนิดนึง ถ้าพี่รับเงื่อนไขตรงนี้ได้ กรุณาตอบ 'ตกลง' ครับ\n"
            .'[แจ้งเตือน Support]';
    }

    private function terms(): string
    {
        return "📋 ก่อนชำระเงิน รบกวนอ่านข้อตกลงครับ\n"
            .'🔗 '.self::TERMS_URL."\n"
            ."พิมพ์ 'ยอมรับ' หลังอ่านจบครับ";
    }

    private function payment(CheckoutSession $checkout): string
    {
        $items = array_map(fn (array $item): array => [
            'name' => $this->displayName($item),
            'qty' => $item['qty'],
            'price' => $this->jsonAmount($item['price_minor']),
        ], $checkout->items);
        $payload = json_encode([
            'items' => $items,
            'total' => $this->jsonAmount($checkout->total_minor),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return "สรุปรายการ revision {$checkout->revision} ที่พี่สั่งซื้อครับ:\n"
            .$this->lineText($checkout)
            ."\nรวมยอดโอน: ".$this->formatMinor($checkout->total_minor)." บาท ✅\n\n"
            ."รบกวนโอนเข้าบัญชี:\n".self::BANK_NAME."\n".self::BANK_ACCOUNT."\n"
            .self::ACCOUNT_NAME
            ."\n[[ORDER]]{$payload}[[/ORDER]]";
    }

    private function lineText(CheckoutSession $checkout): string
    {
        return implode("\n", array_map(function (array $item, int $index): string {
            return ($index + 1).'. '.$this->displayName($item).' ('
                .$this->formatMinor($item['price_minor']).' x '.$item['qty'].') = '
                .$this->formatMinor($item['line_total_minor']).' บาท';
        }, $checkout->items, array_keys($checkout->items)));
    }

    private function displayName(array $item): string
    {
        return $item['name'].match ($item['method'] ?? 'none') {
            'card' => ' (ผูกบัตร)',
            'topup' => ' (เติมเงิน)',
            default => '',
        };
    }

    private function formatMinor(int $minor): string
    {
        $whole = intdiv($minor, 100);
        $fraction = $minor % 100;

        return number_format($whole).($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }

    private function jsonAmount(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }
}
