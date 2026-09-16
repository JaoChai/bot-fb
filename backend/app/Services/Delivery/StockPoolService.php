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

    // prefix order_ref ของ bot-fb — แยกจากบอทเบิก Telegram ภายนอกที่ใช้ items_reserved ร่วมกัน
    // (ของภายนอกไม่มี prefix นี้ → reconcile/orphan จะไม่ไปยุ่ง)
    public const ORDER_REF_PREFIX = 'bfb:';

    private const COLUMNS = [
        'id', 'name', 'detail', 'type', 'viaId', 'bmId', 'adsId',
        'cost', 'price', 'createdAt', 'updatedAt',
    ];

    /** สร้าง order_ref ของ bot-fb จาก delivery id */
    public static function orderRef(int|string $deliveryId, int|string|null $unitId = null): string
    {
        return self::ORDER_REF_PREFIX.$deliveryId.($unitId === null ? '' : ':'.$unitId);
    }

    /** ถอด delivery id จาก order_ref ของ bot-fb — คืน null ถ้าไม่ใช่ของ bot-fb (บอทภายนอก) */
    public static function deliveryIdFromRef(string $orderRef): ?int
    {
        if (! str_starts_with($orderRef, self::ORDER_REF_PREFIX)) {
            return null;
        }
        $suffix = substr($orderRef, strlen(self::ORDER_REF_PREFIX));

        return preg_match('/^(\d+)(?::[^:]+)?$/', $suffix, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

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
                // The external schema has no idempotency column. Use the existing
                // order_ref as an exact per-unit key and serialize that key on PG.
                $unitKey = preg_match('/^'.preg_quote(self::ORDER_REF_PREFIX, '/').'\d+:[^:]+$/', $orderRef) === 1;
                if ($unitKey && $conn->getDriverName() === 'pgsql') {
                    $conn->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$orderRef]);
                }
                if ($unitKey) {
                    $existing = $conn->table('items_reserved')
                        ->where('order_ref', $orderRef)
                        ->lockForUpdate()
                        ->get();
                    if ($existing->count() > 1) {
                        throw new \RuntimeException('Ambiguous duplicate stock reservation key.');
                    }
                    if ($existing->count() === 1) {
                        $row = (array) $existing->first();
                        if ((string) $row['name'] !== $stockCode) {
                            throw new \RuntimeException('Stock reservation key belongs to another product.');
                        }

                        return $row;
                    }
                }

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

    /** Recover an ambiguous remote commit by its exact, credential-free unit key. */
    public function reservedByOrderRef(string $orderRef): ?array
    {
        return $this->guarded(function () use ($orderRef): ?array {
            $rows = DB::connection(self::CONNECTION)->table('items_reserved')
                ->where('order_ref', $orderRef)
                ->get();
            if ($rows->count() > 1) {
                throw new \RuntimeException('Ambiguous duplicate stock reservation key.');
            }

            return $rows->isEmpty() ? null : (array) $rows->first();
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
                    ->map(fn ($row) => $this->buildDestRow((array) $row, $extraColumns))
                    ->all();
                if ($rows !== []) {
                    $conn->table($destTable)->insert($rows);
                }
                $conn->table('items_reserved')->whereIn('id', $stockItemIds)->delete();
            });
        });
    }

    /**
     * สร้าง payload สำหรับ insert ปลายทาง (items_sold / items_available) — ตาราง IDENTITY เหล่านี้
     * ต้องไม่ระบุ id เอง (ให้ Postgres auto-generate) ต่างจาก items_reserved ที่เป็น id ธรรมดา
     */
    private function buildDestRow(array $row, array $extraColumns): array
    {
        $payload = array_intersect_key($row, array_flip(self::COLUMNS)) + $extraColumns;
        unset($payload['id']);

        return $payload;
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

    /**
     * แถว reserved ของ bot-fb (prefix bfb:) ที่ order_ref ไม่อยู่ในงาน active — ใช้โดย delivery:reconcile
     * กรองเฉพาะของ bot-fb (ไม่แตะแถวบอทภายนอก) + เฉพาะที่ค้างเกิน 10 นาที (กัน reservation สดๆ TOCTOU)
     */
    public function orphanedReservedRows(array $activeOrderRefs): array
    {
        return $this->guarded(function () use ($activeOrderRefs): array {
            $query = DB::connection(self::CONNECTION)->table('items_reserved')
                ->where('order_ref', 'like', self::ORDER_REF_PREFIX.'%')
                ->where('reservedAt', '<=', now()->subMinutes(10));
            foreach ($activeOrderRefs as $activeRef) {
                // Exclude both legacy bfb:<delivery> and new bfb:<delivery>:<unit>.
                $query->where('order_ref', '!=', $activeRef)
                    ->where('order_ref', 'not like', $activeRef.':%');
            }

            return $query->get()->map(fn ($row) => (array) $row)->all();
        });
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
