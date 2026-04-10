<?php

namespace Database\Factories;

use App\Models\ProductStock;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductStockFactory extends Factory
{
    protected $model = ProductStock::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'slug' => $this->faker->unique()->slug(2),
            'aliases' => [$this->faker->word()],
            'in_stock' => true,
            'display_order' => $this->faker->numberBetween(0, 10),
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['in_stock' => false]);
    }
}
