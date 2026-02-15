<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add partial composite index for webhook conversation lookup.
 *
 * This index supports the frequent query pattern in WebhookService
 * that looks up active conversations by bot_id, external_customer_id,
 * channel_type, and status, filtering out soft-deleted records.
 */
return new class extends Migration
{
    /**
     * Disable transaction wrapping for this migration.
     * Required because CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // Only create on PostgreSQL (not SQLite for testing)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conversations_webhook_lookup
                ON conversations (bot_id, external_customer_id, channel_type, status)
                WHERE deleted_at IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conversations_webhook_lookup');
        }
    }
};
