<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\DB;

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
    }

    /** @return array<int, array> map id => row จาก items_reserved */
    public function getReserved(array $stockItemIds): array
    {
        return DB::connection(self::CONNECTION)->table('items_reserved')
            ->whereIn('id', $stockItemIds)
            ->get()
            ->keyBy('id')
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function markSold(array $stockItemIds, string $firstName, string $username): void
    {
        if ($stockItemIds === []) {
            return;
        }
        $conn = DB::connection(self::CONNECTION);
        $conn->transaction(function () use ($conn, $stockItemIds, $firstName, $username) {
            $rows = $conn->table('items_reserved')->whereIn('id', $stockItemIds)->get();
            foreach ($rows as $row) {
                $conn->table('items_sold')->insert(
                    array_intersect_key((array) $row, array_flip(self::COLUMNS))
                    + ['isAgent' => false, 'first_name' => $firstName, 'username' => $username],
                );
            }
            $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
        });
    }

    public function returnToAvailable(array $stockItemIds): void
    {
        if ($stockItemIds === []) {
            return;
        }
        $conn = DB::connection(self::CONNECTION);
        $conn->transaction(function () use ($conn, $stockItemIds) {
            $rows = $conn->table('items_reserved')->whereIn('id', $stockItemIds)->get();
            foreach ($rows as $row) {
                $conn->table('items_available')->insert(
                    array_intersect_key((array) $row, array_flip(self::COLUMNS)),
                );
            }
            $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
        });
    }

    /** @return array<string, int> จำนวนของคงเหลือต่อ stock code */
    public function countAvailable(): array
    {
        return DB::connection(self::CONNECTION)->table('items_available')
            ->selectRaw('name, count(*) as cnt')
            ->groupBy('name')
            ->orderBy('name')
            ->pluck('cnt', 'name')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** แถว reserved ที่ order_ref ไม่อยู่ในงานที่ยัง active — ใช้โดย delivery:reconcile */
    public function orphanedReservedRows(array $activeOrderRefs): array
    {
        return DB::connection(self::CONNECTION)->table('items_reserved')
            ->when($activeOrderRefs !== [],
                fn ($q) => $q->whereNotIn('order_ref', $activeOrderRefs))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
