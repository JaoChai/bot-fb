<?php

namespace Database\Factories;

use App\Models\Bot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Flow>
 */
class FlowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'name' => fake()->words(3, true).' Flow',
            'description' => fake()->sentence(),
            'system_prompt' => fake()->paragraph(),
            'temperature' => fake()->randomFloat(2, 0, 1),
            'max_tokens' => fake()->randomElement([1024, 2048, 4096]),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function customerSupport(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Customer Support',
            'system_prompt' => 'คุณเป็นเจ้าหน้าที่ดูแลลูกค้าที่เป็นมิตร...',
            'temperature' => 0.7,
        ]);
    }

    public function sales(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Sales Assistant',
            'system_prompt' => 'คุณเป็นที่ปรึกษาด้านการขาย...',
            'temperature' => 0.8,
        ]);
    }
}
