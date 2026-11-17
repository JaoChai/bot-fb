<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpCapabilities;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2b — FollowUpCapabilities::forBot
 *
 * สัญญาจาก plan §3.7 (lines 289-290) + b2b.md + D150:
 * - blocked_reason เช็คตามลำดับ: line_only, bot_not_in_pilot, telegram_missing,
 *   telegram_allowlist_missing, webhook_secret_missing, writer_missing, admin_url_missing
 * - telegram_allowlist_missing เมื่อ authorized_user_ids เป็นรูปร่างที่ปิด (D150):
 *   string, map, list ที่ไม่มี id ใช้ได้, false, 0 — ขาด/null/[] = ห้องเปิด ทุกคนกดได้
 * - automatic_allowed เมื่อ id อยู่ใน automatic_bot_ids และ follow_up_started_at
 *   น้อยกว่าหรือเท่ากับ now - 14 วันเท่านั้น
 * - ผลลัพธ์ต้องไม่มี token, chat id, user id หรือ URL หลุดออกมา
 */
class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bot = $this->makeReadyBot();
    }

    /** บอทที่ผ่านครบทุกเงื่อนไข — แต่ละเทสไปถอดสิ่งใดสิ่งหนึ่งออกทีละอย่าง */
    private function makeReadyBot(?array $pluginConfig = null): Bot
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => $pluginConfig ?? ['access_token' => 'tok-123', 'chat_id' => '999', 'authorized_user_ids' => ['555']],
        ]);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => true,
            'follow_up_mode' => 'approve',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $bot;
    }

    #[Test]
    public function test_passing_bot_yields_null_blocked_reason_and_exact_object(): void
    {
        $caps = FollowUpCapabilities::forBot($this->bot, $this->now());

        $this->assertSame([
            'supported' => true,
            'telegram_ready' => true,
            'automatic_allowed' => false,
            'blocked_reason' => null,
            'rules' => [
                'payment_wait_hours' => 3,
                'sale_wait_hours' => 24,
                'customer_gap_days' => 7,
                'daily_draft_cap' => 10,
                'approval_ttl_hours' => 12,
                'send_start' => '08:00',
                'send_end' => '22:00',
                'timezone' => 'Asia/Bangkok',
            ],
        ], $caps);
    }

    #[Test]
    public function test_facebook_channel_blocks_line_only(): void
    {
        $this->bot->update(['channel_type' => 'facebook']);

        $caps = FollowUpCapabilities::forBot($this->bot, $this->now());

        $this->assertSame('line_only', $caps['blocked_reason']);
        $this->assertFalse($caps['supported']);
    }

    #[Test]
    public function test_bot_not_in_pilot_blocks(): void
    {
        config(['customer_follow_up.bot_ids' => []]);

        $caps = FollowUpCapabilities::forBot($this->bot, $this->now());

        $this->assertSame('bot_not_in_pilot', $caps['blocked_reason']);
    }

    #[Test]
    public function test_missing_or_disabled_telegram_plugin_blocks(): void
    {
        // ไม่มี flow/plugin เลย
        $bare = Bot::factory()->active()->line()->create(['user_id' => $this->bot->user_id, 'primary_chat_model' => 'openai/gpt-6-luna']);
        config(['customer_follow_up.bot_ids' => [$bare->id]]);

        $this->assertSame('telegram_missing', FollowUpCapabilities::forBot($bare, $this->now())['blocked_reason']);

        // plugin มีแต่ disabled
        $flow = Flow::factory()->create(['bot_id' => $bare->id]);
        $bare->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => false,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tok-123', 'chat_id' => '999'],
        ]);

        $this->assertSame('telegram_missing', FollowUpCapabilities::forBot($bare, $this->now())['blocked_reason']);
    }

    #[Test]
    public function test_plugin_without_token_or_chat_id_blocks(): void
    {
        $noToken = $this->makeReadyBot(['access_token' => '', 'chat_id' => '999']);
        $noChat = $this->makeReadyBot(['access_token' => 'tok-123', 'chat_id' => '']);
        config(['customer_follow_up.bot_ids' => [$noToken->id, $noChat->id]]);

        $this->assertSame('telegram_missing', FollowUpCapabilities::forBot($noToken, $this->now())['blocked_reason']);
        $this->assertSame('telegram_missing', FollowUpCapabilities::forBot($noChat, $this->now())['blocked_reason']);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: bool, 2: ?string}> */
    public static function allowlistRuleD150Provider(): iterable
    {
        $base = ['access_token' => 'tok-123', 'chat_id' => '999'];
        $with = fn (mixed $allow): array => ['authorized_user_ids' => $allow] + $base;

        // OPEN ROOM (D150) — ไม่มี list = ห้องแจ้งเตือนคือสิทธิ์ ทุกคนในห้องกดได้
        yield 'key absent' => [$base, true, null];
        yield 'json null' => [$with(null), true, null];
        yield 'empty list' => [$with([]), true, null];

        // LIST — list ที่มี id ใช้ได้อย่างน้อยหนึ่ง = พร้อม (ความหมายเดิม)
        yield 'string id' => [$with(['555']), true, null];
        yield 'int id' => [$with([555]), true, null];
        yield 'padded id' => [$with([' 555 ']), true, null];
        yield 'blank first usable second' => [$with(['', '555']), true, null];

        // CLOSED (D150) — รูปร่างอื่นทั้งหมด
        yield 'bare string' => [$with('555'), false, 'telegram_allowlist_missing'];
        yield 'map' => [$with(['a' => '555']), false, 'telegram_allowlist_missing'];
        yield 'int key map' => [$with([7 => 7]), false, 'telegram_allowlist_missing'];
        yield 'list of empty' => [$with(['']), false, 'telegram_allowlist_missing'];
        yield 'list of blank' => [$with([' ']), false, 'telegram_allowlist_missing'];
        yield 'false' => [$with(false), false, 'telegram_allowlist_missing'];
        yield 'zero' => [$with(0), false, 'telegram_allowlist_missing'];
    }

    #[Test]
    #[DataProvider('allowlistRuleD150Provider')]
    public function test_allowlist_rule_d150_open_room_list_and_closed(array $pluginConfig, bool $expectedReady, ?string $expectedReason): void
    {
        $bot = $this->makeReadyBot($pluginConfig);
        config(['customer_follow_up.bot_ids' => [$bot->id]]);

        $caps = FollowUpCapabilities::forBot($bot, $this->now());

        $this->assertSame($expectedReady, $caps['supported']);
        $this->assertSame($expectedReady, $caps['telegram_ready']);
        $this->assertSame($expectedReason, $caps['blocked_reason']);

        // ผลลัพธ์ต้องไม่มี token / chat id / user id หลุดออกมา
        $this->assertSame(['supported', 'telegram_ready', 'automatic_allowed', 'blocked_reason', 'rules'], array_keys($caps));
        $json = json_encode($caps, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('tok-123', $json);
        $this->assertStringNotContainsString('999', $json);
        $this->assertStringNotContainsString('555', $json);
    }

    #[Test]
    public function test_missing_webhook_secret_blocks(): void
    {
        config(['services.telegram_alert.secret' => null]);

        $caps = FollowUpCapabilities::forBot($this->bot, $this->now());

        $this->assertSame('webhook_secret_missing', $caps['blocked_reason']);
    }

    #[Test]
    public function test_missing_writer_model_blocks(): void
    {
        $noModel = $this->makeReadyBot();
        $noModel->update(['primary_chat_model' => null, 'fallback_chat_model' => null]);

        $this->assertSame('writer_missing', FollowUpCapabilities::forBot($noModel, $this->now())['blocked_reason']);

        // fallback อย่างเดียวก็เป็น writer ที่ใช้ได้ (resolvedChatModel)
        $fallbackOnly = $this->makeReadyBot();
        $fallbackOnly->update(['primary_chat_model' => null, 'fallback_chat_model' => 'openai/gpt-6-luna']);

        $this->assertNull(FollowUpCapabilities::forBot($fallbackOnly, $this->now())['blocked_reason']);
    }

    #[Test]
    public function test_missing_admin_url_blocks(): void
    {
        config(['customer_follow_up.admin_web_url' => null]);

        $caps = FollowUpCapabilities::forBot($this->bot, $this->now());

        $this->assertSame('admin_url_missing', $caps['blocked_reason']);
    }

    #[Test]
    public function test_automatic_allowed_needs_host_list_and_14_days(): void
    {
        config(['customer_follow_up.automatic_bot_ids' => [$this->bot->id]]);

        $this->setStartedAt('2026-11-02 10:00:01'); // 13 วัน 23:59 ก่อน now

        $this->assertFalse(FollowUpCapabilities::forBot($this->bot, $this->now())['automatic_allowed']);

        $this->setStartedAt('2026-11-02 10:00:00'); // 14 วันพอดี

        $this->assertTrue(FollowUpCapabilities::forBot($this->bot, $this->now())['automatic_allowed']);

        // 14 วันแล้วแต่ไม่อยู่ในลิสต์ฝั่ง host
        config(['customer_follow_up.automatic_bot_ids' => [$this->bot->id + 1]]);

        $this->assertFalse(FollowUpCapabilities::forBot($this->bot, $this->now())['automatic_allowed']);

        // อยู่ในลิสต์แต่ยังไม่เคยเปิด (started_at null)
        config(['customer_follow_up.automatic_bot_ids' => [$this->bot->id]]);
        DB::table('bot_settings')->where('bot_id', $this->bot->id)->update(['follow_up_started_at' => null]);

        $this->assertFalse(FollowUpCapabilities::forBot($this->bot, $this->now())['automatic_allowed']);
    }

    #[Test]
    public function test_result_carries_no_secrets(): void
    {
        config(['customer_follow_up.automatic_bot_ids' => [$this->bot->id]]);
        $this->setStartedAt('2026-11-02 10:00:00');

        $json = json_encode(FollowUpCapabilities::forBot($this->bot, $this->now()), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('tok-123', $json);
        $this->assertStringNotContainsString('999', $json);
        $this->assertStringNotContainsString('555', $json);
        $this->assertStringNotContainsString('webhook-secret-test', $json);
        $this->assertStringNotContainsString('admin.example.test', $json);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function setStartedAt(string $value): void
    {
        DB::table('bot_settings')->where('bot_id', $this->bot->id)->update(['follow_up_started_at' => $value]);
    }
}
