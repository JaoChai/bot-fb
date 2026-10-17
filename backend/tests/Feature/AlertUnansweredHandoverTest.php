<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * การ์ด t_531aadc7 — handover:alert-unanswered
 * ลูกค้าในโหมด handover รอแอดมินตอบเกิน handover.alert_after_minutes นาที → ยิง Telegram เตือน 1 ครั้ง/ตอนรอ
 */
class AlertUnansweredHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
        Cache::flush(); // array cache อยู่ตลอดชีวิต process — กัน key ของเทสก่อนหน้าตกค้าง
    }

    private function makeBot(bool $withTelegramPlugin = true): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        if ($withTelegramPlugin) {
            FlowPlugin::create([
                'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งเตือนแอดมิน',
                'enabled' => true, 'trigger_condition' => 'always',
                'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
            ]);
        }

        return $bot;
    }

    private function makeHandoverConversation(Bot $bot): Conversation
    {
        return Conversation::factory()->create([
            'bot_id' => $bot->id,
            'current_flow_id' => $bot->default_flow_id,
            'is_handover' => true,
        ]);
    }

    private function addMessage(Conversation $conversation, string $sender, string $content, Carbon $at): Message
    {
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
        ]);
        // created_at ไม่อยู่ใน $fillable ของ Message — backdate ผ่าน forceFill เหมือนที่เทสอื่นทำ
        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $message;
    }

    public function test_alerts_once_when_customer_waits_16_minutes_and_never_realerts_same_episode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'เอา 1 ตัว', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', '⏰ <b>ลูกค้ารอแอดมินตอบ 16 นาที</b>'));

        // รันรอบที่สอง (นาทีถัดไปของ scheduler) — ห้ามเตือนซ้ำตอนเดียวกัน
        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_does_not_alert_before_threshold_14_minutes(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'ยังอยู่ไหม', now()->subMinutes(14));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_alert_when_last_message_is_from_agent(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'สวัสดี', now()->subMinutes(30));
        $this->addMessage($conversation, 'agent', 'ตอบเองครับ', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_not_alert_when_conversation_is_not_in_handover(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $bot = $this->makeBot();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'current_flow_id' => $bot->default_flow_id,
            'is_handover' => false,
        ]);
        $this->addMessage($conversation, 'user', 'สนใจครับ', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_alerts_again_for_new_episode_after_agent_reply(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'เอา 1 ตัว', now()->subMinutes(48));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        Http::assertSentCount(1);

        // แอดมินตอบกลางคัน แล้วลูกค้าเขียนใหม่รออีก 16 นาที = ตอนรอใหม่ ต้องเตือนรอบที่ 2
        $this->addMessage($conversation, 'agent', 'ขอโทษครับ พอดีไม่เห็น', now()->subMinutes(32));
        $this->addMessage($conversation, 'user', 'ตอบเราได้นะ', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'ตอบเราได้นะ'));
    }

    public function test_does_not_realert_when_customer_writes_again_without_agent_reply(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'เอา 1 ตัว', now()->subMinutes(32));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        Http::assertSentCount(1);

        // ลูกค้าตามอีกครั้งโดยยังไม่มีใครตอบเลย = ตอนรอเดิม ห้ามเตือนซ้ำ
        $this->addMessage($conversation, 'user', 'เราทำอะไรผิด', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_does_not_alert_for_message_older_than_24_hours(): void
    {
        // กันน้ำท่วมตอน deploy ครั้งแรก — เคสค้างเก่ากว่าหน้าต่าง 24 ชม.ไม่เตือนย้อนหลัง
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', 'สวัสดี', now()->subHours(25));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_loads_only_conversations_with_recent_activity_and_alerts_only_the_fresh_one(): void
    {
        // รีวิวข้อบังคับ #1: is_handover ถาวร — query นอกต้องผูกหน้าต่าง 24 ชม.ผ่าน messages
        // (index conversation_id, created_at) ไม่งั้นเซ็ตที่ต้องโหลดโตทุกนาที
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $bot = $this->makeBot();
        $stale = $this->makeHandoverConversation($bot);
        $fresh = $this->makeHandoverConversation($bot);
        $this->addMessage($stale, 'user', 'สั่งของเมื่อสามวันก่อน', now()->subDays(3));
        $this->addMessage($fresh, 'user', 'สั่งของตอนนี้', now()->subMinutes(16));

        DB::enableQueryLog();
        $this->artisan('handover:alert-unanswered')->assertSuccessful();
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'สั่งของตอนนี้'));

        // คิวรี่หลักต้องมีเงื่อนไข exists บน messages ที่ผูก created_at ที่ cutoff 24 ชม.
        $mainQuery = $queries->first(fn ($q) => str_contains($q['query'], 'from "conversations"'));
        $this->assertNotNull($mainQuery, 'main conversations query not captured');
        $this->assertStringContainsString('exists', $mainQuery['query']);
        $this->assertStringContainsString('"messages"', $mainQuery['query']);
        $this->assertStringContainsString('created_at', $mainQuery['query']);
        $bindings = array_map(
            fn ($b) => $b instanceof \DateTimeInterface ? $b->format('Y-m-d H:i:s') : (string) $b,
            $mainQuery['bindings'],
        );
        $this->assertContains('2026-10-16 12:00:00', $bindings);
    }

    public function test_skips_silently_when_bot_has_no_telegram_plugin(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $conversation = $this->makeHandoverConversation($this->makeBot(withTelegramPlugin: false));
        $this->addMessage($conversation, 'user', 'เอา 1 ตัว', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_escapes_html_special_chars_in_customer_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', '<b>& ข้อความ', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', '&lt;b&gt;&amp; ข้อความ')
            && ! str_contains($r['text'] ?? '', '<b>&'));
    }

    public function test_alert_shows_customer_display_name_instead_of_external_id(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $bot = $this->makeBot();
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'current_flow_id' => $bot->default_flow_id,
            'is_handover' => true,
            'customer_profile_id' => CustomerProfile::factory()->create(['display_name' => 'คุณสมชาย'])->id,
        ]);
        $this->addMessage($conversation, 'user', 'เอา 1 ตัว', now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'คุณสมชาย'));
    }

    public function test_truncates_long_customer_message_to_200_chars(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $conversation = $this->makeHandoverConversation($this->makeBot());
        $this->addMessage($conversation, 'user', str_repeat('ก', 250), now()->subMinutes(16));

        $this->artisan('handover:alert-unanswered')->assertSuccessful();

        Http::assertSent(function ($r) {
            $text = $r['text'] ?? '';

            return str_contains($text, str_repeat('ก', 200))
                && ! str_contains($text, str_repeat('ก', 201));
        });
    }
}
