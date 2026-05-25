<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add composite index messages(conversation_id, webhook_event_id) to optimize
 * the LINE/Facebook webhook deduplication path. Existing composite index
 * messages(conversation_id, external_message_id) does NOT cover this query
 * because webhook_event_id is a different column.
 *
 * Affected sites (verified 2026-05-25 audit):
 *   app/Jobs/ProcessLINEWebhook.php:367, 373, 397, 402
 *   app/Jobs/ProcessFacebookWebhook.php (similar pattern)
 *
 * Uses CONCURRENTLY for zero-lock creation on production.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_messages_conv_webhook_event
            ON messages (conversation_id, webhook_event_id)
            WHERE webhook_event_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_conv_webhook_event');
    }
};
