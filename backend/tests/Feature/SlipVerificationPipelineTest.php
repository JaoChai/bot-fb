<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
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

    public function test_non_slip_image_falls_through_to_vision(): void
    {
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
        $this->enableTelegramAlert();

        // 400 + pending order (from setUp) + vision บอกเป็นสลิป → unreadable slip.
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'SLIP']]],
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
        $this->enableTelegramAlert();

        // 400 + pending order แต่ vision บอกไม่ใช่สลิป (เช่น screenshot โปรโมทโพสต์)
        // → ไม่ alert, ไม่บันทึก, ปล่อยเข้า vision ตอบตามบริบท
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::sequence()
                ->push([
                    'choices' => [['message' => ['content' => 'NOT_SLIP']]],
                    'model' => 'google/gemini-3.5-flash',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
                ])
                ->push([
                    'choices' => [['message' => ['content' => 'จากรูปเป็นหน้าจอโปรโมทโพสต์ครับ กดเริ่มการตรวจสอบยืนยันได้เลยครับ']]],
                    'model' => 'google/gemini-3.5-flash',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('หน้าจอโปรโมทโพสต์', $ctx->response->payload);
        $this->assertStringNotContainsString('ได้รับสลิปแล้ว', $ctx->response->payload);
        $this->assertDatabaseMissing('slip_verifications', ['status' => 'unreadable']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_classifier_vision_disagreement_still_alerts_admin(): void
    {
        $this->enableTelegramAlert();

        // classifier บอก NOT_SLIP แต่ vision ขั้นตอบลูกค้ากลับมองเป็นสลิป (ตอบให้รอทีมงาน)
        // → ต้องมี alert ไปหาแอดมิน ไม่ปล่อยให้ลูกค้ารอเงียบๆ
        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::sequence()
                ->push([
                    'choices' => [['message' => ['content' => 'NOT_SLIP']]],
                    'model' => 'google/gemini-3.5-flash',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
                ])
                ->push([
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

    public function test_config_error_falls_back_to_vision_and_alerts_admin(): void
    {
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
}
