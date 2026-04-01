<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop unused indexes (idx_scan = 0 in production pg_stat_user_indexes).
 *
 * Indexes verified unused via production stats before being dropped.
 * Estimated storage recovered: ~3.2 MB from messages alone.
 *
 * Tables affected: messages, rag_cache, conversations, second_ai_logs, orders, sessions.
 */
return new class extends Migration
{
    /**
     * Disable transaction wrapping.
     * Required because DROP INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // messages — 3.2 MB unused analytics index
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_analytics');

        // rag_cache — 2 unused indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS rag_cache_query_normalized_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS rag_cache_bot_id_expires_at_index');

        // conversations — 4 unused indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_bot_id_external_customer_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_external_customer_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conv_bot_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conversations_channel');

        // second_ai_logs — 2 unused indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS second_ai_logs_flow_id_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS second_ai_logs_was_modified_index');

        // orders — 2 unused indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_bot_id_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_status_index');

        // sessions — 2 unused indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sessions_last_activity_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS sessions_user_id_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // messages
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_messages_analytics ON messages (conversation_id, sender, created_at)');

        // rag_cache
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS rag_cache_query_normalized_index ON rag_cache (query_normalized)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS rag_cache_bot_id_expires_at_index ON rag_cache (bot_id, expires_at)');

        // conversations
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_bot_id_external_customer_id_index ON conversations (bot_id, external_customer_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_external_customer_id_index ON conversations (external_customer_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conv_bot_created ON conversations (bot_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conversations_channel ON conversations (channel_type)');

        // second_ai_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS second_ai_logs_flow_id_created_at_index ON second_ai_logs (flow_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS second_ai_logs_was_modified_index ON second_ai_logs (was_modified)');

        // orders
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_bot_id_created_at_index ON orders (bot_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_status_index ON orders (status)');

        // sessions
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS sessions_last_activity_index ON sessions (last_activity)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS sessions_user_id_index ON sessions (user_id)');
    }
};
