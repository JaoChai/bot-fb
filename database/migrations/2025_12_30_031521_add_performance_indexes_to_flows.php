<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Performance indexes for Flows page optimization:
     * - idx_flows_bot_flow: StreamController lookup (bot_id + id)
     * - idx_flows_bot_default_deleted: Default flow queries with soft deletes
     * - idx_flow_kb_reverse: Reverse lookup (find flows by KB)
     * - idx_conversations_flow_bot: Flow relationship JOIN
     */
    public function up(): void
    {
        // Flows table indexes
        Schema::table('flows', function (Blueprint $table) {
            // Composite index for flow lookup by bot
            $table->index(['bot_id', 'id'], 'idx_flows_bot_flow');

            // Covering index for default flow queries with soft delete
            $table->index(['bot_id', 'is_default', 'deleted_at'], 'idx_flows_bot_default_deleted');
        });

        // Flow-KnowledgeBase pivot table reverse index
        Schema::table('flow_knowledge_base', function (Blueprint $table) {
            // Reverse lookup: find all flows using a specific KB
            $table->index(['knowledge_base_id', 'flow_id'], 'idx_flow_kb_reverse');
        });

        // Conversations table index for Flow relationship
        Schema::table('conversations', function (Blueprint $table) {
            // Composite index for conversation-flow JOIN
            $table->index(['current_flow_id', 'bot_id'], 'idx_conversations_flow_bot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropIndex('idx_flows_bot_flow');
            $table->dropIndex('idx_flows_bot_default_deleted');
        });

        Schema::table('flow_knowledge_base', function (Blueprint $table) {
            $table->dropIndex('idx_flow_kb_reverse');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_flow_bot');
        });
    }
};
