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
use Illuminate\Http\Client\ConnectionException;
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
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 111],
        ])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour(), 'card_message_id' => 42]);

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

    public function test_skips_repeat_reminder_during_quiet_hours(): void
    {
        // ช่วงเงียบยังกันการเตือนซ้ำเหมือนเดิม — เปลี่ยนเฉพาะการเตือนรอบแรก
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $remindedAt = now()->subHours(2);
        $delivery = $this->makeDelivery([
            'created_at' => now()->subHours(3),
            'last_reminded_at' => $remindedAt,
        ]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
        // ไม่ถูกแตะ = รอบเช้าเตือนต่อได้
        $this->assertSame($remindedAt->toDateTimeString(), $delivery->fresh()->last_reminded_at->toDateTimeString());
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

    public function test_does_not_stamp_reminder_when_the_card_never_reached_telegram(): void
    {
        // ประทับเวลาเตือนทั้งที่การ์ดไม่ออก = เผาสิทธิ์ทะลุช่วงเงียบครั้งเดียวทิ้งฟรีๆ
        // รอบถัดไปงานจะเข้าเงื่อนไข "เคยเตือนแล้ว" แล้วเงียบยาวถึง 08:00 แบบเคส #49 เป๊ะ
        // คือตาข่ายสุดท้ายดับตอนที่ต้องใช้พอดี
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $delivery = $this->makeDelivery(['created_at' => now()->subHours(3)]);

        $this->artisan('delivery:remind')->assertSuccessful();

        $this->assertNull($delivery->fresh()->last_reminded_at);
    }

    public function test_first_reminder_fires_even_during_quiet_hours(): void
    {
        // เคส #49 (20 ก.ค. 2026): การ์ดหายตอน 22:49 แล้วช่วงเงียบกลืนการเตือนไป 9 ชั่วโมง
        // ลูกค้าจ่ายเงินแล้วรอข้ามคืนเพราะไม่มีใครรู้ว่าการ์ดไม่เคยไปถึง
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $delivery = $this->makeDelivery(['created_at' => now()->subHours(3)]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }

    public function test_reminder_carries_no_buttons_and_quotes_the_original_card(): void
    {
        // ต้นเหตุที่เจ้าของกดส่งซ้ำ: ใบเตือนเคยเป็นการ์ดเต็มที่แนบปุ่มชุดใหม่ทุกรอบ
        // เตือนกี่รอบ = ปุ่มค้างในกลุ่มเพิ่มอีกกี่ชุด
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 12345],
        ])]);
        $delivery = $this->makeDelivery([
            'created_at' => now()->subHour(),
            'card_message_id' => 4321,
        ]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && ! isset($r['reply_markup'])
            && ($r['reply_to_message_id'] ?? null) === 4321
            && str_contains($r['text'] ?? '', '⏰')
            && str_contains($r['text'] ?? '', "#{$delivery->id}"));
        $this->assertNotNull($delivery->fresh()->last_reminded_at);
    }

    public function test_reminder_falls_back_to_a_full_card_when_the_first_one_never_arrived(): void
    {
        // เคส 1 ส.ค. 2026: การ์ดใบแรกไม่เคยไปถึง Telegram → card_message_id ว่าง
        // รอบเตือนต้องส่งการ์ดพร้อมปุ่มแทน ไม่งั้นงานนี้จะไม่มีปุ่มให้กดเลยตลอดกาล
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 777],
        ])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(function ($r) {
            $markup = json_decode($r['reply_markup'] ?? '[]', true);

            return str_contains($r->url(), 'sendMessage')
                && str_contains(json_encode($markup), 'dv|')
                && str_contains(json_encode($markup), 'dx|');
        });
        // การ์ดใบนี้กลายเป็นใบที่ถือปุ่ม — รอบถัดไปต้อง reply มาที่ใบนี้ ไม่ส่งปุ่มซ้ำอีก
        $this->assertSame(777, $delivery->fresh()->card_message_id);
    }
}
