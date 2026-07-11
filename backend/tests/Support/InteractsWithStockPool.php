<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สร้างตารางจำลองของ mhha_acc_db บน sqlite :memory: สำหรับเทสต์
 * (ของจริงเป็น Postgres — ส่วน FOR UPDATE SKIP LOCKED ทดสอบใน manual E2E, ดู Task 12)
 */
trait InteractsWithStockPool
{
    protected function setUpStockPool(): void
    {
        config(['database.connections.mhha_acc' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('mhha_acc');

        $schema = Schema::connection('mhha_acc');

        $base = function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->text('name');
            $t->text('detail');
            $t->text('type')->default('account');
            $t->text('viaId')->nullable();
            $t->text('bmId')->nullable();
            $t->text('adsId')->nullable();
            $t->decimal('cost', 12, 2)->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->timestamp('createdAt')->nullable();
            $t->timestamp('updatedAt')->nullable();
        };

        $schema->create('items_available', $base);
        $schema->create('items_reserved', function (Blueprint $t) use ($base) {
            $base($t);
            $t->text('order_ref')->nullable();
            $t->timestamp('reservedAt')->nullable();
        });
        $schema->create('items_sold', function (Blueprint $t) use ($base) {
            $base($t);
            $t->boolean('isAgent')->default(false);
            $t->text('first_name')->nullable();
            $t->text('username')->nullable();
        });
    }

    protected function seedAvailable(int $id, string $code, string $detail = 'uid|pass|mail|2fa', string $type = 'x', ?string $bmId = null, ?string $adsId = null): void
    {
        DB::connection('mhha_acc')->table('items_available')->insert([
            'id' => $id, 'name' => $code, 'detail' => $detail, 'type' => $type,
            'bmId' => $bmId, 'adsId' => $adsId,
            'cost' => 0, 'price' => 0, 'createdAt' => now(), 'updatedAt' => now(),
        ]);
    }
}
