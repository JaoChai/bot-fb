<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use Database\Seeders\ProductStockSeeder;
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
            'vip_price' => 1000,
        ]);

        $this->assertSame(1100.0, (float) $product->fresh()->price);
        $this->assertSame(1000.0, (float) $product->fresh()->vip_price);
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
        $this->assertNull($product->fresh()->vip_price);
    }

    public function test_seeder_keeps_normal_and_vip_prices_for_nolimit_products(): void
    {
        $this->seed(ProductStockSeeder::class);

        $this->assertDatabaseHas('product_stocks', [
            'stock_code' => 'NLMP',
            'price' => 1100,
            'vip_price' => 1000,
        ]);
        $this->assertDatabaseHas('product_stocks', [
            'stock_code' => 'NLMBM',
            'price' => 1100,
            'vip_price' => 1000,
        ]);
    }
}
