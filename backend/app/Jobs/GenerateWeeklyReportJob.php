<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\QAWeeklyReport;
use App\Services\QAInspector\QAReportNotificationService;
use App\Services\QAInspector\WeeklyReportGenerator;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateWeeklyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300; // 5 minutes
    public array $backoff = [60, 120];

    public function __construct(
        public Bot $bot,
        public Carbon $weekStart,
    ) {
        $this->onQueue('qa-report');
    }

    public function handle(
        WeeklyReportGenerator $reportGenerator,
        QAReportNotificationService $notificationService,
    ): void {
        Log::info('QA Weekly Report: Starting generation', [
            'bot_id' => $this->bot->id,
            'week_start' => $this->weekStart->toDateString(),
        ]);

        $report = $reportGenerator->generate($this->bot, $this->weekStart);

        Log::info('QA Weekly Report: Generation complete', [
            'bot_id' => $this->bot->id,
            'report_id' => $report->id,
            'status' => $report->status,
        ]);

        // Send notifications if report was generated successfully
        if ($report->isCompleted()) {
            Log::info('QA Weekly Report: Sending notifications', [
                'report_id' => $report->id,
                'bot_id' => $this->bot->id,
            ]);
            $notificationService->notifyReportReady($report);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('QA Weekly Report: Job failed', [
            'bot_id' => $this->bot->id,
            'week_start' => $this->weekStart->toDateString(),
            'error' => $exception->getMessage(),
        ]);

        // Mark report as failed
        QAWeeklyReport::where('bot_id', $this->bot->id)
            ->where('week_start', $this->weekStart->toDateString())
            ->update(['status' => QAWeeklyReport::STATUS_FAILED]);
    }
}
