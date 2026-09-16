<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Services\CommerceSafety\HoldOverride;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HoldOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function test_inactive_by_default(): void
    {
        $this->assertFalse(app(HoldOverride::class)->active(26));
    }

    #[Test]
    public function test_engage_then_active_then_release_then_inactive(): void
    {
        $override = app(HoldOverride::class);

        $override->engage(26);
        $this->assertTrue($override->active(26));

        $override->release(26);
        $this->assertFalse($override->active(26));
    }

    #[Test]
    public function test_scoped_per_bot(): void
    {
        $override = app(HoldOverride::class);

        $override->engage(26);

        $this->assertTrue($override->active(26));
        $this->assertFalse($override->active(27));
    }
}
