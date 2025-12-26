<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender' => $this->faker->randomElement(['user', 'bot', 'agent']),
            'content' => $this->faker->sentence(),
            'type' => 'text',
            'media_url' => null,
            'media_type' => null,
            'media_metadata' => null,
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cost' => null,
            'external_message_id' => $this->faker->uuid(),
            'reply_to_message_id' => null,
            'embedding' => null,
            'sentiment' => null,
            'intents' => null,
        ];
    }

    /**
     * Indicate that the message is from a user.
     */
    public function fromUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender' => 'user',
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cost' => null,
        ]);
    }

    /**
     * Indicate that the message is from a bot (AI).
     */
    public function fromBot(string $model = 'openrouter/auto'): static
    {
        return $this->state(fn (array $attributes) => [
            'sender' => 'bot',
            'model_used' => $model,
            'prompt_tokens' => $this->faker->numberBetween(100, 500),
            'completion_tokens' => $this->faker->numberBetween(50, 200),
            'cost' => $this->faker->randomFloat(6, 0.0001, 0.01),
        ]);
    }

    /**
     * Indicate that the message is from a human agent.
     */
    public function fromAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'sender' => 'agent',
            'model_used' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cost' => null,
        ]);
    }

    /**
     * Set as an image message.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'image',
            'content' => null,
            'media_url' => $this->faker->imageUrl(),
            'media_type' => 'image/jpeg',
        ]);
    }

    /**
     * Set as a sticker message.
     */
    public function sticker(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sticker',
            'content' => null,
            'media_metadata' => [
                'package_id' => $this->faker->randomNumber(5),
                'sticker_id' => $this->faker->randomNumber(7),
            ],
        ]);
    }
}
