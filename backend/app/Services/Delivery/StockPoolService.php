<?php

namespace App\Services\Delivery;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ชั้นเดียวที่คุยกับ mhha_acc_db (stock บัญชีโฆษณา)
 * หลักการ: ของหนึ่งชิ้นอยู่ได้ที่เดียวเสมอ — available → reserved → sold
 * ห้าม log ค่า detail (credential) เด็ดขาด
 */
class StockPoolService
{
    public const CONNECTION = 'mhha_acc';

    private const COLUMNS = [
        'id', 'name', 'detail', 'type', 'viaId', 'bmId', 'adsId',
        'cost', 'price', 'createdAt', 'updatedAt',
    ];

    /**
     * หยิบของ 1 ชิ้นออกจาก items_available แบบ atomic แล้วย้ายเข้า items_reserved
     * DELETE ... RETURNING การันตีว่าสองฝั่ง (bot-fb กับบอทเบิก Telegram ภายนอก)
     * ไม่มีทางได้แถวเดียวกัน; SKIP LOCKED กันรอ lock ค้าง (เฉพาะ pgsql — sqlite ในเทสต์ไม่มี)
     */
    public function reserveOne(string $stockCode, string $orderRef): ?array
    {
        return $this->guarded(function () use ($stockCode, $orderRef) {
            $conn = DB::connection(self::CONNECTION);

            return $conn->transaction(function () use ($conn, $stockCode, $orderRef) {
                $lock = $conn->getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : '';
                $rows = $conn->select(
                    "DELETE FROM items_available WHERE id = (
                        SELECT id FROM items_available WHERE name = ? ORDER BY id LIMIT 1 {$lock}
                    ) RETURNING *",
                    [$stockCode],
                );

                if ($rows === []) {
                    return null;
                }

                $row = (array) $rows[0];
                $conn->table('items_reserved')->insert(
                    array_intersect_key($row, array_flip(self::COLUMNS))
                    + ['order_ref' => $orderRef, 'reservedAt' => now()],
                );

                return $row;
            });
        });
    }

    /** @return array<int, array> map id => row จาก items_reserved */
    public function getReserved(array $stockItemIds): array
    {
        return $this->guarded(fn () => DB::connection(self::CONNECTION)->table('items_reserved')
            ->whereIn('id', $stockItemIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($row) => (array) $row)
            ->all());
    }

    public function markSold(array $stockItemIds, string $firstName, string $username): void
    {
        $this->moveReservedRows($stockItemIds, 'items_sold', [
            'isAgent' => false, 'first_name' => $firstName, 'username' => $username,
        ]);
    }

    public function returnToAvailable(array $stockItemIds): void
    {
        $this->moveReservedRows($stockItemIds, 'items_available');
    }

    /** ย้ายแถวจาก items_reserved ไปตารางปลายทางใน transaction เดียว (ของหนึ่งชิ้นอยู่ได้ที่เดียวเสมอ) */
    private function moveReservedRows(array $stockItemIds, string $destTable, array $extraColumns = []): void
    {
        if ($stockItemIds === []) {
            return;
        }
        $this->guarded(function () use ($stockItemIds, $destTable, $extraColumns): void {
            $conn = DB::connection(self::CONNECTION);
            $conn->transaction(function () use ($conn, $stockItemIds, $destTable, $extraColumns) {
                $rows = $conn->table('items_reserved')->whereIn('id', $stockItemIds)->get()
                    ->map(fn ($row) => array_intersect_key((array) $row, array_flip(self::COLUMNS)) + $extraColumns)
                    ->all();
                if ($rows !== []) {
                    $conn->table($destTable)->insert($rows);
                }
                $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
            });
        });
    }

    /** @return array<string, int> จำนวนของคงเหลือต่อ stock code */
    public function countAvailable(): array
    {
        return $this->guarded(fn () => DB::connection(self::CONNECTION)->table('items_available')
            ->selectRaw('name, count(*) as cnt')
            ->groupBy('name')
            ->orderBy('name')
            ->pluck('cnt', 'name')
            ->map(fn ($c) => (int) $c)
            ->all());
    }

    /** แถว reserved ที่ order_ref ไม่อยู่ในงานที่ยัง active — ใช้โดย delivery:reconcile */
    public function orphanedReservedRows(array $activeOrderRefs): array
    {
        return $this->guarded(fn () => DB::connection(self::CONNECTION)->table('items_reserved')
            ->when($activeOrderRefs !== [],
                fn ($q) => $q->whereNotIn('order_ref', $activeOrderRefs))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all());
    }

    /**
     * กัน credential (detail) หลุดผ่าน QueryException — Laravel แทรกค่า bindings
     * ลงใน message ซึ่งจะถูกส่งขึ้น Sentry ทั้งดิบๆ จึงต้อง rethrow แบบ sanitized
     * (log ได้แค่ SQL template ที่เป็น placeholder กับ SQLSTATE — ห้ามแนบ bindings/driver message)
     */
    private function guarded(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (QueryException $e) {
            Log::error('StockPool query failed', [
                'sql' => $e->getSql(),
                'sqlstate' => $e->getCode(),
            ]);
            throw new \RuntimeException('stock pool operation failed (sqlstate '.$e->getCode().')');
        }
    }
}
