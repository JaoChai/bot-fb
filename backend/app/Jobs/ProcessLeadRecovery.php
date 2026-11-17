<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Autonomous lead recovery was retired in B1 (card B1, plan brief "Task B1").
 *
 * The class stays so already-queued instances and the hourly schedule entry
 * (removed by a later card) resolve to a no-op: no bot lookup, no model call,
 * no channel send.
 */
class ProcessLeadRecovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job — retired: does nothing.
     */
    public function handle(): void
    {
        //
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('LeadRecovery: Job failed permanently', [
            'error' => $exception->getMessage(),
            ...(! app()->environment('production') ? ['trace' => $exception->getTraceAsString()] : []),
        ]);
    }
}
