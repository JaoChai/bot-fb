<?php

namespace Tests\Feature\Console;

use App\Services\CommerceSafety\HoldOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UsesAnUnreadableCacheStore;
use Tests\TestCase;

class Bot26CommerceSafetyHoldCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesAnUnreadableCacheStore;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('bot26:commerce-safety-hold', Artisan::all());
    }

    #[Test]
    public function test_engage_activates_the_override_and_reads_back_hold(): void
    {
        config(['commerce_safety.bots.26.mode' => 'enforce']);

        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26', '--engage' => true])
            ->expectsOutputToContain('"resolved_mode":"hold"')
            ->assertSuccessful();

        $this->assertTrue(app(HoldOverride::class)->active(26));
    }

    #[Test]
    public function test_release_deactivates_the_override(): void
    {
        app(HoldOverride::class)->engage(26);

        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26', '--release' => true])
            ->expectsOutputToContain('"hold_override_active":false')
            ->assertSuccessful();

        $this->assertFalse(app(HoldOverride::class)->active(26));
    }

    #[Test]
    public function test_status_does_not_change_state(): void
    {
        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26', '--status' => true])
            ->expectsOutputToContain('"hold_override_active":false')
            ->assertSuccessful();

        $this->assertFalse(app(HoldOverride::class)->active(26));
    }

    /**
     * Regression: when the cache store is down, --status must not claim
     * hold_override_active:true (as if an operator deliberately engaged it) —
     * it reports the read as unreadable, while resolved_mode still shows the
     * fail-closed `hold` that SafetyScope::mode() actually enforces.
     */
    #[Test]
    public function test_status_does_not_report_engaged_when_the_cache_store_is_unreadable(): void
    {
        config(['commerce_safety.bots.26.mode' => 'enforce']);
        $this->useUnreadableCacheStore();

        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26', '--status' => true])
            ->expectsOutputToContain('"hold_override_readable":false,"hold_override_active":null,"resolved_mode":"hold"')
            ->assertSuccessful();
    }

    #[Test]
    public function test_requires_exactly_one_action(): void
    {
        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26'])
            ->expectsOutputToContain('exactly_one_action_required')
            ->assertFailed();

        $this->artisan('bot26:commerce-safety-hold', ['--bot' => '26', '--engage' => true, '--release' => true])
            ->expectsOutputToContain('exactly_one_action_required')
            ->assertFailed();
    }

    #[Test]
    public function test_rejects_an_invalid_bot_option(): void
    {
        $this->artisan('bot26:commerce-safety-hold', ['--bot' => 'nope', '--status' => true])
            ->expectsOutputToContain('bot_option_invalid')
            ->assertFailed();
    }
}
