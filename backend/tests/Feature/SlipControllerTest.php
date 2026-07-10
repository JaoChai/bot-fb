<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlipControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_own_slips_with_summary(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed', 'amount' => 1500]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake', 'amount' => 999]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'api_error', 'amount' => null]);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.summary.total_amount_passed', 1500);
        $response->assertJsonPath('meta.summary.count_abnormal', 1);
        $response->assertJsonPath('meta.summary.count_system_error', 1);
    }

    public function test_non_owner_gets_403(): void
    {
        $member = User::factory()->admin()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/slips')->assertForbidden();
    }

    public function test_does_not_leak_other_users_slips(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        Sanctum::actingAs($owner);

        $otherBot = Bot::factory()->create(['user_id' => $other->id]);
        SlipVerification::factory()->create(['bot_id' => $otherBot->id, 'status' => 'passed']);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_filters_by_status_csv(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed']);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake']);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'duplicate']);

        $response = $this->getJson('/api/slips?status=fake,duplicate');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_summary_ignores_status_filter_but_list_is_filtered(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed', 'amount' => 1000]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake', 'amount' => 500]);

        $response = $this->getJson('/api/slips?status=fake');

        $response->assertOk();
        $response->assertJsonPath('meta.summary.total_amount_passed', 1000);
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_includes_customer_name_from_conversation(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $customer = CustomerProfile::factory()->create(['display_name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);
        SlipVerification::factory()->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'status' => 'passed',
        ]);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('data.0.customer_name', 'Alice');
    }

    public function test_manual_confirmed_counts_as_money_in_with_thai_label(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed', 'amount' => 1000]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'manual_confirmed', 'amount' => 300]);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        // เงินเข้ารวมทั้ง passed + manual_confirmed
        $response->assertJsonPath('meta.summary.total_amount_passed', 1300);
        // manual_confirmed ไม่ถูกนับเป็นผิดปกติ/error
        $response->assertJsonPath('meta.summary.count_abnormal', 0);
        $response->assertJsonPath('meta.summary.count_system_error', 0);
        // มี label ไทย (ไม่ใช่ raw string)
        $response->assertJsonFragment(['status' => 'manual_confirmed', 'status_label' => 'ยืนยันโดยแอดมิน']);
    }

    public function test_status_filter_money_in_group_includes_manual_confirmed(): void
    {
        $owner = User::factory()->owner()->create();
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed', 'amount' => 1000]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'manual_confirmed', 'amount' => 300]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake', 'amount' => 500]);

        // frontend ส่งกลุ่ม "เงินเข้า" เป็น csv passed,manual_confirmed
        $response = $this->getJson('/api/slips?status=passed,manual_confirmed');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }
}
