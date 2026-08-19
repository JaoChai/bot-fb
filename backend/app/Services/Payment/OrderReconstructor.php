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
    private const SYSTEM_PROMPT = <<<'PROMPT'
คุณคือผู้ช่วยร้านค้า อ่านบทสนทนาแล้วสรุปว่าลูกค้าสั่งซื้ออะไร ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่น:
{"items":[{"slug":"...","qty":1}]}

กติกา:
- slug ต้องเลือกจากรายการสินค้าที่ให้ไว้เท่านั้น ห้ามคิดขึ้นเอง
- qty คือจำนวนชิ้น
- ผลรวม (ราคา × qty) ต้องเท่ากับยอดที่ลูกค้าโอนมาพอดี
- ยึดสิ่งที่ลูกค้าพูดล่าสุดเป็นหลัก ถ้าลูกค้าเปลี่ยนใจระหว่างคุย ให้ใช้ตัวหลังสุด
- ถ้าสรุปไม่ได้หรือรวมยอดไม่ลงตัว ตอบ {"items":[]}
PROMPT;

    /** จำนวนต่อรายการที่ยอมรับ — เกินนี้ถือว่า LLM เพี้ยน ใช้ค่าเดียวกับ delivery.max_qty เสมอ กันสองจุดหลุดไม่ตรงกัน */
    private readonly int $maxQty;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {
        $this->maxQty = max(1, config_int('delivery.max_qty', 20));
    }

    /**
     * @param  array<int, array{sender: string, content: string}>  $history
     */
    public function reconstruct(Bot $bot, array $history, float $slipAmount): ?OrderReconstruction
    {
        $products = ProductStock::where('in_stock', true)->whereNotNull('price')->orderBy('display_order')->get();
        if ($products->isEmpty()) {
            return null;
        }

        $transcript = $this->transcript($history);
        if (trim($transcript) === '') {
            return null;
        }

        // mentioned()/analyzeAmbiguity() เทียบชื่อสินค้าเป็นตัวพิมพ์เล็กเสมอ — lowercase ครั้งเดียวตรงนี้
        // แล้วส่ง $haystack ลงไปแทนการ mb_strtolower ใหม่ทุกครั้ง (เดิมอาจถึงสิบกว่าครั้งต่อรอบ)
        // $transcript ตัวเต็มยังใช้ตอนส่งให้ LLM ใน ask() เหมือนเดิม ไม่ใช่ตัว lowercase
        $haystack = mb_strtolower($transcript);

        $raw = $this->ask($bot, $products, $transcript, $slipAmount);
        if ($raw === []) {
            return null;
        }

        $items = $this->validate($raw, $products, $haystack, $slipAmount, $bot);
        if ($items === null) {
            return null;
        }

        // หา "ชุดตัวเลือกปุ่ม" (sets) กับ "กำกวมไหม" (ambiguous) ในการวน products รอบเดียว
        // (เดิม alternatives() กับ isAmbiguous() วน products ซ้ำกันสองรอบในเคสรายการเดียว)
        $analysis = $this->analyzeAmbiguity($items, $products, $haystack);

        return new OrderReconstruction(
            items: $items,
            total: $slipAmount,
            summary: PaymentMessageDetector::formatItemSummary($items),
            ambiguous: $analysis['ambiguous'],
            alternatives: $analysis['ambiguous'] ? $analysis['sets'] : [],
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
        // ใช้ utility_model ตรง ๆ ไม่ใช้ resolvedUtilityModel() เพราะเมธอดนั้นจะถอยไปใช้
        // fallback_chat_model หรือ primary_chat_model เมื่อไม่ได้ตั้ง utility model ซึ่งเป็นโมเดล
        // สนทนาราคาแพง (เช่น bot 28 ตั้ง gpt-5.1 ไว้) การสรุปออเดอร์เป็นงานเบื้องหลัง ต้องใช้โมเดล
        // งานเบื้องหลังเท่านั้น ไม่ได้ตั้ง utility_model = ไม่ทำงาน ดีกว่ายิงโมเดลแพงโดยไม่ตั้งใจ
        $model = $bot->utility_model;
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

        // ครอบ decode ด้วย try/catch กันทุกกรณีที่คิดไม่ถึง — ถ้า parse พังต้องไม่ทะลุออกไป
        // จน record() ไม่ถูกเรียก (สลิปหายทั้งใบเงียบๆ)
        try {
            return $this->decode($response['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('OrderReconstructor: decode failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return [];
        }
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
            // ข้าม item ที่ qty ไม่ใช่จำนวนเต็มในช่วงปลอดภัย — ค่าหลุดโลกอย่าง 1e20
            // (float ที่ (int) ไม่รับได้ แล้ว throw) ต้องไม่ทำให้ทั้งใบสลิปพัง
            $qty = $this->safeQty($item['qty'] ?? 1);
            if ($qty === null) {
                continue;
            }
            $items[] = ['slug' => trim($item['slug']), 'qty' => $qty];
        }

        return $items;
    }

    /**
     * แปลง qty เป็นจำนวนเต็มอย่างปลอดภัย — คืน null เมื่อไม่ใช่จำนวนเต็มในช่วง 1..maxQty
     *
     * ต้องเช็คช่วงเป็น float ก่อน cast เพราะ (int) ของ float ที่ใหญ่เกิน (เช่น 1e20 ที่ LLM ตอป้าย)
     * จะโยน "The float ... is not representable as an int" ทะลุออกไปจน record() ไม่ถูกเรียก
     */
    private function safeQty(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $asFloat = (float) $value;
        if ($asFloat < 1 || $asFloat > $this->maxQty) {
            return null;
        }

        return (int) $asFloat;
    }

    /**
     * ด่านตรวจทั้งสามชั้น — ผ่านครบเท่านั้นถึงคืนรายการออกไป
     *
     * @param  array<int, array{slug: string, qty: int}>  $raw
     * @param  Collection<int, ProductStock>  $products
     * @return array<int, array{name: string, total: string, qty: int}>|null
     */
    private function validate(array $raw, Collection $products, string $haystack, float $slipAmount, Bot $bot): ?array
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
            if ($entry['qty'] > $this->maxQty) {
                Log::info('OrderReconstructor: qty out of range', ['qty' => $entry['qty']]);

                return null;
            }
            if (! $this->mentioned($product, $haystack)) {
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

    /**
     * ชื่อหรือ alias ของสินค้าถูกพูดถึงในบทสนทนาไหม
     *
     * $haystack คือบทสนทนาที่ lowercase แล้ว (ทำครั้งเดียวใน reconstruct) — เทียบเป็นตัวพิมพ์เล็กเสมอ
     *
     * ยอมรับคำตั้งแต่ 2 ตัวอักษรขึ้นไป เพราะ alias จริงในระบบมีคำสั้น 2 ตัวที่ลูกค้าใช้เรียกสินค้าจริง
     * เช่น 'ไก่' (G3D) และ 'BM' — ถ้าตั้งเกณฑ์ 3 จะบล็อกคำพวกนี้จนด่านนี้ปฏิเสธออเดอร์จริง
     * ด่านนี้เป็นแค่ตัวกัน LLM แต่งสินค้าที่ไม่มีใครพูดถึง ตัวตัดสินจริงคือ checksum ยอดข้างบน
     * จึงยอมให้กว้างขึ้นได้
     */
    private function mentioned(ProductStock $product, string $haystack): bool
    {
        foreach (array_merge([$product->name], $product->aliases ?? []) as $term) {
            $term = mb_strtolower(trim((string) $term));
            if (mb_strlen($term) >= 2 && mb_strpos($haystack, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * หา "ชุดตัวเลือกปุ่ม" (sets) และ "กำกวมไหม" (ambiguous) ในการวน products รอบเดียว —
     * เดิม alternatives() กับ isAmbiguous() วนซ้ำกันสองรอบในเคสรายการเดียว
     *
     * ทำไมสองเคสนี้ "กำกวมเหมือนกัน" แต่ sets ทำต่างกัน:
     *  - รายการเดียว: สลับตัวเลือกได้ชัด เช่น 1,100 = Personal ×1 หรือ BM ×1
     *    → sets สร้างชุดสลับจริงให้เจ้าของกดปุ่มเลือกได้เลย (หลายชุด/หลายปุ่ม)
     *    และ ambiguous ตั้งตาม count(sets) > 1 (สองด่านเงื่อนไขเดียวกันจึงรวมรอบเดียวได้)
     *  - หลายรายการ: ความเป็นไปได้บานปลาย เช่น personal 1 + bm 1 (2,200) อาจเป็น
     *    personal 2 หรือ bm 2 ก็ได้ → ตีเป็นกำกวม (หยุดส่งของเอง) แต่ sets ชุดเดียว
     *    ไม่สร้างปุ่มสลับเพราะสร้างครบทุกแบบทั้งยาวและอ่านสับสน จึงเตือนให้เปิดแชทตรวจแทน
     * ทั้งสองเคสใช้ hasSamePriceSibling ตัดสินเหมือนกัน — มีบรรทัดไหนสลับเป็นสินค้าตัวอื่น
     * (ราคา × จำนวนเท่ากัน) ที่ถูกพูดถึงได้ แปลว่ายอดประกอบได้หลายแบบ
     *
     * @param  array<int, array{name: string, total: string, qty: int}>  $items
     * @param  Collection<int, ProductStock>  $products
     * @return array{sets: array<int, array<int, array{name: string, total: string, qty: int}>>, ambiguous: bool}
     */
    private function analyzeAmbiguity(array $items, Collection $products, string $haystack): array
    {
        // หลายรายการ: ไม่สร้างปุ่มสลับ (ความเป็นไปได้บานปลาย) — คืนชุดเดียวคือ items ที่ LLM สรุป
        // เพื่อให้การ์ดยังมีปุ่มกดยืนยันออเดอร์ชุดนี้ได้ (หลังเจ้าของเปิดแชทตรวจแล้ว)
        // ตัดสินกำกวมทีละบรรทัดด้วย hasSamePriceSibling
        if (count($items) !== 1) {
            foreach ($items as $line) {
                if ($this->hasSamePriceSibling($line, $products, $haystack)) {
                    return ['sets' => [$items], 'ambiguous' => true];
                }
            }

            return ['sets' => [$items], 'ambiguous' => false];
        }

        // รายการเดียว: หาชุดสลับทีละตัว — ชุดที่ LLM เลือกอยู่ตำแหน่งแรกเสมอ
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
            if (! $this->mentioned($product, $haystack)) {
                continue;
            }
            $sets[] = [['name' => $product->name, 'total' => $chosen['total'], 'qty' => $chosen['qty']]];
        }

        return ['sets' => $sets, 'ambiguous' => count($sets) > 1];
    }

    /**
     * บรรทัดนี้สลับเป็นสินค้าตัวอื่น (ราคา × จำนวนของบรรทัดเท่ากัน) ที่ถูกพูดถึงในแชทได้ไหม
     * ใช้ตัดสินความกำกวมของออเดอร์หลายรายการ
     *
     * @param  array{name: string, total: string, qty: int}  $line
     * @param  Collection<int, ProductStock>  $products
     */
    private function hasSamePriceSibling(array $line, Collection $products, string $haystack): bool
    {
        foreach ($products as $product) {
            if ($product->name === $line['name']) {
                continue;
            }
            $lineTotal = (float) $product->price * $line['qty'];
            if (abs($lineTotal - (float) $line['total']) > 0.001) {
                continue;
            }
            if ($this->mentioned($product, $haystack)) {
                return true;
            }
        }

        return false;
    }
}
