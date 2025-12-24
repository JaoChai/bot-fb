<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'customer_profile_id' => null,
            'external_customer_id' => 'U'.$this->faker->uuid(),
            'channel_type' => $this->faker->randomElement(['line', 'facebook', 'demo']),
            'status' => 'active',
            'is_handover' => false,
            'assigned_user_id' => null,
            'memory_notes' => null,
            'tags' => [],
            'context' => [],
            'current_flow_id' => null,
            'message_count' => $this->faker->numberBetween(0, 100),
            'last_message_at' => $this->faker->dateTimeThisMonth(),
        ];
    }

    /**
     * Indicate that the conversation is in handover mode.
     */
    public function handover(User $agent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_handover' => true,
            'assigned_user_id' => $agent?->id ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the conversation is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    /**
     * Associate with a customer profile.
     */
    public function withCustomerProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_profile_id' => CustomerProfile::factory(),
        ]);
    }

    /**
     * Set as a LINE conversation.
     */
    public function line(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => 'line',
            'external_customer_id' => 'U'.$this->faker->uuid(),
        ]);
    }

    /**
     * Set as a Facebook conversation.
     */
    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel_type' => 'facebook',
            'external_customer_id' => $this->faker->numerify('##############'),
        ]);
    }
}
