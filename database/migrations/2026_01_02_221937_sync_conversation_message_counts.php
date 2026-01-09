<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Sync message_count with actual message count for all conversations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL supports table aliases in UPDATE
            $updated = DB::update("
                UPDATE conversations c
                SET message_count = (
                    SELECT COUNT(*)
                    FROM messages m
                    WHERE m.conversation_id = c.id
                )
                WHERE c.message_count != (
                    SELECT COUNT(*)
                    FROM messages m
                    WHERE m.conversation_id = c.id
                )
            ");

            Log::info("Synced message_count for {$updated} conversations");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support table aliases in UPDATE
            $updated = DB::update("
                UPDATE conversations
                SET message_count = (
                    SELECT COUNT(*)
                    FROM messages
                    WHERE messages.conversation_id = conversations.id
                )
                WHERE message_count != (
                    SELECT COUNT(*)
                    FROM messages
                    WHERE messages.conversation_id = conversations.id
                )
            ");

            Log::info("Synced message_count for {$updated} conversations");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse - counts are now correct
    }
};
