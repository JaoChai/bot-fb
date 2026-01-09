<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds performance indexes for chat system optimization.
     * Note: Using regular CREATE INDEX (not CONCURRENTLY) to work within Laravel transactions.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Composite index for common filter + sort pattern in conversation list
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_conv_bot_status_last_msg
            ON conversations (bot_id, status, last_message_at DESC)
        ');

        // Index for creation date sorting
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_conv_bot_created
            ON conversations (bot_id, created_at DESC)
        ');

        // Index for message sender counting/filtering
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_msg_conv_sender
            ON messages (conversation_id, sender)
        ');

        // Full-text search on customer_profiles for faster search
        if ($driver === 'pgsql') {
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_cp_fulltext_search
                ON customer_profiles
                USING gin(to_tsvector('simple',
                    coalesce(display_name, '') || ' ' ||
                    coalesce(email, '') || ' ' ||
                    coalesce(phone, '')
                ))
            ");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support GIN indexes or to_tsvector - skip for testing
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::statement('DROP INDEX IF EXISTS idx_conv_bot_status_last_msg');
        DB::statement('DROP INDEX IF EXISTS idx_conv_bot_created');
        DB::statement('DROP INDEX IF EXISTS idx_msg_conv_sender');

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_cp_fulltext_search');
        } elseif ($driver === 'sqlite') {
            // Skip - index was not created for SQLite
        }
    }
};
