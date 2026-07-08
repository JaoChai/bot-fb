<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAggregatedMessages;
use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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

        // Fix 3: isMessageEvent + isTextMessage are now checked before entering the pipeline
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->once()->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->once()->andReturn(true);
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

        // Pipeline entry: text-event guards return true so Gating runs and short-circuits
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->once()->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->once()->andReturn(true);
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

    public function test_pipeline_falls_back_to_aggregation_when_lock_held(): void
    {
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        Queue::fake();

        // Hold the lock so the pipeline cannot acquire it
        $conv = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_pipeline_test_user',
            'channel_type' => 'line',
            'status' => 'active',
        ]);
        $lockKey = "ai_response:{$conv->id}";
        Cache::lock($lockKey, 30)->get(); // acquire — pipeline will fail to get it

        // Gating: allow
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldReceive('check')->once()->andReturnUsing(function (WebhookContext $ctx) {
            // no gateDecision
        });

        // Context: sets conversation + userMessage so text lock path activates
        $contextSvc = Mockery::mock(LineWebhookContextService::class);
        $contextSvc->shouldReceive('resolve')->once()->andReturnUsing(function (WebhookContext $ctx) use ($conv) {
            $ctx->conversation = $conv;
            $ctx->userMessage = Message::factory()->make([
                'conversation_id' => $conv->id,
                'sender' => 'user',
                'content' => 'สวัสดีครับ pipeline',
            ]);
            $ctx->aggregationBuffered = false;
        });

        // Response must NOT be called (lock held → early return)
        $responseSvc = Mockery::mock(LineWebhookResponseService::class);
        $responseSvc->shouldNotReceive('generate');

        $outputSvc = Mockery::mock(LineWebhookOutputService::class);
        $outputSvc->shouldNotReceive('dispatch');

        // Aggregation: startOrContinueAggregation returns a group
        $aggregationService = Mockery::mock(MessageAggregationService::class);
        $aggregationService->shouldReceive('startOrContinueAggregation')
            ->once()
            ->andReturn(['group_id' => 'grp_test_001']);

        $this->app->instance(LineWebhookGatingService::class, $gating);
        $this->app->instance(LineWebhookContextService::class, $contextSvc);
        $this->app->instance(LineWebhookResponseService::class, $responseSvc);
        $this->app->instance(LineWebhookOutputService::class, $outputSvc);
        $this->app->instance(MessageAggregationService::class, $aggregationService);

        // Pipeline entry: text-event guards return true
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->once()->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->once()->andReturn(true);
        $lineService->shouldReceive('extractUserId')->andReturn(null);
        $aiService = Mockery::mock(AIService::class);
        $rateLimitService = Mockery::mock(RateLimitService::class);
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

        Queue::assertPushed(ProcessAggregatedMessages::class);

        Cache::lock($lockKey, 30)->forceRelease();
    }

    public function test_non_text_event_uses_legacy_path(): void
    {
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        // Pipeline services should NOT be called for non-text events
        $gating = Mockery::mock(LineWebhookGatingService::class);
        $gating->shouldNotReceive('check');

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

        // Non-text (sticker) event
        $stickerEvent = array_merge($this->lineEvent, [
            'message' => [
                'id' => 'stk_001',
                'type' => 'sticker',
                'stickerId' => '1',
                'packageId' => '1',
            ],
        ]);

        // Legacy path: isMessageEvent/isTextMessage called twice (once in handle() flag guard, once in processEvent());
        // isImageMessage is checked once in the handle() flag guard (sticker → false → legacy).
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->twice()->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->twice()->andReturn(false);
        $lineService->shouldReceive('isImageMessage')->once()->andReturn(false);
        $lineService->shouldReceive('extractUserId')->andReturn(null); // null userId → handleNonTextMessage returns early
        $lineService->shouldReceive('extractReplyToken')->andReturn('reply_token');
        $lineService->shouldReceive('extractMessage')->andReturn(['type' => 'sticker', 'id' => 'stk_001', 'sticker_id' => '1']);
        $lineService->shouldReceive('extractWebhookEventId')->andReturn('webhook_legacy_001');
        $lineService->shouldReceive('extractEventTimestamp')->andReturn(time() * 1000);
        $lineService->shouldReceive('isRedelivery')->andReturn(false);

        $aiService = Mockery::mock(AIService::class);
        $rateLimitService = Mockery::mock(RateLimitService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);
        $responseHoursService = Mockery::mock(ResponseHoursService::class);
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $job = new ProcessLINEWebhook($this->bot, $stickerEvent);
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
