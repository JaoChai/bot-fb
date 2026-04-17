<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'conversation_id' => Conversation::factory(),
            'customer_profile_id' => CustomerProfile::factory(),
            'message_id' => null,
            'total_amount' => $this->faker->randomFloat(2, 100, 5000),
            'payment_method' => $this->faker->randomElement(['promptpay', 'credit_card', 'cash']),
            'status' => 'completed',
            'channel_type' => $this->faker->randomElement(['line', 'facebook']),
            'raw_extraction' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }
}
