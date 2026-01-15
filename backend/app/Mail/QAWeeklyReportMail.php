<?php

namespace App\Mail;

use App\Models\QAWeeklyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QAWeeklyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public QAWeeklyReport $report,
    ) {}

    public function envelope(): Envelope
    {
        $botName = $this->report->bot?->name ?? 'Unknown Bot';
        $weekRange = $this->report->week_start->format('M d') . ' - ' . $this->report->week_end->format('M d, Y');

        return new Envelope(
            subject: "QA Weekly Report: {$botName} ({$weekRange})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.qa-weekly-report',
            with: [
                'report' => $this->report,
                'bot' => $this->report->bot,
                'weekRange' => $this->report->week_start->format('M d') . ' - ' . $this->report->week_end->format('M d, Y'),
                'reportUrl' => $this->getReportUrl(),
                'averageScorePercent' => round((float) $this->report->average_score * 100, 1),
                'previousScorePercent' => $this->report->previous_average_score !== null
                    ? round((float) $this->report->previous_average_score * 100, 1)
                    : null,
                'scoreChange' => $this->report->score_change !== null
                    ? round($this->report->score_change * 100, 1)
                    : null,
                'flaggedPercentage' => $this->report->flagged_percentage,
            ],
        );
    }

    private function getReportUrl(): string
    {
        $baseUrl = config('app.frontend_url') ?? config('app.url');
        $botId = $this->report->bot_id;
        $reportId = $this->report->id;

        return "{$baseUrl}/bots/{$botId}/qa-inspector/reports/{$reportId}";
    }
}
