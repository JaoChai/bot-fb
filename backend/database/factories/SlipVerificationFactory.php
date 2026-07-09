<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\SlipVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlipVerification>
 */
class SlipVerificationFactory extends Factory
{
    protected $model = SlipVerification::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'conversation_id' => null,
            'message_id' => null,
            'trans_ref' => strtoupper($this->faker->bothify('TR########')),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => ['data' => ['rawSlip' => ['transRef' => 'TR']]],
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
