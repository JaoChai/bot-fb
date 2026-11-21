<?php

namespace Tests\Feature\FollowUp;

use App\Http\Resources\BotResource;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpEligibility;
use App\Services\FollowUp\FollowUpSalesProjector;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B5a — สวิตช์ "ตามลูกค้า" รายบอท บน Settings API (Lead D60-D66)
 *
 * สัญญาจากการ์ด:
 * - อ่าน: ทุก response มีคีย์ follow_up_enabled (JSON bool), follow_up_mode,
 *   follow_up_started_at (ISO 8601 ของ instant เดียวกับเวลากรุงเทพฯ ที่เก็บ) และ
 *   follow_up_capabilities; คอลัมน์ภายในสองตัวห้ามออก response ทุกที่
 * - เขียน follow_up_enabled: JSON true/false เท่านั้น; false→true ต้อง supported
 *   ไม่งั้น 422 ด้วย blocked_reason; snapshot เขียนครั้งเดียว กันด้วย whereNull
 *   และไม่มีวันขยับ; true→true เป็น no-op ไม่เช็ค capabilities
 * - เขียน follow_up_mode: automatic ได้เมื่อสวิตช์เปิดและ automatic_allowed เท่านั้น;
 *   ปิดสวิตช์รีเซ็ตเป็น approve เสมอ
 * - คีย์ต้องห้าม (snapshot/capabilities) → 422 เสมอ; ทุกอย่างหรือไม่มีอะไรเลย
 */
