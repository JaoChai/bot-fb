<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class StockPoolConnectionTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    public function test_trait_creates_pool_tables_and_seeds(): void
    {
        $this->setUpStockPool();
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'G3D', 'fbuid|fbpass|2FAKEY');

        $this->assertSame(2, DB::connection('mhha_acc')->table('items_available')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_sold')->count());
    }

    public function test_delivery_config_has_defaults(): void
    {
        $this->assertFalse(config('delivery.enabled'));
        $this->assertStringContainsString('lin.ee/sTD5TQL', config('delivery.support_link_template'));
    }
}
