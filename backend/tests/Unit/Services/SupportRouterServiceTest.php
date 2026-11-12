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

        Http::preventStrayRequests();

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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

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

    // ------------------------------------------------------------------
    // LK2: KB shadow measurement (config services.openrouter.support_router_kb_shadow)
    // ------------------------------------------------------------------

    #[Test]
    public function test_kb_shadow_flag_off_sends_two_requests_and_decision_has_exactly_todays_keys(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        // flag not set anywhere: config default must be false
        $this->assertFalse((bool) config('services.openrouter.support_router_kb_shadow'));
        $this->fakeByQuestion(0.9, 0.1);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertCount(2, Http::recorded());
        $this->assertSame(
            ['score', 'buying_score', 'buying_blocked', 'handover', 'mode', 'model', 'latency_ms', 'cost'],
            array_keys($decision),
        );
    }

    #[Test]
    public function test_kb_shadow_flag_on_with_mode_off_still_sends_nothing(): void
    {
        $bot = $this->makeBot(['support_router_mode' => 'off']);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        Http::fake();

        $decision = app(SupportRouterService::class)->decide($bot, 'สวัสดีครับ', $this->history());

        $this->assertNull($decision);
        $this->assertCount(0, Http::recorded());
    }

    #[Test]
    public function test_kb_shadow_flag_on_sends_four_requests_and_kb_bodies_match_measured_shape(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        $latest = 'BM มีสินค้าไหมครับ';
        $this->fakeFour(0.9, 0.1, 0.75, 0.45);

        app(SupportRouterService::class)->decide($bot, $latest, $this->history());

        $requests = Http::recorded();
        $this->assertCount(4, $requests);

        // --- kb variant: ONLY the latest message, no recent_conversation ---
        $kbRequest = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['kb'])
            && ! isset($pair[0]->data()['state']['recent_conversation']))[0];
        $this->assertSame(self::DECISIONS_URL, $kbRequest->url());
        $this->assertSame('Bearer test-key', $kbRequest->header('Authorization')[0]);
        $kb = $kbRequest->data();
        $this->assertSame('openai/gpt-6-luna-decisions', $kb['model']);
        $this->assertSame(['shop', 'latest_customer_message'], array_keys($kb['state']));
        $this->assertSame($this->serviceConstant('SHOP_LINE'), $kb['state']['shop']);
        $this->assertSame($latest, $kb['state']['latest_customer_message']);
        $this->assertSame(['kb'], array_keys($kb['questions']));
        $this->assertSame('noul', $kb['questions']['kb']['type']);
        $this->assertSame($this->serviceConstant('KB_QUESTION'), $kb['questions']['kb']['instructions']);
        $this->assertSame(['true' => 'Yes', 'false' => 'No'], $kb['questions']['kb']['criteria']);

        // --- kb_ctx variant: same question name, state carries recent_conversation ---
        $kbCtxRequest = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['kb'])
            && isset($pair[0]->data()['state']['recent_conversation']))[0];
        $this->assertSame(self::DECISIONS_URL, $kbCtxRequest->url());
        $kbCtx = $kbCtxRequest->data();
        $this->assertSame('openai/gpt-6-luna-decisions', $kbCtx['model']);
        $this->assertSame(['shop', 'latest_customer_message', 'recent_conversation'], array_keys($kbCtx['state']));
        $this->assertSame($this->serviceConstant('SHOP_LINE'), $kbCtx['state']['shop']);
        $this->assertSame($latest, $kbCtx['state']['latest_customer_message']);
        $recent = $kbCtx['state']['recent_conversation'];
        $this->assertCount(6, $recent);
        $this->assertSame(['from' => 'user', 'text' => 'สนใจ BM ครับ'], $recent[0]);
        $this->assertSame(['from' => 'bot', 'text' => 'ขอบคุณครับ'], $recent[5]);
        $this->assertSame(['kb'], array_keys($kbCtx['questions']));
        $this->assertSame('noul', $kbCtx['questions']['kb']['type']);
        $this->assertSame($this->serviceConstant('KB_QUESTION'), $kbCtx['questions']['kb']['instructions']);
        $this->assertSame(['true' => 'Yes', 'false' => 'No'], $kbCtx['questions']['kb']['criteria']);

        // --- support and buying: still exactly one of each, same bodies as today ---
        $supportRequests = $requests->filter(fn ($pair) => isset($pair[0]->data()['questions']['support']));
        $buyingRequests = $requests->filter(fn ($pair) => isset($pair[0]->data()['questions']['buying']));
        $this->assertCount(1, $supportRequests);
        $this->assertCount(1, $buyingRequests);
        // filter() preserves the recorded() keys, so take the first matched pair
        $supportData = $supportRequests->first()[0]->data();
        $this->assertSame(['shop', 'recent_conversation', 'latest_customer_message'], array_keys($supportData['state']));
        $this->assertSame($this->serviceConstant('QUESTION'), $supportData['questions']['support']['instructions']);
        $buyingData = $buyingRequests->first()[0]->data();
        $this->assertSame(['shop', 'latest_customer_message'], array_keys($buyingData['state']));
        $this->assertSame($this->serviceConstant('BUYING'), $buyingData['questions']['buying']['instructions']);
    }

    #[Test]
    public function test_kb_shadow_flag_on_returns_kb_scores_clamped_and_cost_includes_kb_calls(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        $this->fakeFour(0.5, 0.2, 0.75, 1.7);

        $decision = app(SupportRouterService::class)->decide($bot, 'ราคาเท่าไหร่ครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.75, $decision['kb_score']);
        $this->assertSame(1.0, $decision['kb_ctx_score']); // 1.7 clamped to 1.0
        // cost = support + buying + kb + kb_ctx (0.0000639 + 0.0000021 + 0.0000031 + 0.0000041)
        $this->assertEqualsWithDelta(0.0000639 + 0.0000021 + 0.0000031 + 0.0000041, $decision['cost'], 1e-12);
        // the reply decision itself is untouched
        $this->assertSame(0.5, $decision['score']);
        $this->assertSame(0.2, $decision['buying_score']);
        $this->assertFalse($decision['handover']);
    }

    #[Test]
    public function test_kb_shadow_flag_on_clamps_negative_kb_score_to_zero(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        $this->fakeFour(0.5, 0.2, -0.5, 0.45);

        $decision = app(SupportRouterService::class)->decide($bot, 'ราคาเท่าไหร่ครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.0, $decision['kb_score']);
        $this->assertSame(0.45, $decision['kb_ctx_score']);
    }

    #[Test]
    public function test_kb_shadow_flag_on_kb_calls_500_both_scores_null_one_warning_each_and_decision_unchanged(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Log::spy();
        // ONE fake for both runs: flag-off exercises only support/buying, flag-on
        // additionally gets HTTP 500 from both kb calls — same support/buying responses.
        $this->fakeFour(0.99, 0.10, null, null);

        $off = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());
        $this->assertSame(2, Http::recorded()->count());

        config(['services.openrouter.support_router_kb_shadow' => true]);
        $on = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());
        $this->assertSame(6, Http::recorded()->count());

        $this->assertNotNull($off);
        $this->assertNotNull($on);
        $this->assertNull($on['kb_score']);
        $this->assertNull($on['kb_ctx_score']);
        // kb failures change nothing about the reply decision
        $this->assertSame($off['score'], $on['score']);
        $this->assertSame($off['buying_score'], $on['buying_score']);
        $this->assertSame($off['handover'], $on['handover']);
        $this->assertTrue($on['handover']); // baseline genuinely exercises the handover path

        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) use ($bot) {
            return in_array($message, [
                'Support router: KB shadow check failed (kb)',
                'Support router: KB shadow check failed (kb_ctx)',
            ], true)
                && $context['bot_id'] === $bot->id
                && $context['status'] === 500;
        });
    }

    #[Test]
    public function test_kb_shadow_flag_on_kb_calls_timeout_both_scores_null_one_warning_each_and_decision_unchanged(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Log::spy();
        $this->fakeFour(0.99, 0.10, 'timeout', 'timeout');

        $off = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());
        $this->assertSame(2, Http::recorded()->count());

        config(['services.openrouter.support_router_kb_shadow' => true]);
        $on = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());
        // no request-count assertion here: with a throwing stub in the pool,
        // Http::recorded() undercounts (infra quirk, probed) — the null scores,
        // the two warnings and the unchanged decision are the functional proof;
        // "flag on sends four requests" is covered by the body-shape test.

        $this->assertNotNull($off);
        $this->assertNotNull($on);
        $this->assertNull($on['kb_score']);
        $this->assertNull($on['kb_ctx_score']);
        $this->assertSame($off['score'], $on['score']);
        $this->assertSame($off['buying_score'], $on['buying_score']);
        $this->assertSame($off['handover'], $on['handover']);

        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) use ($bot) {
            return in_array($message, [
                'Support router: KB shadow check failed (kb)',
                'Support router: KB shadow check failed (kb_ctx)',
            ], true)
                && $context['bot_id'] === $bot->id
                && $context['status'] === null
                && str_contains($context['error'], 'timed out');
        });
    }

    #[Test]
    public function test_kb_shadow_flag_on_support_failure_still_returns_null(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        $this->fakeSupportFailure(500, 0.10);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNull($decision);
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

    /**
     * Fake all four Decisions calls, routed by the question name in the request
     * body (both kb variants share the question name "kb"; they differ only in
     * state, so the ctx variant is told apart by state.recent_conversation).
     * $kb / $kbCtx spec: float score, null = HTTP 500, 'timeout' = ConnectionException.
     */
    private function fakeFour(float $support, float $buying, float|null|string $kb, float|null|string $kbCtx): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($support, $buying, $kb, $kbCtx) {
                $data = $request->data();
                $questions = $data['questions'] ?? [];

                if (isset($questions['kb'])) {
                    return isset($data['state']['recent_conversation'])
                        ? $this->kbShadowResponse($kbCtx, true)
                        : $this->kbShadowResponse($kb, false);
                }

                if (isset($questions['buying'])) {
                    return Http::response($this->buyingPayload($buying));
                }

                return Http::response($this->decisionPayload($support));
            },
        ]);
    }

    private function kbShadowResponse(float|null|string $spec, bool $ctx)
    {
        if ($spec === 'timeout') {
            return fn () => throw new ConnectionException('cURL error 28: Operation timed out');
        }

        if ($spec === null) {
            return Http::response(['error' => ['message' => 'kb shadow boom']], 500);
        }

        return Http::response($ctx ? $this->kbCtxPayload($spec) : $this->kbPayload($spec));
    }

    private function kbPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['kb' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000031],
            'id' => 'gen-dec-kb',
            'provider' => 'OpenAI',
        ];
    }

    private function kbCtxPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['kb' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 41, 'output_tokens' => 0, 'cost' => 0.0000041],
            'id' => 'gen-dec-kbctx',
            'provider' => 'OpenAI',
        ];
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
