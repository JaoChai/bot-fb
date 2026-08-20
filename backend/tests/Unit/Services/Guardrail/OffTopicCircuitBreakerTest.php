<?php

namespace Tests\Unit\Services\Guardrail;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OffTopicCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private OffTopicCircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->breaker = new OffTopicCircuitBreaker;
    }

    #[Test]
    public function test_not_tripped_below_threshold(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);

        $this->assertFalse($this->breaker->isTripped($bot, $conversation));
    }

    #[Test]
    public function test_tripped_at_threshold(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);
        $this->breaker->recordTrigger($bot, $conversation);

        $this->assertTrue($this->breaker->isTripped($bot, $conversation));
    }

    #[Test]
    public function test_counters_are_isolated_per_conversation(): void
    {
        [$bot, $conversationA] = $this->makeBotWithConversation();
        $conversationB = Conversation::factory()->create(['bot_id' => $bot->id]);

        $this->breaker->recordTrigger($bot, $conversationA);
        $this->breaker->recordTrigger($bot, $conversationA);
        $this->breaker->recordTrigger($bot, $conversationA);

        $this->assertTrue($this->breaker->isTripped($bot, $conversationA));
        $this->assertFalse($this->breaker->isTripped($bot, $conversationB));
    }

    #[Test]
    public function test_cache_key_format(): void
    {
        $this->assertSame('off_topic_count:26:100', OffTopicCircuitBreaker::cacheKey(26, 100));
    }

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }
}
