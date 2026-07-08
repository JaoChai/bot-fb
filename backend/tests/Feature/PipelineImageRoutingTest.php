<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\RateLimitService;
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

    /**
     * Fix 1 — legacy parity for stats on a responded image.
     *
     * Legacy counted the user image (+1 message_count, +1 unread_count) inside its
     * transaction AND the bot reply (+1 message_count) afterwards. The pipeline must net
     * the same: +2 message_count and +1 unread_count on the conversation.
     */
    public function test_responded_image_increments_message_count_by_two_and_unread_by_one(): void
    {
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => false,
        ]);

        Http::fake([
            'api-data.line.me/*' => Http::response('fake-image-bytes', 200),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'รูปแมวน่ารักครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
            'api.easyslip.com/*' => Http::response([], 500),
        ]);

        $before = Conversation::find($this->conversation->id);
        $beforeMessages = $before->message_count;
        $beforeUnread = $before->unread_count;

        $this->runJob($this->imageEvent());

        $after = Conversation::find($this->conversation->id);
        $this->assertSame($beforeMessages + 2, $after->message_count, 'responded image should net +2 message_count');
        $this->assertSame($beforeUnread + 1, $after->unread_count, 'responded image should net +1 unread_count');
    }

    /**
     * Fix 2 — a rate-limited customer's slip must still run.
     *
     * Legacy never rate-limited non-text messages. With the per-user daily limit already
     * exceeded, the image must bypass the gate, persist, and still hit EasySlip.
     */
    public function test_rate_limited_customer_image_still_persists_and_runs_easyslip(): void
    {
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'per_user_limit' => 1,
        ]);
        $this->bot->load('settings');

        // Prime the per-user counter so the customer is already over the daily limit.
        app(RateLimitService::class)->incrementCounters($this->bot, 'U_img_user');

        Http::fake([
            'api-data.line.me/*' => Http::response('fake-image-bytes', 200),
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'isDuplicate' => false,
                    'matchedAccount' => null,
                    'amountInSlip' => 1500,
                    'rawSlip' => [
                        'transRef' => 'TR901',
                        'amount' => ['amount' => 1500],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500),
        ]);

        $this->runJob($this->imageEvent());

        // Image bypassed the rate-limit gate → EasySlip still ran, slip still persisted.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.easyslip.com'));
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'sender' => 'user',
            'type' => 'image',
            'external_message_id' => 'img_msg_001',
        ]);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'passed']);
    }

    /**
     * Fix 3 — an out-of-hours image must be persisted before the offline reply.
     *
     * Legacy saved the image message first, then sent the offline message and skipped the
     * AI response. The pipeline must persist the slip (with media_url), send the offline
     * reply, and never call EasySlip or vision.
     */
    public function test_out_of_hours_image_persists_and_sends_offline_without_ai(): void
    {
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'response_hours_enabled' => true,
            'offline_message' => 'ขณะนี้ปิดทำการชั่วคราวครับ',
            'response_hours' => [
                'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [],
                'fri' => [], 'sat' => [], 'sun' => [],
            ],
        ]);
        $this->bot->load('settings');

        Http::fake([
            'api-data.line.me/*' => Http::response('fake-image-bytes', 200),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.easyslip.com/*' => Http::response([], 500),
            'openrouter.ai/*' => Http::response([], 500),
        ]);

        $this->runJob($this->imageEvent());

        // The slip record survived, with its downloaded media.
        $userMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'user')
            ->where('external_message_id', 'img_msg_001')
            ->first();
        $this->assertNotNull($userMessage, 'Out-of-hours image must still persist');
        $this->assertSame('image', $userMessage->type);
        $this->assertNotNull($userMessage->media_url, 'Persisted image must carry media_url');

        // Offline reply was sent... (LINE JSON-escapes Thai to \uXXXX in the body).
        $offlineNeedle = trim(json_encode('ปิดทำการ'), '"');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me')
            && str_contains($req->body(), $offlineNeedle));

        // ...and no AI (vision) or slip verification ran.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.easyslip.com'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));

        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->whereJsonContains('metadata->slip_verification', true)
            ->count(), 'No slip-verification bot reply out of hours');
    }
}
