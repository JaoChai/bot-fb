<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every withoutOverlapping entry in routes/console.php must carry an explicit,
 * short mutex expiry. withoutOverlapping() with no argument sets expiresAt to
 * the framework default of 1440 minutes, and a scheduler process killed by
 * SIGTERM (e.g. a Railway container swap mid-tick on 2026-11-13) never
 * releases its mutex — the command is then silently skipped for a full day
 * (the cache_locks rows expiring exactly 24h after the deploy).
 *
 * Cap rules (expiry must exceed the command's worst realistic run time but
 * stay far below the 24h default):
 *  - entries whose cron minute field is "*" (every minute) or a step
 *    pattern (every N minutes, N <= 5): expiresAt <= 5 minutes;
 *  - every other entry: expiresAt <= 60 minutes.
 *
 * This iterates ALL registered events — it never hard-codes the list of
 * scheduled commands — so any future schedule entry is guarded too.
 */
class ScheduleLockExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** Cap in minutes for entries repeating at most every 5 minutes. */
    private const FAST_FREQUENCY_CAP_MINUTES = 5;

    /** Cap in minutes for every other entry. */
    private const DEFAULT_CAP_MINUTES = 60;

    public function test_every_without_overlapping_entry_has_an_explicit_short_expiry(): void
    {
        // Boots the console kernel, which loads routes/console.php.
        $this->artisan('schedule:list')->assertExitCode(0);

        $events = app(Schedule::class)->events();
        $this->assertNotEmpty($events, 'routes/console.php must define scheduled tasks');

        $offenders = [];
        foreach ($events as $event) {
            if (! $event->withoutOverlapping) {
                continue;
            }

            $cap = $this->repeatsAtLeastEveryFiveMinutes($event)
                ? self::FAST_FREQUENCY_CAP_MINUTES
                : self::DEFAULT_CAP_MINUTES;

            if ($event->expiresAt > $cap) {
                $offenders[] = sprintf(
                    '%s (cron "%s"): withoutOverlapping expiresAt=%d min > cap %d min',
                    $this->displayName($event),
                    $event->expression,
                    $event->expiresAt,
                    $cap,
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Scheduled entries still carry the framework-default 24h mutex expiry\n"
            ."or an over-long explicit one: a SIGTERM-killed run (redeploy mid-tick)\n"
            ."blocks each of these until the mutex expires. Give every withoutOverlapping\n"
            ."an explicit expiry in routes/console.php — <= 5 min for every-minute /\n"
            ."every-5-minute entries, <= 60 min for the rest.\n\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * For closure events getSummaryForDisplay() yields the ->name() string.
     * Command events have their command string compiled by schedule:list into
     * its shell form ("'...php' 'artisan' NAME > '/dev/null' ..."), so unwrap
     * NAME for a readable failure message.
     */
    private function displayName(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            return $event->getSummaryForDisplay();
        }

        if (preg_match("#'artisan'\s+(.+?)(?:\s+>|$)#", (string) $event->command, $matches) === 1) {
            return 'php artisan '.trim($matches[1]);
        }

        return (string) $event->command;
    }

    /**
     * True when the cron repeats at least every 5 minutes: the minute field is
     * "*" (every minute) or a step pattern (every N minutes, N <= 5). Any other
     * minute field (fixed minutes, lists, ranges) repeats slower than every
     * 5 minutes, so the default cap applies.
     */
    private function repeatsAtLeastEveryFiveMinutes(Event $event): bool
    {
        $minuteField = explode(' ', trim($event->expression))[0];

        if ($minuteField === '*') {
            return true;
        }

        if (preg_match('#^\*/(\d+)$#', $minuteField, $matches) === 1) {
            return (int) $matches[1] <= 5;
        }

        return false;
    }
}
