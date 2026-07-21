<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuietHoursApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_defaults_when_no_settings_row(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_enabled', true)
            ->assertJsonPath('data.quiet_hours_start', '23:00')
            ->assertJsonPath('data.quiet_hours_end', '08:00');
    }

    public function test_update_and_show_quiet_hours(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '22:00', 'end' => '09:00',
        ])->assertOk();

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id, 'quiet_hours_enabled' => true,
        ]);

        $this->actingAs($user)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '22:00')
            ->assertJsonPath('data.quiet_hours_end', '09:00');
    }

    public function test_invalid_time_format_rejected(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '25:99', 'end' => '08:00',
        ])->assertStatus(422);
    }

    public function test_same_start_end_rejected(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '08:00', 'end' => '08:00',
        ])->assertStatus(422);
    }
}
