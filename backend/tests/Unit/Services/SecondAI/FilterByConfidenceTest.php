<?php

namespace Tests\Unit\Services\SecondAI;

use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\UnifiedCheckService;
use ReflectionMethod;
use Tests\TestCase;

class FilterByConfidenceTest extends TestCase
{
    private UnifiedCheckService $service;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $openRouter = $this->createMock(OpenRouterService::class);
        $ragService = $this->createMock(RAGService::class);

        $this->service = new UnifiedCheckService($openRouter, $ragService);

        $this->method = new ReflectionMethod(UnifiedCheckService::class, 'filterByConfidence');
    }

    public function test_high_confidence_required_check_remains_required(): void
    {
        $modifications = [
            'fact_check' => [
                'required' => true,
                'confidence' => 0.9,
                'rewritten' => 'Some corrected text',
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        $this->assertTrue($result['fact_check']['required']);
        $this->assertEquals('Some corrected text', $result['fact_check']['rewritten']);
    }

    public function test_low_confidence_required_check_is_demoted(): void
    {
        $modifications = [
            'fact_check' => [
                'required' => true,
                'confidence' => 0.5,
                'rewritten' => 'Some corrected text',
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        $this->assertFalse($result['fact_check']['required']);
        $this->assertNull($result['fact_check']['rewritten']);
    }

    public function test_exactly_threshold_confidence_remains_required(): void
    {
        $modifications = [
            'policy' => [
                'required' => true,
                'confidence' => 0.7,
                'rewritten' => 'Policy-compliant text',
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        $this->assertTrue($result['policy']['required']);
        $this->assertEquals('Policy-compliant text', $result['policy']['rewritten']);
    }

    public function test_missing_confidence_defaults_to_one_not_filtered(): void
    {
        $modifications = [
            'personality' => [
                'required' => true,
                'rewritten' => 'Brand voice text',
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        $this->assertTrue($result['personality']['required']);
        $this->assertEquals('Brand voice text', $result['personality']['rewritten']);
    }

    public function test_non_required_check_with_low_confidence_remains_unchanged(): void
    {
        $modifications = [
            'fact_check' => [
                'required' => false,
                'confidence' => 0.3,
                'rewritten' => null,
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        $this->assertFalse($result['fact_check']['required']);
        $this->assertNull($result['fact_check']['rewritten']);
    }

    public function test_multiple_checks_mixed_confidence_only_demotes_low(): void
    {
        $modifications = [
            'fact_check' => [
                'required' => true,
                'confidence' => 0.9,
                'rewritten' => 'Fact-checked text',
            ],
            'policy' => [
                'required' => true,
                'confidence' => 0.4,
                'rewritten' => 'Policy text',
            ],
            'personality' => [
                'required' => true,
                'confidence' => 0.7,
                'rewritten' => 'Personality text',
            ],
        ];

        $result = $this->method->invoke($this->service, $modifications);

        // High confidence: remains required
        $this->assertTrue($result['fact_check']['required']);
        $this->assertEquals('Fact-checked text', $result['fact_check']['rewritten']);

        // Low confidence: demoted
        $this->assertFalse($result['policy']['required']);
        $this->assertNull($result['policy']['rewritten']);

        // Exactly threshold: remains required
        $this->assertTrue($result['personality']['required']);
        $this->assertEquals('Personality text', $result['personality']['rewritten']);
    }
}
