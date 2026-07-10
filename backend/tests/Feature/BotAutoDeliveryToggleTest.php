<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotAutoDeliveryToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_delivery_enabled_defaults_to_false(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($bot->fresh()->auto_delivery_enabled);
    }

    public function test_auto_delivery_enabled_is_fillable_and_cast_to_bool(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $bot->update(['auto_delivery_enabled' => 1]);

        $this->assertTrue($bot->fresh()->auto_delivery_enabled);
    }
}
