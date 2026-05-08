<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PingDatabase extends Command
{
    protected $signature = 'db:ping';
    protected $description = 'Ping database to keep Neon compute warm';

    public function handle(): int
    {
        try {
            DB::selectOne('SELECT 1');
            $this->info('Database ping OK');
        } catch (\Throwable $e) {
            $this->error('Database ping failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
