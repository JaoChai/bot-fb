<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Services\CommerceSafety\CartProposalAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CartProposalAdapterTest extends TestCase
{
    private CartProposalAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = app(CartProposalAdapter::class);
    }

    #[Test]
    public function it_reads_explicit_original_numbers_from_visible_order_text(): void
    {
        $proposal = $this->adapter->fromText(
            "สรุปรายการสั่งซื้อ\n"
            ."1. Page (199 บาท x 2) = 398 บาท\n"
            ."2. G3D (50 x 3) = 150 บาท\n"
            .'รวมยอดโอน: 548 บาท'
        );

        $this->assertSame([
            'lines' => [
                ['name' => 'Page', 'qty' => 2, 'price_minor' => 19900],
                ['name' => 'G3D', 'qty' => 3, 'price_minor' => 5000],
            ],
            'total_minor' => 54800,
        ], $proposal);
    }

    #[Test]
    public function it_reads_explicit_original_json_types_without_extractor_coercion(): void
    {
        $proposal = $this->adapter->fromOrderJson(
            '{"items":[{"name":"Page","qty":2,"price":199},{"name":"G3D","qty":3,"price":"50.00"}],"total":"548.00"}'
        );

        $this->assertSame([
            'lines' => [
                ['name' => 'Page', 'qty' => 2, 'price_minor' => 19900],
                ['name' => 'G3D', 'qty' => 3, 'price_minor' => 5000],
            ],
            'total_minor' => 54800,
        ], $proposal);
    }

    #[Test]
    public function known_json_fields_are_accepted_in_any_object_key_order(): void
    {
        $proposal = $this->adapter->fromOrderJson(
            '{"total":199,"items":[{"price":199,"name":"Page","qty":1}]}'
        );

        $this->assertSame([
            'lines' => [['name' => 'Page', 'qty' => 1, 'price_minor' => 19900]],
            'total_minor' => 19900,
        ], $proposal);
    }

    #[Test]
    #[DataProvider('invalidQuantityProvider')]
    public function it_rejects_non_positive_or_non_integer_original_json_quantities(string $qty): void
    {
        $this->assertNull($this->adapter->fromOrderJson(
            '{"items":[{"name":"Page","qty":'.$qty.',"price":199}],"total":199}'
        ));
    }

    public static function invalidQuantityProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-2'],
            'fractional' => ['1.5'],
            'string' => ['"1"'],
            'boolean' => ['true'],
        ];
    }

    #[Test]
    public function it_rejects_missing_or_ambiguous_visible_fields_instead_of_defaulting(): void
    {
        $invalid = [
            "1. Page = 199 บาท\nรวมยอดโอน: 199 บาท",
            '1. Page (199 x 1) = 199 บาท',
            "1. Page (199 x 1) = 199 บาท\nรวมยอดโอน: 199 บาท\nยอดรวม: 199 บาท",
            "1. Page (199 x 0) = 0 บาท\nรวมยอดโอน: 0 บาท",
            "1. Page (199 x -1) = -199 บาท\nรวมยอดโอน: -199 บาท",
            "1. Page (199 x 1.5) = 298.50 บาท\nรวมยอดโอน: 298.50 บาท",
        ];

        foreach ($invalid as $text) {
            $this->assertNull($this->adapter->fromText($text), $text);
        }
    }

    #[Test]
    public function it_rejects_line_arithmetic_that_is_internally_inconsistent(): void
    {
        $this->assertNull($this->adapter->fromText(
            "1. Page (199 x 2) = 199 บาท\nรวมยอดโอน: 199 บาท"
        ));
    }

    #[Test]
    public function it_rejects_missing_fields_and_unknown_state_or_provenance_in_json(): void
    {
        $invalid = [
            '{"items":[{"name":"Page","price":199}],"total":199}',
            '{"items":[{"name":"Page","qty":1}],"total":199}',
            '{"items":[{"name":"Page","qty":1,"price":199}]}',
            '{"items":[{"name":"Page","qty":1,"price":199,"reserved":true}],"total":199}',
            '{"items":[{"name":"Page","qty":1,"price":199}],"total":199,"source":"memory"}',
            '{"items":[],"total":199}',
        ];

        foreach ($invalid as $json) {
            $this->assertNull($this->adapter->fromOrderJson($json), $json);
        }
    }

    #[Test]
    public function it_rejects_invalid_or_overflowing_json_money(): void
    {
        $invalid = [
            '{"items":[{"name":"Page","qty":1,"price":true}],"total":199}',
            '{"items":[{"name":"Page","qty":1,"price":0}],"total":0}',
            '{"items":[{"name":"Page","qty":1,"price":-1}],"total":1}',
            '{"items":[{"name":"Page","qty":1,"price":"1e2"}],"total":100}',
            '{"items":[{"name":"Page","qty":1,"price":"92233720368547758.08"}],"total":"92233720368547758.08"}',
        ];

        foreach ($invalid as $json) {
            $this->assertNull($this->adapter->fromOrderJson($json), $json);
        }
    }
}
