<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSettingsOpenRouterColumnsDroppedTest extends TestCase
{
    use RefreshDatabase;

    public function test_openrouter_columns_are_dropped_from_user_settings(): void
    {
        $this->assertFalse(Schema::hasColumn('user_settings', 'openrouter_api_key'));
        $this->assertFalse(Schema::hasColumn('user_settings', 'openrouter_model'));
    }

    public function test_the_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_20_000001_drop_openrouter_columns_from_user_settings_table.php');

        $migration->down();
        $this->assertTrue(Schema::hasColumn('user_settings', 'openrouter_api_key'));
        $this->assertTrue(Schema::hasColumn('user_settings', 'openrouter_model'));

        $migration->up();
        $this->assertFalse(Schema::hasColumn('user_settings', 'openrouter_api_key'));
        $this->assertFalse(Schema::hasColumn('user_settings', 'openrouter_model'));
    }

    public function test_getting_or_creating_settings_still_works(): void
    {
        $settings = User::factory()->create()->getOrCreateSettings();

        $keys = array_keys($settings->toArray());
        $matches = array_filter($keys, fn ($key) => str_contains($key, 'openrouter'));

        $this->assertSame([], array_values($matches));
    }
}
