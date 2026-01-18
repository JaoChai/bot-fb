<?php

namespace Tests\Unit\Helpers;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConfigHelperTest extends TestCase
{
    // ========================================
    // config_string tests
    // ========================================

    public function test_config_string_returns_string_value(): void
    {
        Config::set('test.string_key', 'test-value');

        $result = config_string('test.string_key');

        $this->assertEquals('test-value', $result);
        $this->assertIsString($result);
    }

    public function test_config_string_returns_default_when_null(): void
    {
        Config::set('test.null_key', null);

        $result = config_string('test.null_key', 'default-value');

        $this->assertEquals('default-value', $result);
    }

    public function test_config_string_returns_default_when_key_missing(): void
    {
        $result = config_string('test.nonexistent_key', 'fallback');

        $this->assertEquals('fallback', $result);
    }

    public function test_config_string_casts_integer_to_string(): void
    {
        Config::set('test.int_key', 42);

        $result = config_string('test.int_key');

        $this->assertEquals('42', $result);
        $this->assertIsString($result);
    }

    public function test_config_string_empty_default(): void
    {
        $result = config_string('test.nonexistent_key');

        $this->assertEquals('', $result);
    }

    // ========================================
    // config_int tests
    // ========================================

    public function test_config_int_returns_integer_value(): void
    {
        Config::set('test.int_key', 100);

        $result = config_int('test.int_key');

        $this->assertEquals(100, $result);
        $this->assertIsInt($result);
    }

    public function test_config_int_returns_default_when_null(): void
    {
        Config::set('test.null_key', null);

        $result = config_int('test.null_key', 50);

        $this->assertEquals(50, $result);
    }

    public function test_config_int_returns_default_when_key_missing(): void
    {
        $result = config_int('test.nonexistent_key', 999);

        $this->assertEquals(999, $result);
    }

    public function test_config_int_casts_string_to_integer(): void
    {
        Config::set('test.string_int', '123');

        $result = config_int('test.string_int');

        $this->assertEquals(123, $result);
        $this->assertIsInt($result);
    }

    public function test_config_int_zero_default(): void
    {
        $result = config_int('test.nonexistent_key');

        $this->assertEquals(0, $result);
    }

    // ========================================
    // config_float tests
    // ========================================

    public function test_config_float_returns_float_value(): void
    {
        Config::set('test.float_key', 3.14);

        $result = config_float('test.float_key');

        $this->assertEquals(3.14, $result);
        $this->assertIsFloat($result);
    }

    public function test_config_float_returns_default_when_null(): void
    {
        Config::set('test.null_key', null);

        $result = config_float('test.null_key', 0.5);

        $this->assertEquals(0.5, $result);
    }

    public function test_config_float_returns_default_when_key_missing(): void
    {
        $result = config_float('test.nonexistent_key', 2.5);

        $this->assertEquals(2.5, $result);
    }

    public function test_config_float_casts_string_to_float(): void
    {
        Config::set('test.string_float', '0.75');

        $result = config_float('test.string_float');

        $this->assertEquals(0.75, $result);
        $this->assertIsFloat($result);
    }

    public function test_config_float_zero_default(): void
    {
        $result = config_float('test.nonexistent_key');

        $this->assertEquals(0.0, $result);
    }

    // ========================================
    // config_bool tests
    // ========================================

    public function test_config_bool_returns_true(): void
    {
        Config::set('test.bool_key', true);

        $result = config_bool('test.bool_key');

        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    public function test_config_bool_returns_false(): void
    {
        Config::set('test.bool_key', false);

        $result = config_bool('test.bool_key');

        $this->assertFalse($result);
    }

    public function test_config_bool_returns_default_when_null(): void
    {
        Config::set('test.null_key', null);

        $result = config_bool('test.null_key', true);

        $this->assertTrue($result);
    }

    public function test_config_bool_returns_default_when_key_missing(): void
    {
        $result = config_bool('test.nonexistent_key', true);

        $this->assertTrue($result);
    }

    public function test_config_bool_casts_truthy_value(): void
    {
        Config::set('test.truthy_key', 1);

        $result = config_bool('test.truthy_key');

        $this->assertTrue($result);
    }

    public function test_config_bool_casts_falsy_value(): void
    {
        Config::set('test.falsy_key', 0);

        $result = config_bool('test.falsy_key');

        $this->assertFalse($result);
    }

    public function test_config_bool_false_default(): void
    {
        $result = config_bool('test.nonexistent_key');

        $this->assertFalse($result);
    }

    // ========================================
    // config_array tests
    // ========================================

    public function test_config_array_returns_array_value(): void
    {
        Config::set('test.array_key', ['a', 'b', 'c']);

        $result = config_array('test.array_key');

        $this->assertEquals(['a', 'b', 'c'], $result);
        $this->assertIsArray($result);
    }

    public function test_config_array_returns_default_when_null(): void
    {
        Config::set('test.null_key', null);

        $result = config_array('test.null_key', ['default']);

        $this->assertEquals(['default'], $result);
    }

    public function test_config_array_returns_default_when_key_missing(): void
    {
        $result = config_array('test.nonexistent_key', ['fallback']);

        $this->assertEquals(['fallback'], $result);
    }

    public function test_config_array_returns_default_when_not_array(): void
    {
        Config::set('test.string_key', 'not-an-array');

        $result = config_array('test.string_key', ['default']);

        $this->assertEquals(['default'], $result);
    }

    public function test_config_array_empty_default(): void
    {
        $result = config_array('test.nonexistent_key');

        $this->assertEquals([], $result);
    }

    public function test_config_array_with_associative_array(): void
    {
        Config::set('test.assoc_key', ['name' => 'value', 'count' => 5]);

        $result = config_array('test.assoc_key');

        $this->assertEquals(['name' => 'value', 'count' => 5], $result);
    }
}
