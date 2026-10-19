<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\SupportRouter\SupportRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class SupportRouterServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.decisions_url' => self::DECISIONS_URL,
            'services.openrouter.support_router_threshold' => 0.7,
            'services.openrouter.support_router_timeout' => 5,
        ]);
    }

    #[Test]
    public function test_mode_off_sends_no_http_request_and_returns_null(): void
    {
        $bot = $this->makeBot(['support_router_mode' => 'off']);
        Http::fake();

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
        $this->assertCount(0, Http::recorded());
        Http::assertNothingSent();
    }

    #[Test]
    public function test_request_body_contains_model_context_window_and_latest_message(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $latest = 'บัญชีโดนแบนครับ';
        $history = [...$this->history(), ['sender' => 'user', 'content' => $latest]];

        $this->fakeByQuestion(0.9, 0.2);

        app(SupportRouterService::class)->decide($bot, $latest, $history);

        Http::assertSent(function ($request) use ($latest) {
            $data = $request->data();

            if ($request->url() !== self::DECISIONS_URL) {
                return false;
            }

            if (! isset($data['questions']['support'])) {
                return false; // the buying request body is asserted in its own test
            }

            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);
            $this->assertSame('openai/gpt-6-luna-decisions', $data['model']);
            $this->assertSame($latest, $data['state']['latest_customer_message']);
            $this->assertCount(6, $data['state']['recent_conversation']);
            // trailing duplicate of the latest message is dropped before slicing the last 6
            $this->assertSame('สนใจ BM ครับ', $data['state']['recent_conversation'][0]['text']);
            $this->assertSame('user', $data['state']['recent_conversation'][0]['from']);
            $this->assertSame('bot', $data['state']['recent_conversation'][5]['from']);
            $this->assertSame('noul', $data['questions']['support']['type']);
            $this->assertStringContainsString('Post-sale issue that the shop\'s human Technical Support must handle', $data['questions']['support']['instructions']);
            $this->assertStringContainsString("support hasn't replied", $data['questions']['support']['instructions']);

            return true;
        });
    }

    #[Test]
    public function test_recent_conversation_text_is_truncated_to_300_chars(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $long = str_repeat('ก', 400);

        $this->fakeByQuestion(0.5, 0.2);

        app(SupportRouterService::class)->decide($bot, 'ครับ', [
            ['sender' => 'user', 'content' => $long],
        ]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            if (! isset($data['questions']['support'])) {
                return false;
            }

            $recent = $data['state']['recent_conversation'];

            $this->assertCount(1, $recent);
            $this->assertSame(300, mb_strlen($recent[0]['text']));

            return true;
        });
    }

    #[Test]
    public function test_support_request_body_unchanged_and_buying_body_has_no_recent_conversation(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $latest = 'ซื้อเฟสไก่ 40 อันครับ';
        $this->fakeByQuestion(0.87, 0.95);

        app(SupportRouterService::class)->decide($bot, $latest, $this->history());

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        $supportRequest = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['support']))[0];
        $buyingRequest = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['buying']))[0];

        // --- support request: byte-for-byte today's body ---
        $this->assertSame(self::DECISIONS_URL, $supportRequest->url());
        $this->assertSame('Bearer test-key', $supportRequest->header('Authorization')[0]);
        $supportData = $supportRequest->data();
        $this->assertSame('openai/gpt-6-luna-decisions', $supportData['model']);
        $this->assertSame(['shop', 'recent_conversation', 'latest_customer_message'], array_keys($supportData['state']));
        $this->assertSame($this->serviceConstant('SHOP_LINE'), $supportData['state']['shop']);
        $this->assertSame($latest, $supportData['state']['latest_customer_message']);
        $this->assertCount(6, $supportData['state']['recent_conversation']);
        $this->assertSame(['support'], array_keys($supportData['questions']));
        $this->assertSame('noul', $supportData['questions']['support']['type']);
        $this->assertSame($this->serviceConstant('QUESTION'), $supportData['questions']['support']['instructions']);
        $this->assertSame(['true' => 'Yes', 'false' => 'No'], $supportData['questions']['support']['criteria']);

        // --- buying request: ONLY the latest message, no history ---
        $this->assertSame(self::DECISIONS_URL, $buyingRequest->url());
        $this->assertSame('Bearer test-key', $buyingRequest->header('Authorization')[0]);
        $buyingData = $buyingRequest->data();
        $this->assertSame('openai/gpt-6-luna-decisions', $buyingData['model']);
        $this->assertSame(['shop', 'latest_customer_message'], array_keys($buyingData['state']));
        $this->assertSame($this->serviceConstant('SHOP_LINE'), $buyingData['state']['shop']);
        $this->assertSame($latest, $buyingData['state']['latest_customer_message']);
        $this->assertArrayNotHasKey('recent_conversation', $buyingData['state']);
        $this->assertSame(['buying'], array_keys($buyingData['questions']));
        $this->assertSame('noul', $buyingData['questions']['buying']['type']);
        $this->assertSame($this->serviceConstant('BUYING'), $buyingData['questions']['buying']['instructions']);
        $this->assertSame(['true' => 'Yes', 'false' => 'No'], $buyingData['questions']['buying']['criteria']);
    }

    #[Test]
    public function test_score_above_threshold_in_on_mode_returns_handover(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $this->fakeByQuestion(0.93, 0.1);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.93, $decision['score']);
        $this->assertTrue($decision['handover']);
        $this->assertSame('on', $decision['mode']);
        $this->assertSame('openai/gpt-6-luna-decisions', $decision['model']);
        $this->assertIsInt($decision['latency_ms']);
        // cost = support call + buying call (0.0000639 + 0.0000021)
        $this->assertEqualsWithDelta(0.0000639 + 0.0000021, $decision['cost'], 1e-12);
    }

    #[Test]
    public function test_shadow_mode_support_0_87_and_buying_0_95_records_buying_blocked_without_handover(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->fakeByQuestion(0.87, 0.95);

        $decision = app(SupportRouterService::class)->decide($bot, 'ซื้อเฟสไก่ 40 อันครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.87, $decision['score']);
        $this->assertSame(0.95, $decision['buying_score']);
        $this->assertTrue($decision['buying_blocked']);
        $this->assertFalse($decision['handover']);
        $this->assertSame('shadow', $decision['mode']);
        $this->assertIsInt($decision['latency_ms']);
        // cost = support call + buying call
        $this->assertEqualsWithDelta(0.0000639 + 0.0000021, $decision['cost'], 1e-12);
    }

    #[Test]
    public function test_on_mode_high_buying_blocks_handover_and_sends_no_alert(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->giveTelegramPlugin($bot);
        $this->fakeByQuestion(0.87, 0.95);

        $decision = app(SupportRouterService::class)->decide($bot, 'ซื้อเฟสไก่ 40 อันครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.87, $decision['score']);
        $this->assertSame(0.95, $decision['buying_score']);
        $this->assertTrue($decision['buying_blocked']);
        $this->assertFalse($decision['handover']);
        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))->count());
    }

    #[Test]
    public function test_on_mode_support_0_99_buying_0_10_hands_over_and_alerts_once(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->giveTelegramPlugin($bot);
        $this->fakeByQuestion(0.99, 0.10);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $decision = app(SupportRouterService::class)->decide($bot, 'เปิดยังพี่', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.99, $decision['score']);
        $this->assertSame(0.10, $decision['buying_score']);
        $this->assertFalse($decision['buying_blocked']);
        $this->assertTrue($decision['handover']);
        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), 'api/alpha/decisions'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))->count());
    }

    #[Test]
    public function test_on_mode_buying_call_500_returns_decision_with_null_buying_score_and_logs_warning(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->fakeByQuestion(0.99, null);
        Log::spy();

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.99, $decision['score']);
        $this->assertNull($decision['buying_score']);
        $this->assertFalse($decision['buying_blocked']);
        // a failed buying check never causes a handover (sales-safe)
        $this->assertFalse($decision['handover']);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($bot) {
            return $message === 'Support router: buying check failed'
                && $context['bot_id'] === $bot->id
                && $context['status'] === 500;
        });
    }

    #[Test]
    public function test_buying_missing_numeric_score_returns_decision_with_null_buying_score(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Http::fake([
            self::DECISIONS_URL => function ($request) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response(['model' => 'openai/gpt-6-luna-decisions-20261006'], 200);
                }

                return Http::response($this->decisionPayload(0.9));
            },
        ]);
        Log::spy();

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.9, $decision['score']);
        $this->assertNull($decision['buying_score']);
        $this->assertFalse($decision['handover']);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'Support router: buying check failed');
    }

    #[Test]
    public function test_support_call_failure_returns_null_even_if_buying_succeeds(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->fakeSupportFailure(500, 0.10);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_support_call_timeout_returns_null_even_if_buying_succeeds(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Http::fake([
            self::DECISIONS_URL => function ($request) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response($this->buyingPayload(0.10));
                }

                return fn () => throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_buying_score_above_one_is_clamped_to_one(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $this->fakeByQuestion(0.4, 1.7);
        $decision = app(SupportRouterService::class)->decide($bot, 'เอา 40 อันครับ', $this->history());
        $this->assertSame(1.0, $decision['buying_score']);
    }

    #[Test]
    public function test_buying_score_below_zero_is_clamped_to_zero(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $this->fakeByQuestion(0.4, -0.5);
        $decision = app(SupportRouterService::class)->decide($bot, 'เอา 40 อันครับ', $this->history());
        $this->assertSame(0.0, $decision['buying_score']);
    }

    #[Test]
    public function test_score_below_threshold_in_on_mode_returns_no_handover(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.4)),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'สินค้านี้ราคาเท่าไหร่ครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.4, $decision['score']);
        $this->assertFalse($decision['handover']);
    }

    #[Test]
    public function test_shadow_mode_with_high_score_still_returns_no_handover(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.99)),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.99, $decision['score']);
        $this->assertFalse($decision['handover']);
        $this->assertSame('shadow', $decision['mode']);
    }

    #[Test]
    public function test_timeout_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_server_error_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_malformed_json_body_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response('<html>gateway error</html>', 200),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_missing_answers_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response(['model' => 'openai/gpt-6-luna-decisions-20261006'], 200),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
    }

    #[Test]
    public function test_missing_api_key_returns_null_and_sends_nothing(): void
    {
        config(['services.openrouter.api_key' => null]);
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Http::fake();

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
        $this->assertCount(0, Http::recorded());
    }

    #[Test]
    public function test_empty_model_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => null,
        ]);
        Http::fake();

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
        $this->assertCount(0, Http::recorded());
    }

    #[Test]
    public function test_score_above_one_is_clamped_to_one(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(1.7)),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertSame(1.0, $decision['score']);
    }

    #[Test]
    public function test_score_below_zero_is_clamped_to_zero(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(-0.5)),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertSame(0.0, $decision['score']);
    }

    #[Test]
    public function test_never_calls_chat_completions(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $this->fakeByQuestion(0.9, 0.1);

        app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertSame(2, Http::recorded(fn ($request) => str_contains($request->url(), 'api/alpha/decisions'))->count());
        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), 'chat/completions'))->count());
    }

    #[Test]
    public function test_handover_sends_telegram_alert(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->giveTelegramPlugin($bot);

        $this->fakeByQuestion(0.93, 0.1);

        app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ ช่วยดูให้หน่อย', $this->history());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            $text = $request->data()['text'];

            $this->assertStringContainsString('ลูกค้าต้องการ Support', $text);
            $this->assertStringContainsString('คะแนน 0.93', $text);
            $this->assertStringContainsString('บัญชีโดนแบนครับ ช่วยดูให้หน่อย', $text);

            return true;
        });
    }

    #[Test]
    public function test_shadow_mode_sends_no_telegram_alert(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->giveTelegramPlugin($bot);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.99)),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))->count());
    }

    private function makeBot(array $attributes = []): Bot
    {
        $user = User::factory()->create();

        return Bot::factory()->create(['user_id' => $user->id, ...$attributes]);
    }

    private function giveTelegramPlugin(Bot $bot): void
    {
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'support-alert',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);
    }

    private function history(): array
    {
        return [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ['sender' => 'bot', 'content' => 'สวัสดีครับ สนใจสินค้าตัวไหนครับ'],
            ['sender' => 'user', 'content' => 'สนใจ BM ครับ'],
            ['sender' => 'bot', 'content' => 'BM ราคา 1,100 ครับ'],
            ['sender' => 'user', 'content' => 'โอนแล้วนะครับ'],
            ['sender' => 'bot', 'content' => 'ได้รับแล้วครับ'],
            ['sender' => 'user', 'content' => 'ขอบคุณครับ'],
            ['sender' => 'bot', 'content' => 'ขอบคุณครับ'],
        ];
    }

    private function serviceConstant(string $name): string
    {
        return (new ReflectionClass(SupportRouterService::class))->getConstant($name);
    }

    /**
     * Fake both Decisions calls, routed by the question name in the request body:
     * the support request gets $support, the buying request gets $buying
     * ($buying === null simulates a failing buying call with HTTP 500).
     */
    private function fakeByQuestion(float $support, ?float $buying): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($support, $buying) {
                if (isset($request->data()['questions']['buying'])) {
                    if ($buying === null) {
                        return Http::response(['error' => ['message' => 'buying boom']], 500);
                    }

                    return Http::response($this->buyingPayload($buying));
                }

                return Http::response($this->decisionPayload($support));
            },
        ]);
    }

    /**
     * Fake a failing support call (HTTP $supportStatus) with a succeeding buying call.
     */
    private function fakeSupportFailure(int $supportStatus, float $buying): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($supportStatus, $buying) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response($this->buyingPayload($buying));
                }

                return Http::response(['error' => ['message' => 'boom']], $supportStatus);
            },
        ]);
    }

    private function buyingPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['buying' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000021],
            'id' => 'gen-dec-buy',
            'provider' => 'OpenAI',
        ];
    }

    private function decisionPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['support' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 639, 'output_tokens' => 0, 'cost' => 0.0000639],
            'id' => 'gen-dec-123',
            'provider' => 'OpenAI',
        ];
    }
}
