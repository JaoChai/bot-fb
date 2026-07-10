<?php

namespace Tests\Feature;

use App\Services\Delivery\StockPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

class StockPoolServiceTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private StockPoolService $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        $this->pool = app(StockPoolService::class);
    }

    public function test_reserve_one_moves_row_out_of_available(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->seedAvailable(11, 'NLMP', 'uid11|pass11');

        $row = $this->pool->reserveOne('NLMP', '99');

        $this->assertSame(10, (int) $row['id']); // FIFO: id ต่ำสุดก่อน
        $this->assertSame('uid10|pass10', $row['detail']);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
        $reserved = DB::connection('mhha_acc')->table('items_reserved')->first();
        $this->assertSame(10, (int) $reserved->id);
        $this->assertSame('99', $reserved->order_ref);
    }

    public function test_reserve_one_returns_null_when_code_out_of_stock(): void
    {
        $this->seedAvailable(10, 'G3D');

        $this->assertNull($this->pool->reserveOne('NLMP', '99'));
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_available')->count());
    }

    public function test_get_reserved_returns_rows_keyed_by_id(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->pool->reserveOne('NLMP', '99');

        $rows = $this->pool->getReserved([10]);

        $this->assertArrayHasKey(10, $rows);
        $this->assertSame('uid10|pass10', $rows[10]['detail']);
    }

    public function test_mark_sold_moves_to_items_sold_with_names(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->pool->reserveOne('NLMP', '99');

        $this->pool->markSold([10], 'บูม', 'bot-fb');

        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $sold = DB::connection('mhha_acc')->table('items_sold')->first();
        $this->assertSame(10, (int) $sold->id);
        $this->assertSame('บูม', $sold->first_name);
        $this->assertSame('bot-fb', $sold->username);
    }

    public function test_return_to_available_restores_row(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        $this->pool->reserveOne('NLMP', '99');

        $this->pool->returnToAvailable([10]);

        $this->assertSame(0, DB::connection('mhha_acc')->table('items_reserved')->count());
        $avail = DB::connection('mhha_acc')->table('items_available')->first();
        $this->assertSame(10, (int) $avail->id);
        $this->assertSame('uid10|pass10', $avail->detail);
    }

    public function test_count_available_groups_by_code(): void
    {
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'NLMP');
        $this->seedAvailable(3, 'G3D');

        $this->assertSame(['G3D' => 1, 'NLMP' => 2], $this->pool->countAvailable());
    }

    public function test_orphaned_reserved_rows(): void
    {
        $this->seedAvailable(1, 'NLMP');
        $this->seedAvailable(2, 'NLMP');
        $this->pool->reserveOne('NLMP', '7');
        $this->pool->reserveOne('NLMP', '8');

        $orphans = $this->pool->orphanedReservedRows(['7']);

        $this->assertCount(1, $orphans);
        $this->assertSame('8', $orphans[0]['order_ref']);
    }
}
