<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Services\LINEService;
use App\Services\LineWebhook\GateDecision;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\RateLimitService;
use Mockery;
use Tests\TestCase;

class LineWebhookGatingServiceTest extends TestCase
{

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeBot(int $id = 26): Bot
    {
        $bot = new Bot;
        $bot->id = $id;
        $bot->setRelation('settings', null);

        return $bot;
    }

    private function makeContext(Bot $bot, ?string $userId = 'U_abc', ?string $replyToken = 'rt_123'): WebhookContext
    {
        return new WebhookContext($bot, [
            'replyToken' => $replyToken,
            'source' => ['userId' => $userId],
            'message' => ['type' => 'text', 'text' => 'hi'],
        ]);
    }

    public function test_allow_when_rate_limit_not_exceeded(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('checkRateLimit')
            ->once()
            ->andReturn(['allowed' => true, 'status' => 'ok']);

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');

        $svc = new LineWebhookGatingService($rateLimit, $line);
        $ctx = $this->makeContext($this->makeBot());

        $svc->check($ctx);

        $this->assertNull($ctx->gateDecision);
    }

    public function test_rate_limited_sets_gate_decision_and_dispatches_custom_message(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('checkRateLimit')
            ->once()
            ->andReturn(['allowed' => false, 'status' => 'per_minute']);
        $rateLimit->shouldReceive('getRateLimitMessage')
            ->once()
            ->with('per_minute', null)
            ->andReturn('slow down');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->with(Mockery::type(Bot::class), 'rt_123', 'U_abc', ['slow down'], 'rk');

        $svc = new LineWebhookGatingService($rateLimit, $line);
        $ctx = $this->makeContext($this->makeBot());

        $svc->check($ctx);

        $this->assertSame(GateDecision::RATE_LIMITED, $ctx->gateDecision);
    }

    public function test_rate_limited_silent_when_no_custom_message(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('checkRateLimit')
            ->once()
            ->andReturn(['allowed' => false, 'status' => 'per_day']);
        $rateLimit->shouldReceive('getRateLimitMessage')
            ->once()
            ->andReturn(null);

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('replyWithFallback');
        $line->shouldNotReceive('generateRetryKey');

        $svc = new LineWebhookGatingService($rateLimit, $line);
        $ctx = $this->makeContext($this->makeBot());

        $svc->check($ctx);

        $this->assertSame(GateDecision::RATE_LIMITED, $ctx->gateDecision);
    }

    public function test_rate_limit_push_failure_does_not_throw(): void
    {
        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('checkRateLimit')
            ->once()
            ->andReturn(['allowed' => false, 'status' => 'per_minute']);
        $rateLimit->shouldReceive('getRateLimitMessage')
            ->once()
            ->andReturn('slow down');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk');
        $line->shouldReceive('replyWithFallback')
            ->once()
            ->andThrow(new \RuntimeException('line api down'));

        $svc = new LineWebhookGatingService($rateLimit, $line);
        $ctx = $this->makeContext($this->makeBot());

        // Must not throw — legacy swallows + logs
        $svc->check($ctx);

        $this->assertSame(GateDecision::RATE_LIMITED, $ctx->gateDecision);
    }
}
