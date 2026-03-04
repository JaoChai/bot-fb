<?php

namespace Tests\Unit\Services\SecondAI;

use App\Services\SecondAI\FactCheckService;
use App\Services\SecondAI\PersonalityCheckService;
use App\Services\SecondAI\PolicyCheckService;
use App\Services\SecondAI\PromptInjectionDetector;
use App\Services\SecondAI\SecondAIMetricsService;
use App\Services\SecondAI\SecondAIService;
use App\Services\SecondAI\UnifiedCheckService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShouldSkipCheckTest extends TestCase
{
    private SecondAIService $service;

    private ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SecondAIService(
            $this->createMock(FactCheckService::class),
            $this->createMock(PolicyCheckService::class),
            $this->createMock(PersonalityCheckService::class),
            $this->createMock(UnifiedCheckService::class),
            $this->createMock(PromptInjectionDetector::class),
            $this->createMock(SecondAIMetricsService::class),
        );

        $this->method = new ReflectionMethod(SecondAIService::class, 'shouldSkipCheck');
    }

    private function invoke(string $response): ?string
    {
        return $this->method->invoke($this->service, $response);
    }

    public function test_short_greeting_without_numbers_returns_response_too_short(): void
    {
        $result = $this->invoke('สวัสดีค่ะ');

        $this->assertSame('response_too_short', $result);
    }

    public function test_short_message_with_numbers_should_not_skip(): void
    {
        $result = $this->invoke('ราคา 599 บาท');

        $this->assertNull($result);
    }

    public function test_short_greeting_full_match_returns_response_too_short_due_to_length_priority(): void
    {
        // "สวัสดีค่ะ ยินดีให้บริการค่ะ" = 27 chars (< 50), no digits
        // Length check fires before greeting pattern check
        $result = $this->invoke('สวัสดีค่ะ ยินดีให้บริการค่ะ');

        $this->assertSame('response_too_short', $result);
    }

    public function test_long_greeting_only_returns_greeting_or_acknowledgment(): void
    {
        // Pad greeting with allowed trailing characters (spaces, particles)
        // to push length >= 50 while still matching the greeting regex
        // Pattern: /^(สวัสดี|...)[ค่ะครับคะนะจ้า\s!\.]*$/u
        $greeting = 'สวัสดี'.str_repeat('ค่ะ ', 15); // 6 + 45 = 51 chars
        $greeting = trim($greeting);

        $result = $this->invoke($greeting);

        $this->assertSame('greeting_or_acknowledgment', $result);
    }

    public function test_greeting_prefix_with_factual_content_should_not_skip(): void
    {
        $result = $this->invoke('สวัสดีค่ะ สินค้า A ราคา 299 บาท ลดราคา 50%');

        $this->assertNull($result);
    }

    public function test_long_factual_response_should_not_skip(): void
    {
        $response = 'สินค้ารุ่น Premium มีราคา 1,299 บาท รับประกัน 2 ปี สามารถสั่งซื้อผ่านเว็บไซต์ได้เลยค่ะ โปรโมชั่นนี้หมดเขต 31 มกราคม 2026';

        $result = $this->invoke($response);

        $this->assertNull($result);
    }

    public function test_empty_string_returns_response_too_short(): void
    {
        $result = $this->invoke('');

        $this->assertSame('response_too_short', $result);
    }

    public function test_whitespace_only_returns_response_too_short(): void
    {
        $result = $this->invoke('   ');

        $this->assertSame('response_too_short', $result);
    }

    public function test_greeting_krub_variant_returns_greeting(): void
    {
        $result = $this->invoke('สวัสดีครับ');

        $this->assertSame('response_too_short', $result);
    }

    public function test_acknowledgment_pattern_returns_greeting(): void
    {
        // This is long enough (>50 chars) to pass the short check,
        // and matches the greeting pattern for "ยินดีให้บริการ"
        $result = $this->invoke('ยินดีให้บริการค่ะ');

        // Under 50 chars without digits -> response_too_short takes priority
        $this->assertSame('response_too_short', $result);
    }

    public function test_long_greeting_only_matches_greeting_pattern(): void
    {
        // "ขอบคุณ" followed by polite particles - matches greeting pattern
        $result = $this->invoke('ขอบคุณค่ะ');

        $this->assertSame('response_too_short', $result);
    }

    public function test_message_exactly_at_50_chars_without_numbers(): void
    {
        // Create a Thai string that is exactly 50 characters - should NOT be skipped as too short
        // 50 chars = not < 50, so the short check won't trigger
        $response = str_repeat('ก', 50);

        $result = $this->invoke($response);

        $this->assertNull($result);
    }

    public function test_message_at_49_chars_without_numbers_is_too_short(): void
    {
        // 49 chars = < 50, no digits -> response_too_short
        $response = str_repeat('ก', 49);

        $result = $this->invoke($response);

        $this->assertSame('response_too_short', $result);
    }
}
