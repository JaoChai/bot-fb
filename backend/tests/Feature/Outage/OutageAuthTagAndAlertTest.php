<?php

namespace Tests\Feature\Outage;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\MessageAggregationService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * การ์ด t_44675510 — บอทล่มเพราะ OpenRouter ปฏิเสธกุญแจ (HTTP 401/403)
 *
 * 1) แถวคำตอบที่ลูกค้าได้ตอน auth ล่ม ต้องติด tag metadata.model_error = "auth"
 *    (ข้อความเดิมทุกตัวอักษร) — ทั้ง generateAndSaveResponse และ ProcessAggregatedMessages
 * 2) แจ้งเจ้าของทาง Telegram 1 ครั้ง/30 นาที ทั้งระบบ (cache key outage:auth-alert)
 *    — ไม่มี plugin / Telegram ล่ม / cache ล่ม ต้องไม่ทำให้แถวลูกค้าหายหรือการส่งล้ม
 */
class OutageAuthTagAndAlertTest extends TestCase
{
    use RefreshDatabase;

    /** exception ที่รอดออกจาก handle() เมื่อ push ล้ม — ตั้งโดย runAggregatedJob() */
    private ?RuntimeException $pushThrew = null;

    private const AUTH_TEXT = 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';

    private const RATE_LIMIT_TEXT = 'ตอนนี้มีข้อความเข้ามาเยอะมากครับ 🙏 รบกวนพี่รอสักครู่แล้วส่งใหม่อีกครั้งนะครับ';

    private const GENERIC_TEXT = 'ขอโทษครับ ระบบใช้เวลาประมวลผลนานกว่าปกติ 🙏 รบกวนพี่พิมพ์เข้ามาอีกครั้งนะครับ เดี๋ยวตอบให้เลยครับ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pushThrew = null;
        Http::preventStrayRequests();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'delivery.order_payload_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Part 1 — tag ผ่าน generateAndSaveResponse()
    // ------------------------------------------------------------------

    #[Test]
    public function test_a_faked_401_saves_the_auth_text_tagged_as_auth(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->fakeOpenRouterFailure(401);

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertNotNull($row->metadata);
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_a_faked_403_is_also_an_auth_failure(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->fakeOpenRouterFailure(403);

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_a_429_keeps_the_rate_limit_text_and_no_auth_tag(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->fakeOpenRouterFailure(429);

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::RATE_LIMIT_TEXT, $row->content);
        $row->refresh();
        $this->assertNull($row->metadata);
    }

    #[Test]
    public function test_a_500_keeps_the_generic_text_and_no_auth_tag(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->fakeOpenRouterFailure(500);

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::GENERIC_TEXT, $row->content);
        $row->refresh();
        $this->assertNull($row->metadata);
    }

    // ------------------------------------------------------------------
    // Part 2 — owner alert ผ่าน generateAndSaveResponse()
    // ------------------------------------------------------------------

    #[Test]
    public function test_the_first_auth_failure_sends_exactly_one_owner_alert(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        $this->failAReply($bot, $conversation);

        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('999', $alerts[0]['chat_id']);
        $this->assertStringContainsString($bot->name, $alerts[0]['text']);
        $this->assertStringContainsString('HTTP 401', $alerts[0]['text']);
    }

