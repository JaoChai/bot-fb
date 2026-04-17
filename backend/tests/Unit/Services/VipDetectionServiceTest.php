<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\VipDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VipDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected VipDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VipDetectionService::class);
    }

    public function test_returns_false_when_customer_has_fewer_than_threshold_orders(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        Order::factory()->count(2)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);

        $result = $this->service->evaluateCustomer($customer);

        $this->assertFalse($result);
        $this->assertEmpty($conversation->fresh()->memory_notes ?? []);
    }

    public function test_creates_vip_memory_note_when_customer_has_three_or_more_orders(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create(['display_name' => 'John Doe']);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [],
        ]);

        $orders = Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'total_amount' => 1000,
        ]);

        foreach ($orders as $order) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_name' => 'Nolimit Personal',
                'category' => 'nolimit',
                'variant' => 'เติมเงิน',
                'quantity' => 1,
            ]);
        }

        $result = $this->service->evaluateCustomer($customer);

        $this->assertTrue($result);
        $notes = $conversation->fresh()->memory_notes;
        $this->assertCount(1, $notes);
        $this->assertEquals('memory', $notes[0]['type']);
        $this->assertEquals('vip_auto', $notes[0]['source']);
        $this->assertStringContainsString('ลูกค้า VIP', $notes[0]['content']);
        $this->assertStringContainsString('ซื้อยืนยันแล้ว 3 ครั้ง', $notes[0]['content']);
        $this->assertStringContainsString('Nolimit Personal', $notes[0]['content']);
    }

    public function test_ignores_orders_older_than_window(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        // 2 recent + 3 old (>12 months) — should NOT qualify
        Order::factory()->count(2)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subMonths(2),
        ]);
        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subMonths(13),
        ]);

        $this->assertFalse($this->service->evaluateCustomer($customer));
    }

    public function test_second_evaluation_updates_note_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);

        $this->service->evaluateCustomer($customer);

        // Add another order → re-evaluate
        Order::factory()->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);
        $this->service->evaluateCustomer($customer);

        $notes = $conversation->fresh()->memory_notes;
        $this->assertCount(1, $notes, 'Expected single vip_auto note after re-evaluation');
        $this->assertStringContainsString('ซื้อยืนยันแล้ว 4 ครั้ง', $notes[0]['content']);
    }

    public function test_handles_legacy_object_format_memory_notes(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        // Simulate legacy format: object instead of array
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => ['vip' => true, 'note' => 'old format'],
        ]);

        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
        ]);

        $result = $this->service->evaluateCustomer($customer);

        $this->assertTrue($result);
        $notes = $conversation->fresh()->memory_notes;
        $this->assertCount(1, $notes);
        $this->assertEquals('vip_auto', $notes[0]['source']);
    }
}
