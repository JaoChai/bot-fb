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
use App\Models\User;
use App\Services\Payment\SlipRetryService;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SlipRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private Message $slipMessage;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->owner()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'channel_type' => 'line',
            'channel_access_token' => 'line-token',
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        // Telegram alert plugin บน default flow (สำหรับ path แจ้งแอดมิน)
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
        $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nบัญชี 223-3-24880-3",
        ]);
        $this->slipMessage = $this->conversation->messages()->create([
            'sender' => 'user', 'type' => 'image', 'content' => '[รูปภาพ]',
            'media_url' => 'https://pub-xxx.r2.dev/line/26/slip.jpg',
        ]);
    }

    private function fakePending(): void
    {
        Http::fake([
            'api.easyslip.com/*' => Http::response(
                ['success' => false, 'error' => ['code' => 'SLIP_PENDING', 'message' => 'pending']], 404
            ),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_still_pending_redispatches_next_attempt(): void
    {
        Bus::fake([RetrySlipVerification::class]);
        $this->fakePending();
        config(['delivery.pending_retry.delays' => [90, 180, 300]]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        Bus::assertDispatched(RetrySlipVerification::class, function (RetrySlipVerification $job) {
            return $job->attempt === 2 && $job->conversationId === $this->conversation->id;
        });
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_pending_on_final_attempt_alerts_admin_and_stops(): void
    {
        Bus::fake([RetrySlipVerification::class]);
        $this->fakePending();
        config(['delivery.pending_retry.delays' => [90, 180, 300]]);

        // attempt 3 = รอบสุดท้าย (count(delays) === 3)
        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 3
        );

        Bus::assertNotDispatched(RetrySlipVerification::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_passed_retry_reserves_stock_and_pushes_success(): void
    {
        Bus::fake([ReserveAccountStock::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'amountInSlip' => 1100,
                    'rawSlip' => [
                        'transRef' => 'TR-RETRY-1',
                        'amount' => ['amount' => 1100],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        // จองของ
        Bus::assertDispatched(ReserveAccountStock::class, function ($job) {
            return $job->conversationId === $this->conversation->id && (float) $job->amount === 1100.0;
        });
        // push ข้อความ "เงินเข้าแล้ว" ไป LINE
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me'));
        // bot message ถูกสร้าง สถานะ passed
        $this->assertTrue(
            $this->conversation->messages()
                ->where('sender', 'bot')
                ->get()
                ->contains(fn ($m) => ($m->metadata['slip_status'] ?? null) === 'passed')
        );
    }

    public function test_retry_hard_fail_pushes_fail_message_and_alerts(): void
    {
        Bus::fake([ReserveAccountStock::class, RetrySlipVerification::class]);
        Http::fake([
            'api.easyslip.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'amountInSlip' => 5, // ยอดไม่ตรงกับ 1,100
                    'rawSlip' => [
                        'transRef' => 'TR-FAIL-1',
                        'amount' => ['amount' => 5],
                        'receiver' => ['bank' => ['id' => '004'], 'account' => ['bank' => ['account' => 'xxx-x-x4880-x']]],
                    ],
                ],
                'message' => 'success',
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );

        Bus::assertNotDispatched(ReserveAccountStock::class);
        Bus::assertNotDispatched(RetrySlipVerification::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.line.me'));
    }

    public function test_passed_retry_via_llm_reconstruction_alerts_admin(): void
    {
        // auto-retry สลิป pending แล้วผ่านด้วยด่านสรุปออเดอร์เอง (orderSource llm)
        // ต้องส่งการ์ดเงียบเหมือนเส้นทาง webhook ปกติ — เจ้าของต้องรู้ว่าระบบส่งของเองแล้ว
        // (ก่อนหน้านี้เส้นทาง retry ส่งของเงียบเลยโดยไม่แจ้งอะไรเลย)
        Bus::fake([ReserveAccountStock::class]);
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $result = new SlipVerificationResult(
            isSlip: true,
            passed: true,
            amount: 1100.0,
            transRef: 'TR-LLM-1',
            expectedAmount: 1100.0,
            orderSummary: 'Nolimit Level Up+ Personal',
            orderSource: 'llm',
        );

        // จำลอง verify() ให้คืนผ่านด้วยด่าน llm ส่วน notifyIfAutoReconstructed ดักการเรียกโดยตรง
        // (กฎ "เมื่อไหร่แจ้งเงียบ" ถูกย้ายเข้า SlipVerificationService::notifyIfAutoReconstructed แล้ว
        //  จึง assert ที่เมธอดนี้แทน notifyAdmin — intent เดิม: llm reconstruction ต้องแจ้งเจ้าของ 1 ครั้ง
        //  runPlugins ก็ส่ง telegram เองอยู่แล้ว จึงไม่ใช่การ assert ผ่าน HTTP)
        $mock = Mockery::mock(SlipVerificationService::class);
        $mock->shouldReceive('verify')->andReturn($result);
        $mock->shouldReceive('notifyIfAutoReconstructed')->once();
        $this->app->instance(SlipVerificationService::class, $mock);

        app(SlipRetryService::class)->retry(
            $this->bot, $this->conversation, $this->slipMessage, $this->slipMessage->media_url, 1
        );
    }
}