    #[Test]
    public function test_a_second_failure_29_minutes_later_sends_no_new_alert(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        $this->failAReply($bot, $conversation);
        $this->assertSame(1, count($this->telegramAlerts()));

        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $this->failAReply($bot, $conversation);

        $this->assertSame(1, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_a_failure_31_minutes_after_the_previous_one_alerts_again(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        // ทุกเข็มเดินจาก "การแจ้งครั้งแรก" — ล็อก +31 นาทีให้แน่นกับ TTL 30 นาที
        // (ถ้า TTL > 31 นาที สล็อตยังไม่หมดอายุ การแจ้งครั้งที่สามต้องไม่ออก — review round 1 #3)
        $firstAlertAt = Carbon::now();
        $this->failAReply($bot, $conversation);
        Carbon::setTestNow($firstAlertAt->copy()->addMinutes(29));
        $this->failAReply($bot, $conversation);
        $this->assertSame(1, count($this->telegramAlerts()));

        Carbon::setTestNow($firstAlertAt->copy()->addMinutes(31));
        $this->failAReply($bot, $conversation);

        $this->assertSame(2, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_a_bot_without_a_telegram_plugin_alerts_nobody_and_still_replies(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation(withTelegramPlugin: false);
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(0, count($this->telegramAlerts()));
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_a_plugin_less_bot_failing_first_does_not_burn_the_alert_slot(): void
    {
        // บอท A ไม่มี plugin ล่มก่อน ต้องไม่กินสล็อต 30 นาที — ไม่งั้นบอท B (มี plugin)
        // ที่ล่มตามมาในอีก 1 นาทีจะเงียบ และเจ้านายไม่รู้เลยว่าระบบล่ม (review round 1 #2)
        [$botA, $conversationA] = $this->makeBotWithConversation(withTelegramPlugin: false);
        [$botB, $conversationB] = $this->makeBotWithConversation(withTelegramPlugin: true);
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        $rowA = $this->failAReply($botA, $conversationA);

        Carbon::setTestNow(Carbon::now()->addMinute());
        $rowB = $this->failAReply($botB, $conversationB);

        $alerts = $this->telegramAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('999', $alerts[0]['chat_id']);
        $rowA->refresh();
        $rowB->refresh();
        $this->assertSame('auth', $rowA->metadata['model_error']);
        $this->assertSame('auth', $rowB->metadata['model_error']);
    }

    #[Test]
    public function test_telegram_being_down_keeps_the_customer_row(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);

        $row = $this->failAReply($bot, $conversation);

        // ลูกค้ายังได้แถวคำตอบ (การส่งจริงอยู่ในเส้นทาง webhook/aggregated — ไม่ใช่ที่นี่)
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_the_cache_being_down_still_sends_the_alert_and_keeps_the_row(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $this->breakCacheForAuthAlert();

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content);
        $this->assertSame(1, count($this->telegramAlerts()));
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_a_plugin_lookup_failure_still_returns_the_tagged_customer_row(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        // Real DB lookup failure; RefreshDatabase rolls this test-only DDL back.
        Schema::drop('flow_plugins');

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertFalse(Cache::has('outage:auth-alert'));
    }

    // ------------------------------------------------------------------
    // Part 1+2 — tag + alert ผ่าน ProcessAggregatedMessages
    // ------------------------------------------------------------------

    #[Test]
    public function test_the_aggregated_job_tags_the_row_and_alerts_the_owner(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();

        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'สั่งของครับ',
        ]);
        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);

        (new ProcessAggregatedMessages($bot, $conversation, $group['group_id'], $conversation->external_customer_id))
            ->handle(
                app(MessageAggregationService::class),
                app(AIService::class),
                app(AggregatedMessageDeliveryService::class),
            );

        $row = Message::query()->where('sender', 'bot')->orderByDesc('id')->first();
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);

        // ลูกค้ายังได้ข้อความ (delivered) และเจ้าของได้ alert 1 ครั้ง
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.line.me') && str_contains($r->url(), 'message/push'));
        $this->assertSame(1, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_the_aggregated_job_tags_a_403_row_as_auth(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(403);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
    }

    #[Test]
    public function test_the_aggregated_job_keeps_the_rate_limit_text_and_no_tag_on_429(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(429);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertSame(self::RATE_LIMIT_TEXT, $row->content);
        $row->refresh();
        $this->assertNull($row->metadata);
        $this->assertSame(0, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_the_aggregated_job_keeps_the_generic_text_and_no_tag_on_500(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(500);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertSame(self::GENERIC_TEXT, $row->content);
        $row->refresh();
        $this->assertNull($row->metadata);
        $this->assertSame(0, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_the_aggregated_job_survives_telegram_being_down(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        Queue::fake();

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertNull($this->pushThrew);
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
        // ลูกค้ายังได้ข้อความ แม้ Telegram ล่ม
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.line.me') && str_contains($r->url(), 'message/push'));
    }

    #[Test]
    public function test_the_aggregated_job_survives_the_cache_being_down(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $this->breakCacheForAuthAlert();
        Queue::fake();

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertNull($this->pushThrew);
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.line.me') && str_contains($r->url(), 'message/push'));
        // cache ล่ม = ยังต้องแจ้งเจ้านาย
        $this->assertSame(1, count($this->telegramAlerts()));
    }

    #[Test]
    public function test_the_aggregated_job_survives_a_plugin_lookup_failure(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();
        Schema::drop('flow_plugins');

        $row = $this->runAggregatedJob($bot, $conversation);

        $this->assertNull($this->pushThrew);
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.line.me')
            && str_contains($r->url(), 'message/push')
            && $r['to'] === $conversation->external_customer_id
            && $r['messages'][0]['text'] === self::AUTH_TEXT);
        $this->assertCount(0, $this->telegramAlerts());
        $this->assertFalse(Cache::has('outage:auth-alert'));
    }

    #[Test]
    public function test_a_failing_line_push_still_tags_the_row_and_alerts_the_owner(): void
    {
        // Lead round 1 note A: tag ต้องเกิดก่อน deliver() และ alert ต้องออกแม้ push โยน exception
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        Queue::fake();

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->andThrow(new RuntimeException('LINE push failed'));

        $row = $this->runAggregatedJob($bot, $conversation, $delivery);

        $this->assertInstanceOf(RuntimeException::class, $this->pushThrew);
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);
        $this->assertSame(1, count($this->telegramAlerts()));
    }

    // ------------------------------------------------------------------
    // Part 3 — ข้อความ down ต่อบอทอ่านจาก bot_settings (การ์ด outage-settings B2)
    // ------------------------------------------------------------------

    #[Test]
    public function test_both_down_reply_paths_read_current_bot_settings(): void
    {
        // เส้นทาง sync: AIService::generateAndSaveResponse
        [$bot, $conversation] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot->id, 'outage_down_message' => 'ทีมงานกำลังเปลี่ยนกุญแจครับ']);
        $this->fakeOpenRouterFailure(401);
        $row = $this->failAReply($bot, $conversation);
        $this->assertSame('ทีมงานกำลังเปลี่ยนกุญแจครับ', $row->content);
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error']);

        // เส้นทาง aggregated: ProcessAggregatedMessages
        [$bot2, $conversation2] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot2->id, 'outage_down_message' => 'บอทสองกำลังซ่อมครับ']);
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $row2 = $this->runAggregatedJob($bot2, $conversation2);
        $this->assertSame('บอทสองกำลังซ่อมครับ', $row2->content);
        $row2->refresh();
        $this->assertSame('auth', $row2->metadata['model_error']);
    }

    #[Test]
    public function test_blank_down_setting_falls_back_to_the_default_on_both_paths(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot->id, 'outage_down_message' => null]);
        $this->fakeOpenRouterFailure(401);
        $row = $this->failAReply($bot, $conversation);
        $this->assertSame(self::AUTH_TEXT, $row->content);

