<?php

namespace App\Console\Commands;

use App\Models\QAEvaluationLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOldQALogs extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'qa:cleanup-old-logs
                            {--days=90 : Number of days to keep logs}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Delete QA evaluation logs older than specified days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = Carbon::now()->subDays($days);

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info("Looking for QA logs older than {$cutoffDate->toDateString()} ({$days} days)...");

        $query = QAEvaluationLog::where('created_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->info('No logs to delete.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would delete {$count} logs older than {$cutoffDate->toDateString()}");

            // Show breakdown by bot
            $breakdown = QAEvaluationLog::where('created_at', '<', $cutoffDate)
                ->selectRaw('bot_id, COUNT(*) as count')
                ->groupBy('bot_id')
                ->get();

            if ($breakdown->isNotEmpty()) {
                $this->newLine();
                $this->info('Breakdown by bot:');
                foreach ($breakdown as $row) {
                    $this->line("  Bot #{$row->bot_id}: {$row->count} logs");
                }
            }

            return Command::SUCCESS;
        }

        // Delete in chunks to avoid memory issues
        $deleted = 0;
        $chunkSize = 1000;

        $this->info("Deleting {$count} logs in chunks of {$chunkSize}...");

        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();

        QAEvaluationLog::where('created_at', '<', $cutoffDate)
            ->chunkById($chunkSize, function ($logs) use (&$deleted, $progressBar) {
                foreach ($logs as $log) {
                    $log->delete();
                    $deleted++;
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Deleted {$deleted} logs older than {$cutoffDate->toDateString()}");

        // Log the cleanup action
        Log::channel('qa_inspector')->info('QA logs cleanup completed', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoffDate->toDateString(),
            'days_retained' => $days,
        ]);

        return Command::SUCCESS;
    }
}
