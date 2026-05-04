<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:clean';

    protected $description = 'Delete idempotency keys older than 24 hours';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subHours(24))
            ->delete();

        $this->info("Deleted {$deleted} expired idempotency keys.");

        return self::SUCCESS;
    }
}
