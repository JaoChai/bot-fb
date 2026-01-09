<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Bot>
 */
class BotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company() . ' Bot',
            'description' => fake()->sentence(),
            'status' => fake()->randomElement(['active', 'inactive', 'paused']),
            'channel_type' => fake()->randomElement(['line', 'facebook', 'telegram']),
            'webhook_url' => config('app.url') . '/webhook/' . Str::random(32),
            'total_conversations' => fake()->numberBetween(0, 1000),
            'total_messages' => fake()->numberBetween(0, 10000),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function line(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => 'line',
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => 'facebook',
        ]);
    }
}
