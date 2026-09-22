<?php

namespace Tests\Unit\Services;

use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MultipleBubblesServiceTest extends TestCase
{
    protected MultipleBubblesService $service;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createPaymentFlexMock(): PaymentFlexService
    {
        $mock = Mockery::mock(PaymentFlexService::class);
        $mock->shouldReceive('tryConvertToFlex')
            ->andReturnUsing(fn (string $bubble) => $bubble);

        return $mock;
    }

    /**
     * Create a bot instance without triggering encryption casts.
     * Uses setRawAttributes to bypass the EncryptedWithFallback cast.
     */
    protected function createBotInstance(array $attributes = []): Bot
    {
        $bot = new Bot;
        $bot->setRawAttributes(array_merge([
            'id' => 1,
            'user_id' => 1,
            'name' => 'Test Bot',
            'channel_type' => 'line',
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_channel_secret',
        ], $attributes));

        return $bot;
    }

    /**
     * Create a bot with settings relation mocked.
     */
    protected function createBotWithSettings(array $settingsOverrides = []): Bot
    {
        $bot = $this->createBotInstance();

        $settings = new BotSetting(array_merge([
            'bot_id' => $bot->id,
            'multiple_bubbles_enabled' => true,
            'multiple_bubbles_min' => 1,
            'multiple_bubbles_max' => 3,
            'multiple_bubbles_delimiter' => '|||',
            'wait_multiple_bubbles_enabled' => true,
            'wait_multiple_bubbles_ms' => 1500,
        ], $settingsOverrides));

        $bot->setRelation('settings', $settings);

        return $bot;
    }

    public function test_single_bubble_sent_immediately_with_reply(): void
    {
        Queue::fake();

        $bot = $this->createBotWithSettings();

        // Mock LINEService to verify replyWithFallback is called
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry-key');
        $lineService->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'reply_token', 'U_user_123', ['Hello'], 'retry-key')
            ->andReturn(['method' => 'reply', 'success' => true]);

        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            'reply_token',
            ['Hello']
        );

        $this->assertTrue($result);

        // No delayed jobs for single bubble
        Queue::assertNothingPushed();
    }

    public function test_multiple_bubbles_first_uses_reply_rest_dispatched(): void
    {
        Queue::fake();

        $bot = $this->createBotWithSettings(['wait_multiple_bubbles_ms' => 1000]);

        // Mock LINEService - only first bubble uses replyWithFallback
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry-key');
        $lineService->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'reply_token', 'U_user_123', ['Bubble 1'], 'retry-key')
            ->andReturn(['method' => 'reply', 'success' => true]);

        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            'reply_token',
            ['Bubble 1', 'Bubble 2', 'Bubble 3']
        );

        $this->assertTrue($result);

        // Should dispatch 2 delayed jobs (bubbles 2 and 3)
        Queue::assertPushed(SendDelayedBubbleJob::class, 2);

        // Verify bubble 2 dispatched
        Queue::assertPushed(SendDelayedBubbleJob::class, function ($job) {
            return $job->bubbleContent === 'Bubble 2'
                && $job->bubbleIndex === 2
                && $job->totalBubbles === 3;
        });

        // Verify bubble 3 dispatched
        Queue::assertPushed(SendDelayedBubbleJob::class, function ($job) {
            return $job->bubbleContent === 'Bubble 3'
                && $job->bubbleIndex === 3
                && $job->totalBubbles === 3;
        });

        // Verify jobs are on correct queue
        Queue::assertPushedOn('webhooks', SendDelayedBubbleJob::class);
    }

    public function test_no_reply_token_uses_push_for_first_bubble(): void
    {
        Queue::fake();

        $bot = $this->createBotWithSettings();

        // Mock LINEService - first bubble uses replyWithFallback (handles null token internally)
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry-key');
        $lineService->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, null, 'U_user_123', ['Bubble 1'], 'retry-key')
            ->andReturn(['method' => 'push', 'success' => true]);

        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            null, // No reply token
            ['Bubble 1', 'Bubble 2']
        );

        $this->assertTrue($result);

        // Bubble 2 dispatched as job
        Queue::assertPushed(SendDelayedBubbleJob::class, 1);
    }

    public function test_zero_delay_sends_all_immediately(): void
    {
        Queue::fake();

        // Disable delay
        $bot = $this->createBotWithSettings(['wait_multiple_bubbles_enabled' => false]);

        // Mock LINEService - both bubbles sent immediately
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry-key');
        $lineService->shouldReceive('replyWithFallback')
            ->once()
            ->with($bot, 'reply_token', 'U_user_123', ['Bubble 1'], 'retry-key')
            ->andReturn(['method' => 'reply', 'success' => true]);
        $lineService->shouldReceive('push')
            ->once()
            ->with($bot, 'U_user_123', ['Bubble 2'], 'retry-key')
            ->andReturn(true);

        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            'reply_token',
            ['Bubble 1', 'Bubble 2']
        );

        $this->assertTrue($result);

        // Should send both bubbles immediately (no queue jobs)
        Queue::assertNothingPushed();
    }

    public function test_empty_bubbles_returns_false(): void
    {
        $bot = $this->createBotWithSettings();
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            'reply_token',
            []
        );

        $this->assertFalse($result);
    }

    public function test_first_bubble_failure_returns_false(): void
    {
        Queue::fake();

        $bot = $this->createBotWithSettings();

        // Mock LINEService to throw exception
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry-key');
        $lineService->shouldReceive('replyWithFallback')
            ->once()
            ->andThrow(new \Exception('Invalid reply token'));

        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $result = $service->sendBubbles(
            $bot,
            'U_user_123',
            'invalid_token',
            ['Bubble 1', 'Bubble 2']
        );

        $this->assertFalse($result);

        // No jobs dispatched on failure
        Queue::assertNothingPushed();
    }

    public function test_parse_into_bubbles_splits_by_delimiter(): void
    {
        $bot = $this->createBotWithSettings();
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('Hello|||World|||Test', $bot);

        $this->assertCount(3, $bubbles);
        $this->assertEquals(['Hello', 'World', 'Test'], $bubbles);
    }

    public function test_parse_into_bubbles_respects_max_limit_and_merges_overflow(): void
    {
        $bot = $this->createBotWithSettings(['multiple_bubbles_max' => 2]);
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('One|||Two|||Three|||Four', $bot);

        // Should be limited to 2 bubbles, with overflow merged into last bubble
        $this->assertCount(2, $bubbles);
        $this->assertEquals('One', $bubbles[0]);
        $this->assertEquals("Two\nThree\nFour", $bubbles[1]);
    }

    public function test_parse_into_bubbles_trims_whitespace(): void
    {
        $bot = $this->createBotWithSettings();
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('  Hello  |||  World  ', $bot);

        $this->assertEquals(['Hello', 'World'], $bubbles);
    }

    public function test_parse_into_bubbles_removes_empty_bubbles(): void
    {
        $bot = $this->createBotWithSettings();
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('Hello||||||World', $bot);

        $this->assertCount(2, $bubbles);
        $this->assertEquals(['Hello', 'World'], $bubbles);
    }

    public function test_parse_into_bubbles_within_limit_returns_all(): void
    {
        $bot = $this->createBotWithSettings(['multiple_bubbles_max' => 3]);
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('One|||Two|||Three', $bot);

        $this->assertCount(3, $bubbles);
        $this->assertEquals(['One', 'Two', 'Three'], $bubbles);
    }

    public function test_parse_into_bubbles_overflow_preserves_important_data(): void
    {
        $bot = $this->createBotWithSettings(['multiple_bubbles_max' => 2]);
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $content = 'สวัสดีครับ|||รายละเอียดสินค้า|||เลขบัญชี: 123-456-789';
        $bubbles = $service->parseIntoBubbles($content, $bot);

        // Overflow merged into last bubble - important data (account number) preserved
        $this->assertCount(2, $bubbles);
        $this->assertEquals('สวัสดีครับ', $bubbles[0]);
        $this->assertStringContainsString('เลขบัญชี: 123-456-789', $bubbles[1]);
    }

    public function test_parse_into_bubbles_overflow_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Multiple bubbles exceeded limit, merged overflow into last bubble', Mockery::on(function ($context) {
                return $context['original_count'] === 4
                    && $context['limit'] === 2
                    && $context['overflow_count'] === 2;
            }));
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $bot = $this->createBotWithSettings(['multiple_bubbles_max' => 2]);
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bubbles = $service->parseIntoBubbles('A|||B|||C|||D', $bot);

        $this->assertCount(2, $bubbles);
    }

    public function test_is_enabled_returns_correct_value(): void
    {
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $enabledBot = $this->createBotWithSettings(['multiple_bubbles_enabled' => true]);
        $disabledBot = $this->createBotWithSettings(['multiple_bubbles_enabled' => false]);

        $this->assertTrue($service->isEnabled($enabledBot));
        $this->assertFalse($service->isEnabled($disabledBot));
    }

    public function test_get_delay_ms_returns_configured_value(): void
    {
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bot = $this->createBotWithSettings([
            'wait_multiple_bubbles_enabled' => true,
            'wait_multiple_bubbles_ms' => 2000,
        ]);

        $this->assertEquals(2000, $service->getDelayMs($bot));
    }

    public function test_get_delay_ms_returns_zero_when_disabled(): void
    {
        $lineService = Mockery::mock(LINEService::class);
        $service = new MultipleBubblesService($lineService, $this->createPaymentFlexMock());

        $bot = $this->createBotWithSettings([
            'wait_multiple_bubbles_enabled' => false,
            'wait_multiple_bubbles_ms' => 2000,
        ]);

        $this->assertEquals(0, $service->getDelayMs($bot));
    }
}
