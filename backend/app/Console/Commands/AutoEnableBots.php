<?php

namespace App\Console\Commands;

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoEnableBots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversations:auto-enable-bots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-enable bot mode for conversations that have reached their timeout';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $conversations = Conversation::needsBotAutoEnable()->get();

        if ($conversations->isEmpty()) {
            $this->info('No conversations need bot auto-enable.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($conversations as $conversation) {
            $conversation->update([
                'is_handover' => false,
                'status' => 'active',
                'bot_auto_enable_at' => null,
            ]);

            // Broadcast the update for real-time sync
            broadcast(new ConversationUpdated($conversation->fresh()))->toOthers();

            $count++;

            Log::info('Bot auto-enabled for conversation', [
                'conversation_id' => $conversation->id,
                'bot_id' => $conversation->bot_id,
            ]);
        }

        $this->info("Auto-enabled bot for {$count} conversation(s).");

        return self::SUCCESS;
    }
}
