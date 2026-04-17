<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_id' => $this->faker->uuid(),
            'channel_type' => $this->faker->randomElement(['line', 'facebook', 'demo']),
            'display_name' => $this->faker->name(),
            'picture_url' => null,
            'phone' => null,
            'email' => null,
            'interaction_count' => 0,
            'first_interaction_at' => null,
            'last_interaction_at' => null,
            'metadata' => null,
            'tags' => null,
            'notes' => null,
        ];
    }
}
