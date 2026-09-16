<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\CommerceSafety\HoldOverride;
use App\Services\CommerceSafety\SafetyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the hold-propagation fix: SafetyScope::mode() checks the shared
 * runtime HoldOverride before config, so an already-resolved SafetyScope
 * instance (standing in for a long-lived worker that resolved its
 * dependencies while the mode was `enforce`) sees `hold` on its very next
 * call — with config left untouched, simulating a config-cache'd/boot-frozen
 * environment where the env var alone cannot reach a running process.
 */
class SafetyScopeHoldOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function trustedBot(string $mode): Bot
    {
        $bot = Bot::factory()->active()->create(['user_id' => User::factory()->owner()->create()->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'order', 'name' => 'fixture',
            'enabled' => true, 'trigger_condition' => 'always', 'config' => [],
        ]);
        config(["commerce_safety.bots.{$bot->id}" => ['mode' => $mode, 'payment_plugin_ids' => [$plugin->id]]]);

        return $bot;
    }

    #[Test]
    public function test_override_forces_hold_regardless_of_configured_mode(): void
    {
        $bot = $this->trustedBot('enforce');
        app(HoldOverride::class)->engage($bot->id);

        foreach (['off', 'shadow', 'enforce', 'hold', 'nonsense'] as $configuredMode) {
            config(["commerce_safety.bots.{$bot->id}.mode" => $configuredMode]);
            $this->assertSame('hold', app(SafetyScope::class)->mode($bot), $configuredMode);
        }
    }

    #[Test]
    public function test_a_resolved_instance_from_before_the_hold_still_sees_it_without_any_config_change(): void
    {
        $bot = $this->trustedBot('enforce');

        // Simulate a long-lived worker: resolve SafetyScope once, before the hold.
        $scope = app(SafetyScope::class);
        $this->assertSame('enforce', $scope->mode($bot));

        // Engage containment. Config is deliberately never touched here — this is the
        // exact scenario a config-cache'd process cannot otherwise escape without a restart.
        app(HoldOverride::class)->engage($bot->id);

        $this->assertSame('hold', $scope->mode($bot));
        $this->assertSame('enforce', config("commerce_safety.bots.{$bot->id}.mode"), 'config itself never changed');
    }

    #[Test]
    public function test_release_restores_config_resolved_mode(): void
    {
        $bot = $this->trustedBot('off');
        $override = app(HoldOverride::class);

        $override->engage($bot->id);
        $this->assertSame('hold', app(SafetyScope::class)->mode($bot));

        $override->release($bot->id);
        $this->assertSame('off', app(SafetyScope::class)->mode($bot));
    }

    #[Test]
    public function test_override_is_scoped_per_bot(): void
    {
        $held = $this->trustedBot('enforce');
        $other = $this->trustedBot('off');

        app(HoldOverride::class)->engage($held->id);

        $this->assertSame('hold', app(SafetyScope::class)->mode($held));
        $this->assertSame('off', app(SafetyScope::class)->mode($other));
    }
}
