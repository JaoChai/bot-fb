<?php
// backend/tests/Unit/Services/ResolveReasoningEffortTest.php
namespace Tests\Unit\Services;

use App\Services\RAGService;
use Tests\TestCase;

class ResolveReasoningEffortTest extends TestCase
{
    private function resolve(string $botEffort, bool $isComplex): string
    {
        $svc = app(RAGService::class);
        $m = new \ReflectionMethod($svc, 'resolveReasoningEffort');
        $m->setAccessible(true);

        return $m->invoke($svc, $botEffort, $isComplex);
    }

    public function test_complex_message_uses_full_bot_effort(): void
    {
        $this->assertSame('high', $this->resolve('high', true));
        $this->assertSame('low', $this->resolve('low', true));
    }

    public function test_simple_message_caps_high_at_medium(): void
    {
        $this->assertSame('medium', $this->resolve('high', false));
    }

    public function test_simple_message_keeps_low_and_medium(): void
    {
        $this->assertSame('low', $this->resolve('low', false));
        $this->assertSame('medium', $this->resolve('medium', false));
    }
}
