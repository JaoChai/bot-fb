<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Services\AIService;
use App\Services\CircuitBreakerService;
use App\Services\LINEService;
use App\Services\LineWebhook\GateDecision;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\LineWebhookGatingService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\MessageAggregationService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessLINEWebhookPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected array $lineEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->bot = Bot::factory()->active()->line()->create([
            'user_id' => $this->user->id,
            'id' => 26,
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ]);

        $this->lineEvent = [
            'type' => 'message',
            'replyToken' => 'reply_token_test_123',
            'source' => [
                'type' => 'user',
                'userId' => 'U_pipeline_test_user',
            ],
            'message' => [
                'id' => 'msg_pipeline_001',
                'type' => 'text',
                'text' => 'สวัสดีครับ pipeline',
            ],
            'webhookEventId' => 'webhook_pipeline_001',
            'deliveryContext' => [
                'isRedelivery' => false,
            ],
            'timestamp' => time() * 1000,
        ];
    }

    protected function buildCircuitBreakerMock(): Mockery\MockInterface
    {
        $circuitBreaker = Mockery::mock(CircuitBreakerService::class);
        $circuitBreaker->shouldReceive('execute')
            ->andReturnUsing(function (string $service, callable $operation, ?callable $fallback = null) {
                return $operation();
            });

        return $circuitBreaker;
    }

    public function test_legacy_path_runs_when_flag_off(): void
    {
        config([
            'line_webhook.pipeline_enabled' => false,
        ]);

        // Pipeline services should NOT be called
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldNotReceive('check');

        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldNotReceive('resolve');

        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldNotReceive('generate');

        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldNotReceive('dispatch');

        // Bind mocks into container
        $this->app->instance(LineWebhookGatingService::class, $gating);
        $this->app->instance(LineWebhookContextService::class, $contextSvc);
        $this->app->instance(LineWebhookResponseService::class, $responseSvc);
        $this->app->instance(LineWebhookOutputService::class, $outputSvc);

        // Legacy path: LINEService::isMessageEvent is the first call in processEvent
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->once()->andReturn(false); // non-message → logs and returns
        $lineService->shouldReceive('extractUserId')->andReturn(null);

        $aiService = Mockery::mock(AIService::class);
        $rateLimitService = Mockery::mock(RateLimitService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);
        $responseHoursService = Mockery::mock(ResponseHoursService::class);
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $job = new ProcessLINEWebhook($this->bot, $this->lineEvent);
        $job->handle(
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $responseHoursService,
            $circuitBreaker,
            $gating,
            $contextSvc,
            $responseSvc,
            $outputSvc,
        );

        // Mockery assertions verify shouldNotReceive constraints above
        $this->addToAssertionCount(1);
    }

    public function test_pipeline_path_runs_when_flag_on_for_whitelisted_bot(): void
    {
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        $callOrder = [];

        // Gating mock: check() called, leaves gateDecision null (allow)
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')
            ->once()
            ->andReturnUsing(function (WebhookContext $ctx) use (&$callOrder) {
                $callOrder[] = 'gating';
                // gateDecision remains null → allow
            });

        // Context mock: resolve() called, sets aggregationBuffered = false
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldReceive('resolve')
            ->once()
            ->andReturnUsing(function (WebhookContext $ctx) use (&$callOrder) {
                $callOrder[] = 'context';
                $ctx->aggregationBuffered = false;
            });

        // Response mock: generate() called
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (WebhookContext $ctx) use (&$callOrder) {
                $callOrder[] = 'response';
            });

        // Output mock: dispatch() called
        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function (WebhookContext $ctx) use (&$callOrder) {
                $callOrder[] = 'output';
            });

        $this->app->instance(LineWebhookGatingService::class, $gating);
        $this->app->instance(LineWebhookContextService::class, $contextSvc);
        $this->app->instance(LineWebhookResponseService::class, $responseSvc);
        $this->app->instance(LineWebhookOutputService::class, $outputSvc);

        // Legacy LINEService should NOT have isMessageEvent called (pipeline takes over)
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldNotReceive('isMessageEvent');
        $lineService->shouldReceive('extractUserId')->andReturn(null);

        $aiService = Mockery::mock(AIService::class);
        $rateLimitService = Mockery::mock(RateLimitService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);
        $responseHoursService = Mockery::mock(ResponseHoursService::class);
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $job = new ProcessLINEWebhook($this->bot, $this->lineEvent);
        $job->handle(
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $responseHoursService,
            $circuitBreaker,
            $gating,
            $contextSvc,
            $responseSvc,
            $outputSvc,
        );

        $this->assertSame(['gating', 'context', 'response', 'output'], $callOrder);
    }

    public function test_pipeline_short_circuits_on_rate_limit(): void
    {
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        // Gating sets RATE_LIMITED
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')
            ->once()
            ->andReturnUsing(function (WebhookContext $ctx) {
                $ctx->gateDecision = GateDecision::RATE_LIMITED;
            });

        // Context, Response, Output must NOT be called
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldNotReceive('resolve');

        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldNotReceive('generate');

        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldNotReceive('dispatch');

        $this->app->instance(LineWebhookGatingService::class, $gating);
        $this->app->instance(LineWebhookContextService::class, $contextSvc);
        $this->app->instance(LineWebhookResponseService::class, $responseSvc);
        $this->app->instance(LineWebhookOutputService::class, $outputSvc);

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldNotReceive('isMessageEvent');
        $lineService->shouldReceive('extractUserId')->andReturn(null);

        $aiService = Mockery::mock(AIService::class);
        $rateLimitService = Mockery::mock(RateLimitService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);
        $responseHoursService = Mockery::mock(ResponseHoursService::class);
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $job = new ProcessLINEWebhook($this->bot, $this->lineEvent);
        $job->handle(
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $responseHoursService,
            $circuitBreaker,
            $gating,
            $contextSvc,
            $responseSvc,
            $outputSvc,
        );

        $this->addToAssertionCount(1);
    }
}
