<?php

namespace Tests\Unit\Services\SecondAI;

use App\Services\OpenRouterService;
use App\Services\RAGService;
use App\Services\SecondAI\UnifiedCheckService;
use ReflectionMethod;
use Tests\TestCase;

class ParseResponseTest extends TestCase
{
    private UnifiedCheckService $service;

    private ReflectionMethod $parseResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $openRouter = $this->createMock(OpenRouterService::class);
        $ragService = $this->createMock(RAGService::class);

        $this->service = new UnifiedCheckService($openRouter, $ragService);

        $this->parseResponse = new ReflectionMethod(UnifiedCheckService::class, 'parseResponse');
    }

    /** @test */
    public function it_parses_clean_json(): void
    {
        $input = json_encode([
            'passed' => true,
            'modifications' => [],
            'final_response' => 'Hello world',
        ]);

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['modifications']);
        $this->assertSame('Hello world', $result['final_response']);
    }

    /** @test */
    public function it_parses_json_wrapped_in_markdown_code_block(): void
    {
        $input = "```json\n".json_encode([
            'passed' => false,
            'modifications' => [
                'fact_check' => [
                    'required' => true,
                    'confidence' => 0.9,
                    'claims_extracted' => ['price is 100'],
                    'unverified_claims' => ['price is 100'],
                    'rewritten' => 'Please contact support for pricing.',
                ],
            ],
            'final_response' => 'Please contact support for pricing.',
        ], JSON_PRETTY_PRINT)."\n```";

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['modifications']['fact_check']['required']);
        $this->assertSame('Please contact support for pricing.', $result['final_response']);
    }

    /** @test */
    public function it_extracts_json_with_preamble_text(): void
    {
        $input = "Here is my analysis of the response:\n\n".json_encode([
            'passed' => true,
            'modifications' => [],
            'final_response' => 'All checks passed.',
        ]);

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertTrue($result['passed']);
        $this->assertSame('All checks passed.', $result['final_response']);
    }

    /** @test */
    public function it_extracts_json_with_trailing_text(): void
    {
        $input = json_encode([
            'passed' => false,
            'modifications' => [
                'policy' => [
                    'required' => true,
                    'confidence' => 0.85,
                    'violations' => ['inappropriate language'],
                    'rewritten' => 'Cleaned response.',
                ],
            ],
            'final_response' => 'Cleaned response.',
        ])."\n\nI hope this analysis helps!";

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertFalse($result['passed']);
        $this->assertSame('Cleaned response.', $result['final_response']);
    }

    /** @test */
    public function it_infers_passed_from_modifications_when_field_missing(): void
    {
        // No 'passed' field; has a required modification -> should infer false
        $input = json_encode([
            'modifications' => [
                'fact_check' => [
                    'required' => true,
                    'confidence' => 0.9,
                    'claims_extracted' => ['claim1'],
                    'unverified_claims' => ['claim1'],
                    'rewritten' => 'Corrected text.',
                ],
            ],
            'final_response' => 'Corrected text.',
        ]);

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertFalse($result['passed']);

        // No 'passed' field; no required modifications -> should infer true
        $inputPassing = json_encode([
            'modifications' => [
                'fact_check' => [
                    'required' => false,
                    'confidence' => 0.9,
                    'claims_extracted' => [],
                    'unverified_claims' => [],
                    'rewritten' => null,
                ],
            ],
            'final_response' => 'Original response.',
        ]);

        $resultPassing = $this->parseResponse->invoke($this->service, $inputPassing);

        $this->assertTrue($resultPassing['passed']);
    }

    /** @test */
    public function it_uses_last_rewritten_as_fallback_when_final_response_missing(): void
    {
        $input = json_encode([
            'passed' => false,
            'modifications' => [
                'fact_check' => [
                    'required' => true,
                    'confidence' => 0.9,
                    'claims_extracted' => ['claim'],
                    'unverified_claims' => ['claim'],
                    'rewritten' => 'First rewrite.',
                ],
                'personality' => [
                    'required' => true,
                    'confidence' => 0.8,
                    'issues' => ['wrong tone'],
                    'rewritten' => 'Last rewrite from personality.',
                ],
            ],
        ]);

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertSame('Last rewrite from personality.', $result['final_response']);
    }

    /** @test */
    public function it_defaults_modifications_to_empty_array_when_missing(): void
    {
        $input = json_encode([
            'passed' => true,
            'final_response' => 'All good.',
        ]);

        $result = $this->parseResponse->invoke($this->service, $input);

        $this->assertSame([], $result['modifications']);
        $this->assertSame('All good.', $result['final_response']);
    }

    /** @test */
    public function it_throws_runtime_exception_for_completely_invalid_text(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response from unified check');

        $this->parseResponse->invoke($this->service, 'This is not JSON at all, just plain text.');
    }

    /** @test */
    public function it_throws_runtime_exception_for_empty_string(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response from unified check');

        $this->parseResponse->invoke($this->service, '');
    }
}
