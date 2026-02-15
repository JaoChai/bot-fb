<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop redundant indexes that are covered by existing composite indexes.
 *
 * - conversations_bot_status_last_msg_idx: duplicate of idx_conv_bot_status_last_msg
 * - conversations_bot_id_index: single column covered by composite indexes
 * - conversations_bot_id_status_index: covered by idx_conversations_handover
 * - messages_conversation_id_index: single column covered by composite indexes
 */
return new class extends Migration
{
    /**
     * Disable transaction wrapping for this migration.
     * Required because DROP INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // Only drop on PostgreSQL (not SQLite for testing)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_bot_status_last_msg_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_bot_id_index');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_bot_id_status_index');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_conversation_id_index');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_bot_status_last_msg_idx ON conversations (bot_id, status, last_message_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_bot_id_index ON conversations (bot_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_bot_id_status_index ON conversations (bot_id, status)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_conversation_id_index ON messages (conversation_id)');
        }
    }
};
