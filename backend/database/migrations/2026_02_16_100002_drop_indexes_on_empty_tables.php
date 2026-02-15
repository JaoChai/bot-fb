<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop indexes on tables that have 0 rows (unused features).
 *
 * Tables: qa_evaluation_logs, injection_attempts_log, improvement_suggestions,
 *         bot_hitl_settings, bot_aggregation_settings, bot_response_hours,
 *         bot_limits, notifications
 *
 * Total: 15 indexes, ~120 KB storage recovered.
 * Risk: Zero — all tables are empty.
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

        // qa_evaluation_logs (0 rows) — 5 indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_qa_eval_bot_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_qa_eval_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_qa_eval_issue_type');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_qa_eval_created_single');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_qa_eval_flagged');

        // injection_attempts_log (0 rows) — 3 indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS injection_attempts_log_bot_id_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS injection_attempts_log_action_taken_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS injection_attempts_log_risk_score_index');

        // improvement_suggestions (0 rows) — 2 indexes
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS improvement_suggestions_session_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS improvement_suggestions_type_index');

        // bot_hitl_settings, bot_aggregation_settings, bot_response_hours, bot_limits (0 rows)
        // These are UNIQUE constraints — must use ALTER TABLE
        DB::statement('ALTER TABLE bot_hitl_settings DROP CONSTRAINT IF EXISTS bot_hitl_settings_bot_setting_id_unique');
        DB::statement('ALTER TABLE bot_aggregation_settings DROP CONSTRAINT IF EXISTS bot_aggregation_settings_bot_setting_id_unique');
        DB::statement('ALTER TABLE bot_response_hours DROP CONSTRAINT IF EXISTS bot_response_hours_bot_setting_id_unique');
        DB::statement('ALTER TABLE bot_limits DROP CONSTRAINT IF EXISTS bot_limits_bot_setting_id_unique');

        // notifications (0 rows) — 1 index (redundant: covered by 3-column index with read_at)
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS notifications_notifiable_type_notifiable_id_index');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // qa_evaluation_logs
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_qa_eval_bot_id ON qa_evaluation_logs (bot_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_qa_eval_created ON qa_evaluation_logs (bot_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_qa_eval_issue_type ON qa_evaluation_logs (issue_type)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_qa_eval_created_single ON qa_evaluation_logs (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_qa_eval_flagged ON qa_evaluation_logs (bot_id, is_flagged, created_at)');

        // injection_attempts_log
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS injection_attempts_log_bot_id_created_at_index ON injection_attempts_log (bot_id, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS injection_attempts_log_action_taken_index ON injection_attempts_log (action_taken)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS injection_attempts_log_risk_score_index ON injection_attempts_log (risk_score)');

        // improvement_suggestions
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS improvement_suggestions_session_id_index ON improvement_suggestions (session_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS improvement_suggestions_type_index ON improvement_suggestions (type)');

        // Unique constraints
        DB::statement('ALTER TABLE bot_hitl_settings ADD CONSTRAINT bot_hitl_settings_bot_setting_id_unique UNIQUE (bot_setting_id)');
        DB::statement('ALTER TABLE bot_aggregation_settings ADD CONSTRAINT bot_aggregation_settings_bot_setting_id_unique UNIQUE (bot_setting_id)');
        DB::statement('ALTER TABLE bot_response_hours ADD CONSTRAINT bot_response_hours_bot_setting_id_unique UNIQUE (bot_setting_id)');
        DB::statement('ALTER TABLE bot_limits ADD CONSTRAINT bot_limits_bot_setting_id_unique UNIQUE (bot_setting_id)');

        // notifications
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_notifiable_type_notifiable_id_index ON notifications (notifiable_type, notifiable_id)');
    }
};
