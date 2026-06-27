<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VipControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_customers_with_vip_auto_notes(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create(['display_name' => 'Alice']);
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [[
                'id' => '00000000-0000-0000-0000-000000000001',
                'content' => 'ลูกค้า VIP — ซื้อยืนยันแล้ว 3 ครั้ง',
                'type' => 'memory',
                'source' => 'vip_auto',
                'created_by' => null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
        ]);
        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'total_amount' => 1000,
        ]);

        $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

        $response->assertOk();
        $response->assertJsonFragment(['display_name' => 'Alice']);
        $response->assertJsonFragment(['note_source' => 'vip_auto']);
    }

    public function test_index_collapses_order_aggregate_into_single_query(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $vipNote = fn (string $id) => [[
            'id' => $id,
            'content' => 'VIP',
            'type' => 'memory',
            'source' => 'vip_auto',
            'created_by' => null,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]];

        $alice = CustomerProfile::factory()->create(['display_name' => 'Alice']);
        $convA = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $alice->id,
            'memory_notes' => $vipNote('00000000-0000-0000-0000-0000000000a1'),
        ]);
        Order::factory()->count(2)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $convA->id,
            'customer_profile_id' => $alice->id,
            'status' => 'completed',
            'total_amount' => 500,
        ]);

        $bob = CustomerProfile::factory()->create(['display_name' => 'Bob']);
        $convB = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $bob->id,
            'memory_notes' => $vipNote('00000000-0000-0000-0000-0000000000b1'),
        ]);
        Order::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'conversation_id' => $convB->id,
            'customer_profile_id' => $bob->id,
            'status' => 'completed',
            'total_amount' => 1000,
        ]);

        $orderQueries = 0;
        DB::listen(function ($query) use (&$orderQueries) {
            if (str_contains($query->sql, 'from "orders"')) {
                $orderQueries++;
            }
        });

        $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $response->assertOk();

        // Correct per-customer totals must survive the refactor (assert per object, not loose fragments).
        $data = collect($response->json('data'));
        $alice = $data->firstWhere('display_name', 'Alice');
        $bob = $data->firstWhere('display_name', 'Bob');

        $this->assertNotNull($alice, 'Alice row missing from response');
        $this->assertNotNull($bob, 'Bob row missing from response');
        $this->assertSame(2, $alice['order_count']);
        // total_amount is cast to float in the controller but a whole-number float serializes to
        // JSON as 1000 and decodes back to a PHP int — assert against the true type the API returns.
        $this->assertSame(1000, $alice['total_amount']);
        $this->assertSame(3, $bob['order_count']);
        $this->assertSame(3000, $bob['total_amount']);

        // The loop must collapse to ONE grouped Order query, not one per VIP conversation.
        $this->assertSame(1, $orderQueries, "Expected a single grouped orders query, got $orderQueries");
    }

    public function test_index_rejects_unauthorized_users(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $intruder = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($intruder);

        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

        $response->assertForbidden();
    }

    public function test_revoke_removes_vip_auto_note(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [[
                'id' => '00000000-0000-0000-0000-000000000002',
                'content' => 'ลูกค้า VIP',
                'type' => 'memory',
                'source' => 'vip_auto',
                'created_by' => null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/vip/customers/{$customer->id}/revoke");

        $response->assertOk();
        $this->assertEmpty($conv->fresh()->memory_notes);
    }

    public function test_promote_creates_vip_manual_note(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
            'memory_notes' => [],
        ]);

        $response = $this->postJson("/api/bots/{$bot->id}/vip/customers/{$customer->id}/promote", [
            'content' => 'ลูกค้า VIP (ตั้งด้วย admin)',
        ]);

        $response->assertOk();
        $notes = $conv->fresh()->memory_notes;
        $this->assertCount(1, $notes);
        $this->assertEquals('vip_manual', $notes[0]['source']);
        $this->assertEquals('ลูกค้า VIP (ตั้งด้วย admin)', $notes[0]['content']);
    }

    public function test_revoke_rejects_customer_from_another_bot(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $myBot = Bot::factory()->create(['user_id' => $owner->id]);
        $otherUser = User::factory()->create(['role' => 'owner']);
        $otherBot = Bot::factory()->create(['user_id' => $otherUser->id]);

        $foreignCustomer = CustomerProfile::factory()->create();
        Conversation::factory()->create([
            'bot_id' => $otherBot->id,
            'customer_profile_id' => $foreignCustomer->id,
            'memory_notes' => [[
                'id' => '00000000-0000-0000-0000-000000000009',
                'content' => 'foreign vip',
                'type' => 'memory',
                'source' => 'vip_auto',
                'created_by' => null,
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
        ]);

        $response = $this->postJson("/api/bots/{$myBot->id}/vip/customers/{$foreignCustomer->id}/revoke");
        $response->assertNotFound();
    }

    public function test_promote_rejects_customer_from_another_bot(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $myBot = Bot::factory()->create(['user_id' => $owner->id]);
        $otherUser = User::factory()->create(['role' => 'owner']);
        $otherBot = Bot::factory()->create(['user_id' => $otherUser->id]);

        $foreignCustomer = CustomerProfile::factory()->create();
        Conversation::factory()->create([
            'bot_id' => $otherBot->id,
            'customer_profile_id' => $foreignCustomer->id,
        ]);

        $response = $this->postJson("/api/bots/{$myBot->id}/vip/customers/{$foreignCustomer->id}/promote", [
            'content' => 'hello',
        ]);
        $response->assertNotFound();
    }

    public function test_promote_rejects_content_longer_than_max(): void
    {
        $user = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($user);

        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $customer = CustomerProfile::factory()->create();
        Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);

        $tooLong = str_repeat('x', 2001);
        $response = $this->postJson("/api/bots/{$bot->id}/vip/customers/{$customer->id}/promote", [
            'content' => $tooLong,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('content');
    }
}
