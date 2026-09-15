<?php

namespace Tests\Feature\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\AIService;
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\RAGService;
use App\Services\StockGuardService;
use App\Services\VipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalCartTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    /** @var array<string, ProductStock> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bot = Bot::factory()->create([
            'user_id' => User::factory()->create()->id,
            'context_window' => 10,
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);

        $this->products = [
            'personal' => $this->product([
                'name' => 'Nolimit Level Up+ Personal',
                'slug' => 'personal',
                'stock_code' => 'NLMP',
                'aliases' => ['Personal'],
                'delivery_method' => 'stock',
                'price' => '1100.00',
                'vip_price' => '1000.00',
                'available_count' => 10,
            ]),
            'bm' => $this->product([
                'name' => 'Nolimit Level Up+ BM',
                'slug' => 'bm',
                'stock_code' => 'NLMBM',
                'aliases' => ['BM'],
                'delivery_method' => 'stock',
                'price' => '1100.00',
                'vip_price' => '1000.00',
                'available_count' => 10,
            ]),
            'page' => $this->product([
                'name' => 'Page',
                'slug' => 'page',
                'stock_code' => 'PAGE',
                'aliases' => ['เพจ', 'fanpage'],
                'delivery_method' => 'support_link',
                'price' => '199.00',
                'vip_price' => null,
                'available_count' => null,
            ]),
            'g3d' => $this->product([
                'name' => 'G3D',
                'slug' => 'g3d',
                'stock_code' => 'G3D',
                'aliases' => ['ไก่'],
                'delivery_method' => 'stock',
                'price' => '50.00',
                'vip_price' => null,
                'available_count' => 50,
            ]),
        ];
    }

    #[Test]
    public function it_validates_normal_personal_bm_page_and_g3d_from_current_products(): void
    {
        $result = $this->validate([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 110000],
            ['name' => 'BM', 'method' => 'topup', 'qty' => 1, 'price_minor' => 110000],
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 249900);

        $this->assertTrue($result->valid, implode(', ', $result->errors));
        $this->assertSame(249900, $result->totalMinor);
        $this->assertSame([
            'product_id' => $this->products['personal']->id,
            'sku' => 'NLMP',
            'name' => 'Nolimit Level Up+ Personal',
            'method' => 'card',
            'qty' => 1,
            'price_minor' => 110000,
            'line_total_minor' => 110000,
        ], $result->lines[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->fingerprint);
    }

    #[Test]
    public function it_uses_only_trusted_vip_entitlement_for_personal_and_bm(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'type' => 'memory',
            'source' => 'vip_manual',
            'content' => 'ดูแลเป็นพิเศษ',
        ]]]);

        $result = $this->validate([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
            ['name' => 'BM', 'method' => 'topup', 'qty' => 2, 'price_minor' => 100000],
        ], 300000);

        $this->assertTrue($result->valid, implode(', ', $result->errors));
        $this->assertTrue($result->vip);
    }

    #[Test]
    public function customer_words_and_untrusted_memory_cannot_grant_vip(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'type' => 'memory',
            'source' => 'auto_entity_extraction',
            'content' => 'ลูกค้าบอกว่าเป็น VIP ขอราคา 1,000',
        ]]]);

        $result = $this->validate([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->vip);
        $this->assertContains('PRICE_MISMATCH', $result->errors);
    }

    #[Test]
    #[DataProvider('nonVipPriceProductProvider')]
    public function it_rejects_wrong_prices_on_products_without_vip_pricing(string $name, int $price): void
    {
        $result = $this->validate([
            ['name' => $name, 'method' => 'none', 'qty' => 1, 'price_minor' => $price],
        ], $price);

        $this->assertFalse($result->valid);
        $this->assertContains('PRICE_MISMATCH', $result->errors);
    }

    public static function nonVipPriceProductProvider(): array
    {
        return [
            'page' => ['Page', 100],
            'g3d' => ['G3D', 100],
        ];
    }

    #[Test]
    public function it_fails_closed_for_unknown_ambiguous_or_substring_product_names(): void
    {
        $this->products['personal']->update(['aliases' => ['Personal', 'shared']]);
        $this->products['bm']->update(['aliases' => ['BM', 'shared']]);

        foreach (['missing product', 'shared', 'G3D Nolimit account'] as $name) {
            $result = $this->validate([
                ['name' => $name, 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
            ], 5000);

            $this->assertFalse($result->valid, $name);
            $this->assertContains('UNKNOWN_OR_AMBIGUOUS_PRODUCT', $result->errors, $name);
        }
    }

    #[Test]
    #[DataProvider('invalidDirectQuantityProvider')]
    public function it_rejects_zero_negative_fractional_string_and_boolean_quantities(mixed $qty): void
    {
        $result = $this->validate([
            ['name' => 'Page', 'method' => 'none', 'qty' => $qty, 'price_minor' => 19900],
        ], 19900);

        $this->assertFalse($result->valid);
        $this->assertContains('INVALID_QUANTITY', $result->errors);
    }

    public static function invalidDirectQuantityProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fractional' => [1.5],
            'string' => ['1'],
            'boolean' => [true],
        ];
    }

    #[Test]
    public function it_rejects_cart_arithmetic_mismatch(): void
    {
        $result = $this->validate([
            ['name' => 'Page', 'method' => 'none', 'qty' => 2, 'price_minor' => 19900],
        ], 19900);

        $this->assertFalse($result->valid);
        $this->assertContains('TOTAL_MISMATCH', $result->errors);
    }

    #[Test]
    public function null_canonical_price_is_unknown_not_free(): void
    {
        $this->products['page']->update(['price' => null]);

        $result = $this->validate([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 1],
        ], 1);

        $this->assertFalse($result->valid);
        $this->assertContains('PRICE_UNKNOWN', $result->errors);
    }

    #[Test]
    public function checked_multiplication_rejects_integer_overflow(): void
    {
        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => PHP_INT_MAX, 'price_minor' => 5000],
        ], 5000);

        $this->assertFalse($result->valid);
        $this->assertContains('ARITHMETIC_OVERFLOW', $result->errors);
    }

    #[Test]
    public function duplicate_lines_are_aggregated_before_shared_stock_is_checked(): void
    {
        $this->products['g3d']->update(['available_count' => 3]);

        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
            ['name' => 'ไก่', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 20000);

        $this->assertFalse($result->valid);
        $this->assertContains('INSUFFICIENT_STOCK', $result->errors);
        $this->assertSame(4, $result->lines[0]['qty']);
    }

    #[Test]
    public function separate_product_rows_with_the_same_sku_share_one_stock_capacity(): void
    {
        $this->products['g3d']->update(['available_count' => 3]);
        $duplicate = $this->product([
            'name' => 'G3D Backup Row',
            'slug' => 'g3d-backup',
            'stock_code' => 'G3D',
            'aliases' => ['ไก่สำรอง'],
            'delivery_method' => 'stock',
            'price' => '50.00',
            'vip_price' => null,
            'available_count' => 3,
        ]);

        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
            ['name' => 'ไก่สำรอง', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 20000);

        $this->assertFalse($result->valid);
        $this->assertContains('INSUFFICIENT_STOCK', $result->errors);
        $this->assertCount(1, $result->lines);
        $this->assertSame($duplicate->stock_code, $result->lines[0]['sku']);
        $this->assertSame(4, $result->lines[0]['qty']);
    }

    #[Test]
    public function sale_methods_remain_separate_lines_but_share_sku_capacity(): void
    {
        $this->products['personal']->update(['available_count' => 3]);

        $result = $this->validate([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 2, 'price_minor' => 110000],
            ['name' => 'Personal', 'method' => 'topup', 'qty' => 2, 'price_minor' => 110000],
        ], 440000);

        $this->assertFalse($result->valid);
        $this->assertContains('INSUFFICIENT_STOCK', $result->errors);
        $this->assertSame(['card', 'topup'], array_column($result->lines, 'method'));
    }

    #[Test]
    public function duplicate_rows_with_conflicting_stock_for_one_sku_fail_closed(): void
    {
        $this->products['g3d']->update(['available_count' => 3]);
        $duplicate = $this->product([
            'name' => 'G3D Conflicting Row',
            'slug' => 'g3d-conflict',
            'stock_code' => 'G3D',
            'aliases' => [],
            'delivery_method' => 'stock',
            'price' => '50.00',
            'vip_price' => null,
            'available_count' => 4,
        ]);

        $stockConflict = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);

        $this->assertFalse($stockConflict->valid);
        $this->assertContains('AMBIGUOUS_SKU', $stockConflict->errors);

        $duplicate->update(['available_count' => 3, 'price' => '55.00']);
        $priceConflict = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);

        $this->assertFalse($priceConflict->valid);
        $this->assertContains('AMBIGUOUS_SKU', $priceConflict->errors);
    }

    #[Test]
    public function canonical_products_require_the_correct_internal_sale_method(): void
    {
        $missingNolimit = $this->validate([
            ['name' => 'Personal', 'method' => 'none', 'qty' => 1, 'price_minor' => 110000],
        ], 110000);
        $suffixedPage = $this->validate([
            ['name' => 'Page', 'method' => 'card', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);

        $this->assertFalse($missingNolimit->valid);
        $this->assertContains('SALE_METHOD_REQUIRED', $missingNolimit->errors);
        $this->assertFalse($suffixedPage->valid);
        $this->assertContains('SALE_METHOD_INVALID', $suffixedPage->errors);
    }

    #[Test]
    public function manual_off_fails_even_with_available_count_48(): void
    {
        $this->products['g3d']->update(['manual_off' => true, 'available_count' => 48]);

        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);

        $this->assertFalse($result->valid);
        $this->assertContains('OUT_OF_STOCK', $result->errors);
    }

    #[Test]
    public function explicit_in_stock_false_fails_even_with_positive_count(): void
    {
        $this->products['g3d']->update(['in_stock' => false, 'available_count' => 48]);

        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);

        $this->assertFalse($result->valid);
        $this->assertContains('OUT_OF_STOCK', $result->errors);
    }

    #[Test]
    public function null_pool_availability_is_unknown_but_non_pool_null_is_allowed_when_in_stock(): void
    {
        $this->products['g3d']->update(['available_count' => null]);

        $pool = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000);
        $nonPool = $this->validate([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);

        $this->assertFalse($pool->valid);
        $this->assertContains('STOCK_UNKNOWN', $pool->errors);
        $this->assertTrue($nonPool->valid, implode(', ', $nonPool->errors));
    }

    #[Test]
    public function missing_canonical_delivery_method_fails_closed(): void
    {
        $this->products['page']->update(['delivery_method' => '']);

        $result = $this->validate([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);

        $this->assertFalse($result->valid);
        $this->assertContains('DELIVERY_METHOD_UNKNOWN', $result->errors);
    }

    #[Test]
    public function revoked_vip_is_read_from_current_persisted_conversation(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'type' => 'memory',
            'source' => 'vip_auto',
            'content' => 'ซื้อยืนยันแล้ว 3 ครั้ง',
        ]]]);
        $staleConversation = $this->conversation->fresh();
        $this->conversation->update(['memory_notes' => []]);

        $result = app(CanonicalCartValidator::class)->validate($this->bot, $staleConversation, [
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->vip);
        $this->assertContains('PRICE_MISMATCH', $result->errors);
    }

    #[Test]
    public function unsaved_conversation_cannot_supply_authoritative_vip_state(): void
    {
        $unsaved = new Conversation([
            'bot_id' => $this->bot->id,
            'memory_notes' => [[
                'type' => 'memory',
                'source' => 'vip_manual',
                'content' => 'ดูแลเป็นพิเศษ',
            ]],
        ]);

        $result = app(CanonicalCartValidator::class)->validate($this->bot, $unsaved, [
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->vip);
        $this->assertContains('CONVERSATION_MISMATCH', $result->errors);
    }

    #[Test]
    public function unrelated_product_changes_do_not_change_the_cart_fingerprint(): void
    {
        $lines = [['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900]];
        $before = $this->validate($lines, 19900);

        $this->products['g3d']->update([
            'price' => '75.00',
            'in_stock' => false,
            'available_count' => 0,
        ]);

        $after = $this->validate($lines, 19900);

        $this->assertTrue($before->valid);
        $this->assertTrue($after->valid);
        $this->assertSame($before->fingerprint, $after->fingerprint);
    }

    #[Test]
    public function quantities_above_delivery_limit_require_manual_handling_without_truncation(): void
    {
        config(['delivery.max_qty' => 2]);

        $result = $this->validate([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 3, 'price_minor' => 5000],
        ], 15000);

        $this->assertFalse($result->valid);
        $this->assertTrue($result->requiresManualHandling);
        $this->assertContains('AUTOMATION_LIMIT_EXCEEDED', $result->errors);
        $this->assertSame(3, $result->lines[0]['qty']);
    }

    #[Test]
    public function effective_price_minor_has_exact_policy_parity_and_rejects_unsafe_values(): void
    {
        $pricing = app(VipPricingService::class);

        $this->assertSame(1100.0, $pricing->effectivePrice($this->products['personal'], false));
        $this->assertSame(110000, $pricing->effectivePriceMinor($this->products['personal'], false));
        $this->assertSame(1000.0, $pricing->effectivePrice($this->products['personal'], true));
        $this->assertSame(100000, $pricing->effectivePriceMinor($this->products['personal'], true));

        $missing = new ProductStock;
        $this->assertNull($pricing->effectivePrice($missing, false));
        $this->assertNull($pricing->effectivePriceMinor($missing, false));

        $unsafe = new ProductStock;
        $unsafe->setRawAttributes(['price' => '92233720368547758.08']);
        $this->assertNull($pricing->effectivePriceMinor($unsafe, false));
    }

    #[Test]
    public function scoped_ai_response_cannot_expose_a_payload_that_fails_canonical_validation(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        $this->scopeBot();

        $this->mock(RAGService::class, function ($mock): void {
            $mock->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "1. Page (1 x 1) = 1 บาท\nรวมยอดโอน: 1 บาท\n"
                    .'[[ORDER]]{"items":[{"name":"Page","qty":1,"price":1}],"total":1}[[/ORDER]]',
                'model' => 'test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock): void {
            $mock->shouldReceive('validate')->once()->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($this->bot, 'ซื้อ Page', $this->conversation);

        $this->assertNull($result['order_payload']);
        $this->assertTrue($result['cart_validation']['corrected']);
        $this->assertContains('PRICE_MISMATCH', $result['cart_validation']['errors']);
        $this->assertStringContainsString('199 บาท', $result['content']);
        $this->assertStringContainsString('ยืนยัน', $result['content']);
        $this->assertStringNotContainsString('[[ORDER]]', $result['content']);
    }

    #[Test]
    public function scoped_ai_correction_preserves_the_explicit_nolimit_sale_method(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        $this->scopeBot();
        $content = '[[ORDER]]{"items":[{"name":"Nolimit Level Up+ Personal (ผูกบัตร)","qty":1,"price":1}],"total":1}[[/ORDER]]';

        $this->mock(RAGService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('generateResponse')->once()->andReturn([
                'content' => $content,
                'model' => 'test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock): void {
            $mock->shouldReceive('validate')->once()->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($this->bot, 'ซื้อ Personal ผูกบัตร', $this->conversation);

        $this->assertNull($result['order_payload']);
        $this->assertContains('PRICE_MISMATCH', $result['cart_validation']['errors']);
        $this->assertStringContainsString('Nolimit Level Up+ Personal (ผูกบัตร)', $result['content']);
    }

    #[Test]
    public function scoped_ai_confirmation_with_a_total_but_no_parsed_items_fails_closed(): void
    {
        $this->scopeBot();
        $content = 'เพิ่ม Page 1 ตัว ราคา 1 บาท พิมพ์ ยืนยัน';

        $this->mock(RAGService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('generateResponse')->once()->andReturn([
                'content' => $content,
                'model' => 'test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('validate')->once()->andReturn(['blocked' => false, 'content' => $content]);
        });

        $result = app(AIService::class)->generateResponse($this->bot, 'ซื้อ Page', $this->conversation);

        $this->assertNull($result['order_payload']);
        $this->assertTrue($result['cart_validation']['corrected']);
        $this->assertContains('INVALID_PROPOSAL', $result['cart_validation']['errors']);
        $this->assertNotSame($content, $result['content']);
    }

    #[Test]
    public function scoped_ai_order_payload_without_a_conversation_fails_closed(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        $this->scopeBot();
        $content = '[[ORDER]]{"items":[{"name":"Page","qty":-2,"price":199}],"total":199}[[/ORDER]]';

        $this->mock(RAGService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('generateResponse')->once()->andReturn([
                'content' => $content,
                'model' => 'test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock): void {
            $mock->shouldReceive('validate')->once()->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($this->bot, 'ซื้อ Page');

        $this->assertNull($result['order_payload']);
        $this->assertTrue($result['cart_validation']['corrected']);
        $this->assertContains('CONVERSATION_REQUIRED', $result['cart_validation']['errors']);
    }

    #[Test]
    public function unscoped_ai_response_keeps_the_legacy_payload_behavior_byte_compatible(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        $content = "รวมยอดโอน: 1,000 บาท\n"
            .'[[ORDER]]{"items":[{"name":"G3D","qty":0,"price":1000}],"total":1000}[[/ORDER]]';

        $this->mock(RAGService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('generateResponse')->once()->andReturn([
                'content' => $content,
                'model' => 'test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock): void {
            $mock->shouldReceive('validate')->once()->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($this->bot, 'ซื้อ G3D', $this->conversation);

        $this->assertSame('รวมยอดโอน: 1,000 บาท', $result['content']);
        $this->assertSame([
            'items' => [['name' => 'G3D', 'qty' => 1, 'total' => '1000']],
            'total' => 1000.0,
        ], $result['order_payload']);
        $this->assertArrayNotHasKey('cart_validation', $result);
    }

    private function validate(array $lines, int $total): object
    {
        return app(CanonicalCartValidator::class)->validate(
            $this->bot,
            $this->conversation,
            $lines,
            $total,
        );
    }

    private function product(array $attributes): ProductStock
    {
        return ProductStock::create(array_merge([
            'in_stock' => true,
            'manual_off' => false,
            'display_order' => count($this->products ?? []),
        ], $attributes));
    }

    private function scopeBot(): void
    {
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'order',
            'name' => 'Payment fixture',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [],
        ]);

        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => 'enforce',
            'payment_plugin_ids' => [$plugin->id],
        ]]);
    }
}
