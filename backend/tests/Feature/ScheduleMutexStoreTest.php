<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec item 3: the scheduler's event mutex and scheduling mutex must live on
 * the database store. FailoverStore::lock() only hands back the FIRST store's
 * lock object, so a Redis failure inside acquire() is not failed over — the
 * 5+ withoutOverlapping tasks must not depend on Redis at all.
 */
class ScheduleMutexStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_event_and_scheduling_mutexes_use_the_database_store(): void
    {
        // Boots the console kernel, which loads routes/console.php where
        // Schedule::useCache() is called.
        $this->artisan('schedule:list')->assertExitCode(0);

        $scheduler = app(Schedule::class);
        $this->assertNotEmpty($scheduler->events(), 'routes/console.php must define scheduled tasks');

        $eventMutex = $this->schedulerMutex($scheduler, 'eventMutex');
        $schedulingMutex = $this->schedulerMutex($scheduler, 'schedulingMutex');

        $this->assertInstanceOf(CacheEventMutex::class, $eventMutex);
        $this->assertSame('database', $eventMutex->store, 'the withoutOverlapping event mutex must use the database store');
        $this->assertInstanceOf(CacheSchedulingMutex::class, $schedulingMutex);
        $this->assertSame('database', $schedulingMutex->store, 'the on-one-schedule scheduling mutex must use the database store');
    }

    private function schedulerMutex(Schedule $scheduler, string $property): object
    {
        return (new \ReflectionProperty($scheduler, $property))->getValue($scheduler);
    }
}
