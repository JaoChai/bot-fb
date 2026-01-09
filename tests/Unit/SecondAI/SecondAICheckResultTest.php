<?php

namespace Tests\Unit\SecondAI;

use App\Services\SecondAI\SecondAICheckResult;
use PHPUnit\Framework\TestCase;

class SecondAICheckResultTest extends TestCase
{
    public function test_creates_from_json(): void
    {
        $json = [
            'passed' => false,
            'modifications' => [
                'fact_check' => ['required' => true, 'rewritten' => 'Fixed response'],
                'policy' => ['required' => false],
            ],
            'final_response' => 'Final response',
            'model_used' => 'claude-3.5-sonnet',
            'latency_ms' => 1234,
        ];

        $result = SecondAICheckResult::fromJson($json);

        $this->assertFalse($result->passed);
        $this->assertEquals('Final response', $result->finalResponse);
        $this->assertEquals('claude-3.5-sonnet', $result->metadata['model_used']);
        $this->assertEquals(1234, $result->metadata['latency_ms']);
    }

    public function test_detects_applied_checks(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true],
                'policy' => ['required' => false],
                'personality' => ['required' => true],
            ],
            finalResponse: 'Final response',
        );

        $this->assertTrue($result->wasApplied('fact_check'));
        $this->assertFalse($result->wasApplied('policy'));
        $this->assertTrue($result->wasApplied('personality'));

        $appliedChecks = $result->getAppliedChecks();
        $this->assertCount(2, $appliedChecks);
        $this->assertContains('fact_check', $appliedChecks);
        $this->assertContains('personality', $appliedChecks);
    }

    public function test_converts_to_legacy_format(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true],
            ],
            finalResponse: 'Final response',
            metadata: ['latency_ms' => 1234],
        );

        $legacy = $result->toLegacyFormat();

        $this->assertEquals('Final response', $legacy['content']);
        $this->assertTrue($legacy['second_ai_applied']);
        $this->assertEquals(['fact_check'], $legacy['second_ai']['checks_applied']);
        $this->assertEquals(1234, $legacy['second_ai']['elapsed_ms']);
    }

    public function test_handles_passing_result(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [
                'fact_check' => ['required' => false],
                'policy' => ['required' => false],
            ],
            finalResponse: 'Original response',
        );

        $this->assertTrue($result->passed);
        $this->assertEmpty($result->getAppliedChecks());

        $legacy = $result->toLegacyFormat();
        $this->assertFalse($legacy['second_ai_applied']);
    }
}
