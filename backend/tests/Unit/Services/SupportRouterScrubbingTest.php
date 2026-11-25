<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\SupportRouter\LunaPayloadScrubber;
use App\Services\SupportRouter\SupportRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Scrubbing of the payload we send to the Luna Decisions API.
 *
 * The *_BODY_JSON fixtures below are the BYTE-EXACT serialized bodies produced
 * by the production code on 5a48982a (2026-11-25) for credential-free input.
 * They are frozen on purpose: if any future change (including the scrubber)
 * alters one byte of a credential-free body, these tests must fail.
 *
 * IRON RULE: every credential-looking string in this file is SYNTHETIC
 * (FAKE-...). Never copy real customer text into tests.
 */
class SupportRouterScrubbingTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

    private const FIXTURE_SUPPORT_BODY_JSON = '{"model":"openai\\/gpt-6-luna-decisions","state":{"shop":"Online shop chat. The bot sells; human Technical Support handles anything about products already bought.","recent_conversation":[{"from":"user","text":"\\u0e2a\\u0e19\\u0e43\\u0e08 BM \\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"BM \\u0e23\\u0e32\\u0e04\\u0e32 1,100 \\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"user","text":"\\u0e42\\u0e2d\\u0e19\\u0e41\\u0e25\\u0e49\\u0e27\\u0e19\\u0e30\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"\\u0e44\\u0e14\\u0e49\\u0e23\\u0e31\\u0e1a\\u0e41\\u0e25\\u0e49\\u0e27\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"user","text":"\\u0e02\\u0e2d\\u0e1a\\u0e04\\u0e38\\u0e13\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"\\u0e02\\u0e2d\\u0e1a\\u0e04\\u0e38\\u0e13\\u0e04\\u0e23\\u0e31\\u0e1a"}],"latest_customer_message":"\\u0e0b\\u0e37\\u0e49\\u0e2d\\u0e40\\u0e1f\\u0e2a\\u0e44\\u0e01\\u0e48 40 \\u0e2d\\u0e31\\u0e19\\u0e04\\u0e23\\u0e31\\u0e1a"},"questions":{"support":{"type":"noul","instructions":"Judging the LATEST customer message in context of the recent conversation: is this topic present? Post-sale issue that the shop\'s human Technical Support must handle: a problem with an account or product already bought (banned, locked, checkpoint, error, login fails, wrong password, limit not rising, card binding fails), technical how-to after purchase (setup, ads manager, pixel, campaigns), account credential checks, replacement\\/claim, or \'support hasn\'t replied\'. Also true when the latest message continues an ongoing post-sale issue from the recent conversation (follow-up answers, pasted account credentials\\/IDs\\/card numbers, \'check this\').","criteria":{"true":"Yes","false":"No"}}}}';

    private const FIXTURE_BUYING_BODY_JSON = '{"model":"openai\\/gpt-6-luna-decisions","state":{"shop":"Online shop chat. The bot sells; human Technical Support handles anything about products already bought.","latest_customer_message":"\\u0e0b\\u0e37\\u0e49\\u0e2d\\u0e40\\u0e1f\\u0e2a\\u0e44\\u0e01\\u0e48 40 \\u0e2d\\u0e31\\u0e19\\u0e04\\u0e23\\u0e31\\u0e1a"},"questions":{"buying":{"type":"noul","instructions":"Judging ONLY the LATEST customer message: is the customer placing or continuing a NEW purchase right now? True for: naming a product or quantity to buy (\'buy 40\', \'I want 2 sets\', \'take that one\'), confirming an order (\'confirm\'), choosing a product option before paying, asking price\\/stock in order to buy now. False when the latest message reports a problem, asks for a claim\\/replacement, asks how to use something already bought, or chases support, even if the customer bought earlier.","criteria":{"true":"Yes","false":"No"}}}}';

    private const FIXTURE_KB_BODY_JSON = '{"model":"openai\\/gpt-6-luna-decisions","state":{"shop":"Online shop chat. The bot sells; human Technical Support handles anything about products already bought.","latest_customer_message":"\\u0e0b\\u0e37\\u0e49\\u0e2d\\u0e40\\u0e1f\\u0e2a\\u0e44\\u0e01\\u0e48 40 \\u0e2d\\u0e31\\u0e19\\u0e04\\u0e23\\u0e31\\u0e1a"},"questions":{"kb":{"type":"noul","instructions":"Is the latest customer message a question that needs factual shop information to answer (products, prices, stock, how to use or set up, policies, warranty, documentation)? Answer No for greetings, casual talk, opinions, thanks, acknowledgements, confirmations, order placement, payment notices and short follow-ups.","criteria":{"true":"Yes","false":"No"}}}}';

    private const FIXTURE_KB_CTX_BODY_JSON = '{"model":"openai\\/gpt-6-luna-decisions","state":{"shop":"Online shop chat. The bot sells; human Technical Support handles anything about products already bought.","latest_customer_message":"\\u0e0b\\u0e37\\u0e49\\u0e2d\\u0e40\\u0e1f\\u0e2a\\u0e44\\u0e01\\u0e48 40 \\u0e2d\\u0e31\\u0e19\\u0e04\\u0e23\\u0e31\\u0e1a","recent_conversation":[{"from":"user","text":"\\u0e2a\\u0e19\\u0e43\\u0e08 BM \\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"BM \\u0e23\\u0e32\\u0e04\\u0e32 1,100 \\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"user","text":"\\u0e42\\u0e2d\\u0e19\\u0e41\\u0e25\\u0e49\\u0e27\\u0e19\\u0e30\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"\\u0e44\\u0e14\\u0e49\\u0e23\\u0e31\\u0e1a\\u0e41\\u0e25\\u0e49\\u0e27\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"user","text":"\\u0e02\\u0e2d\\u0e1a\\u0e04\\u0e38\\u0e13\\u0e04\\u0e23\\u0e31\\u0e1a"},{"from":"bot","text":"\\u0e02\\u0e2d\\u0e1a\\u0e04\\u0e38\\u0e13\\u0e04\\u0e23\\u0e31\\u0e1a"}]},"questions":{"kb":{"type":"noul","instructions":"Is the latest customer message a question that needs factual shop information to answer (products, prices, stock, how to use or set up, policies, warranty, documentation)? Answer No for greetings, casual talk, opinions, thanks, acknowledgements, confirmations, order placement, payment notices and short follow-ups.","criteria":{"true":"Yes","false":"No"}}}}';

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
    public function test_credential_free_bodies_stay_byte_identical_to_production(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);

        $this->fakeByQuestion(0.87, 0.95);

        app(SupportRouterService::class)->decide($bot, $this->cleanLatest(), $this->history());

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        $support = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['support']))[0];
        $buying = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['buying']))[0];

        $this->assertSame(self::FIXTURE_SUPPORT_BODY_JSON, json_encode($support->data()));
        $this->assertSame(self::FIXTURE_BUYING_BODY_JSON, json_encode($buying->data()));
    }

    #[Test]
    public function test_credential_free_kb_bodies_stay_byte_identical_to_production(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);

        $this->fakeFour(0.9, 0.1, 0.75, 0.45);

        app(SupportRouterService::class)->decide($bot, $this->cleanLatest(), $this->history());

        $requests = Http::recorded();
        $this->assertCount(4, $requests);

        $kb = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['kb'])
            && ! isset($pair[0]->data()['state']['recent_conversation']))[0];
        $kbCtx = $requests->first(fn ($pair) => isset($pair[0]->data()['questions']['kb'])
            && isset($pair[0]->data()['state']['recent_conversation']))[0];

        $this->assertSame(self::FIXTURE_KB_BODY_JSON, json_encode($kb->data()));
        $this->assertSame(self::FIXTURE_KB_CTX_BODY_JSON, json_encode($kbCtx->data()));
    }

    #[Test]
    public function test_all_four_bodies_carry_placeholders_and_never_the_raw_secret(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        config(['services.openrouter.support_router_kb_shadow' => true]);
        $latest = 'รหัสผ่าน: FAKE-PASS-123 ครับ บัญชีผมมีปัญหา';
        $history = $this->historyWithCredentials();

        $this->fakeFour(0.9, 0.1, 0.75, 0.45);

        $decision = app(SupportRouterService::class)->decide($bot, $latest, $history);

        $this->assertNotNull($decision);

        foreach (Http::recorded() as $pair) {
            $serialized = json_encode($pair[0]->data());

            $this->assertStringNotContainsString('FAKE-PASS-123', $serialized);
            $this->assertStringNotContainsString('FAKE-USER-1', $serialized);
            $this->assertStringNotContainsString('FAKEPASS99', $serialized);
        }

        $latestSeen = 0;

        foreach (Http::recorded() as $pair) {
            $state = $pair[0]->data()['state'];

            $this->assertStringContainsString('[รหัส]', $state['latest_customer_message']);

            if (isset($state['recent_conversation'])) {
                // history with credentials is scrubbed too; clean lines untouched
                $texts = array_column($state['recent_conversation'], 'text');
                $this->assertSame(2, count(array_keys($texts, '[รหัส]', true)));
                $this->assertContains('โอนแล้วนะครับ', $texts);
                $this->assertContains('ได้รับแล้วครับ', $texts);
                $latestSeen++;
            }
        }

        $this->assertSame(2, $latestSeen); // support + kb_ctx carry the history
    }

    #[Test]
    public function test_history_is_scrubbed_before_the_300_char_truncation(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $padded = 'รหัส: FAKE-CODE-99 '.str_repeat('ค', 400);

        $this->fakeByQuestion(0.87, 0.95);

        app(SupportRouterService::class)->decide($bot, 'ล็อกไม่ได้ครับ', [
            ['sender' => 'user', 'content' => $padded],
        ]);

        $support = Http::recorded()->first(fn ($pair) => isset($pair[0]->data()['questions']['support']))[0];
        $recent = $support->data()['state']['recent_conversation'];

        $this->assertSame(300, mb_strlen($recent[0]['text']));
        $this->assertStringNotContainsString('FAKE-CODE-99', $recent[0]['text']);
        $this->assertStringContainsString('[รหัส]', $recent[0]['text']);
    }

    #[Test]
    public function test_telegram_alert_keeps_the_raw_latest_message(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->giveTelegramPlugin($bot);

        $this->fakeByQuestion(0.99, 0.10);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $decision = app(SupportRouterService::class)->decide($bot, 'รหัสผ่าน: FAKE-PASS-123 ครับ บัญชีโดนแบน', $this->history());

        $this->assertNotNull($decision);
        $this->assertTrue($decision['handover']);

        // decisions bodies are scrubbed...
        $rawInBodies = Http::recorded(fn ($request) => str_contains($request->url(), 'api/alpha/decisions')
            && str_contains(json_encode($request->data()), 'FAKE-PASS-123'))->count();
        $this->assertSame(0, $rawInBodies);

        // ...the admin alert intentionally keeps the raw message for the human
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            return str_contains($request->data()['text'], 'FAKE-PASS-123');
        });
    }

    #[Test]
    public function test_scrubber_failure_returns_null_sends_nothing_and_logs_without_customer_text(): void
    {
        $bot = $this->makeBot([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        Http::fake();
        Log::spy();
        $this->mock(LunaPayloadScrubber::class, function ($mock) {
            $mock->shouldReceive('scrub')->andThrow(new RuntimeException('scrub boom'));
        });

        $decision = app(SupportRouterService::class)->decide($bot, 'รหัสผ่าน: FAKE-PASS-123 ครับ', $this->history());

        $this->assertNull($decision);
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($bot) {
            $dumped = json_encode($context);

            return $message === 'Support router: payload scrub failed, skipping Luna call this turn'
                && $context['bot_id'] === $bot->id
                && $dumped !== false
                && ! str_contains($dumped, 'FAKE-PASS-123');
        });
    }

    // ------------------------------------------------------------------

    private function cleanLatest(): string
    {
        return 'ซื้อเฟสไก่ 40 อันครับ';
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

    private function historyWithCredentials(): array
    {
        // the last-6 window must contain the credential rows AND clean lines
        return [
            ...$this->history(),
            ['sender' => 'user', 'content' => 'uid|รหัส|2fa'],
            ['sender' => 'user', 'content' => 'FAKE-USER-1|FAKEPASS99|FAKE-3FA-3'],
        ];
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

    private function fakeByQuestion(float $support, float $buying): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($support, $buying) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response($this->buyingPayload($buying));
                }

                return Http::response($this->supportPayload($support));
            },
        ]);
    }

    private function fakeFour(float $support, float $buying, float $kb, float $kbCtx): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($support, $buying, $kb, $kbCtx) {
                $questions = $request->data()['questions'] ?? [];

                if (isset($questions['kb'])) {
                    $score = isset($request->data()['state']['recent_conversation']) ? $kbCtx : $kb;

                    return Http::response([
                        'model' => 'openai/gpt-6-luna-decisions-20261006',
                        'answers' => ['kb' => ['type' => 'noul', 'noul' => $score]],
                        'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000031],
                        'id' => 'gen-dec-kb',
                        'provider' => 'OpenAI',
                    ]);
                }

                if (isset($questions['buying'])) {
                    return Http::response($this->buyingPayload($buying));
                }

                return Http::response($this->supportPayload($support));
            },
        ]);
    }

    private function supportPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['support' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 639, 'output_tokens' => 0, 'cost' => 0.0000639],
            'id' => 'gen-dec-123',
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
}
