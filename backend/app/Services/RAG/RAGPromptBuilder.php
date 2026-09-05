<?php

namespace App\Services\RAG;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Order;
use App\Services\MultipleBubblesService;
use App\Services\StockInjectionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * System-prompt assembly split out of RAGService (Track 3). Bodies are moved
 * verbatim.
 */
class RAGPromptBuilder
{
    public function __construct(
        private readonly StockInjectionService $stockInjectionService,
    ) {}

    /**
     * Build enhanced system prompt with memory notes, KB context, and multiple bubbles instruction.
     */
    public function buildEnhancedPrompt(
        string $basePrompt,
        string $kbContext,
        ?Bot $bot = null,
        array $memoryNotes = [],
        string $purchaseHistoryBlock = ''
    ): string {
        // Static persona leads so it forms a stable, cacheable prefix for
        // OpenRouter/gemini prefix caching. Dynamic memory/stock/KB come AFTER.
        $prompt = $basePrompt;

        if (! empty($memoryNotes)) {
            $prompt .= "\n\n## Memory (ประวัติสะสมของลูกค้าจากออเดอร์เก่า):\n";
            $prompt .= "ใช้เพื่อจดจำสินค้า/พฤติกรรมที่เคยซื้อเท่านั้น ตัวเลขจำนวน (x1, x2) คือยอดสะสมในอดีต ห้ามนำไปรวมหรือนับเป็นจำนวนของออเดอร์ใหม่\n";
            foreach ($memoryNotes as $content) {
                $prompt .= "- {$content}\n";
            }
            $prompt .= "---\n";
        }

        // ประวัติการซื้อจากตาราง orders จริง — วางก่อน stock เสมอ เพราะ stock reminder อยู่ท้าย
        // prompt (ใกล้ข้อความลูกค้าที่สุด = LLM ให้น้ำหนักสูงสุด) กฎสต็อกจึงทับประวัติได้ตามตำแหน่ง
        if ($purchaseHistoryBlock !== '') {
            $prompt .= "\n\n".$purchaseHistoryBlock;
        }

        // Always inject stock — conditional injection caused sales of out-of-stock products
        $stocks = $this->stockInjectionService->getStockStatus();
        $hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();
        // มีจำนวนคงเหลือให้คุมโควตาการขาย แม้ของจะยังไม่หมดก็ต้องฉีด (กันรับออเดอร์เกิน stock)
        $hasQty = $this->stockInjectionService->hasQtyToEnforce($stocks);

        if ($hasOutOfStock || $hasQty) {
            $stockInjection = $this->stockInjectionService->buildStockInjection($stocks);
            if (! empty($stockInjection)) {
                $prompt .= "\n\n".$stockInjection;
            }
        }

        if (! empty($kbContext)) {
            $prompt .= "\n\n".$kbContext;
        }

        if ($bot) {
            $bubblesService = app(MultipleBubblesService::class);
            $instruction = $bubblesService->buildPromptInstruction($bot);
            if (! empty($instruction)) {
                $prompt .= "\n".$instruction;
            }
        }

        // Stock reminder at END of prompt — closest to user message = highest LLM attention
        if ($hasOutOfStock || $hasQty) {
            $stockReminder = $this->stockInjectionService->buildStockReminder($stocks);
            if (! empty($stockReminder)) {
                $prompt .= "\n\n".$stockReminder;
            }
        }

        return $prompt;
    }

