<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'primary_chat_model' => 'google/gemini-3.5-flash',
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        $profile = CustomerProfile::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $profile->id,
            'is_handover' => false,
        ]);

        // ประวัติ: บอทสรุปยอดไว้แล้ว (ทำให้มี pending order 1,500)
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
        ]);
    }

    private function makeContext(): WebhookContext
    {
        $userMessage = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'user',
            'type' => 'image',
            'content' => '[รูปภาพ]',
            'media_url' => 'https://cdn.example.com/slip.jpg',
        ]);

        // Mirror Stage 2 (LineWebhookContextService::updateStatsForUserMessageOnly), which
        // always stamps last_message_at=now() on the incoming message before Stage 3 runs.
        // Without this, the factory's random last_message_at (this month) can be >6h old,
        // making autoClearIfIdle() wipe the pending-order history set up in setUp().
        $this->conversation->update(['last_message_at' => now()]);

        $ctx = new WebhookContext($this->bot, [
            'type' => 'message',
            'message' => ['type' => 'image', 'id' => 'msg-1'],
            'source' => ['userId' => 'U123'],
            'replyToken' => 'rt-1',
        ]);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $userMessage;

        return $ctx;
    }

    public function test_passed_slip_replies_confirmation_without_vision(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    'transRef' => 'TR900',
                    'amount' => ['amount' => 1500],
                    'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                ],
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500), // ต้องไม่ถูกเรียก
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('เงินเข้าแล้ว 1,500 บาท', $ctx->response->payload);
        $this->assertStringContainsString('[ยืนยันชำระเงิน]', $ctx->response->payload);

        $botMessage = $ctx->metadata['bot_message'];
        $this->assertTrue($botMessage->metadata['slip_verification']);
        $this->assertSame('passed', $botMessage->metadata['slip_status']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
    }

    public function test_failed_slip_replies_fail_template_and_alerts(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    'transRef' => 'TR901',
                    'amount' => ['amount' => 900],
                    'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                ],
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertStringNotContainsString('[ยืนยันชำระเงิน]', $ctx->response->payload);
        $this->assertSame('amount_mismatch', $ctx->metadata['bot_message']->metadata['slip_status']);
    }

    public function test_non_slip_image_falls_through_to_vision(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response(['status' => 400, 'message' => 'invalid_image'], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'รูปแมวน่ารักครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
    }

    public function test_disabled_feature_never_calls_easyslip(): void
    {
        $this->bot->settings->update(['slip_verification_enabled' => false]);
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ตอบจาก vision']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'easyslip.com'));
    }
}
