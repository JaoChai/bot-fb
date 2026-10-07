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
use PHPUnit\Framework\Attributes\Test;
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

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.9)),
        ]);

        app(SupportRouterService::class)->decide($bot, $latest, $history);

        Http::assertSent(function ($request) use ($latest) {
            $data = $request->data();

            if ($request->url() !== self::DECISIONS_URL) {
                return false;
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

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.5)),
        ]);

        app(SupportRouterService::class)->decide($bot, 'ครับ', [
            ['sender' => 'user', 'content' => $long],
        ]);

        Http::assertSent(function ($request) {
            $recent = $request->data()['state']['recent_conversation'];

            $this->assertCount(1, $recent);
            $this->assertSame(300, mb_strlen($recent[0]['text']));

            return true;
        });
    }

    #[Test]
    public function test_score_above_threshold_in_on_mode_returns_handover(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.93)),
        ]);

        $decision = app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertNotNull($decision);
        $this->assertSame(0.93, $decision['score']);
        $this->assertTrue($decision['handover']);
        $this->assertSame('on', $decision['mode']);
        $this->assertSame('openai/gpt-6-luna-decisions', $decision['model']);
        $this->assertIsInt($decision['latency_ms']);
        $this->assertEqualsWithDelta(0.0000639, $decision['cost'], 1e-12);
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

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.9)),
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => []]),
        ]);

        app(SupportRouterService::class)->decide($bot, 'บัญชีโดนแบนครับ', $this->history());

        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'api/alpha/decisions'))->count());
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

        Http::fake([
            self::DECISIONS_URL => Http::response($this->decisionPayload(0.93)),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

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
