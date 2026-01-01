<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds performance indexes for chat system optimization.
     */
    public function up(): void
    {
        // Composite index for common filter + sort pattern in conversation list
        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conv_bot_status_last_msg
            ON conversations (bot_id, status, last_message_at DESC)
        ');

        // Index for creation date sorting
        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conv_bot_created
            ON conversations (bot_id, created_at DESC)
        ');

        // Index for message sender counting/filtering
        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_msg_conv_sender
            ON messages (conversation_id, sender)
        ');

        // Full-text search on customer_profiles for faster search
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cp_fulltext_search
            ON customer_profiles
            USING gin(to_tsvector('simple',
                coalesce(display_name, '') || ' ' ||
                coalesce(email, '') || ' ' ||
                coalesce(phone, '')
            ))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conv_bot_status_last_msg');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conv_bot_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_msg_conv_sender');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_cp_fulltext_search');
    }
};
