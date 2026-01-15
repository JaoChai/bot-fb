<?php

namespace App\Notifications;

use App\Models\Bot;
use App\Models\QAEvaluationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QALowScoreAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public QAEvaluationLog $evaluationLog,
        public Bot $bot,
        public float $score,
        public ?string $issueType,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * Uses database channel for in-app notifications.
     * Email can be added later if needed.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $scorePercent = round($this->score * 100, 1);
        $issueLabel = $this->formatIssueType($this->issueType);

        return [
            'type' => 'qa_low_score_alert',
            'title' => "Critical QA Issue: {$this->bot->name}",
            'message' => "A response scored only {$scorePercent}% " .
                ($issueLabel ? "(Issue: {$issueLabel})" : '') .
                ". Review recommended.",
            'bot_id' => $this->bot->id,
            'bot_name' => $this->bot->name,
            'evaluation_id' => $this->evaluationLog->id,
            'conversation_id' => $this->evaluationLog->conversation_id,
            'score' => $this->score,
            'score_percent' => $scorePercent,
            'issue_type' => $this->issueType,
            'issue_label' => $issueLabel,
            'severity' => $this->getSeverity(),
            'action_url' => $this->getActionUrl(),
        ];
    }

    /**
     * Format issue type for display
     */
    private function formatIssueType(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        return match ($type) {
            'hallucination' => 'Hallucination',
            'off_topic' => 'Off Topic',
            'wrong_tone' => 'Wrong Tone',
            'missing_info' => 'Missing Information',
            'unanswered' => 'Unanswered Question',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * Get severity level based on score
     */
    private function getSeverity(): string
    {
        return match (true) {
            $this->score < 0.3 => 'critical',
            $this->score < 0.4 => 'high',
            default => 'medium',
        };
    }

    /**
     * Get the URL to view this specific evaluation
     */
    private function getActionUrl(): string
    {
        $baseUrl = config('app.frontend_url') ?? config('app.url');
        return "{$baseUrl}/bots/{$this->bot->id}/qa-inspector/evaluations/{$this->evaluationLog->id}";
    }
}
