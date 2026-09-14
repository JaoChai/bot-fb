<?php

namespace Tests\Unit\Services\RAG;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\RAG\RAGPromptBuilder;
use App\Services\StockInjectionService;
use App\Services\VipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RAGPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private RAGPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new RAGPromptBuilder(
            app(StockInjectionService::class),
            app(VipPricingService::class),
        );
    }

    public function test_system_prompt_prefers_bot_prompt_then_default(): void
    {
        $user = User::factory()->create();
        $withPrompt = Bot::factory()->create(['user_id' => $user->id, 'system_prompt' => 'You are Bot A.']);
        $withoutPrompt = Bot::factory()->create(['user_id' => $user->id, 'system_prompt' => null, 'default_flow_id' => null, 'name' => 'Shop B']);

        $this->assertSame('You are Bot A.', $this->builder->getSystemPromptForBot($withPrompt));
        $this->assertStringContainsString('helpful AI assistant for Shop B', $this->builder->getSystemPromptForBot($withoutPrompt));
    }

    public function test_chain_of_thought_instruction_is_localised(): void
    {
        $this->assertStringContainsString('คำถามซับซ้อน', $this->builder->buildChainOfThoughtInstruction('thai'));
        $this->assertStringContainsString('Complex Questions', $this->builder->buildChainOfThoughtInstruction('english'));
    }

    public function test_enhanced_prompt_places_base_before_kb_context(): void
    {
        $prompt = $this->builder->buildEnhancedPrompt('BASE PERSONA', 'KB CONTEXT');

        $this->assertLessThan(strpos($prompt, 'KB CONTEXT'), strpos($prompt, 'BASE PERSONA'));
    }

    public function test_enhanced_prompt_places_authoritative_vip_price_after_kb_context(): void
    {
        ProductStock::create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'stock_code' => 'NLMP',
            'aliases' => ['Personal'],
            'in_stock' => true,
            'display_order' => 1,
            'delivery_method' => 'stock',
            'price' => 1100,
            'vip_price' => 1000,
        ]);
        $conversation = new Conversation;
        $conversation->memory_notes = [[
            'type' => 'memory',
            'source' => 'vip_auto',
            'content' => 'ซื้อยืนยันแล้ว 3 ครั้ง',
        ]];

        $prompt = $this->builder->buildEnhancedPrompt(
            'BASE',
            'KB PRICE 1,100',
            conversation: $conversation,
        );

        $this->assertStringContainsString('VIP PRICING', $prompt);
        $this->assertStringContainsString('1,000 บาท/ตัว', $prompt);
        $this->assertGreaterThan(strpos($prompt, 'KB PRICE 1,100'), strpos($prompt, 'VIP PRICING'));
    }

    public function test_purchase_history_block_is_empty_without_conversation(): void
    {
        $this->assertSame('', $this->builder->buildPurchaseHistoryBlock(null));
    }
}
