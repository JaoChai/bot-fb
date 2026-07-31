<?php

namespace App\Services\Payment;

use App\Models\Bot;
use App\Models\ProductStock;
use App\Services\OpenRouterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ด่าน 3 ของการหาออเดอร์: ลูกค้าโอนข้ามขั้นตอนจน regex สองด่านแรกหาไม่เจอ
 * → ให้ utility model อ่านบทสนทนาทั้งสองฝั่งแล้วสรุปว่าสั่งอะไร
 *
 * LLM เป็นแค่ผู้เสนอ — ตัวตัดสินคือด่านตรวจในคลาสนี้ทั้งหมด:
 *   1. slug ต้องมีจริงและเปิดขายอยู่
 *   2. ผลรวม ราคา × จำนวน ต้องเท่ายอดในสลิป (± tolerance ของบอท)
 *   3. ชื่อหรือ alias ต้องถูกพูดถึงในบทสนทนาจริง (กัน LLM แต่งของขึ้นมา)
 * ไม่ throw — ทุกทางที่พลาดคืน null ให้ผู้เรียกตกไปพฤติกรรมเดิม
 */
class OrderReconstructor
{
    /** จำนวนต่อรายการที่ยอมรับ — เกินนี้ถือว่า LLM เพี้ยน (สอดคล้อง delivery.max_qty) */
    private const MAX_QTY = 20;

    private const SYSTEM_PROMPT = <<<'PROMPT'
คุณคือผู้ช่วยร้านค้า อ่านบทสนทนาแล้วสรุปว่าลูกค้าสั่งซื้ออะไร ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่น:
{"items":[{"slug":"...","qty":1}],"confidence":"high|low"}

กติกา:
- slug ต้องเลือกจากรายการสินค้าที่ให้ไว้เท่านั้น ห้ามคิดขึ้นเอง
- qty คือจำนวนชิ้น
- ผลรวม (ราคา × qty) ต้องเท่ากับยอดที่ลูกค้าโอนมาพอดี
- ยึดสิ่งที่ลูกค้าพูดล่าสุดเป็นหลัก ถ้าลูกค้าเปลี่ยนใจระหว่างคุย ให้ใช้ตัวหลังสุด
- ถ้าสรุปไม่ได้หรือรวมยอดไม่ลงตัว ตอบ {"items":[],"confidence":"low"}
PROMPT;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @param  array<int, array{sender: string, content: string}>  $history
     */
    public function reconstruct(Bot $bot, array $history, float $slipAmount): ?OrderReconstruction
    {
        $products = ProductStock::where('in_stock', true)->whereNotNull('price')->get();
        if ($products->isEmpty()) {
            return null;
        }

        $transcript = $this->transcript($history);
        if (trim($transcript) === '') {
            return null;
        }

        $raw = $this->ask($bot, $products, $transcript, $slipAmount);
        if ($raw === []) {
            return null;
        }

        $items = $this->validate($raw, $products, $transcript, $slipAmount, $bot);
        if ($items === null) {
            return null;
        }

        $alternatives = $this->alternatives($items, $products, $transcript);

        return new OrderReconstruction(
            items: $items,
            total: $slipAmount,
            summary: $this->summarize($items),
            ambiguous: count($alternatives) > 1,
            alternatives: count($alternatives) > 1 ? $alternatives : [],
        );
    }

    /** @param  array<int, array{sender: string, content: string}>  $history */
    private function transcript(array $history): string
    {
        $lines = [];
        foreach ($history as $msg) {
            $sender = ($msg['sender'] ?? '') === 'user' ? 'ลูกค้า' : 'ร้าน';
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content !== '') {
                $lines[] = "{$sender}: {$content}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array{slug: string, qty: int}>
     */
    private function ask(Bot $bot, Collection $products, string $transcript, float $slipAmount): array
    {
        $model = $bot->resolvedUtilityModel();
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey();
        if ($model === null || empty($apiKey)) {
            Log::debug('OrderReconstructor: no utility model or API key, skipping', ['bot_id' => $bot->id]);

            return [];
        }

        $catalog = $products
            ->map(fn (ProductStock $p) => "- slug: {$p->slug} | ชื่อ: {$p->name} | ราคา: ".(float) $p->price.' บาท')
            ->implode("\n");

        $user = "สินค้าที่ขาย:\n{$catalog}\n\nยอดที่ลูกค้าโอนมา: {$slipAmount} บาท\n\nบทสนทนา:\n{$transcript}";

        try {
            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $user],
                ],
                model: $model,
                temperature: 0.1,
                maxTokens: 300,
                useFallback: false,
                apiKeyOverride: $apiKey,
            );
        } catch (\Throwable $e) {
            Log::warning('OrderReconstructor: LLM call failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }

        return $this->decode($response['content'] ?? '');
    }

    /** @return array<int, array{slug: string, qty: int}> */
    private function decode(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            Log::debug('OrderReconstructor: JSON parse failed', ['content' => mb_substr($content, 0, 200)]);

            return [];
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (! is_array($item) || empty($item['slug']) || ! is_string($item['slug'])) {
                continue;
            }
            $items[] = ['slug' => trim($item['slug']), 'qty' => max(1, (int) ($item['qty'] ?? 1))];
        }

        return $items;
    }

