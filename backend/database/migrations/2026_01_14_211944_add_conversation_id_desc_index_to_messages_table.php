<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add composite index for optimizing window function queries.
 *
 * This index supports the CTE query in ConversationService::getAllCounts()
 * which uses: ROW_NUMBER() OVER (PARTITION BY conversation_id ORDER BY id DESC)
 *
 * Expected impact: 20-30% faster query execution for conversation list stats.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only create on PostgreSQL (not SQLite for testing)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_messages_conv_id_desc
                ON messages (conversation_id, id DESC)
            ');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_conv_id_desc');
        }
    }
};
