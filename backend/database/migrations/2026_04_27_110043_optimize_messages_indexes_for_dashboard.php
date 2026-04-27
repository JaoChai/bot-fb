<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optimize messages indexes:
 * 1. Add partial index for dashboard cost query (covers sender='bot' + cost IS NOT NULL)
 * 2. Drop redundant messages_sender_index (sender has only 3 values — not selective)
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Partial covering index for dashboard cost aggregation query
        // Filters: sender='bot' AND cost IS NOT NULL
        // Covers: conversation_id (join), created_at (date range), cost (SUM)
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_messages_bot_cost
            ON messages (conversation_id, created_at, cost)
            WHERE sender = \'bot\' AND cost IS NOT NULL');

        // Drop low-cardinality sender index (3 values: user/bot/agent)
        // idx_msg_conv_sender (conversation_id, sender) is the better alternative
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_sender_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_bot_cost');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_sender_index ON messages (sender)');
    }
};