    /**
     * บล็อกประวัติการซื้อล่าสุดของลูกค้า ดึงจากตาราง orders จริง (ไม่พึ่ง LLM จด)
     *
     * คืน '' เมื่อไม่มีข้อมูลหรือ query ล้มเหลว — ความจำเป็นของเสริม ห้ามทำให้บอทตอบลูกค้าไม่ได้
     */
    public function buildPurchaseHistoryBlock(?Conversation $conversation): string
    {
        $profileId = $conversation?->customer_profile_id;

        if (! $profileId) {
            return '';
        }

        try {
            $order = Order::query()
                ->where('customer_profile_id', $profileId)
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(90))
                // id เป็น tiebreaker กันกรณีลูกค้าซื้อหลายใบใน timestamp เดียวกัน
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->with('items')
                ->first();

            if (! $order || $order->items->isEmpty()) {
                return '';
            }

            $lines = $order->items
                ->map(function ($item) {
                    $variant = $item->variant ? " ({$item->variant})" : '';

                    return "- {$item->product_name}{$variant} x{$item->quantity}";
                })
                ->implode("\n");

            $date = $this->formatThaiDate($order->created_at);

            return "## ประวัติการซื้อล่าสุดของลูกค้ารายนี้ (ข้อมูลจากระบบออเดอร์จริง)\n"
                ."{$lines} — {$date}\n\n"
                ."วิธีใช้: ใช้เพื่อเข้าใจบริบทคำถามที่ต่อเนื่องจากของที่ลูกค้าถืออยู่เท่านั้น\n"
                ."⛔ ห้ามเอ่ยถึงประวัตินี้ก่อนที่ลูกค้าจะพูดถึงเอง ห้ามทักทายด้วยประวัติ\n"
                ."⛔ ตัวเลขจำนวนนี้เป็นของออเดอร์เก่า ห้ามนำไปรวมกับออเดอร์ใหม่\n"
                ."⛔ ถ้าสินค้าที่เคยซื้อหมดสต็อก ให้ทำตามกฎสต็อกและเสนอตัวแทน ห้ามยึดประวัติ\n"
                ."---\n";
        } catch (Throwable $e) {
            Log::warning('RAGService: buildPurchaseHistoryBlock failed', [
                'conversation_id' => $conversation?->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * แปลงวันที่เป็นไทยย่อ เช่น "15 ส.ค. 2026" — map เองไม่พึ่ง locale package
     * เพื่อให้ผลลัพธ์เหมือนกันทุกเครื่องและเทสต์ได้แน่นอน
     */
    private function formatThaiDate(\DateTimeInterface $date): string
    {
        $months = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
            7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];

        return (int) $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }

    /**
     * Inject stock status (header + reminder) around a prompt.
     *
     * Used by test/emulator endpoints that don't go through buildEnhancedPrompt().
     */
    public function injectStockStatus(string $prompt): string
    {
        return $this->stockInjectionService->injectStockStatus($prompt);
    }

    /**
     * Get system prompt for a bot with fallback chain:
     * 1. Bot's own system_prompt (if set)
     * 2. Default Flow's system_prompt (if bot has default_flow_id)
     * 3. Default system prompt
     */
    public function getSystemPromptForBot(Bot $bot): string
    {
        // 1. Use bot's own system_prompt if set
        if (! empty($bot->system_prompt)) {
            return $bot->system_prompt;
        }

        // 2. Use default flow's system_prompt if available
        if ($bot->default_flow_id) {
            $flow = Flow::find($bot->default_flow_id);
            if ($flow && ! empty($flow->system_prompt)) {
                Log::debug('Using system_prompt from Flow', [
                    'bot_id' => $bot->id,
                    'flow_id' => $flow->id,
                    'flow_name' => $flow->name,
                ]);

                return $flow->system_prompt;
            }
        }

        // 3. Fallback to default
        return $this->getDefaultSystemPrompt($bot);
    }

    public function getDefaultSystemPrompt(Bot $bot): string
    {
        return <<<PROMPT
You are a helpful AI assistant for {$bot->name}.
Be friendly, professional, and helpful.
Respond in the same language as the user's message.
If you don't know something, be honest about it.
Keep responses concise but informative.
PROMPT;
    }

    /**
     * Build Chain-of-Thought instruction to append to system prompt.
     *
     * Instructs the LLM to think step-by-step for complex questions.
     *
     * @param  string  $language  'thai' or 'english'
     * @return string The CoT instruction to append
     */
    public function buildChainOfThoughtInstruction(string $language = 'thai'): string
    {
        if ($language === 'thai') {
            return <<<'PROMPT'


## คำแนะนำสำหรับคำถามซับซ้อน
คำถามนี้ต้องการการวิเคราะห์อย่างละเอียด กรุณา:
1. **แยกประเด็น**: ระบุประเด็นสำคัญที่ต้องพิจารณา
2. **วิเคราะห์ทีละขั้น**: อธิบายเหตุผลหรือขั้นตอนอย่างชัดเจน
3. **สรุปคำตอบ**: ให้คำตอบที่ชัดเจนและครบถ้วน

PROMPT;
        }

        return <<<'PROMPT'


## Instructions for Complex Questions
This question requires detailed analysis. Please:
1. **Identify Key Points**: Break down the important aspects to consider
2. **Analyze Step by Step**: Explain your reasoning or process clearly
3. **Provide Conclusion**: Give a clear and comprehensive answer

PROMPT;
    }
}
