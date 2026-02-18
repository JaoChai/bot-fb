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

// Failed jobs cleanup - daily at 3:30am
// Deletes failed jobs older than 7 days (168 hours) to manage storage
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->at('03:30')
    ->withoutOverlapping()
    ->runInBackground();

// LINE profile picture URL refresh - daily at 4am
// Refreshes expired LINE profile pictures for active users (30-day window)
// LINE CDN URLs expire periodically, this ensures we have valid URLs
Schedule::command('profiles:refresh-line-pictures')
    ->daily()
    ->at('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Prune expired cache entries - daily at 02:00
Schedule::command('cache:prune-stale-tags')->daily()->at('02:00')
    ->withoutOverlapping()->runInBackground();

// Clean old activity logs (>90 days) - weekly Sunday 03:30
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('activity_logs')
        ->where('created_at', '<', now()->subDays(90))
        ->delete();
})->weekly()->sundays()->at('03:30')
    ->name('activity-logs-cleanup')->withoutOverlapping();