    /**
     * ด่านตรวจทั้งสามชั้น — ผ่านครบเท่านั้นถึงคืนรายการออกไป
     *
     * @param  array<int, array{slug: string, qty: int}>  $raw
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array{name: string, total: string, qty: int}>|null
     */
    private function validate(array $raw, Collection $products, string $transcript, float $slipAmount, Bot $bot): ?array
    {
        if ($raw === []) {
            return null;
        }

        $items = [];
        $sum = 0.0;
        foreach ($raw as $entry) {
            $product = $products->firstWhere('slug', $entry['slug']);
            if ($product === null) {
                Log::info('OrderReconstructor: unknown slug', ['slug' => $entry['slug']]);

                return null;
            }
            if ($entry['qty'] > self::MAX_QTY) {
                Log::info('OrderReconstructor: qty out of range', ['qty' => $entry['qty']]);

                return null;
            }
            if (! $this->mentioned($product, $transcript)) {
                Log::info('OrderReconstructor: product never mentioned in chat', ['slug' => $product->slug]);

                return null;
            }

            $lineTotal = (float) $product->price * $entry['qty'];
            $sum += $lineTotal;
            $items[] = [
                'name' => $product->name,
                'total' => rtrim(rtrim(number_format($lineTotal, 2, '.', ''), '0'), '.'),
                'qty' => $entry['qty'],
            ];
        }

        $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
        if (abs($sum - $slipAmount) > $tolerance) {
            Log::info('OrderReconstructor: checksum mismatch', ['sum' => $sum, 'slip' => $slipAmount]);

            return null;
        }

        return $items;
    }

    /** ชื่อหรือ alias ของสินค้าถูกพูดถึงในบทสนทนาไหม (ตัดคำสั้นกว่า 3 ตัวอักษรทิ้ง กัน match กว้างเกิน) */
    private function mentioned(ProductStock $product, string $transcript): bool
    {
        $haystack = mb_strtolower($transcript);
        foreach (array_merge([$product->name], $product->aliases ?? []) as $term) {
            $term = mb_strtolower(trim((string) $term));
            if (mb_strlen($term) >= 3 && mb_strpos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ชุดที่ประกอบยอดได้เท่ากันโดยสลับเป็นสินค้าราคาเดียวกันที่ถูกพูดถึงในแชทด้วย
     * เช่น 1,100 = Personal ×1 หรือ BM ×1 → ต้องให้เจ้าของเลือก ห้ามเดาเอง
     * ชุดที่ LLM เลือกอยู่ตำแหน่งแรกเสมอ
     *
     * @param  array<int, array{name: string, total: string, qty: int}>  $items
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array<int, array{name: string, total: string, qty: int}>>
     */
    private function alternatives(array $items, Collection $products, string $transcript): array
    {
        if (count($items) !== 1) {
            return [$items]; // ออเดอร์หลายรายการ: ไม่สลับให้ ความเสี่ยงจับคู่ผิดสูงเกิน
        }

        $chosen = $items[0];
        $sets = [$items];

        foreach ($products as $product) {
            if ($product->name === $chosen['name']) {
                continue;
            }
            $lineTotal = (float) $product->price * $chosen['qty'];
            if (abs($lineTotal - (float) $chosen['total']) > 0.001) {
                continue;
            }
            if (! $this->mentioned($product, $transcript)) {
                continue;
            }
            $sets[] = [['name' => $product->name, 'total' => $chosen['total'], 'qty' => $chosen['qty']]];
        }

        return $sets;
    }

    /** @param  array<int, array{name: string, total: string, qty: int}>  $items */
    private function summarize(array $items): string
    {
        return implode(', ', array_map(
            fn (array $item) => $item['qty'] > 1 ? "{$item['name']} x{$item['qty']}" : $item['name'],
            $items,
        ));
    }
}
