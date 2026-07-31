<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\OrderReconstructor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderReconstructorTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'sk-test']);
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'utility_model' => 'openai/gpt-4o-mini']);

        ProductStock::create(['name' => 'Nolimit Level Up+ Personal', 'slug' => 'personal', 'aliases' => ['Personal'], 'in_stock' => true, 'display_order' => 1, 'delivery_method' => 'stock', 'price' => 1100]);
        ProductStock::create(['name' => 'Nolimit Level Up+ BM', 'slug' => 'bm', 'aliases' => ['BM'], 'in_stock' => true, 'display_order' => 2, 'delivery_method' => 'stock', 'price' => 1100]);
        ProductStock::create(['name' => 'G3D', 'slug' => 'g3d', 'aliases' => ['ไก่'], 'in_stock' => true, 'display_order' => 3, 'delivery_method' => 'stock', 'price' => 50]);
    }

    private function fakeLLM(string $json): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->andReturn(['content' => $json]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    private function expectNoLLMCall(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldNotReceive('chat');
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_reconstructs_order_when_amount_matches_and_product_named_in_chat(): void
    {
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Nolimit Level Up+ Personal 1 ตัวครับ'],
        ], 1100.0);

        $this->assertNotNull($result);
        $this->assertFalse($result->ambiguous);
        $this->assertSame(1100.0, $result->total);
        $this->assertSame('Nolimit Level Up+ Personal', $result->summary);
        $this->assertSame([['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]], $result->items);
    }

    public function test_multiplies_quantity_and_writes_it_into_the_summary(): void
    {
        $this->fakeLLM('{"items":[{"slug":"personal","qty":2}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal 2 ตัวครับ'],
        ], 2200.0);

        $this->assertNotNull($result);
        $this->assertSame('Nolimit Level Up+ Personal x2', $result->summary);
        $this->assertSame(2, $result->items[0]['qty']);
        $this->assertSame('2200', $result->items[0]['total']);
    }

    public function test_rejects_when_total_does_not_match_the_slip(): void
    {
        // LLM เดา 1 ตัว (1,100) แต่ลูกค้าโอน 1,500 → ห้ามเชื่อ
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1500.0);

        $this->assertNull($result);
    }

    public function test_rejects_product_never_mentioned_in_the_conversation(): void
    {
        // ยอดตรง 50 บาท แต่ไม่มีใครพูดถึง G3D เลย → LLM แต่งเอง ห้ามเชื่อ
        $this->fakeLLM('{"items":[{"slug":"g3d","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
        ], 50.0);

        $this->assertNull($result);
    }

    public function test_flags_ambiguous_when_another_mentioned_product_has_the_same_price(): void
    {
        // ในแชทพูดถึงทั้ง BM และ Personal ซึ่งราคาเท่ากัน 1,100 → ห้ามส่งของเอง
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'bot', 'content' => 'รอบนี้จัด Nolimit Level Up+ BM 1 ตัว เซ็ตเดิมเลยไหมครับ?'],
            ['sender' => 'user', 'content' => 'Nolimit Level Up+ Personal'],
        ], 1100.0);

        $this->assertNotNull($result);
        $this->assertTrue($result->ambiguous);
        $this->assertCount(2, $result->alternatives);
        $this->assertSame('Nolimit Level Up+ Personal', $result->alternatives[0][0]['name']);
        $this->assertSame('Nolimit Level Up+ BM', $result->alternatives[1][0]['name']);
    }

    public function test_rejects_slug_that_does_not_exist(): void
    {
        $this->fakeLLM('{"items":[{"slug":"ไม่มีสินค้านี้","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอาอันนั้นครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_rejects_product_that_is_out_of_stock(): void
    {
        ProductStock::where('slug', 'personal')->update(['in_stock' => false]);
        $this->fakeLLM('{"items":[{"slug":"personal","qty":1}],"confidence":"high"}');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_returns_null_and_never_calls_llm_without_a_utility_model(): void
    {
        $this->bot->update(['utility_model' => null]);
        $this->expectNoLLMCall();

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_survives_broken_llm_output(): void
    {
        $this->fakeLLM('ขอโทษครับ ผมไม่เข้าใจคำถาม');

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }

    public function test_survives_llm_exception(): void
    {
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('timeout'));
        $this->app->instance(OpenRouterService::class, $mock);

        $result = app(OrderReconstructor::class)->reconstruct($this->bot, [
            ['sender' => 'user', 'content' => 'เอา Personal ครับ'],
        ], 1100.0);

        $this->assertNull($result);
    }
}
