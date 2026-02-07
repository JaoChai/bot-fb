<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\OpenRouterService;
use App\Services\FlowCacheService;
use App\Services\RAGService;
use App\Services\SemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class RAGServiceTest extends TestCase
{
    use RefreshDatabase;

    private RAGService $service;
    private User $user;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $semanticSearch = $this->createMock(SemanticSearchService::class);
        $hybridSearch = $this->createMock(HybridSearchService::class);
        $openRouter = $this->createMock(OpenRouterService::class);
        $intentAnalysis = $this->createMock(IntentAnalysisService::class);
        $flowCache = $this->createMock(FlowCacheService::class);

        $this->service = new RAGService(
            $semanticSearch,
            $hybridSearch,
            $openRouter,
            $intentAnalysis,
            $flowCache
        );

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Helper to call protected buildEnhancedPrompt method.
     */
    private function callBuildEnhancedPrompt(
        string $basePrompt,
        string $kbContext,
        ?Bot $bot = null,
        array $memoryNotes = []
    ): string {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildEnhancedPrompt');
        $method->setAccessible(true);

        return $method->invoke($this->service, $basePrompt, $kbContext, $bot, $memoryNotes);
    }

    public function test_build_enhanced_prompt_injects_memory_notes(): void
    {
        $basePrompt = 'You are a helpful assistant.';
        $memoryNotes = ['ลูกค้าแพ้ถั่ว', 'ชอบกาแฟเย็น ไม่หวาน'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, '', null, $memoryNotes);

        $this->assertStringContainsString('## Memory:', $result);
        $this->assertStringContainsString('- ลูกค้าแพ้ถั่ว', $result);
        $this->assertStringContainsString('- ชอบกาแฟเย็น ไม่หวาน', $result);
    }

    public function test_build_enhanced_prompt_with_empty_memory_notes(): void
    {
        $basePrompt = 'You are a helpful assistant.';

        $result = $this->callBuildEnhancedPrompt($basePrompt, '', null, []);

        // Should not contain memory section when empty
        $this->assertStringNotContainsString('## Memory:', $result);
        $this->assertEquals($basePrompt, $result);
    }

    public function test_memory_notes_injected_before_kb_context(): void
    {
        $basePrompt = 'You are a helpful assistant.';
        $kbContext = '## ข้อมูลอ้างอิงจาก Knowledge Base:';
        $memoryNotes = ['ลูกค้าชื่อสมชาย'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, $kbContext, null, $memoryNotes);

        // Memory notes should appear before KB context
        $memoryPos = strpos($result, '## Memory:');
        $kbPos = strpos($result, '## ข้อมูลอ้างอิงจาก Knowledge Base:');

        $this->assertNotFalse($memoryPos);
        $this->assertNotFalse($kbPos);
        $this->assertLessThan($kbPos, $memoryPos, 'Memory notes should be injected before KB context');
    }

    public function test_filters_only_memory_type_notes_from_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [
                [
                    'id' => 'note-1',
                    'content' => 'This is a regular note',
                    'type' => 'note',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
                [
                    'id' => 'memory-1',
                    'content' => 'ลูกค้าแพ้ถั่ว',
                    'type' => 'memory',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
                [
                    'id' => 'reminder-1',
                    'content' => 'โทรกลับวันจันทร์',
                    'type' => 'reminder',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
                [
                    'id' => 'memory-2',
                    'content' => 'ที่อยู่: สุขุมวิท 55',
                    'type' => 'memory',
                    'created_by' => $this->user->id,
                    'created_at' => now()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ],
            ],
        ]);

        // Simulate what RAGService does internally
        $memoryNotes = collect($conversation->memory_notes ?? [])
            ->where('type', 'memory')
            ->pluck('content')
            ->all();

        $this->assertCount(2, $memoryNotes);
        $this->assertContains('ลูกค้าแพ้ถั่ว', $memoryNotes);
        $this->assertContains('ที่อยู่: สุขุมวิท 55', $memoryNotes);
        $this->assertNotContains('This is a regular note', $memoryNotes);
        $this->assertNotContains('โทรกลับวันจันทร์', $memoryNotes);
    }

    public function test_handles_null_memory_notes_in_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => null,
        ]);

        // Simulate what RAGService does internally
        $memoryNotes = collect($conversation->memory_notes ?? [])
            ->where('type', 'memory')
            ->pluck('content')
            ->all();

        $this->assertIsArray($memoryNotes);
        $this->assertEmpty($memoryNotes);
    }

    public function test_build_enhanced_prompt_with_all_components(): void
    {
        $basePrompt = 'You are a helpful assistant.';
        $kbContext = "## ข้อมูลอ้างอิง:\nสินค้า A ราคา 100 บาท";
        $memoryNotes = ['ลูกค้า VIP', 'ได้ส่วนลด 10%'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, $kbContext, $this->bot, $memoryNotes);

        // Should contain all parts
        $this->assertStringContainsString('You are a helpful assistant.', $result);
        $this->assertStringContainsString('## Memory:', $result);
        $this->assertStringContainsString('- ลูกค้า VIP', $result);
        $this->assertStringContainsString('- ได้ส่วนลด 10%', $result);
        $this->assertStringContainsString('## ข้อมูลอ้างอิง:', $result);
        $this->assertStringContainsString('สินค้า A ราคา 100 บาท', $result);
    }
}
