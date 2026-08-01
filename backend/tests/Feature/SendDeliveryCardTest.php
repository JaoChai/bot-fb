<?php

namespace Tests\Feature;

use App\Jobs\SendDeliveryCard;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendDeliveryCardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDelivery(): AccountDelivery
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งออเดอร์',
            'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);
        $conv = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1100, 'status' => 'passed',
        ]);

        return AccountDelivery::create([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVED,
            'amount' => 1100,
        ]);
    }

    public function test_job_sends_the_card(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery();

        (new SendDeliveryCard($delivery->id))->handle(app(AccountDeliveryService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', "งาน #{$delivery->id}"));
    }

    public function test_job_throws_so_the_queue_retries_when_telegram_is_unreachable(): void
    {
        // ถ้าไม่โยน queue จะถือว่าสำเร็จแล้วเลิก = การ์ดหายเงียบแบบเคส #99
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $delivery = $this->makeDelivery();

        $this->expectException(\RuntimeException::class);

        (new SendDeliveryCard($delivery->id))->handle(app(AccountDeliveryService::class));
    }

    public function test_job_gives_up_quietly_when_the_delivery_is_gone(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new SendDeliveryCard(999999))->handle(app(AccountDeliveryService::class));

        Http::assertNothingSent();
    }

    public function test_job_retries_at_least_three_times(): void
    {
        // เคสจริง: Telegram ค้างประมาณ 30 วิ retry 2 ครั้งใน 10 วิ ไม่พอ
        $job = new SendDeliveryCard(1);

        $this->assertGreaterThanOrEqual(3, $job->tries);
    }
}
