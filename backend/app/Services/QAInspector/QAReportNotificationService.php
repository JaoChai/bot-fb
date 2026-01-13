<?php

namespace App\Services\QAInspector;

use App\Mail\QAWeeklyReportMail;
use App\Models\QAWeeklyReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class QAReportNotificationService
{
    public function __construct(
        private QAInspectorService $qaInspectorService,
    ) {}

    /**
     * Send notifications for a completed report based on bot's notification settings
     */
    public function notifyReportReady(QAWeeklyReport $report): void
    {
        $bot = $report->bot;

        if (!$bot) {
            Log::warning('QA Report Notification: Bot not found for report', [
                'report_id' => $report->id,
            ]);
            return;
        }

        $notifications = $this->qaInspectorService->getNotificationSettings($bot);

        Log::info('QA Report Notification: Processing notifications', [
            'report_id' => $report->id,
            'bot_id' => $bot->id,
            'notifications' => $notifications,
        ]);

        // Send email notification if enabled
        if (!empty($notifications['email'])) {
            $this->sendEmail($report);
        }

        // Mark notification as sent
        $report->update(['notification_sent' => true]);

        Log::info('QA Report Notification: Notifications sent', [
            'report_id' => $report->id,
            'bot_id' => $bot->id,
        ]);
    }

    /**
     * Send email notification to the bot owner
     */
    private function sendEmail(QAWeeklyReport $report): void
    {
        $bot = $report->bot;
        $user = $bot->user;

        if (!$user instanceof User) {
            Log::warning('QA Report Notification: Bot owner not found', [
                'report_id' => $report->id,
                'bot_id' => $bot->id,
            ]);
            return;
        }

        if (empty($user->email)) {
            Log::warning('QA Report Notification: Bot owner has no email', [
                'report_id' => $report->id,
                'bot_id' => $bot->id,
                'user_id' => $user->id,
            ]);
            return;
        }

        try {
            Mail::to($user->email)->send(new QAWeeklyReportMail($report));

            Log::info('QA Report Notification: Email sent', [
                'report_id' => $report->id,
                'bot_id' => $bot->id,
                'user_email' => $user->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('QA Report Notification: Failed to send email', [
                'report_id' => $report->id,
                'bot_id' => $bot->id,
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process all reports pending notification
     *
     * Useful for batch processing in case notifications failed earlier
     */
    public function processPendingNotifications(): int
    {
        $pendingReports = QAWeeklyReport::pendingNotification()
            ->with('bot.user')
            ->get();

        $processed = 0;

        foreach ($pendingReports as $report) {
            $this->notifyReportReady($report);
            $processed++;
        }

        return $processed;
    }
}
