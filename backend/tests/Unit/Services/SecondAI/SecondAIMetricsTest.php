<?php

namespace Tests\Unit\Services\SecondAI;

use App\Services\SecondAI\SecondAICheckResult;
use PHPUnit\Framework\TestCase;

class SecondAIMetricsTest extends TestCase
{
    // -------------------------------------------------------
    // toLegacyFormat() tests
    // -------------------------------------------------------

    public function test_toLegacyFormat_second_ai_applied_is_true_when_not_passed(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true, 'rewritten' => 'Fixed'],
            ],
            finalResponse: 'Fixed response',
            metadata: ['model_used' => 'openai/gpt-4o-mini', 'latency_ms' => 150],
        );

        $legacy = $result->toLegacyFormat();

        $this->assertTrue($legacy['second_ai_applied']);
    }

    public function test_toLegacyFormat_second_ai_applied_is_false_when_passed(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [
                'fact_check' => ['required' => false],
                'policy' => ['required' => false],
            ],
            finalResponse: 'Original response',
            metadata: ['model_used' => 'openai/gpt-4o-mini', 'latency_ms' => 80],
        );

        $legacy = $result->toLegacyFormat();

        $this->assertFalse($legacy['second_ai_applied']);
    }

    public function test_toLegacyFormat_second_ai_applied_equals_not_passed(): void
    {
        // Verify the relationship: second_ai_applied === !$passed
        foreach ([true, false] as $passed) {
            $result = new SecondAICheckResult(
                passed: $passed,
                modifications: [],
                finalResponse: 'test',
                metadata: [],
            );

            $legacy = $result->toLegacyFormat();

            $this->assertSame(
                !$passed,
                $legacy['second_ai_applied'],
                "second_ai_applied should be " . (!$passed ? 'true' : 'false') . " when passed=$passed"
            );
        }
    }

    public function test_toLegacyFormat_includes_model_used_in_second_ai(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [],
            finalResponse: 'test',
            metadata: ['model_used' => 'openai/gpt-4o-mini', 'latency_ms' => 100],
        );

        $legacy = $result->toLegacyFormat();

        $this->assertArrayHasKey('model_used', $legacy['second_ai']);
        $this->assertSame('openai/gpt-4o-mini', $legacy['second_ai']['model_used']);
    }

    public function test_toLegacyFormat_model_used_is_null_when_not_in_metadata(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [],
            finalResponse: 'test',
            metadata: [],
        );

        $legacy = $result->toLegacyFormat();

        $this->assertArrayHasKey('model_used', $legacy['second_ai']);
        $this->assertNull($legacy['second_ai']['model_used']);
    }

    public function test_toLegacyFormat_checks_applied_includes_all_check_types(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true, 'rewritten' => 'Fixed'],
                'policy' => ['required' => false],
                'personality' => ['required' => true, 'tone' => 'adjusted'],
            ],
            finalResponse: 'Final response',
            metadata: ['model_used' => 'openai/gpt-4o-mini', 'latency_ms' => 200],
        );

        $legacy = $result->toLegacyFormat();

        // checks_applied uses getAllCheckTypes() which returns ALL keys, not just required:true
        $checksApplied = $legacy['second_ai']['checks_applied'];
        $this->assertCount(3, $checksApplied);
        $this->assertContains('fact_check', $checksApplied);
        $this->assertContains('policy', $checksApplied);
        $this->assertContains('personality', $checksApplied);
    }

    public function test_toLegacyFormat_checks_applied_includes_non_required_checks(): void
    {
        // Even checks with required:false should appear in checks_applied
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [
                'fact_check' => ['required' => false],
                'policy' => ['required' => false],
            ],
            finalResponse: 'Unchanged response',
            metadata: [],
        );

        $legacy = $result->toLegacyFormat();

        $checksApplied = $legacy['second_ai']['checks_applied'];
        $this->assertCount(2, $checksApplied);
        $this->assertContains('fact_check', $checksApplied);
        $this->assertContains('policy', $checksApplied);
    }

    // -------------------------------------------------------
    // getAllCheckTypes() tests
    // -------------------------------------------------------

    public function test_getAllCheckTypes_returns_all_keys_regardless_of_required(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [
                'fact_check' => ['required' => false],
                'policy' => ['required' => true],
                'personality' => ['required' => false],
            ],
            finalResponse: 'test',
        );

        $allTypes = $result->getAllCheckTypes();

        $this->assertCount(3, $allTypes);
        $this->assertContains('fact_check', $allTypes);
        $this->assertContains('policy', $allTypes);
        $this->assertContains('personality', $allTypes);
    }

    public function test_getAllCheckTypes_differs_from_getAppliedChecks(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true],
                'policy' => ['required' => false],
                'personality' => ['required' => true],
            ],
            finalResponse: 'test',
        );

        $allTypes = $result->getAllCheckTypes();
        $appliedChecks = $result->getAppliedChecks();

        // getAllCheckTypes returns all 3 keys
        $this->assertCount(3, $allTypes);
        // getAppliedChecks returns only required:true (2 items)
        $this->assertCount(2, $appliedChecks);

        // policy is in allTypes but not in appliedChecks
        $this->assertContains('policy', $allTypes);
        $this->assertNotContains('policy', $appliedChecks);
    }

    public function test_getAllCheckTypes_returns_empty_when_no_modifications(): void
    {
        $result = new SecondAICheckResult(
            passed: true,
            modifications: [],
            finalResponse: 'test',
        );

        $this->assertEmpty($result->getAllCheckTypes());
    }

    // -------------------------------------------------------
    // toLegacyFormat() structure completeness
    // -------------------------------------------------------

    public function test_toLegacyFormat_has_complete_structure(): void
    {
        $result = new SecondAICheckResult(
            passed: false,
            modifications: [
                'fact_check' => ['required' => true, 'rewritten' => 'Fixed text'],
            ],
            finalResponse: 'Fixed text',
            metadata: ['model_used' => 'openai/gpt-4o-mini', 'latency_ms' => 250],
        );

        $legacy = $result->toLegacyFormat();

        // Top-level keys
        $this->assertArrayHasKey('content', $legacy);
        $this->assertArrayHasKey('second_ai_applied', $legacy);
        $this->assertArrayHasKey('second_ai', $legacy);

        // second_ai sub-keys
        $this->assertArrayHasKey('checks_applied', $legacy['second_ai']);
        $this->assertArrayHasKey('modifications', $legacy['second_ai']);
        $this->assertArrayHasKey('elapsed_ms', $legacy['second_ai']);
        $this->assertArrayHasKey('model_used', $legacy['second_ai']);

        // Value assertions
        $this->assertSame('Fixed text', $legacy['content']);
        $this->assertSame(250, $legacy['second_ai']['elapsed_ms']);
        $this->assertSame('openai/gpt-4o-mini', $legacy['second_ai']['model_used']);
        $this->assertSame(
            ['fact_check' => ['required' => true, 'rewritten' => 'Fixed text']],
            $legacy['second_ai']['modifications']
        );
    }
}
