<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualPaymentConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Plugin trigger evaluation needs an OpenRouter key (falls back to config).
        config(['services.openrouter.api_key' => 'test-or-key']);

        $this->owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $this->owner->id,
            'status' => 'active',
            'channel_access_token' => 'line-token',
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งแอดมิน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-tok', 'chat_id' => '-100999'],
        ]);

        $profile = CustomerProfile::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $profile->id,
            'channel_type' => 'line',
            'external_customer_id' => 'U123',
            'is_handover' => false,
        ]);

        // Bot summarized the order → pending payment 1,500 detectable from history.
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
        ]);
    }

    public function test_manual_confirm_fires_full_output_pipeline_and_creates_order(): void
    {
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": true, "variables": {"amount": "1500", "product": "Nolimit BM"}}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment");

        $response->assertOk()->assertJsonPath('order_created', true);

        $botMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->latest('id')
            ->first();

        $this->assertTrue($botMessage->metadata['slip_verification']);
        $this->assertSame('manual_confirmed', $botMessage->metadata['slip_status']);
        $this->assertSame($this->owner->id, $botMessage->metadata['confirmed_by']);
        $this->assertStringContainsString('เงินเข้าแล้ว 1,500 บาท', $botMessage->content);
        $this->assertStringContainsString('[ยืนยันชำระเงิน]', $botMessage->content);

        $this->assertDatabaseHas('slip_verifications', [
            'conversation_id' => $this->conversation->id,
            'status' => 'manual_confirmed',
            'trans_ref' => null,
        ]);

        // Order pipeline fired exactly like the happy path.
        $this->assertDatabaseHas('orders', [
            'conversation_id' => $this->conversation->id,
            'total_amount' => 1500,
        ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_request_amount_overrides_detected_amount(): void
    {
        // No detectable pending order — amount must come from the request.
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": false}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 2000]);

        $response->assertOk();

        $botMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->latest('id')
            ->first();
        $this->assertStringContainsString('เงินเข้าแล้ว 2,000 บาท', $botMessage->content);
    }

    public function test_no_amount_available_returns_422(): void
    {
        $this->conversation->messages()->where('sender', 'bot')->delete();

        $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'ไม่พบยอดออเดอร์ กรุณาระบุยอด');
    }

    public function test_double_confirm_within_window_returns_409_and_creates_one_order(): void
    {
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": true, "variables": {"amount": "1500", "product": "Nolimit BM"}}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $first = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment");
        $first->assertOk();

        $second = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment");
        $second->assertStatus(409)
            ->assertJsonPath('message', 'เพิ่งยืนยันรับเงินใน conversation นี้ไปเมื่อครู่ — ถ้าต้องการยืนยันซ้ำจริงๆ รอ 2 นาทีแล้วลองใหม่');

        $this->assertSame(1, Order::where('conversation_id', $this->conversation->id)->count());
        $this->assertSame(1, SlipVerification::where('conversation_id', $this->conversation->id)
            ->where('status', 'manual_confirmed')
            ->count());
    }

    public function test_zero_amount_returns_422(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 0])
            ->assertStatus(422);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $other = User::factory()->owner()->create();

        $this->actingAs($other)
            ->postJson("/api/conversations/{$this->conversation->id}/confirm-payment", ['amount' => 100])
            ->assertStatus(403);
    }
}
