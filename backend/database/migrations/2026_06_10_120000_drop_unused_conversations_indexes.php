<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop two unused (idx_scan = 0) indexes on the hot conversations table.
 *
 * - idx_conversations_webhook_lookup (152 KB) — partial composite added
 *   2026-02-16; query planner never chose it (production pg_stat_user_indexes
 *   idx_scan=0 as of 2026-06-10).
 * - conversations_last_message_id_index (128 KB) — plain B-tree from the
 *   2026-04-01 last_message_id migration; the FK does not require it and it
 *   has 0 scans.
 *
 * Reduces write amplification on the most-updated table. The last_message_id
 * FOREIGN KEY constraint stays intact (Postgres does not need an index on it).
 *
 * NOT dropped: the messages composite indexes (open question — needs EXPLAIN
 * before any drop; explicitly out of scope for this round).
 */
return new class extends Migration
{
    /**
     * Disable transaction wrapping — DROP INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conversations_webhook_lookup');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_last_message_id_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Recreate with the exact original definitions. Note: if a CONCURRENTLY
        // create is interrupted, IF NOT EXISTS skips the leftover INVALID index on
        // re-run — drop it manually if a rollback ever fails mid-flight.
        DB::statement('
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conversations_webhook_lookup
            ON conversations (bot_id, external_customer_id, channel_type, status)
            WHERE deleted_at IS NULL
        ');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_last_message_id_index ON conversations (last_message_id)');
    }
};
