<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use App\Services\LINEService;
use Mockery;
use Tests\TestCase;

class SendDelayedBubbleJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a bot instance without triggering encryption casts.
     * Uses setRawAttributes to bypass the EncryptedWithFallback cast.
     */
    protected function createBotInstance(array $attributes = []): Bot
    {
        $bot = new Bot();
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

    public function test_job_sends_push_message(): void
    {
        $bot = $this->createBotInstance();

        // Mock LINEService to verify push is called correctly
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('push')
            ->once()
            ->with($bot, 'U_user_123', ['Hello bubble 2'])
            ->andReturn(true);

        $job = new SendDelayedBubbleJob(
            $bot,
            'U_user_123',
            'Hello bubble 2',
            2,
            3
        );

        $job->handle($lineService);

        // Mockery verifies the expectation, this ensures test has assertion
        $this->assertTrue(true);
    }

    public function test_job_has_correct_retry_configuration(): void
    {
        $bot = $this->createBotInstance();

        $job = new SendDelayedBubbleJob(
            $bot,
            'U_user_123',
            'Hello bubble',
            1,
            2
        );

        $this->assertEquals(2, $job->tries);
        $this->assertEquals(2, $job->backoff);
    }

    public function test_job_throws_exception_on_line_api_failure(): void
    {
        $bot = $this->createBotInstance();

        // Mock LINEService to simulate API failure
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('push')
            ->once()
            ->andThrow(new \Exception('LINE API error'));

        $job = new SendDelayedBubbleJob(
            $bot,
            'U_user_123',
            'Hello bubble',
            2,
            3
        );

        $this->expectException(\Exception::class);
        $job->handle($lineService);
    }

    public function test_job_stores_all_properties_correctly(): void
    {
        $bot = $this->createBotInstance();

        $job = new SendDelayedBubbleJob(
            $bot,
            'U_user_123',
            'Test content',
            3,
            5
        );

        $this->assertEquals($bot->id, $job->bot->id);
        $this->assertEquals('U_user_123', $job->userId);
        $this->assertEquals('Test content', $job->bubbleContent);
        $this->assertEquals(3, $job->bubbleIndex);
        $this->assertEquals(5, $job->totalBubbles);
    }
}
