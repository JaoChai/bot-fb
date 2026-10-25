<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 2 · plan #6 (timing half): p50/p90/p99 per phase and per bot from
 * reply_phase_timings. Read-only; prune lives in replies:prune-timings.
 */
class RepliesTimingReport extends Command
{
    protected $signature = 'replies:timing-report {--days=7 : Look back this many days}';

    protected $description = 'Print p50/p90/p99 of reply phase timings per phase and per bot';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $query = DB::table('reply_phase_timings')->where('created_at', '>=', $since);
        $total = (clone $query)->count();

        $this->info("Reply phase timings — last {$days} days ({$total} rows)");
        $this->newLine();

        if ($total === 0) {
            $this->line('No rows in the window.');

            return self::SUCCESS;
        }

        $phases = [
            'aggregation_wait_ms',
            'queue_wait_ms',
            'router_ms',
            'intent_ms',
            'retrieval_ms',
            'primary_ms',
            'fallback_ms',
            'vip_retry_ms',
            'send_ms',
            'plugin_ms',
            'total_ms',
        ];

        $this->renderSection('ALL BOTS', $query, $phases, $total);

        foreach ((clone $query)->selectRaw('bot_id, count(*) as n')->groupBy('bot_id')->orderBy('bot_id')->get() as $botRow) {
            $botQuery = (clone $query)->where('bot_id', $botRow->bot_id);
            $this->renderSection("bot {$botRow->bot_id}", $botQuery, $phases, (int) $botRow->n);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $phases
     */
    private function renderSection(string $label, $query, array $phases, int $rowCount): void
    {
        $this->info("{$label} ({$rowCount} rows)");

        $rows = [];
        foreach ($phases as $phase) {
            $values = (clone $query)->whereNotNull($phase)->pluck($phase)->sort()->values()->all();

            if ($values === []) {
                $rows[] = [$phase, '-', '-', '-'];

                continue;
            }

            $rows[] = [
                $phase,
                $this->percentile($values, 50),
                $this->percentile($values, 90),
                $this->percentile($values, 99),
            ];
        }

        $this->table(['phase', 'p50', 'p90', 'p99'], $rows);
    }

    /**
     * Nearest-rank percentile over pre-sorted values.
     *
     * @param  array<int, int>  $sorted
     */
    private function percentile(array $sorted, int $p): int
    {
        $n = count($sorted);
        $index = (int) max(0, min($n - 1, (int) ceil($p / 100 * $n) - 1));

        return (int) $sorted[$index];
    }
}
