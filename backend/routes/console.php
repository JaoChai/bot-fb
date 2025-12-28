<?php

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
