<?php

namespace Tests\Unit\Models;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotUtilityModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_utility_model_returns_utility_model_when_set(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o',
            'fallback_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'anthropic/claude-3-haiku',
        ]);

        $this->assertSame('anthropic/claude-3-haiku', $bot->resolvedUtilityModel());
    }

    public function test_resolved_utility_model_falls_back_to_fallback_chat_model_when_unset(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o',
            'fallback_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => null,
        ]);

        $this->assertSame('openai/gpt-4o-mini', $bot->resolvedUtilityModel());
    }

    public function test_resolved_utility_model_falls_back_to_primary_chat_model_when_no_fallback(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o',
            'fallback_chat_model' => null,
            'utility_model' => null,
        ]);

        $this->assertSame('openai/gpt-4o', $bot->resolvedUtilityModel());
    }

    public function test_resolved_utility_model_returns_null_when_no_model_configured(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => null,
            'fallback_chat_model' => null,
            'utility_model' => null,
        ]);

        $this->assertNull($bot->resolvedUtilityModel());
    }
}
