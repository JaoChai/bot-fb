<?php

use App\Jobs\ProcessLeadRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-enable bot for conversations that have reached their timeout
// Runs every minute to check for conversations needing bot re-enabled
Schedule::command('conversations:auto-enable-bots')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Clean up expired RAG cache entries
// Runs every 6 hours as configured in rag.semantic_cache.cleanup_interval_hours
Schedule::command('rag:cleanup-cache')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Process lead recovery to re-engage inactive leads
// Runs hourly to check for leads needing follow-up
Schedule::job(new ProcessLeadRecovery)->hourly()->name('lead-recovery');

// Generate QA Inspector weekly reports
// Runs hourly to check each bot's individual schedule
// Each bot can configure their own report schedule (e.g., "monday_00:00", "friday_18:00")
Schedule::command('qa:generate-weekly-reports')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// QA Log cleanup - daily at 3am
// Deletes evaluation logs older than 90 days to manage storage
Schedule::command('qa:cleanup-old-logs --days=90')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping()
    ->runInBackground();
