<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateVipStatusJob;
use App\Models\Order;
use Illuminate\Console\Command;

class VipBackfillCommand extends Command
{
    protected $signature = 'vip:backfill {--dry-run : Only report candidates, do not dispatch jobs}';

    protected $description = 'Backfill VIP evaluation for customers who meet the threshold of completed orders in the configured window';

    public function handle(): int
    {
        $threshold = (int) config('rag.vip.threshold');
        $windowMonths = (int) config('rag.vip.window_months');
        $since = now()->subMonths($windowMonths);

        $candidateIds = Order::where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->whereNotNull('customer_profile_id')
            ->groupBy('customer_profile_id')
            ->selectRaw('customer_profile_id, COUNT(*) as c')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->pluck('customer_profile_id');

        $count = $candidateIds->count();

        $this->info("Found {$count} candidate customer(s) at or above threshold ({$threshold} orders in last {$windowMonths} months).");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no jobs dispatched.');

            return self::SUCCESS;
        }

        foreach ($candidateIds as $id) {
            EvaluateVipStatusJob::dispatch((int) $id);
        }

        $this->info("Dispatched {$count} EvaluateVipStatusJob(s).");

        return self::SUCCESS;
    }
}
