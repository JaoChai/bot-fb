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
        // id ไม่ใช่ 10 ที่ย้ายมาโดยตั้งใจ — items_sold.id เป็น IDENTITY บน Postgres จริง
        // ต้องปล่อยให้ auto-generate ใหม่เสมอ (ห้าม insert id เดิม)
        $this->assertSame('NLMP', $sold->name);
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
        // id ไม่ใช่ 10 ที่ย้ายมาโดยตั้งใจ — items_available.id เป็น IDENTITY บน Postgres จริง
        // ต้องปล่อยให้ auto-generate ใหม่เสมอ (ห้าม insert id เดิม)
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
        $old = now()->subMinutes(20);
        $mk = fn (int $id, string $ref, $reservedAt) => [
            'id' => $id, 'name' => 'NLMP', 'detail' => 'x', 'type' => 'x',
            'order_ref' => $ref, 'reservedAt' => $reservedAt,
            'createdAt' => $old, 'updatedAt' => $old,
        ];
        DB::connection('mhha_acc')->table('items_reserved')->insert([
            $mk(7, StockPoolService::orderRef(7), $old),   // bfb: active (จะถูก exclude ด้วย activeRefs)
            $mk(8, StockPoolService::orderRef(8), $old),   // bfb: orphan จริง
            $mk(9, 'ext-telegram-1', $old),                // บอทภายนอก — ต้องไม่ถูกแตะ
            $mk(10, StockPoolService::orderRef(99), now()), // bfb: แต่เพิ่งจอง (<10 นาที) — กัน TOCTOU
        ]);

        $orphans = $this->pool->orphanedReservedRows([StockPoolService::orderRef(7)]);

        $this->assertCount(1, $orphans);
        $this->assertSame(StockPoolService::orderRef(8), $orphans[0]['order_ref']);
    }

    /**
     * items_sold / items_available เป็น Postgres IDENTITY column (id) — INSERT ที่มี id
     * แบบ explicit จะพัง "cannot insert a non-DEFAULT value into column id" บน Postgres จริง
     * แต่ sqlite (ที่เทสต์รันด้วย) ไม่บังคับกฎนี้ จึงต้องเทสต์ payload ตรงๆ ผ่าน reflection
     * แทนที่จะเทสต์ผ่าน insert จริงซึ่งจะผ่านทั้งก่อน/หลังแก้บน sqlite
     */
    public function test_build_dest_row_excludes_id_but_keeps_other_columns_and_extras(): void
    {
        $method = new \ReflectionMethod(StockPoolService::class, 'buildDestRow');

        $row = [
            'id' => 10, 'name' => 'NLMP', 'detail' => 'uid10|pass10', 'type' => 'account',
            'viaId' => 'v1', 'bmId' => 'bm1', 'adsId' => 'ads1',
            'cost' => 100, 'price' => 200, 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01',
        ];
        $extraColumns = ['isAgent' => false, 'first_name' => 'บูม', 'username' => 'bot-fb'];

        $result = $method->invoke($this->pool, $row, $extraColumns);

        $this->assertArrayNotHasKey('id', $result);
        $this->assertSame('NLMP', $result['name']);
        $this->assertSame('uid10|pass10', $result['detail']);
        $this->assertSame('account', $result['type']);
        $this->assertSame('v1', $result['viaId']);
        $this->assertSame('bm1', $result['bmId']);
        $this->assertSame('ads1', $result['adsId']);
        $this->assertSame(100, $result['cost']);
        $this->assertSame(200, $result['price']);
        $this->assertSame('2026-01-01', $result['createdAt']);
        $this->assertSame('2026-01-01', $result['updatedAt']);
        $this->assertFalse($result['isAgent']);
        $this->assertSame('บูม', $result['first_name']);
        $this->assertSame('bot-fb', $result['username']);
    }

    public function test_query_failure_never_leaks_detail_in_exception(): void
    {
        $this->seedAvailable(10, 'NLMP', 'uid10|pass10');
        // ทำให้ insert ลง items_reserved พัง — QueryException ดิบจะมี detail ใน bindings
        DB::connection('mhha_acc')->statement('DROP TABLE items_reserved');

        try {
            $this->pool->reserveOne('NLMP', '99');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('uid10|pass10', $e->getMessage());
            $this->assertStringContainsString('stock pool operation failed', $e->getMessage());
        }
    }
}
