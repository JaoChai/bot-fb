<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExtractEntitiesJob;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtractEntitiesJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_should_extract_returns_true_at_interval(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'message_count' => 5,
        ]);

        $this->assertTrue(ExtractEntitiesJob::shouldExtract($conversation));
    }

    public function test_should_extract_returns_true_at_double_interval(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'message_count' => 10,
        ]);

        $this->assertTrue(ExtractEntitiesJob::shouldExtract($conversation));
    }

    public function test_should_extract_returns_false_between_intervals(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'message_count' => 3,
        ]);

        $this->assertFalse(ExtractEntitiesJob::shouldExtract($conversation));
    }

    public function test_should_extract_returns_false_at_zero(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'message_count' => 0,
        ]);

        $this->assertFalse(ExtractEntitiesJob::shouldExtract($conversation));
    }

    public function test_job_is_queued_on_low_queue(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        $job = new ExtractEntitiesJob($conversation);

        $this->assertEquals('low', $job->queue);
    }
}
