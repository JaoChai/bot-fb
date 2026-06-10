<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ProcessAggregatedMessagesShouldGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function callShouldGenerate(Bot $bot, Conversation $conversation): bool
    {
        $job = new ProcessAggregatedMessages($bot, $conversation, 'group-1', 'U_test_user');
        $m = (new ReflectionClass($job))->getMethod('shouldGenerate');
        $m->setAccessible(true);

        return $m->invoke($job);
    }

    public function test_returns_true_when_bot_active_and_not_in_handover(): void
    {
        $bot = Bot::factory()->create(['status' => 'active']);
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => false,
        ]);

        $this->assertTrue($this->callShouldGenerate($bot, $conv));
    }

    public function test_returns_false_when_bot_inactive(): void
    {
        $bot = Bot::factory()->create(['status' => 'inactive']);
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => false,
        ]);

        $this->assertFalse($this->callShouldGenerate($bot, $conv));
    }

    public function test_returns_false_when_conversation_in_handover(): void
    {
        $bot = Bot::factory()->create(['status' => 'active']);
        $conv = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'is_handover' => true,
        ]);

        $this->assertFalse($this->callShouldGenerate($bot, $conv));
    }
}
