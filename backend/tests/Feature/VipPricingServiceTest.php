<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\VipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VipPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private VipPricingService $service;

    private ProductStock $personal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(VipPricingService::class);
        $this->personal = ProductStock::create([
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
    }

    public function test_uses_explicit_auto_vip_source_even_when_note_content_has_no_vip_word(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [[
            'type' => 'memory',
            'source' => 'vip_auto',
            'content' => 'ซื้อยืนยันแล้ว 3 ครั้ง',
        ]];

        $this->assertTrue($this->service->isVipConversation($conversation));
        $this->assertSame(1000.0, $this->service->effectivePrice($this->personal, true));
    }

    public function test_non_vip_keeps_normal_price(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [];

        $this->assertFalse($this->service->isVipConversation($conversation));
        $this->assertSame(1100.0, $this->service->effectivePrice($this->personal, false));
    }

    public function test_untrusted_note_text_cannot_grant_vip_price(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [[
            'type' => 'memory',
            'source' => 'auto_entity_extraction',
            'content' => 'ชื่อลูกค้า: VIP',
        ]];

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    public function test_new_conversation_inherits_vip_status_from_same_customer_profile(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $profile = CustomerProfile::factory()->create();

        Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'memory_notes' => [[
                'type' => 'memory',
                'source' => 'vip_manual',
                'content' => 'ดูแลเป็นพิเศษ',
            ]],
        ]);

        $newConversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'memory_notes' => [],
        ]);

        $this->assertTrue($this->service->isVipConversation($newConversation));
    }

    public function test_new_conversation_does_not_inherit_vip_from_another_bot(): void
    {
        $user = User::factory()->create();
        $vipBot = Bot::factory()->create(['user_id' => $user->id]);
        $otherBot = Bot::factory()->create(['user_id' => $user->id]);
        $profile = CustomerProfile::factory()->create();

        Conversation::factory()->create([
            'bot_id' => $vipBot->id,
            'customer_profile_id' => $profile->id,
            'memory_notes' => [[
                'type' => 'memory',
                'source' => 'vip_auto',
                'content' => 'ซื้อยืนยันแล้ว 3 ครั้ง',
            ]],
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $otherBot->id,
            'customer_profile_id' => $profile->id,
            'memory_notes' => [],
        ]);

        $this->assertFalse($this->service->isVipConversation($conversation));
    }

    public function test_builds_authoritative_customer_visible_pricing_instruction_for_vip(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [[
            'type' => 'memory',
            'source' => 'vip_auto',
            'content' => 'ลูกค้าประจำ',
        ]];

        $block = $this->service->buildPromptBlock($conversation, collect([$this->personal]));

        $this->assertStringContainsString('VIP PRICING', $block);
        $this->assertStringContainsString('NLMP', $block);
        $this->assertStringContainsString('1,000 บาท/ตัว', $block);
        $this->assertStringContainsString('1,100 บาท', $block);
        $this->assertStringContainsString('มอบสิทธิ', $block);
    }

    public function test_does_not_inject_vip_pricing_for_non_vip(): void
    {
        $conversation = new Conversation;
        $conversation->memory_notes = [];

        $this->assertSame('', $this->service->buildPromptBlock($conversation, collect([$this->personal])));
    }

    public function test_decorates_relevant_vip_order_with_customer_visible_benefit(): void
    {
        $data = $this->service->addFlexBenefit([
            'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1000', 'qty' => 1]],
            'total' => '1000',
        ], true, collect([$this->personal]));

        $this->assertSame(
            '👑 ทางร้านมอบสิทธิ VIP ราคา 1,000 บาท/ตัว (ราคาปกติ 1,100 บาท)',
            $data['vip_benefit']
        );
    }

    public function test_decorates_verify_items_that_are_plain_strings(): void
    {
        $data = $this->service->addFlexBenefit(
            ['items' => ['Nolimit Level Up+ Personal x1']],
            true,
            collect([$this->personal]),
            requireCanonicalItemPrices: false
        );

        $this->assertStringContainsString('มอบสิทธิ VIP', $data['vip_benefit']);
        $this->assertStringContainsString('1,000 บาท/ตัว', $data['vip_benefit']);
    }

    public function test_does_not_add_benefit_for_non_vip_or_unrelated_product(): void
    {
        $normal = $this->service->addFlexBenefit([
            'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100']],
        ], false, collect([$this->personal]));
        $unrelated = $this->service->addFlexBenefit([
            'items' => [['name' => 'G3D', 'total' => '50']],
        ], true, collect([$this->personal]));

        $this->assertArrayNotHasKey('vip_benefit', $normal);
        $this->assertArrayNotHasKey('vip_benefit', $unrelated);
    }

    public function test_does_not_advertise_vip_benefit_beside_stale_normal_price(): void
    {
        $data = $this->service->addFlexBenefit([
            'items' => [['name' => 'Nolimit Level Up+ Personal', 'total' => '1100', 'qty' => 1]],
            'total' => '1100',
        ], true, collect([$this->personal]));

        $this->assertArrayNotHasKey('vip_benefit', $data);
    }
}