        [$bot2, $conversation2] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot2->id, 'outage_down_message' => '   ']);
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $row2 = $this->runAggregatedJob($bot2, $conversation2);
        $this->assertSame(self::AUTH_TEXT, $row2->content);
    }

    #[Test]
    public function test_other_bots_settings_are_not_used_on_either_path(): void
    {
        [$otherBot] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $otherBot->id, 'outage_down_message' => 'ข้อความของบอทอื่น']);

        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $row = $this->failAReply($bot, $conversation);
        $this->assertSame(self::AUTH_TEXT, $row->content);
        $this->assertStringNotContainsString('ข้อความของบอทอื่น', $row->content);

        [$bot2, $conversation2] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $row2 = $this->runAggregatedJob($bot2, $conversation2);
        $this->assertSame(self::AUTH_TEXT, $row2->content);
        $this->assertStringNotContainsString('ข้อความของบอทอื่น', $row2->content);
    }

    #[Test]
    public function test_rate_limit_text_survives_custom_settings_on_both_paths(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot->id, 'outage_down_message' => 'ข้อความกำหนดเอง']);
        $this->fakeOpenRouterFailure(429);
        $row = $this->failAReply($bot, $conversation);
        $this->assertSame(self::RATE_LIMIT_TEXT, $row->content);
        $row->refresh();
        $this->assertNull($row->metadata);

        [$bot2, $conversation2] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot2->id, 'outage_down_message' => 'ข้อความกำหนดเอง']);
        $this->fakeOpenRouterFailure(429);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $row3 = $this->runAggregatedJob($bot2, $conversation2);
        $this->assertSame(self::RATE_LIMIT_TEXT, $row3->content);
        $row3->refresh();
        $this->assertNull($row3->metadata);
    }

    #[Test]
    public function test_generic_text_survives_custom_settings_on_both_paths(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot->id, 'outage_down_message' => 'ข้อความกำหนดเอง']);
        $this->fakeOpenRouterFailure(500);
        $row2 = $this->failAReply($bot, $conversation);
        $this->assertSame(self::GENERIC_TEXT, $row2->content);
        $row2->refresh();
        $this->assertNull($row2->metadata);

        [$bot2, $conversation2] = $this->makeBotWithConversation();
        BotSetting::query()->create(['bot_id' => $bot2->id, 'outage_down_message' => 'ข้อความกำหนดเอง']);
        $this->fakeOpenRouterFailure(500);
        $this->fakeOwnerAlertAndCustomerDelivery();
        $row3 = $this->runAggregatedJob($bot2, $conversation2);
        $this->assertSame(self::GENERIC_TEXT, $row3->content);
        $row3->refresh();
        $this->assertNull($row3->metadata);
    }

    #[Test]
    public function test_settings_lookup_failure_on_sync_path_still_saves_the_default_tagged_row(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->breakBotSettingsReadsAfterOpenRouterFailure();

        $row = $this->failAReply($bot, $conversation);

        $this->assertSame(self::AUTH_TEXT, $row->content, 'failed lookup must fall back to the default text');
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error'], 'row must still be tagged');
        $this->assertSame(1, count($this->telegramAlerts()), 'owner alert must still go out');
    }

    #[Test]
    public function test_settings_lookup_failure_on_aggregated_path_still_saves_delivers_and_alerts(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->breakBotSettingsReadsAfterOpenRouterFailure();

        $row = null;
        try {
            $row = $this->runAggregatedJob($bot, $conversation);
        } catch (\TypeError) {
            // main: job ยังไม่สร้างแถวเมื่ออ่าน settings ล้ม — เป็น RED ที่คาดหมาย
        }

        $this->assertInstanceOf(Message::class, $row, 'the reply row must exist even when the settings lookup fails');
        $this->assertSame(self::AUTH_TEXT, $row->content, 'failed lookup must fall back to the default text');
        $row->refresh();
        $this->assertSame('auth', $row->metadata['model_error'], 'row must still be tagged');
        $this->assertSame(1, count($this->linePushes()), 'the reply must still be delivered');
        $this->assertSame(1, count($this->telegramAlerts()), 'owner alert must still go out');
    }

    #[Test]
    public function test_owner_down_alert_last_line_carries_the_d3_wording(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->fakeOpenRouterFailure(401);
        $this->fakeOwnerAlertAndCustomerDelivery();

        $this->failAReply($bot, $conversation);

        $expected = '🚨 <b>บอทตอบลูกค้าไม่ได้</b> ('.$this->esc($bot->name).")\n"
            .'OpenRouter ปฏิเสธกุญแจ (HTTP 401)'."\n"
            .'ลูกค้าได้ข้อความแจ้งระบบขัดข้องตามที่ตั้งไว้ ต้องเปลี่ยน OPENROUTER_API_KEY';
        $texts = collect($this->telegramAlerts())->map(fn ($request) => $request['text'])->all();
        $this->assertSame([$expected], $texts);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeBotWithConversation(bool $withTelegramPlugin = true): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'channel_access_token' => 'line-token',
            'primary_chat_model' => 'test/primary-model',
            'fallback_chat_model' => 'test/fallback-model',
            'context_window' => 10,
        ]);

        if ($withTelegramPlugin) {
            $flow = Flow::factory()->create(['bot_id' => $bot->id]);
            $bot->update(['default_flow_id' => $flow->id]);
            FlowPlugin::create([
                'flow_id' => $flow->id,
                'type' => 'telegram',
                'name' => 'แจ้งเตือน',
                'enabled' => true,
                'trigger_condition' => 'always',
                'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
            ]);
        }

        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }

    private function fakeOpenRouterFailure(int $status): void
    {
        $body = match ($status) {
            401 => ['error' => ['message' => 'User not found.', 'code' => 401]],
            403 => ['error' => ['message' => 'Forbidden', 'code' => 403]],
            429 => ['error' => ['message' => 'Rate limited', 'code' => 429]],
            default => ['error' => ['message' => 'Server error', 'code' => $status]],
        };

        Http::fake([
            'openrouter.test/api/v1/chat/completions' => Http::response($body, $status),
        ]);
    }

    /** LINE push + Telegram alert ที่ทำงานปกติ (ผู้เรียก fake อื่นก่อนแล้วแต่เคส) */
    private function fakeOwnerAlertAndCustomerDelivery(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.line.me/*' => Http::response([], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function failAReply(Bot $bot, Conversation $conversation): Message
    {
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'สวัสดีครับ',
        ]);

        return app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);
    }

    /** สร้างข้อความลูกค้า เข้า aggregation, รัน ProcessAggregatedMessages แล้วคืนแถวคำตอบบอทล่าสุด */
    private function runAggregatedJob(Bot $bot, Conversation $conversation, ?AggregatedMessageDeliveryService $delivery = null): Message
    {
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'สั่งของครับ',
        ]);
        $group = app(MessageAggregationService::class)->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);

        try {
            (new ProcessAggregatedMessages($bot, $conversation, $group['group_id'], $conversation->external_customer_id))
                ->handle(
                    app(MessageAggregationService::class),
                    app(AIService::class),
                    $delivery ?? app(AggregatedMessageDeliveryService::class),
                );
        } catch (RuntimeException $e) {
            // push ที่ล้มทิ้ง exception ต่อเหมือนของจริง — เก็บไว้ให้เทสต์เช็คว่า tag+alert รอดมาก่อน
            $this->pushThrew = $e;
        }

        return Message::query()->where('sender', 'bot')->orderByDesc('id')->first();
    }

    private function telegramAlerts(): array
    {
        return collect(Http::recorded(fn ($r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r->url(), 'sendMessage')))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function linePushes(): array
    {
        return collect(Http::recorded(fn ($r) => str_contains($r->url(), 'api.line.me') && str_contains($r->url(), 'message/push')))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * จำลอง "อ่าน bot_settings ล้ม" — ทุก query ที่แตะตารางนี้โยน exception
     * (ตารางอื่นและส่วนอื่นของระบบทำงานปกติหมด)
     */
    private function breakBotSettingsReads(): void
    {
        DB::beforeExecuting(function (string $sql): void {
            if (str_contains($sql, 'bot_settings')) {
                throw new RuntimeException('bot_settings lookup failed');
            }
        });
    }

    /**
     * แบบติดอาวุธ: อ่าน bot_settings ล้ม "หลังจาก" OpenRouter ตอบ 401 เท่านั้น
     * การอ่าน settings ก่อนหน้า (เช่น aggregation gate) ทำงานปกติ — ตรงกับสิ่งที่
     * failure path ของ getErrorMessage ต้องรอด
     */
    private function breakBotSettingsReadsAfterOpenRouterFailure(): void
    {
        $armed = false;
        Http::fake([
            'openrouter.test/api/v1/chat/completions' => function () use (&$armed) {
                $armed = true;

                return Http::response(['error' => ['message' => 'User not found.', 'code' => 401]], 401);
            },
            'api.line.me/*' => Http::response([], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        DB::beforeExecuting(function (string $sql) use (&$armed): void {
            // เจาะเฉพาะ "การอ่าน outage_down_message ใน getErrorMessage" —
            // การอ่าน bot_settings อื่นใน failure path (เช่น multiple bubbles) เป็นพฤติกรรมเดิม
            if ($armed && str_contains($sql, 'select "outage_down_message"')) {
                throw new RuntimeException('bot_settings lookup failed');
            }
        });
    }

    /** จำลอง cache store ที่ add('outage:auth-alert') โยน exception ทุกครั้ง (key อื่นทำงานปกติ) */
    private function breakCacheForAuthAlert(): void
    {
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('add')
            ->withArgs(fn ($key) => $key === 'outage:auth-alert')
            ->andThrow(new RuntimeException('cache store is down'));

        Cache::extend('outage-broken', fn () => Cache::repository($store));
        config(['cache.default' => 'outage-broken']);
    }
}
