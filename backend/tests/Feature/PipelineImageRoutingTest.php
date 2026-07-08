<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end coverage for the image → pipeline routing fix.
 *
 * Before this fix the handle() gate required isTextMessage(), so IMAGE events always
 * ran the legacy processEvent() path and the EasySlip slip-verification hook (which lives
 * in the pipeline's LineWebhookResponseService) never ran in production. These tests drive
 * the full job (Stage 1 gating → Stage 2 context/image-download → Stage 3 response → Stage 4
 * output) for a pipeline-enabled bot and assert the image actually flows through the pipeline.
 */
class PipelineImageRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Route this bot through the pipeline.
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
            // Run the operation directly (no circuit-breaker state machine in tests).
            'circuit-breaker.enabled' => false,
        ]);

        Storage::fake(config('filesystems.default'));

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->active()->line()->create([
            'id' => 26,
            'user_id' => $user->id,
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
            'primary_chat_model' => 'google/gemini-3.5-flash',
        ]);

        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_img_user',
            'channel_type' => 'line',
        ]);

        // Pre-create the conversation so the pipeline appends to it (and the seeded
        // pending-order history is visible to slip verification / vision).
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => 'U_img_user',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'last_message_at' => now(),
        ]);

        // History: bot summarised a 1,500 order → establishes a pending payment context.
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
        ]);
    }

    private function imageEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'reply_token_img_1',
            'source' => ['type' => 'user', 'userId' => 'U_img_user'],
            'message' => ['id' => 'img_msg_001', 'type' => 'image'],
            'webhookEventId' => 'webhook_img_001',
            'deliveryContext' => ['isRedelivery' => false],
            'timestamp' => time() * 1000,
        ];
    }

    private function runJob(array $event): void
    {
        $job = new ProcessLINEWebhook($this->bot, $event);
        app()->call([$job, 'handle']);
    }

    public function test_image_with_slip_enabled_runs_easyslip_and_replies_via_pipeline(): void
    {
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        Http::fake([
            // LINE image content download (Stage 2).
            'api-data.line.me/*' => Http::response('fake-image-bytes', 200),
            // EasySlip verification (Stage 3) — a passing slip.
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'isDuplicate' => false,
                    'matchedAccount' => null,
                    'amountInSlip' => 1500,
                    'rawSlip' => [
                        'transRef' => 'TR900',
                        'amount' => ['amount' => 1500],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            // LINE reply/loading-indicator.
            'api.line.me/*' => Http::response(['ok' => true]),
            // Must NOT be called — a passing slip skips vision entirely.
            'openrouter.ai/*' => Http::response([], 500),
        ]);

        $this->runJob($this->imageEvent());

        // EasySlip was actually hit — proving the pipeline (not legacy vision) ran.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.easyslip.com'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));

        // The incoming image was persisted with media_url + type 'image' (Stage 2).
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'sender' => 'user',
            'type' => 'image',
            'external_message_id' => 'img_msg_001',
        ]);

        // The slip-path bot reply was produced (Stage 3/4).
        $botMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->whereJsonContains('metadata->slip_verification', true)
            ->first();

        $this->assertNotNull($botMessage, 'Expected a slip-verification bot reply');
        $this->assertSame('passed', $botMessage->metadata['slip_status']);
        $this->assertStringContainsString('เงินเข้าแล้ว 1,500 บาท', $botMessage->content);

        $this->assertDatabaseHas('slip_verifications', ['status' => 'passed']);
    }

    public function test_image_with_slip_disabled_still_gets_vision_reply_via_pipeline(): void
    {
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => false,
        ]);

        Http::fake([
            'api-data.line.me/*' => Http::response('fake-image-bytes', 200),
            'api.line.me/*' => Http::response(['ok' => true]),
            // Non-slip bots' images must still get a vision reply through the pipeline.
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'รูปแมวน่ารักครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
            // Slip disabled → EasySlip must never be called.
            'api.easyslip.com/*' => Http::response([], 500),
        ]);

        $this->runJob($this->imageEvent());

        Http::assertSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.easyslip.com'));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'sender' => 'user',
            'type' => 'image',
            'external_message_id' => 'img_msg_001',
        ]);

        $botMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->whereJsonContains('metadata->vision_analysis', true)
            ->first();

        $this->assertNotNull($botMessage, 'Expected a vision bot reply');
        $this->assertStringContainsString('รูปแมว', $botMessage->content);
    }
}
