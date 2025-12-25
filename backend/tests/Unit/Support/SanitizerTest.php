<?php

namespace Tests\Unit\Support;

use App\Support\Sanitizer;
use PHPUnit\Framework\TestCase;

class SanitizerTest extends TestCase
{
    public function test_clean_removes_script_tags(): void
    {
        $input = 'Hello <script>alert("XSS")</script> World';
        $result = Sanitizer::clean($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('World', $result);
    }

    public function test_clean_removes_event_handlers(): void
    {
        $input = '<img src="x" onerror="alert(1)">';
        $result = Sanitizer::clean($input);

        $this->assertStringNotContainsString('onerror', $result);
    }

    public function test_clean_removes_javascript_protocol(): void
    {
        $input = '<a href="javascript:alert(1)">Click</a>';
        $result = Sanitizer::clean($input);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_clean_removes_dangerous_tags(): void
    {
        $input = '<iframe src="evil.com"></iframe><embed src="x"><object data="y">';
        $result = Sanitizer::clean($input);

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<embed', $result);
        $this->assertStringNotContainsString('<object', $result);
    }

    public function test_plain_text_strips_all_html(): void
    {
        $input = '<p>Hello <strong>World</strong></p>';
        $result = Sanitizer::plainText($input);

        $this->assertEquals('Hello World', $result);
    }

    public function test_message_truncates_to_max_length(): void
    {
        $input = str_repeat('a', 15000);
        $result = Sanitizer::message($input, 10000);

        $this->assertEquals(10000, mb_strlen($result));
    }

    public function test_message_normalizes_whitespace(): void
    {
        $input = "Hello    World\n\n\n\nTest";
        $result = Sanitizer::message($input);

        $this->assertEquals("Hello World\n\nTest", $result);
    }

    public function test_email_sanitizes_correctly(): void
    {
        $valid = 'user@example.com';
        $invalid = 'user<script>@example.com';

        $this->assertEquals('user@example.com', Sanitizer::email($valid));
        $this->assertStringNotContainsString('<script>', Sanitizer::email($invalid));
    }

    public function test_url_only_allows_http_https(): void
    {
        $http = 'http://example.com';
        $https = 'https://example.com';
        $javascript = 'javascript:alert(1)';
        $ftp = 'ftp://example.com';

        $this->assertEquals('http://example.com', Sanitizer::url($http));
        $this->assertEquals('https://example.com', Sanitizer::url($https));
        $this->assertEquals('', Sanitizer::url($javascript));
        $this->assertEquals('', Sanitizer::url($ftp));
    }

    public function test_filename_removes_directory_traversal(): void
    {
        $input = '../../../etc/passwd';
        $result = Sanitizer::filename($input);

        $this->assertStringNotContainsString('..', $result);
        $this->assertEquals('passwd', $result);
    }

    public function test_filename_removes_dangerous_characters(): void
    {
        $input = 'file<script>.txt';
        $result = Sanitizer::filename($input);

        $this->assertStringNotContainsString('<', $result);
        $this->assertStringNotContainsString('>', $result);
    }

    public function test_numeric_returns_integer_or_null(): void
    {
        $this->assertEquals(123, Sanitizer::numeric('123'));
        $this->assertEquals(456, Sanitizer::numeric(456));
        $this->assertNull(Sanitizer::numeric('abc'));
        $this->assertNull(Sanitizer::numeric(''));
    }

    public function test_boolean_converts_correctly(): void
    {
        $this->assertTrue(Sanitizer::boolean('true'));
        $this->assertTrue(Sanitizer::boolean('1'));
        $this->assertTrue(Sanitizer::boolean(true));
        $this->assertFalse(Sanitizer::boolean('false'));
        $this->assertFalse(Sanitizer::boolean('0'));
        $this->assertFalse(Sanitizer::boolean(false));
    }

    public function test_has_xss_detects_dangerous_patterns(): void
    {
        $this->assertTrue(Sanitizer::hasXss('<script>alert(1)</script>'));
        $this->assertTrue(Sanitizer::hasXss('javascript:alert(1)'));
        $this->assertTrue(Sanitizer::hasXss('<img onerror="alert(1)">'));
        $this->assertTrue(Sanitizer::hasXss('<iframe src="x">'));

        $this->assertFalse(Sanitizer::hasXss('Hello World'));
        $this->assertFalse(Sanitizer::hasXss('Normal text with numbers 123'));
    }

    public function test_mask_hides_sensitive_data(): void
    {
        $input = 'sk-1234567890abcdef';
        $result = Sanitizer::mask($input);

        $this->assertStringStartsWith('sk-1', $result);
        $this->assertStringEndsWith('cdef', $result);
        $this->assertStringContainsString('****', $result);
    }

    public function test_for_log_removes_newlines_and_control_chars(): void
    {
        $input = "Line1\nLine2\rLine3\tTab";
        $result = Sanitizer::forLog($input);

        $this->assertStringNotContainsString("\n", $result);
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringNotContainsString("\t", $result);
    }

    public function test_for_log_truncates_long_strings(): void
    {
        $input = str_repeat('a', 2000);
        $result = Sanitizer::forLog($input);

        $this->assertStringContainsString('...[truncated]', $result);
        $this->assertLessThanOrEqual(1015, strlen($result)); // 1000 + '...[truncated]'
    }

    public function test_array_sanitizes_recursively(): void
    {
        $input = [
            'name' => '<script>alert(1)</script>',
            'nested' => [
                'value' => '<iframe src="x"></iframe>',
            ],
        ];

        $result = Sanitizer::array($input);

        $this->assertStringNotContainsString('<script>', $result['name']);
        $this->assertStringNotContainsString('<iframe', $result['nested']['value']);
    }

    public function test_clean_handles_null_bytes(): void
    {
        $input = "Hello\0World";
        $result = Sanitizer::clean($input);

        $this->assertStringNotContainsString("\0", $result);
        $this->assertEquals('HelloWorld', $result);
    }
}
