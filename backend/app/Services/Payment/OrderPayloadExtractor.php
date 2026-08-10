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
    /** ใช้ดึงข้อมูล — ต้องปิดครบคู่เท่านั้น บล็อกที่ขาดกลาง = ข้อมูลไม่ครบ ห้าม decode */
    private const PATTERN = '/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/su';

    /**
     * ใช้ตัดทิ้ง — ครอบบล็อกที่ไม่ปิดด้วย (โมเดลถูกตัดกลางเพราะชนเพดาน token)
     * ตัดถึงท้ายข้อความเมื่อไม่เจอตัวปิด: ข้อมูลไม่ครบก็จริง แต่ห้ามให้ลูกค้าเห็น JSON ดิบ
     */
    private const STRIP_PATTERN = '/\[\[ORDER\]\].*?(?:\[\[\/ORDER\]\]|$)/su';

    /**
     * @return array{clean: string, payload: ?array{items: array<int, array{name: string, qty: int, total: string}>, total: float}}
     */
    public function extract(string $content): array
    {
        // เช็คด้วย STRIP_PATTERN (ไม่ใช่ PATTERN) — ไม่งั้นเคสไม่ปิดจะหลุดผ่านตั้งแต่บรรทัดนี้
        if (! preg_match(self::STRIP_PATTERN, $content)) {
            return ['clean' => $content, 'payload' => null];
        }

        // ตัดก่อนเสมอ ไม่ว่าจะ decode ได้หรือไม่ — JSON เสีย/ขาดกลาง ห้ามหลุดถึงลูกค้า
        $clean = trim((string) preg_replace(self::STRIP_PATTERN, '', $content));

        // decode เฉพาะบล็อกที่ปิดครบคู่ — บล็อกที่ขาดกลาง = ข้อมูลไม่ครบ เชื่อไม่ได้ ถอยไปใช้ regex
        $payload = preg_match(self::PATTERN, $content, $match)
            ? $this->decode($match[1])
            : null;

        return ['clean' => $clean, 'payload' => $payload];
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
        // ยอดโอนจริงไม่มีทางเป็น 0 หรือติดลบ — payload แบบนี้เชื่อไม่ได้ ถอยไปใช้ regex
        // (เช่น ไม่มี price → item total เป็น 0 → ผลรวม 0 = total 0 → checksum หลอกผ่าน)
        if ($total <= 0) {
            Log::info('OrderPayload: rejected — total is not positive');

            return null;
        }
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
