<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemindPendingDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
    }

    private function makeDelivery(array $attrs = []): AccountDelivery
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

        // created_at ไม่อยู่ใน $fillable ของ AccountDelivery (mass assignment จะทิ้งเงียบ)
        // จึง backdate ผ่าน forceFill หลังสร้าง เพื่อจำลองงานค้างนานตามที่เทสต์ต้องการ
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $delivery = AccountDelivery::create(array_merge([
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => 'reserved', 'amount' => 1100,
        ], $attrs));

        if ($createdAt !== null) {
            $delivery->forceFill(['created_at' => $createdAt])->save();
        }

        return $delivery;
    }

    public function test_reminds_stale_reserved_delivery(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', '⏰'));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }

    public function test_skips_fresh_and_recently_reminded(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->makeDelivery(['created_at' => now()->subMinutes(5)]); // ยังใหม่

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_skips_reminder_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->last_reminded_at); // รอบเช้าต้องเตือนต่อได้
    }

    public function test_reminds_at_night_when_quiet_hours_disabled(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);
        $delivery->bot->user->getOrCreateSettings()->update(['quiet_hours_enabled' => false]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }
}
