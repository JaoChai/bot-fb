<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 2 · plan #6 (timing half): drop reply_phase_timings rows older than
 * the retention window (card: 30 days). Scheduled daily in routes/console.php.
 */
class RepliesPruneTimings extends Command
{
    protected $signature = 'replies:prune-timings {--days=30 : Delete rows older than this many days}';

    protected $description = 'Delete reply_phase_timings rows older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = DB::table('reply_phase_timings')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} reply_phase_timings rows older than {$days} days.");

        return self::SUCCESS;
    }
}
