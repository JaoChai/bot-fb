<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use App\Services\CircuitBreakerService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessLINEWebhookOfflineTest extends TestCase
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
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ]);

        BotSetting::create([
            'bot_id' => $this->bot->id,
            'response_hours_enabled' => true,
            'offline_message' => 'ขณะนี้นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลาทำการ',
            'response_hours' => [
                'mon' => [],
                'tue' => [],
                'wed' => [],
                'thu' => [],
                'fri' => [],
                'sat' => [],
                'sun' => [],
            ],
        ]);

        $this->bot->load('settings');

        $this->lineEvent = [
            'type' => 'message',
            'replyToken' => 'reply_token_test_123',
            'source' => [
                'type' => 'user',
                'userId' => 'U_test_user_123',
            ],
            'message' => [
                'id' => 'msg_test_001',
                'type' => 'text',
                'text' => 'สวัสดีครับ',
            ],
            'webhookEventId' => 'webhook_event_001',
            'deliveryContext' => [
                'isRedelivery' => false,
            ],
            'timestamp' => time() * 1000,
        ];
    }

    protected function buildLineServiceMock(bool $expectReplyWithFallback): Mockery\MockInterface
    {
        $lineService = Mockery::mock(LINEService::class);

        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(true);
        $lineService->shouldReceive('extractUserId')->andReturn('U_test_user_123');
        $lineService->shouldReceive('extractReplyToken')->andReturn('reply_token_test_123');
        $lineService->shouldReceive('extractMessage')->andReturn([
            'id' => 'msg_test_001',
            'type' => 'text',
            'text' => 'สวัสดีครับ',
        ]);
        $lineService->shouldReceive('extractWebhookEventId')->andReturn('webhook_event_001');
        $lineService->shouldReceive('extractEventTimestamp')->andReturn(time() * 1000);
        $lineService->shouldReceive('isRedelivery')->andReturn(false);
        $lineService->shouldReceive('showLoadingIndicator')->andReturn(null);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry_key_test');

        if ($expectReplyWithFallback) {
            $lineService->shouldReceive('replyWithFallback')->once();
        } else {
            $lineService->shouldReceive('replyWithFallback')->never();
        }

        return $lineService;
    }

    protected function buildResponseHoursServiceMock(): Mockery\MockInterface
    {
        $responseHoursService = Mockery::mock(ResponseHoursService::class);

        $responseHoursService->shouldReceive('checkResponseHours')
            ->andReturn([
                'allowed' => false,
                'status' => ResponseHoursService::STATUS_OUTSIDE_HOURS,
                'current_time' => '02:00',
                'timezone' => 'Asia/Bangkok',
                'day' => 'mon',
            ]);

        $responseHoursService->shouldReceive('getOfflineMessage')
            ->andReturn('ขณะนี้นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลาทำการ');

        return $responseHoursService;
    }

    protected function buildRateLimitServiceMock(): Mockery\MockInterface
    {
        $rateLimitService = Mockery::mock(RateLimitService::class);

        $rateLimitService->shouldReceive('checkRateLimit')
            ->andReturn([
                'allowed' => true,
                'status' => RateLimitService::STATUS_ALLOWED,
            ]);

        return $rateLimitService;
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

    public function test_sends_offline_message_when_not_handover(): void
    {
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_test_user_123',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
        ]);

        $lineService = $this->buildLineServiceMock(expectReplyWithFallback: true);
        $responseHoursService = $this->buildResponseHoursServiceMock();
        $rateLimitService = $this->buildRateLimitServiceMock();
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $aiService = Mockery::mock(AIService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);

        $job = new ProcessLINEWebhook($this->bot, $this->lineEvent);
        $job->handle(
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $responseHoursService,
            $circuitBreaker
        );

        $this->addToAssertionCount(1);
    }

    public function test_skips_offline_message_when_handover(): void
    {
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_test_user_123',
            'channel_type' => 'line',
            'status' => 'handover',
            'is_handover' => true,
        ]);

        $lineService = $this->buildLineServiceMock(expectReplyWithFallback: false);
        $responseHoursService = $this->buildResponseHoursServiceMock();
        $rateLimitService = $this->buildRateLimitServiceMock();
        $circuitBreaker = $this->buildCircuitBreakerMock();

        $aiService = Mockery::mock(AIService::class);
        $aggregationService = Mockery::mock(MessageAggregationService::class);

        $job = new ProcessLINEWebhook($this->bot, $this->lineEvent);
        $job->handle(
            $lineService,
            $aiService,
            $rateLimitService,
            $aggregationService,
            $responseHoursService,
            $circuitBreaker
        );

        $this->addToAssertionCount(1);
    }
}
