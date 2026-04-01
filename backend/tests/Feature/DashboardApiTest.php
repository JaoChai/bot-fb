<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_vip_total_spent_not_inflated_by_multiple_conversations(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $bot = Bot::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $customer = CustomerProfile::create([
            'external_id' => 'test-vip-customer',
            'display_name' => 'VIP Test',
            'channel_type' => 'line',
        ]);

        // Create 5 conversations for this customer (1 with VIP note)
        for ($i = 0; $i < 5; $i++) {
            Conversation::create([
                'bot_id' => $bot->id,
                'customer_profile_id' => $customer->id,
                'external_customer_id' => 'test-vip-customer',
                'channel_type' => 'line',
                'status' => 'active',
                'memory_notes' => $i === 0 ? json_encode(['vip' => true, 'note' => 'VIP customer']) : null,
            ]);
        }

        // Create 3 orders totaling 1500
        foreach ([500, 500, 500] as $amount) {
            Order::create([
                'bot_id' => $bot->id,
                'customer_profile_id' => $customer->id,
                'conversation_id' => Conversation::where('customer_profile_id', $customer->id)->first()->id,
                'total_amount' => $amount,
                'status' => 'completed',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary');

        $response->assertOk();
        $data = $response->json('data.summary');

        // VIP total spent should be 1500, NOT 1500 * 5 = 7500
        $this->assertEquals(1500, $data['vip_total_spent']);
        $this->assertEquals(1, $data['vip_customers']);
    }

    public function test_vip_with_zero_orders_returns_zero_spent(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        $bot = Bot::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $customer = CustomerProfile::create([
            'external_id' => 'test-vip-no-orders',
            'display_name' => 'VIP No Orders',
            'channel_type' => 'line',
        ]);

        Conversation::create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'external_customer_id' => 'test-vip-no-orders',
            'channel_type' => 'line',
            'status' => 'active',
            'memory_notes' => json_encode(['vip' => true]),
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary');

        $response->assertOk();
        $data = $response->json('data.summary');
        $this->assertEquals(0, $data['vip_total_spent']);
        $this->assertEquals(1, $data['vip_customers']);
    }

    public function test_dashboard_summary_returns_expected_fields(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'summary' => [
                    'total_bots',
                    'active_bots',
                    'total_conversations',
                    'active_conversations',
                    'handover_conversations',
                    'messages_today',
                    'messages_yesterday',
                    'vip_customers',
                    'vip_total_spent',
                ],
                'bots',
                'alerts',
                'recent_activity',
            ],
        ]);
    }

    public function test_dashboard_summary_with_no_bots(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary');

        $response->assertOk();
        $data = $response->json('data.summary');
        $this->assertEquals(0, $data['total_bots']);
        $this->assertEquals(0, $data['vip_total_spent']);
        $this->assertEquals(0, $data['messages_yesterday']);
    }
}
