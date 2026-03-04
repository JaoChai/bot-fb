<?php

namespace App\Console\Commands;

use App\Services\SemanticCacheService;
use Illuminate\Console\Command;

class CleanupRagCache extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'rag:cleanup-cache
                            {--bot= : Only cleanup for a specific bot ID}
                            {--dry-run : Show what would be deleted without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up expired RAG cache entries';

    /**
     * Execute the console command.
     */
    public function handle(SemanticCacheService $cacheService): int
    {
        $botId = $this->option('bot');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        if (! $cacheService->isEnabled()) {
            $this->warn('Semantic cache is disabled. Nothing to clean up.');

            return Command::SUCCESS;
        }

        // If specific bot requested
        if ($botId) {
            if ($dryRun) {
                $stats = $cacheService->getStats((int) $botId);
                $this->info("Bot {$botId} has {$stats['expired_entries']} expired entries.");

                return Command::SUCCESS;
            }

            $deleted = $cacheService->clearForBot((int) $botId);
            $this->info("Cleared {$deleted} cache entries for bot {$botId}.");

            return Command::SUCCESS;
        }

        // Otherwise, cleanup all expired entries
        if ($dryRun) {
            $count = \App\Models\RagCache::where('expires_at', '<', now())->count();
            $this->info("Found {$count} expired cache entries that would be deleted.");

            return Command::SUCCESS;
        }

        $deleted = $cacheService->cleanup();
        $this->info("Cleaned up {$deleted} expired cache entries.");

        return Command::SUCCESS;
    }
}
