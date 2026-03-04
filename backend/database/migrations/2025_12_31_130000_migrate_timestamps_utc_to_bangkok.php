<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate all timestamps from UTC to Asia/Bangkok (+7 hours)
     *
     * IMPORTANT: This migration assumes all existing timestamps were stored in UTC
     * and converts them to Bangkok time (UTC+7)
     */
    public function up(): void
    {
        // Define all tables and their timestamp columns
        $tables = [
            'activity_logs' => ['created_at', 'updated_at'],
            'agent_cost_usage' => ['created_at', 'updated_at'],
            'bot_settings' => ['created_at', 'updated_at'],
            'bots' => ['created_at', 'updated_at', 'deleted_at', 'last_active_at'],
            'conversations' => ['created_at', 'updated_at', 'deleted_at', 'last_message_at', 'bot_auto_enable_at', 'context_cleared_at'],
            'customer_profiles' => ['created_at', 'updated_at', 'first_interaction_at', 'last_interaction_at'],
            'document_chunks' => ['created_at', 'updated_at'],
            'documents' => ['created_at', 'updated_at', 'deleted_at'],
            'evaluation_messages' => ['created_at', 'updated_at'],
            'evaluation_reports' => ['created_at', 'updated_at'],
            'evaluation_test_cases' => ['created_at', 'updated_at'],
            'evaluations' => ['created_at', 'updated_at', 'deleted_at', 'started_at', 'completed_at'],
            'failed_jobs' => ['failed_at'],
            'flow_knowledge_base' => ['created_at', 'updated_at'],
            'flows' => ['created_at', 'updated_at', 'deleted_at'],
            'improvement_sessions' => ['created_at', 'updated_at', 'started_at', 'completed_at'],
            'improvement_suggestions' => ['created_at', 'updated_at', 'applied_at'],
            'knowledge_bases' => ['created_at', 'updated_at', 'deleted_at'],
            'messages' => ['created_at', 'updated_at'],
            'password_reset_tokens' => ['created_at'],
            'personal_access_tokens' => ['created_at', 'updated_at', 'last_used_at', 'expires_at'],
            'semantic_routes' => ['created_at', 'updated_at'],
            'user_settings' => ['created_at', 'updated_at'],
            'users' => ['created_at', 'updated_at', 'email_verified_at', 'subscription_expires_at'],
        ];

        DB::transaction(function () use ($tables) {
            foreach ($tables as $table => $columns) {
                // Check if table exists
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    // Check if column exists
                    if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                        continue;
                    }

                    // Add 7 hours to convert UTC to Bangkok time
                    $driver = DB::getDriverName();

                    if ($driver === 'pgsql') {
                        DB::statement("
                            UPDATE {$table}
                            SET {$column} = {$column} + INTERVAL '7 hours'
                            WHERE {$column} IS NOT NULL
                        ");
                    } elseif ($driver === 'sqlite') {
                        DB::statement("
                            UPDATE {$table}
                            SET {$column} = datetime({$column}, '+7 hours')
                            WHERE {$column} IS NOT NULL
                        ");
                    }
                }
            }
        });
    }

    /**
     * Reverse the migration (Bangkok to UTC, -7 hours)
     */
    public function down(): void
    {
        $tables = [
            'activity_logs' => ['created_at', 'updated_at'],
            'agent_cost_usage' => ['created_at', 'updated_at'],
            'bot_settings' => ['created_at', 'updated_at'],
            'bots' => ['created_at', 'updated_at', 'deleted_at', 'last_active_at'],
            'conversations' => ['created_at', 'updated_at', 'deleted_at', 'last_message_at', 'bot_auto_enable_at', 'context_cleared_at'],
            'customer_profiles' => ['created_at', 'updated_at', 'first_interaction_at', 'last_interaction_at'],
            'document_chunks' => ['created_at', 'updated_at'],
            'documents' => ['created_at', 'updated_at', 'deleted_at'],
            'evaluation_messages' => ['created_at', 'updated_at'],
            'evaluation_reports' => ['created_at', 'updated_at'],
            'evaluation_test_cases' => ['created_at', 'updated_at'],
            'evaluations' => ['created_at', 'updated_at', 'deleted_at', 'started_at', 'completed_at'],
            'failed_jobs' => ['failed_at'],
            'flow_knowledge_base' => ['created_at', 'updated_at'],
            'flows' => ['created_at', 'updated_at', 'deleted_at'],
            'improvement_sessions' => ['created_at', 'updated_at', 'started_at', 'completed_at'],
            'improvement_suggestions' => ['created_at', 'updated_at', 'applied_at'],
            'knowledge_bases' => ['created_at', 'updated_at', 'deleted_at'],
            'messages' => ['created_at', 'updated_at'],
            'password_reset_tokens' => ['created_at'],
            'personal_access_tokens' => ['created_at', 'updated_at', 'last_used_at', 'expires_at'],
            'semantic_routes' => ['created_at', 'updated_at'],
            'user_settings' => ['created_at', 'updated_at'],
            'users' => ['created_at', 'updated_at', 'email_verified_at', 'subscription_expires_at'],
        ];

        DB::transaction(function () use ($tables) {
            foreach ($tables as $table => $columns) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                        continue;
                    }

                    // Subtract 7 hours to convert Bangkok time back to UTC
                    $driver = DB::getDriverName();

                    if ($driver === 'pgsql') {
                        DB::statement("
                            UPDATE {$table}
                            SET {$column} = {$column} - INTERVAL '7 hours'
                            WHERE {$column} IS NOT NULL
                        ");
                    } elseif ($driver === 'sqlite') {
                        DB::statement("
                            UPDATE {$table}
                            SET {$column} = datetime({$column}, '-7 hours')
                            WHERE {$column} IS NOT NULL
                        ");
                    }
                }
            }
        });
    }
};
