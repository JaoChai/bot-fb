<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance optimization indexes for common query patterns.
     *
     * These indexes target:
     * - Analytics queries (messages table)
     * - Dashboard filters (conversations table)
     * - Customer engagement (customer_profiles table)
     * - Knowledge base search (documents table)
     */
    public function up(): void
    {
        // Messages analytics index for cost tracking queries
        // Supports: SELECT SUM(cost) WHERE sender='bot' AND created_at BETWEEN ...
        Schema::table('messages', function (Blueprint $table) {
            $table->index(
                ['conversation_id', 'sender', 'created_at', 'cost'],
                'idx_messages_analytics'
            );
        });

        // Conversations filter indexes for dashboard queries
        Schema::table('conversations', function (Blueprint $table) {
            // Handover queries: WHERE bot_id = ? AND is_handover = true AND status = ?
            $table->index(
                ['bot_id', 'is_handover', 'status'],
                'idx_conversations_handover'
            );

            // Assigned user queries: WHERE assigned_user_id = ? AND status = ?
            $table->index(
                ['assigned_user_id', 'status'],
                'idx_conversations_assigned'
            );

            // Channel type filter: WHERE channel_type = ? AND status = ?
            $table->index(
                ['channel_type', 'status'],
                'idx_conversations_channel'
            );
        });

        // Customer profiles engagement index
        // Supports sorting by interaction count for engagement dashboards
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->index(['interaction_count'], 'idx_customer_interaction');
        });

        // Documents status index for knowledge base queries
        // Supports: WHERE knowledge_base_id = ? AND status = 'completed'
        Schema::table('documents', function (Blueprint $table) {
            $table->index(
                ['knowledge_base_id', 'status'],
                'idx_documents_kb_status'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_analytics');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_handover');
            $table->dropIndex('idx_conversations_assigned');
            $table->dropIndex('idx_conversations_channel');
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropIndex('idx_customer_interaction');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_kb_status');
        });
    }
};
