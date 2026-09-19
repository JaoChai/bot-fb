<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSettingsOpenRouterRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_no_longer_mentions_openrouter(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('openrouter', $response->getContent());
    }
}