class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    /**
     * รายการคีย์ที่หน้า settings ส่งจริงวันนี้ (frontend/src/pages/BotSettingsPage.tsx
     * handleSave — คัดลอกมาตรง ๆ ทั้ง 31 คีย์ ไม่มีคีย์ follow_up_* และไม่มี lead_recovery_*)
     */
    private const PAGE_SAVE_PAYLOAD = [
        'daily_message_limit' => 1000,
        'per_user_limit' => 100,
        'rate_limit_bot_message' => 'ข้อความโดนจำกัดอัตราครับ',
        'rate_limit_user_message' => 'พิมพ์เร็วไปนิดครับ',
        'hitl_enabled' => true,
        'response_hours_enabled' => true,
        'response_hours' => ['mon' => [['start' => '09:00', 'end' => '18:00']]],
        'response_hours_timezone' => 'Asia/Bangkok',
        'offline_message' => 'ออฟไลน์อยู่ครับ',
        'multiple_bubbles_enabled' => true,
        'multiple_bubbles_min' => 2,
        'multiple_bubbles_max' => 4,
        'wait_multiple_bubbles_enabled' => true,
        'wait_multiple_bubbles_ms' => 800,
        'smart_aggregation_enabled' => true,
        'smart_min_wait_ms' => 600,
        'smart_max_wait_ms' => 2000,
        'smart_early_trigger_enabled' => false,
        'smart_per_user_learning_enabled' => false,
        'reply_sticker_enabled' => true,
        'reply_sticker_message' => 'สวัสดีครับ',
        'reply_sticker_mode' => 'static',
        'reply_sticker_ai_prompt' => null,
        'slip_verification_enabled' => true,
        'slip_receiver_account' => '123-x',
        'slip_amount_tolerance' => 1.5,
        'slip_success_message' => 'รับสลิปแล้วครับ',
        'slip_fail_message' => 'สลิปไม่ผ่านครับ',
        'outage_recovery_enabled' => true,
        'outage_recovery_message_th' => null,
        'outage_down_message' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'Asia/Bangkok'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures — ค่าที่รูปร่างเป็น credential ประกอบร่างตอนรันไทม์ (กัน gitleaks)
    // ------------------------------------------------------------------

    /**
     * บอทพื้นฐาน: LINE + pilot + plugin telegram (token, chat id, allowlist ชัดเจน)
     * + secret/writer/admin_url ครบ และแถว bot_settings ที่สวิตช์ปิด โหมด approve
     * snapshot null + สามข้อความในตาราง
     *
     * @return array{0: User, 1: Bot}
     */
    private function makeBaseline(?array $pluginConfig = null): array
    {
        [$user, $bot] = $this->makeSupportedBot($pluginConfig);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => false,
            'follow_up_mode' => 'approve',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        Message::factory()->count(3)->create(['conversation_id' => $room->id]);

        return [$user, $bot];
    }

    /** บอทที่ capabilities ผ่านทุกเงื่อนไข (ยังไม่มีแถว bot_settings) */
    private function makeSupportedBot(?array $pluginConfig = null): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        // append เข้ารายชื่อ pilot — makeBaseline ตัวที่สองต้องไม่ทับบอทตัวแรก
        config(['customer_follow_up.bot_ids' => array_values(array_unique(array_merge(
            (array) config('customer_follow_up.bot_ids'),
            [$bot->id]
        )))]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'whsec-'.'b5a'.'-webhook-secret']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => $pluginConfig ?? [
                'access_token' => 'tgk-'.'b5a'.'-alert-token',
                'chat_id' => '-100'.'4567',
                'authorized_user_ids' => ['987'.'65432'],
            ],
        ]);

        return [$user, $bot];
    }

    /** บอทตัวเดิมสลับครั้งละหนึ่งเรื่องเพื่อให้ blocked_reason เป็นโค้ดที่ต้องการ */
    private function mutateBot(Bot $bot, string $reason): void
    {
        switch ($reason) {
            case 'line_only':
                $bot->update(['channel_type' => 'facebook']);

                break;
            case 'bot_not_in_pilot':
                config(['customer_follow_up.bot_ids' => [999999]]);

                break;
            case 'telegram_missing':
                FlowPlugin::query()->update(['enabled' => false]);

                break;
            case 'telegram_allowlist_missing':
                // D150: รูปร่าง CLOSED (map) — supported เป็น false เหมือนเดิม
                FlowPlugin::query()->update(['config' => json_encode([
                    'access_token' => 'tgk-'.'b5a'.'-alert-token',
                    'chat_id' => '-100'.'4567',
                    'authorized_user_ids' => ['a' => '555'],
                ])]);

                break;
            case 'webhook_secret_missing':
                config(['services.telegram_alert.secret' => '']);

                break;
            case 'writer_missing':
                $bot->update(['primary_chat_model' => '', 'fallback_chat_model' => null]);

                break;
            case 'admin_url_missing':
                config(['customer_follow_up.admin_web_url' => null]);

                break;
        }
    }

    private function row(int $botId): ?object
    {
        return DB::table('bot_settings')->where('bot_id', $botId)->first();
    }

    private function castId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * ห้าคอลัมน์ follow-up ของแถว ตรงค่าที่กำหนดเป๊ะ (byte-equal)
     *
     * @param  array{enabled: bool, mode: string, started_at: ?string, activation_id: ?int, activated_at: ?string}  $expected
     */
    private function assertColumns(object $row, array $expected, string $message = ''): void
    {
        $this->assertSame($expected['enabled'], (bool) $row->follow_up_enabled, $message.' follow_up_enabled');
        $this->assertSame($expected['mode'], $row->follow_up_mode, $message.' follow_up_mode');
        $this->assertSame($expected['started_at'], $row->follow_up_started_at, $message.' follow_up_started_at');
        $this->assertSame($expected['activation_id'], $this->castId($row->follow_up_activation_message_id), $message.' follow_up_activation_message_id');
        $this->assertSame($expected['activated_at'], $row->follow_up_activated_at, $message.' follow_up_activated_at');
    }

    private function assertInternalKeysAbsent($data, string $context): void
    {
        $this->assertIsArray($data, $context);
        $this->assertArrayNotHasKey('follow_up_activation_message_id', $data, $context);
        $this->assertArrayNotHasKey('follow_up_activated_at', $data, $context);
    }

    private function putSettings(User $user, Bot $bot, array $payload): TestResponse
    {
        return $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", $payload);
    }

    private function patchSettings(User $user, Bot $bot, array $payload): TestResponse
    {
        return $this->actingAs($user)->patchJson("/api/bots/{$bot->id}/settings", $payload);
    }

    // ------------------------------------------------------------------
    // เปิดสวิตช์: snapshot ครั้งเดียว + capabilities ใน response
    // ------------------------------------------------------------------

    #[Test]
    public function test_owner_enables_by_put_once_with_snapshot_and_capabilities_in_response(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $highWater = (int) DB::table('messages')->max('id');

        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);

        $put->assertOk();
        $data = $put->json('data');
        $this->assertTrue($data['follow_up_enabled']);
        $this->assertSame('approve', $data['follow_up_mode']);
        // เก็บเป็นเวลากรุงเทพฯ 2026-11-16 10:00:00 = instant 2026-11-16T03:00:00Z
        $this->assertSame(
            CarbonImmutable::parse('2026-11-16T03:00:00Z')->getTimestamp(),
            CarbonImmutable::parse($data['follow_up_started_at'])->getTimestamp()
        );
        $this->assertStringEndsWith('Z', $data['follow_up_started_at']);
        $this->assertSame(
            FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::now()),
            $data['follow_up_capabilities']
        );
        $this->assertInternalKeysAbsent($data, 'PUT response data');

        $this->assertColumns($this->row($bot->id), [
            'enabled' => true,
            'mode' => 'approve',
            'started_at' => self::NOW,
            'activation_id' => $highWater,
            'activated_at' => self::NOW,
        ], 'stored row after PUT true');
    }

    #[Test]
    public function test_owner_enables_by_patch_the_same_way(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $highWater = (int) DB::table('messages')->max('id');

        $patch = $this->patchSettings($user, $bot, ['follow_up_enabled' => true]);

        $patch->assertOk();
        $data = $patch->json('data');
        $this->assertTrue($data['follow_up_enabled']);
        $this->assertSame(
            CarbonImmutable::parse('2026-11-16T03:00:00Z')->getTimestamp(),
            CarbonImmutable::parse($data['follow_up_started_at'])->getTimestamp()
        );
        $this->assertSame(
            FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::now()),
            $data['follow_up_capabilities']
        );
        $this->assertInternalKeysAbsent($data, 'PATCH response data');

        $this->assertColumns($this->row($bot->id), [
            'enabled' => true,
            'mode' => 'approve',
            'started_at' => self::NOW,
            'activation_id' => $highWater,
            'activated_at' => self::NOW,
        ], 'stored row after PATCH true');
    }

    // ------------------------------------------------------------------
    // ปฏิเสธการเปิด — เจ็ดโค้ด บอทพื้นฐานเปลี่ยนสิ่งเดียวต่อเคส (ชื่อบอกสิ่งที่เปลี่ยน)
    // ------------------------------------------------------------------

    private function refuseEnable(string $reason, \Closure $mutate): void
    {
        [$user, $bot] = $this->makeBaseline();
        $mutate($bot);
        $before = $this->row($bot->id);

        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);

        $put->assertStatus(422);
        $this->assertSame($reason, $put->json('errors.follow_up_enabled.0'), $reason);
        $this->assertSame((array) $before, (array) $this->row($bot->id), 'row must be byte-identical after refusal');
    }

    #[Test]
    public function test_enable_refused_when_channel_is_not_line(): void
    {
        $this->refuseEnable('line_only', fn (Bot $bot) => $this->mutateBot($bot, 'line_only'));
    }

    #[Test]
    public function test_enable_refused_when_bot_not_in_pilot_list(): void
    {
        $this->refuseEnable('bot_not_in_pilot', fn (Bot $bot) => $this->mutateBot($bot, 'bot_not_in_pilot'));
    }

    #[Test]
    public function test_enable_refused_when_no_enabled_telegram_plugin(): void
    {
        $this->refuseEnable('telegram_missing', fn (Bot $bot) => $this->mutateBot($bot, 'telegram_missing'));
    }

    #[Test]
    public function test_enable_refused_when_plugin_allowlist_shape_is_closed(): void
    {
        $this->refuseEnable('telegram_allowlist_missing', fn (Bot $bot) => $this->mutateBot($bot, 'telegram_allowlist_missing'));
    }

    #[Test]
    public function test_enable_refused_when_webhook_secret_missing(): void
    {
        $this->refuseEnable('webhook_secret_missing', fn (Bot $bot) => $this->mutateBot($bot, 'webhook_secret_missing'));
    }

    #[Test]
    public function test_enable_refused_when_bot_has_no_chat_model(): void
    {
        $this->refuseEnable('writer_missing', fn (Bot $bot) => $this->mutateBot($bot, 'writer_missing'));
    }

    #[Test]
    public function test_enable_refused_when_admin_url_missing(): void
    {
        $this->refuseEnable('admin_url_missing', fn (Bot $bot) => $this->mutateBot($bot, 'admin_url_missing'));
    }

    // ------------------------------------------------------------------
    // รูปร่างค่า follow_up_enabled — JSON true/false เท่านั้น
    // ------------------------------------------------------------------

    #[Test]
    public function test_enabled_value_must_be_strict_json_boolean(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $before = $this->row($bot->id);

        foreach ([1, 0, '1', 'true', 'false', null, []] as $shape) {
            $put = $this->putSettings($user, $bot, ['follow_up_enabled' => $shape]);

            $put->assertStatus(422, 'shape '.var_export($shape, true).' must be refused');
            $this->assertArrayHasKey('follow_up_enabled', $put->json('errors'), 'errors must point at follow_up_enabled for '.var_export($shape, true));
        }

        $this->assertSame((array) $before, (array) $this->row($bot->id), 'row unchanged after every refused shape');

        // false บนพื้นฐาน → 200 และไม่มีอะไรเปลี่ยน
        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => false]);
        $put->assertOk();
        $this->assertSame((array) $before, (array) $this->row($bot->id), 'false on the baseline changes nothing');
    }

    // ------------------------------------------------------------------
    // Snapshot ครั้งเดียว — ปิดแล้วเปิดใหม่ต้องไม่ขยับ และไม่กระทบบอทอื่น
    // ------------------------------------------------------------------

    #[Test]
    public function test_snapshot_is_written_once_across_off_on_cycles_and_second_bot_untouched(): void
    {
        [$user, $bot] = $this->makeBaseline();

        // บอทที่สอง: แถว snapshot null ต้องเหมือนเดิมทั้งการทดสอบ
        [$userB, $botB] = $this->makeBaseline();

        // high-water อ่านก่อนเปิด (max ทั้งตาราง รวมข้อความของบอทที่สองด้วย)
        $highWater = (int) DB::table('messages')->max('id');
        $rowBBefore = $this->row($botB->id);

        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();
        $afterFirst = $this->row($bot->id);
        $this->assertColumns($afterFirst, [
            'enabled' => true,
            'mode' => 'approve',
            'started_at' => self::NOW,
            'activation_id' => $highWater,
            'activated_at' => self::NOW,
        ], 'first enable');
        $this->assertSame((array) $rowBBefore, (array) $this->row($botB->id), 'second bot row untouched after first enable');

        // เพิ่มสองข้อความ + ย้ายนาฬิกาหนึ่งวัน แล้วปิดสวิตช์
        $room2 = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        Message::factory()->count(2)->create(['conversation_id' => $room2->id]);
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 10:00:00', 'Asia/Bangkok'));

        $off = $this->putSettings($user, $bot, ['follow_up_enabled' => false]);
        $off->assertOk();
        $rowOff = $this->row($bot->id);
        $this->assertFalse((bool) $rowOff->follow_up_enabled);
        $this->assertSame('approve', $rowOff->follow_up_mode);
        $this->assertSame(self::NOW, $rowOff->follow_up_started_at, 'snapshot started_at byte-equal through the off cycle');
        $this->assertSame($highWater, (int) $rowOff->follow_up_activation_message_id, 'activation id byte-equal through the off cycle');
        $this->assertSame(self::NOW, $rowOff->follow_up_activated_at, 'snapshot activated_at byte-equal through the off cycle');
        $this->assertSame((array) $rowBBefore, (array) $this->row($botB->id), 'second bot row untouched after off');

        $on = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);
        $on->assertOk();
        $rowOn = $this->row($bot->id);
        $this->assertTrue((bool) $rowOn->follow_up_enabled);
        // snapshot ต้องเท่าค่าเดิมทุกไบต์ (started/activated ยังเป็นครั้งแรก, id เดิม ไม่ใช่ max ใหม่ 5)
        $this->assertSame(self::NOW, $rowOn->follow_up_started_at, 'snapshot started_at never moves');
        $this->assertSame(self::NOW, $rowOn->follow_up_activated_at, 'snapshot activated_at never moves');
        $this->assertSame($highWater, (int) $rowOn->follow_up_activation_message_id, 'activation id stays the first high-water');
        $this->assertSame((array) $rowBBefore, (array) $this->row($botB->id), 'second bot row untouched after re-enable');
    }

    #[Test]
    public function test_already_on_with_blocked_capabilities_still_saves_other_settings(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();
        $afterEnable = $this->row($bot->id);

        // เปลี่ยนเป็นรูปร่าง CLOSED (D150: string ไม่ใช่ list) ทำให้ supported เป็น false แล้ว
        FlowPlugin::query()->update(['config' => json_encode([
            'access_token' => 'tgk-'.'b5a'.'-alert-token',
            'chat_id' => '-100'.'4567',
            'authorized_user_ids' => '555',
        ])]);

        // true→true เป็น no-op ไม่เช็ค capabilities — ช่องอื่นยังบันทึกได้
        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true, 'offline_message' => 'x']);
        $put->assertOk();
        $this->assertSame('x', $put->json('data.offline_message'));
        $row = $this->row($bot->id);
        $this->assertTrue((bool) $row->follow_up_enabled);
        $this->assertSame('x', $row->offline_message);
        $this->assertColumns($row, [
            'enabled' => true,
            'mode' => 'approve',
            'started_at' => self::NOW,
            'activation_id' => (int) $afterEnable->follow_up_activation_message_id,
            'activated_at' => self::NOW,
        ], 'follow-up columns unchanged by the no-op save');

        $this->putSettings($user, $bot, ['follow_up_enabled' => false])->assertOk();

        $refused = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);
        $refused->assertStatus(422);
        $this->assertSame('telegram_allowlist_missing', $refused->json('errors.follow_up_enabled.0'));
    }

    #[Test]
    public function test_owner_enables_when_plugin_is_open_room_for_each_open_shape(): void
    {
        foreach ([
            'key absent' => ['access_token' => 'tgk-'.'b5a'.'-alert-token', 'chat_id' => '-100'.'4567'],
            'json null' => ['access_token' => 'tgk-'.'b5a'.'-alert-token', 'chat_id' => '-100'.'4567', 'authorized_user_ids' => null],
            'empty list' => ['access_token' => 'tgk-'.'b5a'.'-alert-token', 'chat_id' => '-100'.'4567', 'authorized_user_ids' => []],
        ] as $label => $config) {
            [$user, $bot] = $this->makeBaseline();
            FlowPlugin::query()->update(['config' => json_encode($config)]);
            $highWater = (int) DB::table('messages')->max('id');

            $get = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");
            $get->assertOk();
            $this->assertTrue($get->json('data.follow_up_capabilities.supported'), $label.': capabilities of the settings GET must be supported');
            $this->assertNull($get->json('data.follow_up_capabilities.blocked_reason'), $label.': capabilities of the settings GET must have no reason');

            $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);

            $put->assertStatus(200, $label);
            $this->assertTrue($put->json('data.follow_up_enabled'), $label.': the switch must be on');
            $this->assertTrue($put->json('data.follow_up_capabilities.supported'), $label.': capabilities in the PUT response must be supported');
            $this->assertColumns($this->row($bot->id), [
                'enabled' => true,
                'mode' => 'approve',
                'started_at' => self::NOW,
                'activation_id' => $highWater,
                'activated_at' => self::NOW,
            ], $label.': columns of a normal first enable');
        }
    }

    // ------------------------------------------------------------------
    // ไม่ส่งคีย์ follow-up เลย — หน้าเว็บวันนี้ต้องเซฟได้เหมือนเดิม
    // ------------------------------------------------------------------

    #[Test]
    public function test_page_save_without_follow_up_keys_leaves_five_columns_untouched(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();
        $before = $this->row($bot->id);

        $put = $this->putSettings($user, $bot, self::PAGE_SAVE_PAYLOAD);

        $put->assertOk();
        // ช่องอื่นของหน้าเว็บบันทึกได้ตามปกติ — ต้องเท่าเดิมเฉพาะห้าคอลัมน์ follow-up
        $after = $this->row($bot->id);
        $this->assertColumns($after, [
            'enabled' => (bool) $before->follow_up_enabled,
            'mode' => $before->follow_up_mode,
            'started_at' => $before->follow_up_started_at,
            'activation_id' => $this->castId($before->follow_up_activation_message_id),
            'activated_at' => $before->follow_up_activated_at,
        ], 'five follow-up columns byte-equal after a page PUT');

        $patch = $this->patchSettings($user, $bot, ['hitl_enabled' => true]);
        $patch->assertOk();
        $this->assertTrue($patch->json('data.hitl_enabled'));
        $afterPatch = $this->row($bot->id);
        $this->assertColumns($afterPatch, [
            'enabled' => (bool) $before->follow_up_enabled,
            'mode' => $before->follow_up_mode,
            'started_at' => $before->follow_up_started_at,
            'activation_id' => $this->castId($before->follow_up_activation_message_id),
            'activated_at' => $before->follow_up_activated_at,
        ], 'five follow-up columns byte-equal after a PATCH without follow-up keys');
    }

    // ------------------------------------------------------------------
    // คีย์ต้องห้าม — snapshot และ capabilities ห้ามเขียนผ่าน request
    // ------------------------------------------------------------------

    #[Test]
    public function test_prohibited_snapshot_keys_are_refused(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $cases = [
            ['follow_up_started_at', '2026-11-01 08:00:00'],
            ['follow_up_started_at', null],
            ['follow_up_activation_message_id', 999],
            ['follow_up_activation_message_id', null],
            ['follow_up_activated_at', '2026-11-01 08:00:00'],
            ['follow_up_activated_at', null],
            ['follow_up_capabilities', ['supported' => true]],
            ['follow_up_capabilities', null],
        ];

        foreach ($cases as [$key, $value]) {
            $before = $this->row($bot->id);

            $put = $this->putSettings($user, $bot, [$key => $value, 'offline_message' => 'x']);

            $put->assertStatus(422, "{$key} (".var_export($value, true).') must be refused');
            $this->assertArrayHasKey($key, $put->json('errors'), "errors must point at {$key}");
            $this->assertSame((array) $before, (array) $this->row($bot->id), "row unchanged after refusing {$key}");
            $this->assertNull($this->row($bot->id)->offline_message, "offline_message must not be saved beside {$key}");
        }
    }

    // ------------------------------------------------------------------
    // B5b — คีย์รุ่นเก่า lead_recovery_* ห้ามส่ง (สวิตช์เก่าที่ยังไม่มีอะไรส่ง)
    // ------------------------------------------------------------------

    /** ชุดคีย์/ค่ารุ่นเก่า: ทุกตัวที่ขึ้นต้น lead_recovery_ ต้องถูกปฏิเสธ ไม่สนค่าใด ๆ รวม null */
    private function legacyKeyCases(): array
    {
        return [
            ['lead_recovery_enabled', true],
            ['lead_recovery_enabled', false],
            ['lead_recovery_enabled', null],
            ['lead_recovery_mode', 'approve'],
            ['lead_recovery_message', 'ข้อความตามลูกค้า (ของสังเคราะห์)'],
            ['lead_recovery_timeout_hours', 4],
            ['lead_recovery_max_attempts', 2],
            ['lead_recovery_anything', ['x' => 1]],
        ];
    }

    #[Test]
    public function test_legacy_lead_recovery_keys_are_refused_on_put_and_patch(): void
    {
        [$user, $bot] = $this->makeBaseline();

        foreach ($this->legacyKeyCases() as [$key, $value]) {
            foreach (['alone', 'beside_offline_message'] as $mode) {
                foreach (['put', 'patch'] as $method) {
                    $payload = $mode === 'alone'
                        ? [$key => $value]
                        : [$key => $value, 'offline_message' => 'x'];
                    $before = $this->row($bot->id);

                    $response = $method === 'put'
                        ? $this->putSettings($user, $bot, $payload)
                        : $this->patchSettings($user, $bot, $payload);

                    $label = "{$method} {$key} (".var_export($value, true).") {$mode}";
                    $response->assertStatus(422, "{$label} must be refused");
                    $this->assertSame(
                        "The {$key} field must not be sent.",
                        $response->json("errors.{$key}.0"),
                        "{$label} must point errors at the key with the exact message"
                    );
                    $this->assertSame((array) $before, (array) $this->row($bot->id), "{$label} must leave the row byte-equal");
                    $this->assertNull($this->row($bot->id)->offline_message, "{$label} must not save offline_message");
                }
            }
        }
    }

    #[Test]
    public function test_legacy_key_refusal_on_bot_without_settings_row_creates_no_row(): void
    {
        [$user, $bot] = $this->makeSupportedBot();

        $put = $this->putSettings($user, $bot, ['lead_recovery_enabled' => true]);
        $patch = $this->patchSettings($user, $bot, ['lead_recovery_anything' => 1]);

        $put->assertStatus(422, 'PUT lead_recovery_enabled on a bot without a settings row must be refused');
        $patch->assertStatus(422, 'PATCH lead_recovery_anything on a bot without a settings row must be refused');
        $this->assertNull($this->row($bot->id), 'a refused legacy request must not create the settings row');
    }

    #[Test]
    public function test_keys_without_the_exact_prefix_are_not_refused_by_this_rule(): void
    {
        [$user, $bot] = $this->makeBaseline();

        foreach (['leadrecovery_enabled', 'my_lead_recovery_enabled'] as $key) {
            $before = $this->row($bot->id);

            $put = $this->putSettings($user, $bot, [$key => true, 'offline_message' => 'x']);

            $put->assertOk("{$key} must not be refused by the lead_recovery_ prefix rule");
            $put->assertJsonMissingValidationErrors([$key], "{$key} must not be a validation error");
            $after = $this->row($bot->id);
            $this->assertSame('x', $after->offline_message, "{$key} beside offline_message must still save offline_message");
            $this->assertColumns($after, [
                'enabled' => (bool) $before->follow_up_enabled,
                'mode' => $before->follow_up_mode,
                'started_at' => $before->follow_up_started_at,
                'activation_id' => $this->castId($before->follow_up_activation_message_id),
                'activated_at' => $before->follow_up_activated_at,
            ], "{$key} must not touch the five follow-up columns");
        }
    }

    // ------------------------------------------------------------------
    // ทุกอย่างหรือไม่มีอะไรเลย
    // ------------------------------------------------------------------

    #[Test]
    public function test_refusal_writes_nothing_all_or_nothing(): void
    {
        // บอทที่ไม่ supported + ช่องอื่นข้างใน → 422 และไม่บันทึกอย่างอื่น
        [$user, $bot] = $this->makeBaseline();
        $this->mutateBot($bot, 'bot_not_in_pilot');

        $refused = $this->putSettings($user, $bot, ['follow_up_enabled' => true, 'offline_message' => 'x']);
        $refused->assertStatus(422);
        $this->assertNull($this->row($bot->id)->offline_message, 'offline_message must not be saved when the enable is refused');

        // 422 จาก validation ของช่องอื่น → สวิตช์ยังปิด snapshot ยัง null
        [$user2, $bot2] = $this->makeBaseline();
        $invalid = $this->putSettings($user2, $bot2, ['follow_up_enabled' => true, 'daily_message_limit' => -1]);
        $invalid->assertStatus(422);
        $row2 = $this->row($bot2->id);
        $this->assertFalse((bool) $row2->follow_up_enabled);
        $this->assertNull($row2->follow_up_started_at);

        // ปฏิเสธบนบอทที่ยังไม่มีแถว → ต้องไม่เกิดแถวใหม่
        [$user3, $bot3] = $this->makeSupportedBot();
        config(['customer_follow_up.admin_web_url' => null]);
        $noRow = $this->putSettings($user3, $bot3, ['follow_up_enabled' => true]);
        $noRow->assertStatus(422);
        $this->assertNull($this->row($bot3->id), 'a refused request on a bot without a settings row creates no row');
    }

    // ------------------------------------------------------------------
    // โหมด automatic
    // ------------------------------------------------------------------

    /** พื้นฐาน-หลังเปิด: เปิดสวิตช์ผ่าน API แล้ว (snapshot = NOW) */
    private function enabledBaseline(): array
    {
        [$user, $bot] = $this->makeBaseline();
        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();

        return [$user, $bot];
    }

    #[Test]
    public function test_mode_automatic_accepted_at_14_days(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $put = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic']);

        $put->assertOk();
        $this->assertSame('automatic', $this->row($bot->id)->follow_up_mode);
    }

    #[Test]
    public function test_mode_automatic_refused_at_13d23h59m59s(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 09:59:59', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 09:59:59', 'Asia/Bangkok'));

        $put = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertArrayHasKey('follow_up_mode', $put->json('errors'));
        $this->assertSame('approve', $this->row($bot->id)->follow_up_mode);
    }

    #[Test]
    public function test_mode_automatic_refused_when_bot_not_in_automatic_list(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [999999]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $put = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertArrayHasKey('follow_up_mode', $put->json('errors'));
        $this->assertSame('approve', $this->row($bot->id)->follow_up_mode);
    }

    #[Test]
    public function test_mode_automatic_refused_when_switch_is_off(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $this->putSettings($user, $bot, ['follow_up_enabled' => false])->assertOk();
        $afterOff = $this->row($bot->id);
        $this->assertSame('approve', $afterOff->follow_up_mode, 'switching off resets the mode to approve');

        $put = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertArrayHasKey('follow_up_mode', $put->json('errors'));
        $this->assertSame('approve', $this->row($bot->id)->follow_up_mode);
    }

    #[Test]
    public function test_disable_with_automatic_mode_refused_and_writes_nothing(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $before = $this->row($bot->id);

        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => false, 'follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertArrayHasKey('follow_up_mode', $put->json('errors'));
        $this->assertSame((array) $before, (array) $this->row($bot->id), 'nothing written when the disable+automatic combination is refused');
    }

    #[Test]
    public function test_first_enable_with_automatic_mode_refused_and_writes_nothing(): void
    {
        [$user, $bot] = $this->makeBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);

        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true, 'follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertArrayHasKey('follow_up_mode', $put->json('errors'));
        $row = $this->row($bot->id);
        $this->assertFalse((bool) $row->follow_up_enabled, 'switch still off after the refused combination');
        $this->assertNull($row->follow_up_started_at, 'snapshot still null after the refused combination');
    }

    #[Test]
    public function test_stored_automatic_resets_to_approve_on_disable_and_stays_on_reenable(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic'])->assertOk();
        $this->assertSame('automatic', $this->row($bot->id)->follow_up_mode);
        $snapshot = [
            $this->row($bot->id)->follow_up_started_at,
            $this->row($bot->id)->follow_up_activation_message_id,
            $this->row($bot->id)->follow_up_activated_at,
        ];

        $this->putSettings($user, $bot, ['follow_up_enabled' => false])->assertOk();
        $this->assertSame('approve', $this->row($bot->id)->follow_up_mode, 'disable resets automatic to approve');

        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();
        $row = $this->row($bot->id);
        $this->assertTrue((bool) $row->follow_up_enabled);
        $this->assertSame('approve', $row->follow_up_mode, 're-enable does not resurrect automatic');
        $this->assertSame($snapshot, [$row->follow_up_started_at, $row->follow_up_activation_message_id, $row->follow_up_activated_at]);
    }

    #[Test]
    public function test_stored_automatic_repeat_is_noop_even_when_removed_from_automatic_list(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic'])->assertOk();

        // เอาบอทออกจากรายการ automatic — automatic ที่เก็บไว้ซ้ำยังเป็น no-op ที่ยอมรับ
        config(['customer_follow_up.automatic_bot_ids' => []]);

        $repeat = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic', 'offline_message' => 'x']);
        $repeat->assertOk();
        $this->assertSame('automatic', $this->row($bot->id)->follow_up_mode);
        $this->assertSame('x', $this->row($bot->id)->offline_message);

        $back = $this->putSettings($user, $bot, ['follow_up_mode' => 'approve']);
        $back->assertOk();
        $this->assertSame('approve', $this->row($bot->id)->follow_up_mode);
    }

    #[Test]
    public function test_mode_value_must_be_approve_or_automatic(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        $before = $this->row($bot->id);

        foreach (['auto', '', null, 1] as $shape) {
            $put = $this->putSettings($user, $bot, ['follow_up_mode' => $shape]);

            $put->assertStatus(422, 'shape '.var_export($shape, true).' must be refused');
            $this->assertArrayHasKey('follow_up_mode', $put->json('errors'), 'errors must point at follow_up_mode');
        }

        $this->assertSame((array) $before, (array) $this->row($bot->id), 'row unchanged after every refused mode shape');
    }

    // ------------------------------------------------------------------
    // B3b — สถานะหลับ (dormant): รายชื่อ automatic ว่าง = ห้ามเปลี่ยนเป็น automatic
    // ส่วนค่า automatic ที่เก็บไว้แล้วพิมพ์ซ้ำยังเป็น no-op ที่ยอมรับ เหมือนเดิมบน main
    // ------------------------------------------------------------------

    #[Test]
    public function test_dormant_empty_host_list_rejects_change_to_automatic_422(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        // ลูกค้า (CUSTOMER_FOLLOW_UP_AUTOMATIC_BOT_IDS) ว่าง — ทุกบอทยังกดอนุมัติเอง
        config(['customer_follow_up.automatic_bot_ids' => []]);
        $before = $this->row($bot->id);
        $this->assertSame('approve', $before->follow_up_mode, 'พื้นฐานต้องเริ่มจากโหมด approve');

        $put = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic']);

        $put->assertStatus(422);
        $this->assertSame(['automatic_not_allowed'], $put->json('errors.follow_up_mode'));
        $row = $this->row($bot->id);
        $this->assertSame('approve', $row->follow_up_mode, 'แถวยังเป็น approve หลังถูกปฏิเสธ');
        $this->assertSame((array) $before, (array) $row, 'แถวคงเดิมทุกไบต์หลังถูกปฏิเสธ');
    }

    #[Test]
    public function test_already_automatic_noop_remains_allowed(): void
    {
        [$user, $bot] = $this->enabledBaseline();
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));

        $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic'])->assertOk();
        $stored = $this->row($bot->id);
        $this->assertSame('automatic', $stored->follow_up_mode);

        // พิมพ์ซ้ำค่าเดิม = no-op ที่ยอมรับ (ไม่เช็ค capabilities ใหม่) และช่องอื่นยังบันทึกได้
        $repeat = $this->putSettings($user, $bot, ['follow_up_mode' => 'automatic', 'offline_message' => 'x']);

        $repeat->assertOk();
        $row = $this->row($bot->id);
        $this->assertSame('automatic', $row->follow_up_mode, 'ค่า automatic ที่เก็บไว้คงเดิม');
        $this->assertSame('x', $row->offline_message, 'ช่องอื่นของการเซฟเดียวกันบันทึกได้');
        $this->assertColumns($row, [
            'enabled' => true,
            'mode' => 'automatic',
            'started_at' => self::NOW,
            'activation_id' => (int) $stored->follow_up_activation_message_id,
            'activated_at' => self::NOW,
        ], 'ห้าคอลัมน์ follow-up คงเดิมทุกไบต์หลัง no-op');
    }

    // ------------------------------------------------------------------
    // อ่าน: แถวแรก, admin, owner อื่น, ไม่ล็อกอิน
    // ------------------------------------------------------------------

    #[Test]
    public function test_first_get_creates_row_and_returns_four_follow_up_fields(): void
    {
        [$user, $bot] = $this->makeSupportedBot();

        $first = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");

        $first->assertOk();
        $data = $first->json('data');
        $this->assertFalse($data['follow_up_enabled']);
        $this->assertSame('approve', $data['follow_up_mode']);
        $this->assertNull($data['follow_up_started_at']);
        $this->assertSame(
            FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::now()),
            $data['follow_up_capabilities']
        );
        $this->assertInternalKeysAbsent($data, 'first GET response data');

        $row = $this->row($bot->id);
        $this->assertNotNull($row, 'GET creates the settings row');
        $this->assertColumns($row, [
            'enabled' => false,
            'mode' => 'approve',
            'started_at' => null,
            'activation_id' => null,
            'activated_at' => null,
        ], 'row right after createDefaultSettings');

        $second = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");
        $second->assertOk();
        $this->assertSame(array_keys($first->json('data')), array_keys($second->json('data')), 'second GET has the same key set');
        $this->assertSame($data['follow_up_started_at'], $second->json('data.follow_up_started_at'));
        $this->assertSame($data['follow_up_capabilities'], $second->json('data.follow_up_capabilities'));
    }

    #[Test]
    public function test_get_admin_sees_fields_other_owner_and_anon_are_blocked(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $admin = User::factory()->admin()->create();
        DB::table('admin_bot_assignments')->insert([
            'user_id' => $admin->id,
            'bot_id' => $bot->id,
            'assigned_by' => $user->id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        // เรียกแบบไม่ล็อกอินก่อน actingAs ตัวแรก — actingAs ค้างทั้งเทสต์
        $this->getJson("/api/bots/{$bot->id}/settings")->assertUnauthorized();

        $byAdmin = $this->actingAs($admin)->getJson("/api/bots/{$bot->id}/settings");
        $byAdmin->assertOk();
        $data = $byAdmin->json('data');
        $this->assertFalse($data['follow_up_enabled']);
        $this->assertSame('approve', $data['follow_up_mode']);
        $this->assertNull($data['follow_up_started_at']);
        $this->assertArrayHasKey('follow_up_capabilities', $data);
        $this->assertInternalKeysAbsent($data, 'admin GET response data');

        [$otherUser] = $this->makeBaseline();
        $this->actingAs($otherUser)->getJson("/api/bots/{$bot->id}/settings")->assertForbidden();
    }

    #[Test]
    public function test_write_by_admin_or_other_owner_is_403_and_row_unchanged(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();
        $before = $this->row($bot->id);

        $admin = User::factory()->admin()->create();
        DB::table('admin_bot_assignments')->insert([
            'user_id' => $admin->id,
            'bot_id' => $bot->id,
            'assigned_by' => $user->id,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $adminPut = $this->actingAs($admin)->putJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => false, 'hitl_enabled' => true]);
        $adminPut->assertForbidden();
        $adminPatch = $this->actingAs($admin)->patchJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => false]);
        $adminPatch->assertForbidden();

        [$otherUser] = $this->makeBaseline();
        $otherPut = $this->actingAs($otherUser)->putJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => false]);
        $otherPut->assertForbidden();
        $otherPatch = $this->actingAs($otherUser)->patchJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => false]);
        $otherPatch->assertForbidden();

        $this->assertSame((array) $before, (array) $this->row($bot->id), 'row unchanged after every 403 write');
    }

    // ------------------------------------------------------------------
    // Snapshot มีกันเชิง SQL — ค่าของคนอื่นที่แทรกก่อน update ต้องชนะ
    // ------------------------------------------------------------------

    #[Test]
    public function test_guarded_snapshot_hook_beats_the_request_values(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $highWater = (int) DB::table('messages')->max('id');

        // Hook ที่ยิงหนึ่งครั้ง: ทันทีหลัง controller อ่านแถว (SELECT ... lockForUpdate)
        // ก่อน controller เขียน — จำลองนักเขียนคนอื่นชนะไปก่อน (ชื่อ hook: DB::listen
        // บน SELECT แรกของ bot_settings ใน transaction)
        $fired = false;
        DB::listen(function ($query) use (&$fired, $bot): void {
            if ($fired) {
                return;
            }
            if (str_starts_with($query->sql, 'select * from "bot_settings"')) {
                $fired = true;
                DB::table('bot_settings')->where('bot_id', $bot->id)->update([
                    'follow_up_started_at' => '2026-11-01 08:00:00',
                    'follow_up_activated_at' => '2026-11-01 08:00:00',
                    'follow_up_activation_message_id' => 7,
                ]);
            }
        });

        $put = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);

        $put->assertOk();
        $this->assertTrue($fired, 'the hook must fire exactly once');
        $row = $this->row($bot->id);
        $this->assertTrue((bool) $row->follow_up_enabled, 'the switch ends on');
        $this->assertSame('2026-11-01 08:00:00', $row->follow_up_started_at, 'snapshot = the hook values, not the request time');
        $this->assertSame('2026-11-01 08:00:00', $row->follow_up_activated_at);
        $this->assertSame(7, (int) $row->follow_up_activation_message_id, 'activation id = the hook value, not the high-water '."{$highWater}");
    }

    // ------------------------------------------------------------------
    // ตัวอ่านเห็นค่าตรงกับที่ตัวเขียนเขียน
    // ------------------------------------------------------------------

    #[Test]
    public function test_readers_agree_with_writer_eligibility_and_automatic_allowed(): void
    {
        [$user, $bot] = $this->makeBaseline();

        // ห้องที่ผ่านทุกเงื่อนไข (ลูกค้าพิมพ์ 08:59 เงียบ 25 ชม. > 24 ชม.)
        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U'.str_repeat('a1', 16),
        ]);
        foreach ([
            ['user', '2026-11-15 08:55:00', 'สนใจสินค้าครับ'],
            ['bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ'],
            ['user', '2026-11-15 08:57:00', 'เอาสองตัวครับ'],
            ['bot', '2026-11-15 08:58:00', 'กำลังเตรียมให้ครับ'],
            ['user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ'],
        ] as [$sender, $at, $content]) {
            DB::table('messages')->insert([
                'conversation_id' => $room->id,
                'sender' => $sender,
                'content' => $content,
                'type' => 'text',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        FollowUpSalesProjector::refresh($room, CarbonImmutable::parse(self::NOW, 'Asia/Bangkok'));

        // เปิดผ่าน API จริง แล้วตัวอ่านต้องเห็นครบ (ไม่เข้าสาขา cutoff missing → นับได้ 1)
        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();

        $this->assertSame(1, FollowUpEligibility::query($bot, CarbonImmutable::parse(self::NOW, 'Asia/Bangkok'))->count());

        // 14 วันต่อมา ถ้าบอทอยู่ใน automatic_bot_ids → automatic_allowed true
        config(['customer_follow_up.automatic_bot_ids' => [$bot->id]]);
        $caps = FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Bangkok'));
        $this->assertTrue($caps['automatic_allowed']);
    }

    // ------------------------------------------------------------------
    // ล็อก: เปิด/ปิดคนละบรรทัด ไม่มี canary หลุด
    // ------------------------------------------------------------------

    #[Test]
    public function test_switch_flip_logs_one_line_each_and_leaks_no_secrets(): void
    {
        $canaryToken = 'canary-'.'tok-part';
        $canaryChat = 'canary-'.'chat-part';
        $canaryAdmin = 'canary-'.'admin-part';

        [$user, $bot] = $this->makeBaseline([
            'access_token' => $canaryToken,
            'chat_id' => $canaryChat,
            'authorized_user_ids' => [$canaryAdmin],
        ]);

        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = [$event->level, $event->message, $event->context];
        });

        $responses = [];
        $responses[] = $this->putSettings($user, $bot, ['follow_up_enabled' => true]);
        $responses[] = $this->putSettings($user, $bot, ['follow_up_enabled' => false]);

        foreach ($responses as $response) {
            $response->assertOk();
        }

        $switchLines = array_values(array_filter($logged, fn (array $line) => str_contains((string) $line[1], 'follow-up switch')));
        $this->assertCount(2, $switchLines, 'exactly one info line per flip');

        foreach ($switchLines as $index => [$level, $message, $context]) {
            $oldNew = $index === 0 ? ['old' => false, 'new' => true] : ['old' => true, 'new' => false];
            $this->assertSame('info', $level, $message);
            $this->assertSame($bot->id, $context['bot_id']);
            $this->assertSame($user->id, $context['user_id']);
            $this->assertSame($oldNew, $context['follow_up_enabled'], 'line '.($index + 1).' old→new for follow_up_enabled');
            $this->assertArrayHasKey('follow_up_mode', $context, 'old→new for follow_up_mode must be in the line too');
            $this->assertSame('approve', $context['follow_up_mode']['old']);
        }

        // ไม่มี canary หลุดในล็อกใด ๆ และไม่หลุดใน response body ของไฟล์นี้
        foreach ($logged as [$level, $message, $context]) {
            $rendered = $message.json_encode($context);
            $this->assertStringNotContainsString($canaryToken, $rendered);
            $this->assertStringNotContainsString($canaryChat, $rendered);
            $this->assertStringNotContainsString($canaryAdmin, $rendered);
        }
        foreach ($responses as $response) {
            $body = json_encode($response->json());
            $this->assertStringNotContainsString($canaryToken, $body);
            $this->assertStringNotContainsString($canaryChat, $body);
            $this->assertStringNotContainsString($canaryAdmin, $body);
        }
    }

    // ------------------------------------------------------------------
    // คอลัมน์ภายในห้ามออกทุกจุดที่ serialise BotSetting
    // ------------------------------------------------------------------

    #[Test]
    public function test_internal_keys_absent_from_bot_resource_settings_serialisation(): void
    {
        [$user, $bot] = $this->makeBaseline();
        $this->putSettings($user, $bot, ['follow_up_enabled' => true])->assertOk();

        $bot->load('settings');
        $resource = (new BotResource($bot))->toArray(Request::create("/api/bots/{$bot->id}", 'GET'));

        $this->assertArrayHasKey('settings', $resource);
        // JsonResource คืนโมเดลตามที่เป็น — serialise แบบเดียวกับ response จริง
        $this->assertInstanceOf(BotSetting::class, $resource['settings']);
        $this->assertInternalKeysAbsent($resource['settings']->toArray(), 'BotResource settings serialisation');
    }
}
