<?php

namespace App\Services\Delivery;

use App\Models\ProductStock;
use Illuminate\Support\Collection;

/**
 * จับคู่ชื่อสินค้าที่ parse จากข้อความสรุปยอด (PaymentMessageDetector)
 * กับ ProductStock ที่เปิดส่งอัตโนมัติ (delivery_method != 'none')
 *
 * เทียบแบบ substring สองทาง (ชื่อสินค้าอยู่ในชื่อรายการ) แล้วเลือก candidate
 * ที่ยาวที่สุดก่อน — กัน "Nolimit Share BM" ไปจับคู่กับ "Nolimit" ของ NLMP
 */
class ProductMapper
{
    /** @var Collection<int, ProductStock>|null */
    private $products = null;

    public function map(string $itemName): ?ProductStock
    {
        $needle = mb_strtolower(trim($itemName));
        if ($needle === '') {
            return null;
        }

        $candidates = [];
        $products = $this->products ??= ProductStock::where('delivery_method', '!=', 'none')->get();
        foreach ($products as $product) {
            $terms = array_merge([$product->name], $product->aliases ?? []);
            foreach ($terms as $term) {
                $term = mb_strtolower(trim((string) $term));
                if ($term !== '' && mb_strpos($needle, $term) !== false) {
                    $candidates[] = ['len' => mb_strlen($term), 'product' => $product];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['len'] <=> $a['len']);

        return $candidates[0]['product'];
    }
}
