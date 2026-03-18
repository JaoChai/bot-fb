<?php

namespace Database\Seeders;

use App\Models\ProductStock;
use Illuminate\Database\Seeder;

class ProductStockSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'slug' => 'personal',
                'name' => 'Nolimit Level Up+ Personal',
                'aliases' => ['Personal', 'ส่วนตัว', 'ตัวหลัก', 'หลักพ่วง', 'พ่วงหลัก'],
                'in_stock' => false,
                'display_order' => 0,
            ],
            [
                'slug' => 'bm',
                'name' => 'Nolimit Level Up+ BM',
                'aliases' => ['BM', 'บีเอ็ม', 'พอร์ตโฟลิโอ', 'บัญชีธุรกิจ', 'กระเป๋า BM'],
                'in_stock' => false,
                'display_order' => 1,
            ],
            [
                'slug' => 'page',
                'name' => 'Page',
                'aliases' => ['เพจ', 'fanpage'],
                'in_stock' => true,
                'display_order' => 2,
            ],
            [
                'slug' => 'g3d',
                'name' => 'G3D',
                'aliases' => ['ไก่', 'gai', 'หน้าม้า', 'เฟสผี', 'เฟสขาว'],
                'in_stock' => true,
                'display_order' => 3,
            ],
        ];

        foreach ($products as $product) {
            ProductStock::updateOrCreate(
                ['slug' => $product['slug']],
                $product,
            );
        }
    }
}
