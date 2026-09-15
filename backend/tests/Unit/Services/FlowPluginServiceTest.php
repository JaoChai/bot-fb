<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\PaymentEffectDispatcher;
use App\Services\CommerceSafety\PaymentEffectFailure;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\FlowPluginService;
use App\Services\OpenRouterService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class FlowPluginServiceTest extends TestCase
{
    use RefreshDatabase;

    private FlowPluginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $openRouter = $this->createStub(OpenRouterService::class);
        $this->service = new FlowPluginService($openRouter);
    }

    #[DataProvider('verifiedTransportOutcomes')]
    public function test_verified_transport_uses_authorized_values_and_exposes_typed_outcomes(string $outcome, ?bool $ambiguous): void
    {
        Http::preventStrayRequests();
        $event = new VerifiedPaymentEvent;
        $event->forceFill(['id' => 'fixture', 'bot_id' => 26, 'amount_minor' => 19901, 'source' => 'easyslip', 'created_at' => now()]);
        $conversation = new Conversation;
        $conversation->setRelation('customerProfile', null);
        $checkout = new CheckoutSession;
        $checkout->forceFill(['items' => [['name' => '<Page>', 'qty' => 2]]]);
        $event->setRelations(['bot' => new Bot, 'conversation' => $conversation, 'checkout' => $checkout]);
        $plugin = new FlowPlugin;
        $plugin->forceFill(['id' => 1, 'config' => ['access_token' => 'fixture', 'chat_id' => 'finance',
            'message_template' => '{amount} {product} {source_bank} {unknown}']]);
        $dispatcher = $this->mock(PaymentEffectDispatcher::class);
        $dispatcher->shouldReceive('authority')->once()->with('fixture')->andReturn($event);
        $dispatcher->shouldReceive('configuredPlugin')->once()->andReturn($plugin);
        $this->mock(SafetyScope::class)->shouldReceive('mode')->once()->andReturn('enforce');
        $calls = 0;
        $marked = false;
        Http::fake(function ($request) use ($outcome, &$calls, &$marked) {
            $calls++;
            $this->assertTrue($marked);
            $this->assertSame('finance', $request['chat_id']);
            $this->assertStringContainsString('199.01 &lt;Page&gt; x2 - -', $request['text']);
            if ($outcome === 'timeout') {
                throw new ConnectionException('secret transport detail');
            }

            return match ($outcome) {
                'success' => Http::response(['ok' => true, 'result' => ['message_id' => 10]]),
                'definite' => Http::response(['ok' => false], 400),
                'missing_id' => Http::response(['ok' => true]),
                default => Http::response('gateway error', 502),
            };
        });
        // Only the authority seam is stubbed here; the feature matrix validates its real DB scope checks.
        $manager = DB::getFacadeRoot();
        DB::swap(\Mockery::mock($manager)->shouldReceive('transactionLevel')->andReturn(0)->getMock());
        try {
            try {
                $this->service->sendVerifiedPayment($event, $outcome === 'plugin_mismatch' ? 2 : 1, function () use (&$marked) {
                    $marked = true;
                });
                $this->assertNull($ambiguous);
            } catch (PaymentEffectFailure $e) {
                $this->assertSame($ambiguous, $e->ambiguous);
                $this->assertStringNotContainsString('secret', $e->getMessage());
            }
            $this->assertSame($outcome === 'plugin_mismatch' ? 0 : 1, $calls);
        } finally {
            DB::swap($manager);
        }
    }

    public static function verifiedTransportOutcomes(): array
    {
        return [['success', null], ['definite', false], ['timeout', true], ['missing_id', true], ['server_error', true], ['plugin_mismatch', false]];
    }

    public function test_verified_transport_refuses_a_settlement_transaction(): void
    {
        Http::preventStrayRequests();
        $this->expectException(PaymentEffectFailure::class);
        $this->expectExceptionMessage('transport_inside_transaction');
        $this->service->sendVerifiedPayment(new VerifiedPaymentEvent, 1);
    }

    /**
     * Helper to call private methods via reflection.
     */
    private function callPrivate(string $method, ...$args): mixed
    {
        $reflection = new ReflectionClass($this->service);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    public function test_extract_fallback_amount(): void
    {
        $content = "เงินเข้าแล้ว 2,598.00 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ Personal 1 ตัว";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['amount']);

        $this->assertArrayHasKey('amount', $result);
        $this->assertEquals('2,598.00', $result['amount']);
    }

    public function test_extract_fallback_amount_without_decimal(): void
    {
        $content = 'เงินเข้าแล้ว 1,100 บาท ✅';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['amount']);

        $this->assertArrayHasKey('amount', $result);
        $this->assertEquals('1,100', $result['amount']);
    }

    public function test_normalize_amount_strips_trailing_baht(): void
    {
        // AI บางครั้งดึง amount ติด "บาท" มา — template มี "บาท" อยู่แล้ว (บั๊ก "1,100 บาท บาท")
        $this->assertEquals('1,100', $this->callPrivate('normalizeAmountVariable', '1,100 บาท'));
        $this->assertEquals('2,598.00', $this->callPrivate('normalizeAmountVariable', '2,598.00 บาท '));
        $this->assertEquals('800', $this->callPrivate('normalizeAmountVariable', '800'));
    }

    public function test_extract_fallback_product(): void
    {
        $content = "เงินเข้าแล้ว 1,100 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ Personal 1 ตัว\n\nส่งใน 5-10 นาที";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['product']);

        $this->assertArrayHasKey('product', $result);
        $this->assertEquals('Nolimit Level Up+ Personal 1 ตัว', $result['product']);
    }

    public function test_extract_fallback_product_multiple_items(): void
    {
        $content = "เงินเข้าแล้ว 3,200 บาท ✅\nออเดอร์:\n- Nolimit Level Up+ BM x1\n- Nolimit Page x1\n\nส่งใน 5-10 นาที";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['product']);

        $this->assertArrayHasKey('product', $result);
        $this->assertStringContainsString('Nolimit Level Up+ BM x1', $result['product']);
        $this->assertStringContainsString('Nolimit Page x1', $result['product']);
    }

    public function test_extract_fallback_source_bank(): void
    {
        $content = "ชำระผ่าน K PLUS ธนาคารกสิกรไทย\nเงินเข้าแล้ว 1,100 บาท ✅";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayHasKey('source_bank', $result);
        $this->assertEquals('กสิกรไทย (KBANK)', $result['source_bank']);
    }

    public function test_extract_fallback_scb_bank(): void
    {
        $content = 'โอนจาก SCB 1,100 บาท';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayHasKey('source_bank', $result);
        $this->assertEquals('ไทยพาณิชย์ (SCB)', $result['source_bank']);
    }

    public function test_unreplaced_variables_get_dash(): void
    {
        // Test the final cleanup pattern: {varName} → "-"
        $message = 'สินค้า: {product}, จำนวน: {quantity}';

        // Simulate what the service does: preg_replace remaining vars with dash
        $result = preg_replace('/\{(\w+)\}/', '-', $message);

        $this->assertEquals('สินค้า: -, จำนวน: -', $result);
        $this->assertStringNotContainsString('{product}', $result);
        $this->assertStringNotContainsString('{quantity}', $result);
    }

    public function test_extract_fallback_returns_empty_for_unknown_vars(): void
    {
        $content = 'เงินเข้าแล้ว 1,100 บาท ✅';

        $result = $this->callPrivate('extractVariablesFallback', $content, ['unknown_var']);

        $this->assertEmpty($result);
    }

    public function test_extract_fallback_no_bank_in_content(): void
    {
        $content = "เงินเข้าแล้ว 1,100 บาท ✅\nออเดอร์:\n- Product A";

        $result = $this->callPrivate('extractVariablesFallback', $content, ['source_bank']);

        $this->assertArrayNotHasKey('source_bank', $result);
    }

    public function test_product_breakdown_shows_quantity_per_type(): void
    {
        $result = $this->callPrivate('buildProductBreakdown', 'G3D x2, Page');

        $this->assertStringContainsString('<b>📦 รายการสินค้า</b>', $result);
        $this->assertStringContainsString('• G3D ×2', $result);
        $this->assertStringContainsString('• Page ×1', $result);
    }

    public function test_product_breakdown_groups_and_sums_same_type(): void
    {
        // Same type appearing twice should be summed, not duplicated
        $result = $this->callPrivate('buildProductBreakdown', 'ไก่ x2, เฟสไก่ x1');

        $this->assertStringContainsString('• G3D ×3', $result);
        $this->assertEquals(1, substr_count($result, 'G3D'));
    }

    public function test_product_breakdown_includes_variant(): void
    {
        $result = $this->callPrivate('buildProductBreakdown', 'BM ผูกบัตร x1');

        $this->assertStringContainsString('• Nolimit BM (ผูกบัตร) ×1', $result);
    }

    public function test_product_breakdown_empty_when_no_product(): void
    {
        $this->assertSame('', $this->callPrivate('buildProductBreakdown', null));
        $this->assertSame('', $this->callPrivate('buildProductBreakdown', ''));
    }

    public function test_parse_product_items_parses_quantity_and_normalizes(): void
    {
        $items = OrderService::parseProductItems('G3D x2, BM ผูกบัตร');

        $this->assertCount(2, $items);
        $this->assertSame('G3D', $items[0]['product_name']);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame('Nolimit BM', $items[1]['product_name']);
        $this->assertSame('ผูกบัตร', $items[1]['variant']);
        $this->assertSame(1, $items[1]['quantity']);
    }

    public function test_parse_product_items_reads_quantity_from_real_summary_format(): void
    {
        // รูปแบบที่ summary ผลิตจริง (ชื่อมี + และภาษาไทย) — ต้องอ่าน qty ครบทั้งการ์ด Telegram
        // และ order_items ที่ใช้ parser ตัวนี้ร่วมกัน
        $items = OrderService::parseProductItems('Nolimit Level Up+ BM x2, บริการเสริม Page x2');

        $this->assertCount(2, $items);
        $this->assertSame(2, $items[0]['quantity']);
        $this->assertSame(2, $items[1]['quantity']);
    }

    public function test_parse_product_items_empty_for_blank_input(): void
    {
        $this->assertSame([], OrderService::parseProductItems(null));
        $this->assertSame([], OrderService::parseProductItems(''));
        $this->assertSame([], OrderService::parseProductItems('   '));
    }

    // =========================================================================
    // Test: resolvedUtilityModel() call site (evaluateAndExecute)
    // =========================================================================

    public function test_evaluate_and_execute_uses_bot_utility_model_when_configured(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);

        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'anthropic/claude-3-haiku',
        ]);

        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $botMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'มีลูกค้าสอบถามครับ',
        ]);
        $plugin = new FlowPlugin([
            'type' => 'telegram',
            'trigger_condition' => 'ลูกค้าถามราคา',
            'config' => ['message_template' => 'test message'],
        ]);

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->with($this->anything(), 'anthropic/claude-3-haiku', $this->anything(), $this->anything(), $this->anything(), $this->anything())
            ->willReturn(['content' => '{"triggered": false}']);

        $service = new FlowPluginService($openRouter);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('evaluateAndExecute');
        $method->setAccessible(true);

        $method->invoke($service, $plugin, $bot, $conversation, $botMessage);
    }

    public function test_evaluate_and_execute_skips_llm_call_and_returns_false_when_bot_has_no_model_configured(): void
    {
        config(['services.openrouter.api_key' => 'test-key']);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $botMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'มีลูกค้าสอบถามครับ',
        ]);
        $plugin = new FlowPlugin([
            'type' => 'telegram',
            'trigger_condition' => 'ลูกค้าถามราคา',
            'config' => ['message_template' => 'test message'],
        ]);

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $service = new FlowPluginService($openRouter);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('evaluateAndExecute');
        $method->setAccessible(true);

        $result = $method->invoke($service, $plugin, $bot, $conversation, $botMessage);

        $this->assertFalse($result);
    }
}
