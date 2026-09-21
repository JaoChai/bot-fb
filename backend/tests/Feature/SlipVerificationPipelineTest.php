<?php

namespace Tests\Feature;

use App\Jobs\ReserveAccountStock;
use App\Jobs\RetrySlipVerification;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlipVerificationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBotAndConversation();
    }

    private function makeBotAndConversation(array $botAttributes = []): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => 'active',
            'primary_chat_model' => 'google/gemini-3.5-flash',
        ], $botAttributes));
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

    public static function classificationLoggingModes(): array
    {
        return [['off'], ['shadow'], ['enforce'], ['hold']];
    }

    #[DataProvider('classificationLoggingModes')]
    public function test_malformed_classification_logs_only_safe_diagnostics(string $mode): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        Http::preventStrayRequests();
        $this->makeBotAndConversation(['id' => 26]);
        config(['commerce_safety.bots.26.mode' => $mode]);
        $content = 'malformed LINE @adsvance';
        $this->mock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(true);
        });
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false,
                'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);
        Log::spy();
        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Log::shouldHaveReceived('info')->with('Slip image classification', [
            'bot_id' => 26, 'conversation_id' => $this->conversation->id,
            'content_length' => mb_strlen($content), 'content_hash' => hash('sha256', $content),
            'reason' => 'malformed_classification',
        ])->once();
        $this->assertArrayNotHasKey('slip_vision_draft', $ctx->metadata);
        foreach (['warning', 'info', 'debug', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, [Mockery::any(), Mockery::on(
                fn ($context) => str_contains(json_encode($context), '@adsvance')
            )]);
        }
    }

    public function test_review_scoped_vision_cannot_emit_generated_transfer_instructions(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        Http::preventStrayRequests();
        $this->bot->settings->update(['slip_verification_enabled' => false]);
        foreach (['enforce', 'hold'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            Http::fake(['api.line.me/*' => Http::response(['ok' => true]), 'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'โอน 199 บาทเข้าบัญชี 223-3-24880-3 ได้เลยครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ])]);
            $ctx = $this->makeContext();
            app(LineWebhookResponseService::class)->generate($ctx);
            $this->assertNotNull($ctx->response);
            $this->assertStringNotContainsString('223-3-24880-3', $ctx->response->payload);
        }
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

    /**
     * เปิด Telegram alert plugin ให้ default flow ของบอท (ใช้ทดสอบ path ที่แจ้งแอดมิน)
     */
    private function enableTelegramAlert(): void
    {
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
    }

    public function test_passed_slip_replies_confirmation_without_vision(): void
    {
        Http::fake([
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
        $this->enableTelegramAlert();

        Http::fake([
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'isDuplicate' => false,
                    'matchedAccount' => null,
                    'amountInSlip' => 900,
                    'rawSlip' => [
                        'transRef' => 'TR901',
                        'amount' => ['amount' => 900],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertStringNotContainsString('[ยืนยันชำระเงิน]', $ctx->response->payload);
        $this->assertSame('amount_mismatch', $ctx->metadata['bot_message']->metadata['slip_status']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_easyslip_api_error_falls_back_to_vision_and_alerts_admin(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->enableTelegramAlert();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'internal error']], 500),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ตอบจาก vision']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('ตอบจาก vision', $ctx->response->payload);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_api_error_with_non_slip_image_does_not_alert_admin(): void
    {
        // เคสจริง prod 27 ก.ค. แชท #361: screenshot หน้าเพจ FB + EasySlip ล่ม
        // → ต้องตอบลูกค้าตามบริบท และห้ามเด้งการ์ดหาเจ้าของ
        // (reply ต้องไม่มีคำว่า "ได้รับสลิป" ไม่งั้นจะไปโดน safety net อีกชั้นที่ generateImageResponse)
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(false);
        });
        $this->enableTelegramAlert();
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['message' => 'server error'], 500),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_slip": false, "reply": "เรื่องนี้ต้องให้ทีม Support ดูครับ"}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ทีม Support', $ctx->response->payload);
        $this->assertDatabaseCount('slip_verifications', 0);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        // ตัดสิน+ตอบจบใน LLM call เดียว (draft ถูกใช้ต่อ) — ต้นทุน vision เท่าเดิมกับก่อนแก้
        $this->assertCount(1, Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai')));
    }

    public function test_api_error_with_slip_image_costs_two_vision_calls(): void
    {
        // ต้นทุนที่ยอมรับไว้ (วัดแล้ว: เดิม 1 → ใหม่ 2): classifySlipImage เก็บ draft ไว้ใช้ต่อ
        // เฉพาะตอน is_slip=false เท่านั้น (LineWebhookResponseService::classifySlipImage)
        // พอตอบว่าเป็นสลิป generateImageResponse จึงต้องยิง vision ซ้ำเพื่อตอบลูกค้า
        // แลกกับการที่รูปทั่วไปไม่เด้งการ์ดหาเจ้าของอีกต่อไป และเกิดเฉพาะตอน EasySlip ล่ม
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(false);
        });
        $this->enableTelegramAlert();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['message' => 'server error'], 500),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_slip": true, "reply": ""}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertCount(2, Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai')));
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_non_slip_image_falls_through_to_vision(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        // Remove the pending-order summary so a 400 is treated as a genuine non-slip → vision.
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
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

    public function test_unreadable_slip_replies_fail_template_and_alerts(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(false);
        });
        $this->enableTelegramAlert();

        // 400 + pending order (from setUp) + vision บอกเป็นสลิป → unreadable slip.
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_slip": true, "reply": ""}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertSame('unreadable', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        // openrouter ถูกเรียกครั้งเดียว = classification เท่านั้น ไม่มี vision ตอบลูกค้า
        $openrouterCalls = Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
        $this->assertCount(1, $openrouterCalls);
    }

    public function test_non_slip_image_with_pending_order_falls_through_to_vision(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(false);
        });
        $this->enableTelegramAlert();

        // 400 + pending order แต่ vision บอกไม่ใช่สลิป (เช่น screenshot โปรโมทโพสต์)
        // → ใช้ reply จาก call เดียวกันตอบลูกค้าเลย ไม่ alert, ไม่บันทึก, ไม่เรียก vision ซ้ำ
        // (ตอบเป็น JSON ห่อ code fence — พิสูจน์ว่า parser รองรับ model ที่ไม่รองรับ structured output)
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => "```json\n{\"is_slip\": false, \"reply\": \"จากรูปเป็นหน้าจอโปรโมทโพสต์ครับ กดเริ่มการตรวจสอบยืนยันได้เลยครับ\"}\n```"]]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('หน้าจอโปรโมทโพสต์', $ctx->response->payload);
        $this->assertStringNotContainsString('ได้รับสลิปแล้ว', $ctx->response->payload);
        $this->assertDatabaseMissing('slip_verifications', ['status' => 'unreadable']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        // ตัดสิน+ตอบจบใน LLM call เดียว — ไม่มีการเรียก vision รอบสอง
        $openrouterCalls = Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
        $this->assertCount(1, $openrouterCalls);
    }

    public function test_vision_fallback_slip_acknowledgement_still_alerts_admin(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->enableTelegramAlert();

        // ไม่มีออเดอร์ค้าง (ลบสรุปยอดทิ้ง) → 400 ไป vision ปกติ แต่ vision เห็นเป็นสลิป
        // (ตอบให้รอทีมงาน) → ต้องมี alert ไปหาแอดมิน ไม่ปล่อยให้ลูกค้ารอเงียบๆ
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ได้รับสลิปแล้วครับ รอทีมงานตรวจสอบยอดเข้าสักครู่นะครับ ปกติไม่เกิน 5 นาที ขอบคุณที่รอครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ได้รับสลิปแล้ว', $ctx->response->payload);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_structured_output_used_when_model_supports_it(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->with('google/gemini-3.5-flash')->andReturn(true);
        });

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"is_slip": false, "reply": "ตอบจากรูปครับ"}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ตอบจากรูปครับ', $ctx->response->payload);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'openrouter.ai')) {
                return false;
            }
            $data = $req->data();

            return ($data['response_format']['type'] ?? null) === 'json_schema'
                && ($data['response_format']['json_schema']['name'] ?? null) === 'slip_image_check'
                && ($data['response_format']['json_schema']['strict'] ?? null) === true;
        });
    }

    public function test_classifier_failure_keeps_unreadable_fail_safe(): void
    {
        $this->enableTelegramAlert();

        // 400 + pending order แต่ classifier เรียกไม่ได้ (openrouter ล่ม)
        // → fail-safe: ถือเป็นสลิปอ่านไม่ได้ → alert แอดมินตรวจมือ
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertSame('unreadable', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_pending_slip_replies_pending_message_without_alert(): void
    {
        $this->enableTelegramAlert();
        // Bus::fake: กัน RetrySlipVerification รันจริงใน queue sync (test env) — จะ recurse ผ่านทุก
        // attempt ในคำขอเดียวแล้วจบที่ notifyAdmin (exhausted), ทำให้ทดสอบ "ครั้งแรกไม่ alert" ผิดไป
        // การ retry เองตรวจแยกใน SlipRetryServiceTest + test ใหม่ด้านล่างแล้ว
        Bus::fake([RetrySlipVerification::class]);

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'slip pending']], 404),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500), // ต้องไม่ถูกเรียก
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ธนาคารกำลังประมวลผล', $ctx->response->payload);
        $this->assertSame('pending', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'pending']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
    }

    public function test_pending_slip_dispatches_retry_job_and_tells_customer_to_wait(): void
    {
        $this->bot->update(['auto_delivery_enabled' => true]);
        Bus::fake([RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        // ข้อความใหม่: ไม่ขอให้ส่งสลิปซ้ำแบบเดิม (แต่มี fallback hint ให้ส่งซ้ำถ้าเกิน 10 นาที)
        $this->assertStringNotContainsString('ส่งสลิปเดิมมาอีกครั้ง', $ctx->response->payload);
        $this->assertStringContainsString('ตรวจให้อัตโนมัติ', $ctx->response->payload);

        Bus::assertDispatched(RetrySlipVerification::class, function ($job) {
            return $job->attempt === 1
                && $job->conversationId === $this->conversation->id
                && $job->imageUrl === 'https://cdn.example.com/slip.jpg';
        });
    }

    public function test_pending_slip_does_not_dispatch_retry_for_non_delivery_bot(): void
    {
        // auto_delivery_enabled default = false → ไม่ dispatch retry job แม้ feature flag เปิด
        // (กันแข่งกับ manual-confirm/ลูกค้าส่งสลิปใหม่บนบอทที่ไม่มี auto-delivery dedup คุ้มกัน)
        Bus::fake([RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Bus::assertNotDispatched(RetrySlipVerification::class);
        $this->assertStringContainsString('ตรวจให้อัตโนมัติ', $ctx->response->payload);
    }

    public function test_pending_retry_disabled_keeps_legacy_behaviour(): void
    {
        $this->bot->update(['auto_delivery_enabled' => true]);
        config(['delivery.pending_retry.enabled' => false]);
        Bus::fake([RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Bus::assertNotDispatched(RetrySlipVerification::class);
    }

    public function test_config_error_falls_back_to_vision_and_alerts_admin(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->bot->user->settings->update(['easyslip_api_token' => null]);

        $this->enableTelegramAlert();

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ตอบจาก vision']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('ตอบจาก vision', $ctx->response->payload);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'easyslip.com'));
    }

    public function test_enabled_bot_vision_prompt_is_cautious_no_self_confirm(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        // Remove the pending-order summary so a 400 is treated as a genuine non-slip → vision.
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ได้รับสลิปแล้วครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'openrouter.ai')) {
                return false;
            }
            $systemContent = collect($req->data()['messages'] ?? [])
                ->firstWhere('role', 'system')['content'] ?? '';

            // Cautious prompt present; legacy self-confirm block gone.
            return str_contains($systemContent, 'ห้ามยืนยันการรับเงิน')
                && ! str_contains($systemContent, 'เงินเข้าแล้ว [จำนวนเงิน] บาท ✅');
        });
    }

    public function test_disabled_bot_vision_prompt_keeps_legacy_confirm_instruction(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        $this->bot->settings->update(['slip_verification_enabled' => false]);
        // Remove the pending-order summary so vision uses the generic image prompt.
        $this->conversation->messages()->where('sender', 'bot')->delete();

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'รูปแมวน่ารักครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'openrouter.ai')) {
                return false;
            }
            $systemContent = collect($req->data()['messages'] ?? [])
                ->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($systemContent, 'เงินเข้าแล้ว [จำนวนเงิน] บาท ✅')
                && str_contains($systemContent, '[ยืนยันชำระเงิน]')
                && ! str_contains($systemContent, 'ห้ามยืนยันการรับเงิน');
        });
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

    public function test_scoped_automatic_held_receipt_never_sends_directly(): void
    {
        $this->enableTelegramAlert();
        $plugin = FlowPlugin::where('flow_id', $this->bot->default_flow_id)->firstOrFail();
        config(["commerce_safety.bots.{$this->bot->id}" => ['mode' => 'enforce', 'payment_plugin_ids' => [$plugin->id]]]);
        Event::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['api.easyslip.com/*' => Http::response([
            'success' => true,
            'data' => ['isDuplicate' => false, 'matchedAccount' => null, 'amountInSlip' => 199.01,
                'rawSlip' => ['transRef' => 'AUTO-A2', 'amount' => ['amount' => 199.01],
                    'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'fixture'], 'bank' => ['account' => 'xxx-x-x4880-x']]]]],
            'message' => 'success',
        ])]);
        $sent = false;
        $line = $this->mock(LINEService::class);
        $line->shouldReceive('showLoadingIndicator')->andReturn(true);
        $line->shouldNotReceive('replyWithFallback', 'pushPaymentReceipt', 'reply', 'push');
        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame($ctx->metadata['bot_message']->id, $event->receipt_message_id);
        $ctx->metadata['bot_message']->update(['content' => 'เงินเข้าแล้ว 1 บาท FORGED ส่งใน 5-10 นาที']);
        DB::beginTransaction();
        app(LineWebhookOutputService::class)->dispatch($ctx);
        $this->assertFalse($sent);
        DB::commit();
        $this->assertFalse($sent);
        // Reviewed I2 policy: a valid held event owns exactly one proof-only
        // line_receipt effect pending team verification; direct sends stay suppressed.
        $this->assertSame('manual_hold', $event->fresh()->disposition);
        $this->assertDatabaseCount('payment_effects', 1);
        $this->assertDatabaseHas('payment_effects', [
            'event_id' => $event->id,
            'kind' => 'line_receipt',
            'state' => 'pending',
        ]);
        $this->assertSame(0, DB::table('payment_effects')
            ->whereIn('kind', ['telegram_payment', 'reserve_stock'])
            ->count());
        $this->assertSame(1, VerifiedPaymentEvent::count());
        $this->assertSame(0, Order::count());
        Queue::assertNotPushed(ReserveAccountStock::class);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'telegram') || str_contains($request->url(), 'openrouter'));
    }
}
