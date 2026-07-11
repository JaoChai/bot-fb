<?php

namespace App\Services\Payment;

use App\Models\Bot;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

/**
 * ชั้น 2 fallback: ดึงรายการสินค้าด้วย utility_model เมื่อ regex (PaymentMessageDetector)
 * เจอ total แต่ดึง items ไม่ได้ (prose ล้วน / หลายสินค้าบรรทัดเดียวไม่มีราคาต่อชิ้น).
 * ไม่ throw — ทุก error path คืน [] ให้ผู้เรียกใช้ items เดิม (ว่าง) แทน.
 */
class LLMOrderItemExtractor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
ดึงรายการสินค้าจากข้อความสรุปออเดอร์นี้ ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่น:
{"items":[{"name":"...", "qty":1, "total":"..."}]}

ตัวอย่างชื่อสินค้าจริงที่อาจเจอ: "Nolimit Level Up+ Personal", "Nolimit Level Up+ BM", "Page", "G3D"

กติกา:
- name: ชื่อสินค้าตามที่ปรากฏในข้อความ
- qty: จำนวนชิ้น (ถ้าไม่ระบุ ใช้ 1)
- total: ราคารวมของรายการนั้น (ตัวเลขล้วน ไม่มีคอมมา/บาท)
- ถ้าดึงรายการไม่ได้เลย ตอบ {"items":[]}
PROMPT;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @return array<int, array{name: string, qty: int, total: string}>
     */
    public function extract(string $orderSummaryText, Bot $bot): array
    {
        $model = $bot->resolvedUtilityModel();
        if ($model === null) {
            Log::debug('LLMOrderItemExtractor: no utility model configured, skipping', ['bot_id' => $bot->id]);

            return [];
        }

        $apiKey = $bot->user?->settings?->getOpenRouterApiKey();
        if (empty($apiKey)) {
            Log::debug('LLMOrderItemExtractor: no OpenRouter API key configured, skipping', ['bot_id' => $bot->id]);

            return [];
        }

        try {
            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $orderSummaryText],
                ],
                model: $model,
                temperature: 0.1,
                maxTokens: 300,
                useFallback: false,
                apiKeyOverride: $apiKey,
            );
        } catch (\Throwable $e) {
            Log::warning('LLMOrderItemExtractor: LLM call failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->parseItems($response['content'] ?? '');
    }

    /**
     * @return array<int, array{name: string, qty: int, total: string}>
     */
    private function parseItems(string $content): array
    {
        $content = trim($content);

        // ลอก ```json fences (เผื่อ LLM ห่อ markdown มาแม้จะสั่งห้ามในพรอมต์แล้ว)
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            Log::debug('LLMOrderItemExtractor: JSON parse failed or missing items', [
                'content' => substr($content, 0, 200),
            ]);

            return [];
        }

        $items = [];
        foreach ($decoded['items'] as $item) {
            if (! is_array($item) || empty($item['name']) || ! is_string($item['name'])) {
                continue;
            }

            $items[] = [
                'name' => trim($item['name']),
                'qty' => isset($item['qty']) ? (int) $item['qty'] : 1,
                'total' => isset($item['total']) ? (string) $item['total'] : '0',
            ];
        }

        return $items;
    }
}
