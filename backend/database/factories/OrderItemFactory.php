<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_name' => $this->faker->randomElement(['Nolimit Personal', 'Nolimit BM', 'Nolimit Pro']),
            'category' => 'nolimit',
            'variant' => $this->faker->randomElement(['เติมเงิน', 'ผูกบัตร', null]),
            'quantity' => $this->faker->numberBetween(1, 5),
            'unit_price' => $this->faker->randomFloat(2, 100, 1000),
            'subtotal' => $this->faker->randomFloat(2, 100, 5000),
        ];
    }
}
