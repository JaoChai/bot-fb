<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use App\Models\QAEvaluationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QAEvaluationLog>
 */
class QAEvaluationLogFactory extends Factory
{
    protected $model = QAEvaluationLog::class;

    public function definition(): array
    {
        $scores = [
            'answer_relevancy' => fake()->randomFloat(2, 0.5, 1.0),
            'faithfulness' => fake()->randomFloat(2, 0.5, 1.0),
            'role_adherence' => fake()->randomFloat(2, 0.6, 1.0),
            'context_precision' => fake()->randomFloat(2, 0.4, 1.0),
            'task_completion' => fake()->randomFloat(2, 0.5, 1.0),
        ];

        $overallScore = array_sum($scores) / count($scores);
        $threshold = 0.70;
        $isFlagged = $overallScore < $threshold;

        return [
            'bot_id' => Bot::factory(),
            'conversation_id' => Conversation::factory(),
            'message_id' => Message::factory(),
            'flow_id' => Flow::factory(),
            'answer_relevancy' => $scores['answer_relevancy'],
            'faithfulness' => $scores['faithfulness'],
            'role_adherence' => $scores['role_adherence'],
            'context_precision' => $scores['context_precision'],
            'task_completion' => $scores['task_completion'],
            'overall_score' => round($overallScore, 2),
            'is_flagged' => $isFlagged,
            'issue_type' => $isFlagged ? fake()->randomElement(['hallucination', 'off_topic', 'wrong_tone', 'missing_info', 'unanswered']) : null,
            'issue_details' => $isFlagged ? ['reason' => fake()->sentence()] : null,
            'user_question' => fake()->sentence() . '?',
            'bot_response' => fake()->paragraph(),
            'system_prompt_used' => fake()->paragraph(),
            'kb_chunks_used' => [
                ['content' => fake()->sentence(), 'score' => fake()->randomFloat(2, 0.7, 0.95)],
            ],
            'model_metadata' => [
                'model' => 'google/gemini-2.5-flash-preview',
                'latency_ms' => fake()->numberBetween(200, 1500),
                'cost_estimate' => fake()->randomFloat(4, 0.0001, 0.01),
            ],
            'evaluated_at' => now(),
        ];
    }

    /**
     * Create a flagged evaluation log
     */
    public function flagged(): static
    {
        return $this->state(fn (array $attributes) => [
            'answer_relevancy' => fake()->randomFloat(2, 0.3, 0.6),
            'faithfulness' => fake()->randomFloat(2, 0.2, 0.5),
            'overall_score' => fake()->randomFloat(2, 0.3, 0.65),
            'is_flagged' => true,
            'issue_type' => fake()->randomElement(['hallucination', 'off_topic', 'wrong_tone']),
            'issue_details' => ['reason' => 'Low score detected'],
        ]);
    }

    /**
     * Create a passing evaluation log
     */
    public function passing(): static
    {
        return $this->state(fn (array $attributes) => [
            'answer_relevancy' => fake()->randomFloat(2, 0.8, 1.0),
            'faithfulness' => fake()->randomFloat(2, 0.85, 1.0),
            'role_adherence' => fake()->randomFloat(2, 0.8, 1.0),
            'context_precision' => fake()->randomFloat(2, 0.75, 1.0),
            'task_completion' => fake()->randomFloat(2, 0.8, 1.0),
            'overall_score' => fake()->randomFloat(2, 0.8, 0.95),
            'is_flagged' => false,
            'issue_type' => null,
            'issue_details' => null,
        ]);
    }

    /**
     * Create with hallucination issue
     */
    public function withHallucination(): static
    {
        return $this->state(fn (array $attributes) => [
            'faithfulness' => fake()->randomFloat(2, 0.1, 0.4),
            'overall_score' => fake()->randomFloat(2, 0.4, 0.6),
            'is_flagged' => true,
            'issue_type' => 'hallucination',
            'issue_details' => ['reason' => 'Bot response contains information not in knowledge base'],
        ]);
    }

    /**
     * Create with off-topic issue
     */
    public function withOffTopic(): static
    {
        return $this->state(fn (array $attributes) => [
            'answer_relevancy' => fake()->randomFloat(2, 0.1, 0.4),
            'overall_score' => fake()->randomFloat(2, 0.4, 0.6),
            'is_flagged' => true,
            'issue_type' => 'off_topic',
            'issue_details' => ['reason' => 'Response does not address user question'],
        ]);
    }

    /**
     * Create for a specific bot
     */
    public function forBot(Bot $bot): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_id' => $bot->id,
        ]);
    }

    /**
     * Create with specific date
     */
    public function createdAt(\DateTimeInterface $date): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $date,
            'evaluated_at' => $date,
        ]);
    }
}
