<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_column_is_fillable_and_cast_to_number(): void
    {
        $product = ProductStock::create([
            'name' => 'Nolimit Level Up+ Personal',
            'slug' => 'personal',
            'aliases' => ['Personal'],
            'in_stock' => true,
            'display_order' => 1,
            'delivery_method' => 'stock',
            'price' => 1100,
        ]);

        $this->assertSame(1100.0, (float) $product->fresh()->price);
    }

    public function test_price_defaults_to_null_for_products_without_a_price(): void
    {
        $product = ProductStock::create([
            'name' => 'สินค้าทดสอบ',
            'slug' => 'test-item',
            'in_stock' => true,
            'display_order' => 9,
            'delivery_method' => 'none',
        ]);

        $this->assertNull($product->fresh()->price);
    }
}
