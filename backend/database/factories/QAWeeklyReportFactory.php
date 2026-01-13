<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\QAWeeklyReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QAWeeklyReport>
 */
class QAWeeklyReportFactory extends Factory
{
    protected $model = QAWeeklyReport::class;

    public function definition(): array
    {
        $weekStart = Carbon::now()->subWeek()->startOfWeek();
        $totalConversations = fake()->numberBetween(100, 500);
        $totalFlagged = (int) ($totalConversations * fake()->randomFloat(2, 0.05, 0.20));

        return [
            'bot_id' => Bot::factory(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->endOfWeek()->toDateString(),
            'status' => QAWeeklyReport::STATUS_COMPLETED,
            'performance_summary' => [
                'highlights' => [fake()->sentence(), fake()->sentence()],
                'concerns' => [fake()->sentence()],
                'recommendations' => [fake()->sentence()],
            ],
            'top_issues' => [
                [
                    'type' => 'hallucination',
                    'count' => fake()->numberBetween(5, 20),
                    'examples' => [fake()->sentence()],
                ],
                [
                    'type' => 'off_topic',
                    'count' => fake()->numberBetween(3, 15),
                    'examples' => [fake()->sentence()],
                ],
            ],
            'prompt_suggestions' => [
                [
                    'original' => fake()->paragraph(),
                    'suggested' => fake()->paragraph(),
                    'reason' => fake()->sentence(),
                    'applied' => false,
                ],
            ],
            'total_conversations' => $totalConversations,
            'total_flagged' => $totalFlagged,
            'average_score' => fake()->randomFloat(2, 0.70, 0.95),
            'previous_average_score' => fake()->randomFloat(2, 0.65, 0.90),
            'generation_cost' => fake()->randomFloat(4, 0.01, 0.10),
            'generated_at' => now(),
            'notification_sent' => false,
        ];
    }

    /**
     * Create a report that is still generating
     * Use empty arrays/objects to satisfy NOT NULL constraints
     */
    public function generating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QAWeeklyReport::STATUS_GENERATING,
            'performance_summary' => [],
            'top_issues' => [],
            'prompt_suggestions' => [],
            'generated_at' => null,
            'total_conversations' => 0,
            'total_flagged' => 0,
            'average_score' => 0,
        ]);
    }

    /**
     * Create a failed report
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QAWeeklyReport::STATUS_FAILED,
            'performance_summary' => [],
            'top_issues' => [],
            'prompt_suggestions' => [],
            'generated_at' => null,
        ]);
    }

    /**
     * Create a completed report
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => QAWeeklyReport::STATUS_COMPLETED,
            'generated_at' => now(),
        ]);
    }

    /**
     * Create report with notification sent
     */
    public function notified(): static
    {
        return $this->state(fn (array $attributes) => [
            'notification_sent' => true,
        ]);
    }

    /**
     * Create report for specific week
     */
    public function forWeek(Carbon $weekStart): static
    {
        return $this->state(fn (array $attributes) => [
            'week_start' => $weekStart->startOfWeek()->toDateString(),
            'week_end' => $weekStart->copy()->endOfWeek()->toDateString(),
        ]);
    }

    /**
     * Create report for a specific bot
     */
    public function forBot(Bot $bot): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_id' => $bot->id,
        ]);
    }

    /**
     * Create report with specific prompt suggestions
     */
    public function withPromptSuggestions(array $suggestions): static
    {
        return $this->state(fn (array $attributes) => [
            'prompt_suggestions' => $suggestions,
        ]);
    }

    /**
     * Create report showing improvement
     */
    public function improving(): static
    {
        $previousScore = fake()->randomFloat(2, 0.60, 0.75);
        return $this->state(fn (array $attributes) => [
            'average_score' => $previousScore + fake()->randomFloat(2, 0.05, 0.15),
            'previous_average_score' => $previousScore,
        ]);
    }

    /**
     * Create report showing decline
     */
    public function declining(): static
    {
        $previousScore = fake()->randomFloat(2, 0.80, 0.90);
        return $this->state(fn (array $attributes) => [
            'average_score' => $previousScore - fake()->randomFloat(2, 0.05, 0.15),
            'previous_average_score' => $previousScore,
        ]);
    }
}
