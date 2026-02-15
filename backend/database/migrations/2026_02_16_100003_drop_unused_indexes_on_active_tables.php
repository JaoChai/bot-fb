<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop unused indexes (idx_scan = 0) on tables that have data.
 *
 * These indexes have never been used by any query since database statistics reset.
 *
 * Tables: second_ai_logs (2,285 rows), activity_logs, lead_recovery_logs,
 *         flow_audit_logs, semantic_routes
 *
 * Total: 6 indexes, ~216 KB storage recovered.
 *
 * NOT dropped (with reason):
 * - document_chunks_content_fts: Used by KeywordSearchService (@@, ts_rank_cd)
 * - customer_profiles_external_id_channel_type_unique: UNIQUE constraint for data integrity
 * - idx_conversations_webhook_lookup: New Phase 1 index, query planner may not have chosen it yet
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
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // second_ai_logs (2,285 rows) — 2 indexes with 0 scans
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS second_ai_logs_bot_id_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS second_ai_logs_overall_score_index');

        // activity_logs — 1 index with 0 scans
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS activity_logs_type_index');

        // lead_recovery_logs — 1 index with 0 scans
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS lead_recovery_logs_bot_id_sent_at_index');

        // flow_audit_logs — 1 index with 0 scans
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS flow_audit_logs_flow_id_created_at_index');

        // semantic_routes — 1 index with 0 scans
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS semantic_routes_intent_language_is_active_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // second_ai_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS second_ai_logs_bot_id_created_at_index ON second_ai_logs (bot_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS second_ai_logs_overall_score_index ON second_ai_logs (overall_score)');

        // activity_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS activity_logs_type_index ON activity_logs (type)');

        // lead_recovery_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS lead_recovery_logs_bot_id_sent_at_index ON lead_recovery_logs (bot_id, sent_at)');

        // flow_audit_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS flow_audit_logs_flow_id_created_at_index ON flow_audit_logs (flow_id, created_at)');

        // semantic_routes
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS semantic_routes_intent_language_is_active_index ON semantic_routes (intent, language, is_active)');
    }
};
