<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyReportJob;
use App\Models\Bot;
use App\Services\QAInspector\QAInspectorService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateQAWeeklyReports extends Command
{
    protected $signature = 'qa:generate-weekly-reports
                            {--bot= : Generate for specific bot ID}
                            {--week= : Week start date (YYYY-MM-DD), defaults to last week}
                            {--force : Bypass schedule check and generate for all bots}';

    protected $description = 'Generate QA Inspector weekly reports for bots based on their individual schedules';

    public function __construct(
        private QAInspectorService $qaInspectorService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $specificBotId = $this->option('bot');
        $forceGenerate = $this->option('force');
        $weekStart = $this->option('week')
            ? Carbon::parse($this->option('week'))->startOfWeek()
            : Carbon::now()->subWeek()->startOfWeek();

        $this->info("Generating reports for week starting: {$weekStart->toDateString()}");

        if ($forceGenerate) {
            $this->info('Force mode enabled - bypassing schedule check');
        }

        $query = Bot::where('qa_inspector_enabled', true);

        if ($specificBotId) {
            $query->where('id', $specificBotId);
        }

        $bots = $query->get();

        if ($bots->isEmpty()) {
            $this->warn('No bots with QA Inspector enabled');
            return 0;
        }

        $this->info("Found {$bots->count()} bot(s) with QA Inspector enabled");

        $now = Carbon::now();
        $dispatchedCount = 0;
        $skippedCount = 0;

        foreach ($bots as $bot) {
            // Check if bot's schedule matches current time (unless force mode)
            if (!$forceGenerate && !$this->qaInspectorService->isScheduleMatching($bot, $now)) {
                $schedule = $this->qaInspectorService->getReportSchedule($bot);
                $this->line("  Skipped Bot #{$bot->id} ({$bot->name}) - schedule: {$schedule}");
                $skippedCount++;
                continue;
            }

            $this->line("  Dispatching report job for Bot #{$bot->id}: {$bot->name}");
            GenerateWeeklyReportJob::dispatch($bot, $weekStart);
            $dispatchedCount++;
        }

        $this->newLine();
        $this->info("Report generation summary:");
        $this->line("  - Dispatched: {$dispatchedCount}");
        $this->line("  - Skipped (schedule not matching): {$skippedCount}");

        if ($dispatchedCount > 0) {
            $this->info('Report jobs dispatched successfully');
        } elseif ($skippedCount > 0) {
            $this->info('No bots matched their schedule at this time');
        }

        return 0;
    }
}
