<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncMessageCounts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'conversations:sync-message-counts
                            {--bot= : Only sync for a specific bot ID}
                            {--conversation= : Only sync a specific conversation ID}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Sync conversation message_count with actual message count in database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $botId = $this->option('bot');
        $conversationId = $this->option('conversation');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Build query to find mismatched conversations
        $query = DB::table('conversations as c')
            ->select([
                'c.id',
                'c.bot_id',
                'c.message_count as stored_count',
                DB::raw('(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) as actual_count'),
            ])
            ->whereNull('c.deleted_at')
            ->havingRaw('c.message_count != (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id)');

        if ($botId) {
            $query->where('c.bot_id', $botId);
        }

        if ($conversationId) {
            $query->where('c.id', $conversationId);
        }

        $mismatched = $query->get();

        if ($mismatched->isEmpty()) {
            $this->info('All conversation message counts are in sync!');
            return Command::SUCCESS;
        }

        $this->info("Found {$mismatched->count()} conversations with mismatched counts:");

        $headers = ['Conversation ID', 'Bot ID', 'Stored Count', 'Actual Count', 'Difference'];
        $rows = $mismatched->map(function ($row) {
            $diff = $row->stored_count - $row->actual_count;
            $diffStr = $diff > 0 ? "+{$diff}" : (string) $diff;
            return [
                $row->id,
                $row->bot_id,
                $row->stored_count,
                $row->actual_count,
                $diffStr,
            ];
        })->toArray();

        $this->table($headers, $rows);

        if ($dryRun) {
            $this->warn('Dry run complete. Run without --dry-run to apply fixes.');
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to sync these counts?')) {
            $this->info('Aborted.');
            return Command::SUCCESS;
        }

        // Update message_count to match actual count
        $updated = 0;
        foreach ($mismatched as $row) {
            Conversation::withoutTimestamps(function () use ($row, &$updated) {
                Conversation::where('id', $row->id)
                    ->update(['message_count' => $row->actual_count]);
                $updated++;
            });
        }

        $this->info("Successfully synced {$updated} conversations.");

        return Command::SUCCESS;
    }
}
