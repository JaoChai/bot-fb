<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\CommerceSafetyShadowObservation;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\CommerceSafety\ShadowObservationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowObservationRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function recorderWithMode(string $mode): ShadowObservationRecorder
    {
        $scope = Mockery::mock(SafetyScope::class);
        $scope->shouldReceive('mode')->andReturn($mode);

        return new ShadowObservationRecorder($scope);
    }

    #[Test]
    public function test_records_nothing_off_enforce_hold(): void
    {
        $bot = (new Bot)->forceFill(['id' => 26]);

        foreach (['off', 'enforce', 'hold'] as $mode) {
            $recorder = $this->recorderWithMode($mode);
            $recorder->record($bot, 'cart_proposal', 'valid');
            $recorder->recordEach($bot, 'cart_proposal', 'invalid', ['PRICE_MISMATCH', 'OUT_OF_STOCK']);
        }

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 0);
    }

    #[Test]
    public function test_records_one_row_per_call_in_shadow_mode(): void
    {
        $bot = (new Bot)->forceFill(['id' => 26]);
        $this->recorderWithMode('shadow')->record($bot, 'cart_proposal', 'valid');

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 1);
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => 26, 'category' => 'cart_proposal', 'outcome' => 'valid', 'reason' => null,
        ]);
    }

    #[Test]
    public function test_record_each_writes_one_row_per_reason_and_bot_is_scoped(): void
    {
        $recorder = $this->recorderWithMode('shadow');

        $recorder->recordEach((new Bot)->forceFill(['id' => 26]), 'cart_proposal', 'invalid', ['PRICE_MISMATCH', 'OUT_OF_STOCK']);
        $recorder->recordEach((new Bot)->forceFill(['id' => 27]), 'cart_proposal', 'invalid', ['PRICE_MISMATCH']);

        $this->assertSame(2, CommerceSafetyShadowObservation::where('bot_id', 26)->count());
        $this->assertSame(1, CommerceSafetyShadowObservation::where('bot_id', 27)->count());
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => 26, 'category' => 'cart_proposal', 'outcome' => 'invalid', 'reason' => 'PRICE_MISMATCH',
        ]);
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => 26, 'category' => 'cart_proposal', 'outcome' => 'invalid', 'reason' => 'OUT_OF_STOCK',
        ]);
    }

    #[Test]
    public function test_record_each_with_no_reasons_writes_a_single_reasonless_row(): void
    {
        $bot = (new Bot)->forceFill(['id' => 26]);
        $this->recorderWithMode('shadow')->recordEach($bot, 'cart_proposal', 'valid', []);

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 1);
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => 26, 'category' => 'cart_proposal', 'outcome' => 'valid', 'reason' => null,
        ]);
    }
}
