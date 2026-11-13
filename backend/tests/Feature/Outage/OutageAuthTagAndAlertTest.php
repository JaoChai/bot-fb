<?php

namespace Tests\Feature\Outage;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    private const AUTH_TEXT = 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';

    private const RATE_LIMIT_TEXT = 'ตอนนี้มีข้อความเข้ามาเยอะมากครับ 🙏 รบกวนพี่รอสักครู่แล้วส่งใหม่อีกครั้งนะครับ';

    private const GENERIC_TEXT = 'ขอโทษครับ ระบบใช้เวลาประมวลผลนานกว่าปกติ 🙏 รบกวนพี่พิมพ์เข้ามาอีกครั้งนะครับ เดี๋ยวตอบให้เลยครับ';

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->failAReply($bot, $conversation);
        Carbon::setTestNow(Carbon::now()->addMinutes(29));
        $this->failAReply($bot, $conversation);
        $this->assertSame(1, count($this->telegramAlerts()));

        Carbon::setTestNow(Carbon::now()->addMinutes(31));
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

    private function telegramAlerts(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r->url(), 'sendMessage'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
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
