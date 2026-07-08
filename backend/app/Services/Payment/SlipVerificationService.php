<?php

namespace App\Services\Payment;

class SlipVerificationService
{
    public function __construct(
        private readonly PaymentMessageDetector $detector,
    ) {}

    /**
     * เทียบเลขบัญชีที่ตั้งค่าไว้ กับเลขบัญชี mask จาก EasySlip (เช่น "xxx-x-x4880-x").
     * กติกา: ตัดอักขระที่ไม่ใช่ตัวเลข/x ทั้งสองฝั่ง แล้วเทียบตำแหน่งจากท้าย
     * เฉพาะตำแหน่งที่ EasySlip เปิดเผยตัวเลข ต้องตรงทุกตัว และต้องมีตัวเลขเปิดเผยอย่างน้อย 1 ตัว
     */
    public static function accountMatches(string $configured, string $masked): bool
    {
        $configuredDigits = array_reverse(str_split(preg_replace('/\D/', '', $configured)));
        $maskedChars = array_reverse(str_split(preg_replace('/[^0-9xX]/', '', $masked)));

        if (count($maskedChars) === 0 || count($configuredDigits) < count($maskedChars)) {
            return false;
        }

        $visibleDigits = 0;
        foreach ($maskedChars as $i => $char) {
            if ($char === 'x' || $char === 'X') {
                continue;
            }
            $visibleDigits++;
            if ($configuredDigits[$i] !== $char) {
                return false;
            }
        }

        return $visibleDigits > 0;
    }

    /**
     * หาข้อความสรุปยอดโอนล่าสุดของบอทใน history แล้วคืนยอด + สรุปรายการ
     *
     * @param  array<int, array{sender: string, content: string}>  $conversationHistory
     * @return array{total: float, summary: string}|null
     */
    public function findExpectedPayment(array $conversationHistory): ?array
    {
        foreach (array_reverse($conversationHistory) as $msg) {
            if (($msg['sender'] ?? '') !== 'bot') {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (! $this->detector->isPaymentMessage($content)) {
                continue;
            }
            $data = $this->detector->parsePaymentData($content);
            if ($data === null) {
                continue;
            }

            $itemNames = array_map(fn (array $item) => $item['name'], $data['items']);

            return [
                'total' => (float) str_replace(',', '', $data['total']),
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
            ];
        }

        return null;
    }
}
