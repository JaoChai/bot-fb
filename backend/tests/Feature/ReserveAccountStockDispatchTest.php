<?php

namespace Tests\Feature;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReserveAccountStockDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_expected_payment_returns_items(): void
    {
        $service = app(SlipVerificationService::class);
        $history = [[
            'sender' => 'bot',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว (1,100 x 2) = 2,200 บาท\nรวมยอดโอน: 2,200 บาท\nบัญชี 223-3-24880-3",
        ]];

        $expected = $service->findExpectedPayment($history);

        $this->assertSame(2200.0, $expected['total']);
        $this->assertSame('Nolimit ส่วนตัว', $expected['items'][0]['name']);
        $this->assertSame(2, $expected['items'][0]['qty']);
    }

    public function test_manual_confirm_dispatches_reserve_job(): void
    {
        Bus::fake([ReserveAccountStock::class]);

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);
        $conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nบัญชี 223-3-24880-3",
        ]);

        app(ManualPaymentConfirmService::class)->confirm($bot, $conversation, null, $user->id);

        Bus::assertDispatched(ReserveAccountStock::class, function (ReserveAccountStock $job) use ($bot, $conversation) {
            return $job->botId === $bot->id
                && $job->conversationId === $conversation->id
                && $job->slipVerificationId > 0
                && $job->items[0]['name'] === 'Nolimit ส่วนตัว';
        });
    }

    public function test_manual_confirm_survives_reserve_job_failure(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);
        $conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit ส่วนตัว = 1,100 บาท\nรวมยอดโอน: 1,100 บาท\nบัญชี 223-3-24880-3",
        ]);

        // เปิด delivery จริง (QUEUE_CONNECTION=sync → job body รันทันที) + service พังเสมอ
        config(['delivery.enabled' => true]);
        $bot->update(['auto_delivery_enabled' => true]);
        $failing = $this->createMock(AccountDeliveryService::class);
        $failing->method('createFromPayment')->willThrowException(new \RuntimeException('mhha DB down'));
        $this->app->instance(AccountDeliveryService::class, $failing);

        $result = app(ManualPaymentConfirmService::class)->confirm($bot, $conversation, null, $user->id);

        // confirm ต้องสำเร็จตามปกติ — dispatch ที่พังห้ามล้มการยืนยันเงิน
        $this->assertSame(
            'manual_confirmed',
            $result['message']->metadata['slip_status'] ?? null,
        );
    }

    public function test_dispatch_safely_delays_job_per_config(): void
    {
        // หน่วง job เพื่อให้ข้อความ "ออเดอร์ใหม่!" จาก plugin ไปถึง Telegram ก่อนการ์ดปุ่ม
        Bus::fake([ReserveAccountStock::class]);
        config(['delivery.card_delay_seconds' => 20]);

        ReserveAccountStock::dispatchSafely(1, 2, 3, 100.0, []);

        Bus::assertDispatched(
            ReserveAccountStock::class,
            fn (ReserveAccountStock $job) => $job->delay === 20,
        );
    }
}
