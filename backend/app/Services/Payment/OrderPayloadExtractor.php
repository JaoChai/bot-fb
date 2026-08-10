<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Log;

/**
 * ช่องทางข้อมูลออเดอร์แบบมีโครงสร้างที่บอทปล่อยมาพร้อมข้อความสรุปยอด
 *
 * ที่มา: จำนวนสินค้าเคยเดินทางผ่าน "ประโยคที่ LLM แต่งเอง" อย่างเดียว แล้วให้ regex
 * อ่านกลับ — โมเดล drift ฟอร์แมตเมื่อไร จำนวนก็หายเมื่อนั้น (17 ก.ค. 2026 "G3D x20",
 * 10 ส.ค. 2026 "(50 บาท x 20)" ลูกค้าจ่าย 1,000 ได้ของ 1 จาก 20)
 *
 * บล็อกนี้ถูกตัดออกก่อนบันทึกข้อความและก่อนส่งออกทุกช่องทาง ลูกค้าจึงไม่มีวันเห็น
 */
class OrderPayloadExtractor
{
    /** ตัวคั่นที่เลือกให้ไม่ชนกับ "|||" (ตัวแบ่ง bubble) และไม่โผล่ในภาษาไทยปกติ */
    private const PATTERN = '/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/su';

    /**
     * @return array{clean: string, payload: ?array{items: array<int, array{name: string, qty: int, total: string}>, total: float}}
     */
    public function extract(string $content): array
    {
        if (! preg_match(self::PATTERN, $content, $match)) {
            return ['clean' => $content, 'payload' => null];
        }

        // ตัดก่อนเสมอ ไม่ว่าจะ decode ได้หรือไม่ — JSON เสียห้ามหลุดถึงลูกค้า
        $clean = trim((string) preg_replace(self::PATTERN, '', $content));

        return ['clean' => $clean, 'payload' => $this->decode($match[1])];
    }

    /**
     * @return array{items: array<int, array{name: string, qty: int, total: string}>, total: float}|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode(trim($json), true);
        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items']) || $decoded['items'] === []) {
            Log::info('OrderPayload: block present but unusable', ['len' => mb_strlen($json)]);

            return null;
        }

        $total = (float) ($decoded['total'] ?? 0);
        $items = [];
        foreach ($decoded['items'] as $item) {
            if (! is_array($item) || empty($item['name']) || ! is_string($item['name'])) {
                return null;
            }
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $price = (float) ($item['price'] ?? 0);
            $items[] = [
                'name' => trim($item['name']),
                'qty' => $qty,
                // เก็บเป็น string ให้เข้ารูปเดียวกับ items ที่มาจาก regex (ผู้ใช้ปลายทางเดียวกัน)
                'total' => (string) ($price * $qty),
            ];
        }

        // payload ที่ตัวเลขไม่สอดคล้องกันเอง = โมเดลคำนวณพลาด เชื่อไม่ได้ ถอยไปใช้ regex
        if (PaymentMessageDetector::itemsMatchTotal($items, $total) === false) {
            Log::warning('OrderPayload: rejected — items do not sum to total', ['total' => $total]);

            return null;
        }

        return ['items' => $items, 'total' => $total];
    }
}
