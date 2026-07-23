<?php

namespace Tests\Feature;

use App\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class SyncProductStockFromPoolTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal', 'aliases' => [],
            'in_stock' => true, 'display_order' => 1,
            'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);
        ProductStock::create([
            'name' => 'เพจ', 'slug' => 'page', 'aliases' => [], 'in_stock' => true,
            'display_order' => 2, 'stock_code' => null, 'delivery_method' => 'support_link',
        ]);
    }

    public function test_turns_off_when_pool_empty_and_busts_cache(): void
    {
        Cache::put(ProductStock::STOCK_CACHE_KEY, 'stale', 300);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertFalse(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
        $this->assertNull(Cache::get(ProductStock::STOCK_CACHE_KEY));
        // support_link ไม่ถูกแตะ
        $this->assertTrue(ProductStock::where('slug', 'page')->first()->in_stock);
    }

    public function test_turns_back_on_when_restocked(): void
    {
        ProductStock::where('slug', 'nolimit-personal')->update(['in_stock' => false]);
        $this->seedAvailable(1, 'NLMP');

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertTrue(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
    }

    public function test_no_change_no_cache_bust(): void
    {
        $this->seedAvailable(1, 'NLMP'); // มีของ + in_stock=true อยู่แล้ว
        ProductStock::where('slug', 'nolimit-personal')->update(['available_count' => 1]);
        Cache::put(ProductStock::STOCK_CACHE_KEY, 'keep', 300);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertSame('keep', Cache::get(ProductStock::STOCK_CACHE_KEY));
    }

    public function test_records_available_count_for_stock_products(): void
    {
        foreach (range(1, 5) as $id) {
            $this->seedAvailable($id, 'NLMP');
        }

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertSame(5, ProductStock::where('slug', 'nolimit-personal')->first()->available_count);
        // support_link ไม่เกี่ยวกับ pool — ต้องเป็น null เสมอ
        $this->assertNull(ProductStock::where('slug', 'page')->first()->available_count);
    }

    public function test_count_change_busts_cache_even_without_toggle(): void
    {
        // in_stock true อยู่แล้วและยังมีของ (ไม่ toggle) แต่จำนวนเปลี่ยน 1 → 2 → ต้องล้าง cache
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'NLMP');
        ProductStock::where('slug', 'nolimit-personal')->update(['available_count' => 1]);
        Cache::put(ProductStock::STOCK_CACHE_KEY, 'stale', 300);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertSame(2, ProductStock::where('slug', 'nolimit-personal')->first()->available_count);
        $this->assertNull(Cache::get(ProductStock::STOCK_CACHE_KEY));
    }

    public function test_manual_off_stays_off_even_when_pool_has_stock(): void
    {
        // เจ้าของสั่งปิดค้าง ทั้งที่ pool ยังมีของ — cron ต้องไม่เปิดกลับ
        $this->seedAvailable(5, 'NLMP');
        ProductStock::where('slug', 'nolimit-personal')
            ->update(['in_stock' => false, 'manual_off' => true]);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertFalse(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
    }

    public function test_available_count_column_persists(): void
    {
        $p = ProductStock::where('slug', 'nolimit-personal')->first();
        $p->update(['available_count' => 5]);

        $this->assertSame(5, $p->fresh()->available_count);
    }

    public function test_releasing_manual_off_lets_auto_sync_turn_back_on(): void
    {
        // กดเปิดมือ (manual_off=false) → auto-sync กลับมาทำงานตาม pool
        $this->seedAvailable(5, 'NLMP');
        ProductStock::where('slug', 'nolimit-personal')
            ->update(['in_stock' => false, 'manual_off' => false]);

        $this->artisan('stock:sync-pool')->assertSuccessful();

        $this->assertTrue(ProductStock::where('slug', 'nolimit-personal')->first()->in_stock);
    }
}
