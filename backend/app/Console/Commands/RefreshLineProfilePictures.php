<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Services\LINEService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshLineProfilePictures extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'profiles:refresh-line-pictures
                            {--days=30 : Only refresh profiles active within this many days}
                            {--batch-size=50 : Number of profiles to process per batch}
                            {--delay=1000 : Milliseconds to wait between batches}
                            {--dry-run : Show what would be refreshed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Refresh LINE profile picture URLs for active users';

    protected int $total = 0;

    protected int $processed = 0;

    protected int $updated = 0;

    protected int $skipped = 0;

    protected int $cleared = 0;

    protected int $errors = 0;

    protected Carbon $startTime;

    /**
     * Execute the console command.
     */
    public function handle(LINEService $lineService): int
    {
        $this->startTime = now();
        $days = (int) $this->option('days');
        $batchSize = (int) $this->option('batch-size');
        $delayMs = (int) $this->option('delay');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info("Refreshing LINE profile pictures for users active in last {$days} days...");

        // Build query for active LINE profiles with valid bot access
        $query = CustomerProfile::query()
            ->where('channel_type', 'line')
            ->whereNotNull('last_interaction_at')
            ->where('last_interaction_at', '>=', now()->subDays($days))
            ->whereHas('conversations', function ($q) {
                $q->whereHas('bot', function ($bq) {
                    $bq->where('channel_type', 'line')
                        ->whereNotNull('channel_access_token');
                });
            });

        $this->total = $query->count();

        if ($this->total === 0) {
            $this->info('No active LINE profiles found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$this->total} profiles to refresh.");

        if ($dryRun) {
            $this->displayDryRunInfo($query, $days);

            return Command::SUCCESS;
        }

        // Process in batches with progress bar
        $progressBar = $this->output->createProgressBar($this->total);
        $progressBar->start();

        $query->with(['conversations' => function ($q) {
            $q->whereHas('bot', function ($bq) {
                $bq->where('channel_type', 'line')
                    ->whereNotNull('channel_access_token');
            })
                ->with(['bot:id,channel_access_token,channel_type'])
                ->orderByDesc('last_message_at')
                ->limit(1);
        }])
            ->chunkById($batchSize, function ($profiles) use ($lineService, $delayMs, $progressBar) {
                foreach ($profiles as $profile) {
                    $this->refreshProfile($profile, $lineService);
                    $this->processed++;
                    $progressBar->advance();
                }

                // Rate limiting delay between batches
                usleep($delayMs * 1000);
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->logSummary();

        return Command::SUCCESS;
    }

    /**
     * Refresh a single profile from LINE API.
     */
    protected function refreshProfile(CustomerProfile $profile, LINEService $lineService): bool
    {
        $bot = $profile->conversations->first()?->bot;

        if (! $bot) {
            $this->skipped++;
            Log::debug('LINE profile refresh: No bot found', [
                'profile_id' => $profile->id,
                'external_id' => $profile->external_id,
            ]);

            return false;
        }

        try {
            $lineProfile = $lineService->getProfile($bot, $profile->external_id);

            // Check if we have valid profile data (at least displayName should be present)
            $hasValidProfile = ! empty($lineProfile['displayName']) || ! empty($lineProfile['pictureUrl']);

            if (! $hasValidProfile) {
                // API returned empty/invalid data - skip without modifying
                $this->skipped++;
                Log::debug('LINE profile refresh: Empty response from API', [
                    'profile_id' => $profile->id,
                    'external_id' => $profile->external_id,
                ]);

                return false;
            }

            // Determine if picture_url should be cleared (LINE returned null/empty)
            $newPictureUrl = ! empty($lineProfile['pictureUrl']) ? $lineProfile['pictureUrl'] : null;
            $pictureWasCleared = $profile->picture_url !== null && $newPictureUrl === null;

            // Always update profile with latest data from LINE
            $profile->update([
                'picture_url' => $newPictureUrl,
                'display_name' => $lineProfile['displayName'] ?? $profile->display_name,
                'metadata' => array_merge(
                    $profile->metadata ?? [],
                    ['status_message' => $lineProfile['statusMessage'] ?? null]
                ),
            ]);

            if ($pictureWasCleared) {
                $this->cleared++;
                Log::debug('LINE profile picture cleared (expired or removed by user)', [
                    'profile_id' => $profile->id,
                    'external_id' => $profile->external_id,
                ]);
            } else {
                $this->updated++;
            }

            return true;

        } catch (\Exception $e) {
            $this->errors++;
            Log::warning('LINE profile refresh failed', [
                'profile_id' => $profile->id,
                'external_id' => $profile->external_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Display dry run information.
     */
    protected function displayDryRunInfo($query, int $days): void
    {
        $this->newLine();
        $this->info('Profiles by activity period:');

        $breakdown = [
            ['Last 7 days', (clone $query)->where('last_interaction_at', '>=', now()->subDays(7))->count()],
            ['Last 14 days', (clone $query)->where('last_interaction_at', '>=', now()->subDays(14))->count()],
            ["Last {$days} days", $this->total],
        ];

        $this->table(['Period', 'Count'], $breakdown);
    }

    /**
     * Log and display summary.
     */
    protected function logSummary(): void
    {
        $duration = $this->startTime->diffInSeconds(now());

        $summary = [
            ['Total profiles', $this->total],
            ['Processed', $this->processed],
            ['Updated', $this->updated],
            ['Cleared (expired URLs)', $this->cleared],
            ['Skipped', $this->skipped],
            ['Errors', $this->errors],
            ['Duration', "{$duration} seconds"],
        ];

        Log::info('LINE profile picture refresh completed', [
            'total' => $this->total,
            'processed' => $this->processed,
            'updated' => $this->updated,
            'cleared' => $this->cleared,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'duration_seconds' => $duration,
        ]);

        $this->info('Summary:');
        $this->table(['Metric', 'Value'], $summary);
    }
}
